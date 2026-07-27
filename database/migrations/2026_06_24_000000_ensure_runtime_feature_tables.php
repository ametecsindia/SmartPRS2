<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * rev154 — guarantee the "self-creating" feature tables/columns exist at INSTALL
 * time instead of only on first runtime use.
 *
 * Why: attendance_logs, transfers, commission_payments and commission_logs (plus
 * the richer employees columns) were created on-demand by their controllers. On
 * the developer box (MySQL root) that always succeeded, so everything worked. On
 * a client on-prem install the on-demand creation could fail or never fire — and
 * with APP_DEBUG=false the error is hidden — which made exactly three things
 * break while the rest of the app was fine:
 *   - Employee import (ALTER employees ... fails)            -> upload "does nothing"
 *   - Attendance -> Payroll (attendance_logs missing)        -> present days = 0
 *   - Approvals / transfers (transfers table missing)        -> workflow errors
 *
 * Every block below is guarded (hasTable / hasColumn), so this is a safe no-op on
 * any install where the table already exists (e.g. the demo editions). Schemas
 * mirror the runtime definitions exactly.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1) attendance_logs — read by PayrollGenController::presentDays() (LOP proration).
        if (! Schema::hasTable('attendance_logs')) {
            Schema::create('attendance_logs', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('tenant_id')->nullable()->index();
                $t->unsignedBigInteger('company_id')->nullable()->index();
                $t->string('emp_code')->index();
                $t->string('emp_name')->nullable();
                $t->date('log_date')->index();
                $t->dateTime('punch_at');
                $t->string('direction', 4);                 // in | out
                $t->string('source')->default('biometric');
                $t->timestamps();
                $t->index(['emp_code', 'log_date']);
            });
        }

        // 2) transfers — the only request table with no base migration; the whole
        //    transfer/approval workflow (ApprovalService) depends on it.
        if (! Schema::hasTable('transfers')) {
            Schema::create('transfers', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('tenant_id')->nullable()->index();
                $t->unsignedBigInteger('company_id')->nullable();
                $t->unsignedBigInteger('employee_id')->index();
                $t->string('type', 30)->default('Branch transfer');
                $t->string('from_company')->nullable();
                $t->string('from_branch')->nullable();
                $t->string('to_company')->nullable();
                $t->unsignedBigInteger('to_company_id')->nullable();
                $t->string('to_branch')->nullable();
                $t->string('to_department')->nullable();
                $t->date('effective_date')->nullable();
                $t->string('reason', 500)->nullable();
                $t->string('status', 20)->default('pending');
                $t->timestamp('applied_at')->nullable();
                $t->string('accept_token', 64)->nullable()->index();
                $t->timestamp('accepted_at')->nullable();
                $t->timestamp('order_sent_at')->nullable();
                $t->unsignedBigInteger('approver_id')->nullable();
                $t->string('approver_name')->nullable();
                $t->string('decided_by')->nullable();
                $t->timestamp('decided_at')->nullable();
                $t->string('remarks')->nullable();
                $t->string('fin_year', 10)->nullable();
                $t->timestamps();
            });
        }

        // 3) commission_payments — collection-commission payout records (approvals).
        if (! Schema::hasTable('commission_payments')) {
            Schema::create('commission_payments', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('tenant_id')->nullable()->index();
                $t->unsignedBigInteger('commission_id')->index();
                $t->unsignedBigInteger('employee_id')->nullable()->index();
                $t->date('paid_on')->nullable();
                $t->decimal('amount', 12, 2);
                $t->string('mode', 30)->nullable();
                $t->string('reference')->nullable();
                $t->string('note', 500)->nullable();
                $t->string('by')->nullable();
                $t->timestamp('created_at')->useCurrent();
            });
        }

        // 4) commission_logs — audit trail for the commission approval lifecycle.
        if (! Schema::hasTable('commission_logs')) {
            Schema::create('commission_logs', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('tenant_id')->nullable()->index();
                $t->unsignedBigInteger('commission_id')->index();
                $t->string('action', 40);
                $t->string('details', 1000)->nullable();
                $t->string('by')->nullable();
                $t->timestamp('at')->useCurrent();
            });
        }

        // 5) employees — richer columns the import template writes (org hierarchy +
        //    contact/date fields). Added here so the import never needs runtime ALTER.
        if (Schema::hasTable('employees')) {
            $cols = [
                'department', 'designation', 'branch', 'team',
                'reporting_manager', 'team_leader',
                'whatsapp', 'address', 'dob', 'doj',
            ];
            $missing = array_values(array_filter($cols, fn ($c) => ! Schema::hasColumn('employees', $c)));
            if ($missing) {
                Schema::table('employees', function (Blueprint $t) use ($missing) {
                    foreach ($missing as $c) {
                        $t->string($c)->nullable();
                    }
                });
            }
        }

        // 6) APPROVAL audit columns. ApprovalService::ensureAuditCols() adds these
        //    to every approvable request table on the fly; if that runtime ALTER
        //    fails on a client, every Approve/Reject errors. Guarantee them here so
        //    the approval workflow works with no runtime DDL. (transfers already has
        //    them from its create above.)
        $approvable = [
            'expenses', 'advances', 'loans', 'commissions',
            'clawbacks', 'increments', 'exits', 'bonus_encashment',
        ];
        foreach ($approvable as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $needStr = array_values(array_filter(
                ['approver_name', 'decided_by', 'remarks'],
                fn ($c) => ! Schema::hasColumn($table, $c)
            ));
            $needApproverId = ! Schema::hasColumn($table, 'approver_id');
            $needDecidedAt = ! Schema::hasColumn($table, 'decided_at');
            if (! $needStr && ! $needApproverId && ! $needDecidedAt) {
                continue;
            }
            Schema::table($table, function (Blueprint $t) use ($needStr, $needApproverId, $needDecidedAt) {
                foreach ($needStr as $c) {
                    $t->string($c)->nullable();
                }
                if ($needApproverId) {
                    $t->unsignedBigInteger('approver_id')->nullable();
                }
                if ($needDecidedAt) {
                    $t->timestamp('decided_at')->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        // Non-destructive: these tables may hold live client data created either by
        // this migration or by the legacy runtime path, so we do not drop them.
    }
};
