<?php

namespace App\Http\Controllers;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Shared approval engine reused by every request type (leave, expense, advance,
 * loan, commission, clawback, increment, exit, bonus, …).
 *
 * Responsibilities:
 *  - resolveApprover(): hierarchy + amount-threshold rule (hybrid).
 *  - ensureAuditCols(): add approver_id/approver_name/decided_by/decided_at/
 *    remarks to any request table that lacks them (schema-safe, idempotent).
 *  - safeRow(): filter an insert/update array to columns the table actually has.
 *
 * Hierarchy (escalation order):
 *   Reporting Manager  →  Team Leader  →  Branch / Company Admin (HR).
 * Threshold: a money request at/above the module threshold is bumped one level
 * up (so large amounts need a higher authority).
 */
class ApprovalService
{
    /** Module registry: table + amount column + threshold (₹) that bumps a level. */
    public static function modules(): array
    {
        return [
            'expenses' => ['table' => 'expenses', 'label' => 'Expense Claim', 'amount' => 'amount', 'threshold' => 10000],
            'advances' => ['table' => 'advances', 'label' => 'Salary Advance', 'amount' => 'amount', 'threshold' => 10000],
            'loans' => ['table' => 'loans', 'label' => 'Loan', 'amount' => 'amount', 'threshold' => 50000],
            'commissions' => ['table' => 'commissions', 'label' => 'Commission', 'amount' => 'amount', 'threshold' => 0],
            'clawbacks' => ['table' => 'clawbacks', 'label' => 'Clawback', 'amount' => 'amount', 'threshold' => 0],
            'increments' => ['table' => 'increments', 'label' => 'Increment / Appraisal', 'amount' => 'new_ctc', 'threshold' => 0],
            'exits' => ['table' => 'exits', 'label' => 'Exit & FnF', 'amount' => null, 'threshold' => 0],
            'bonus' => ['table' => 'bonus_encashment', 'label' => 'Bonus / Encashment', 'amount' => 'amount', 'threshold' => 0],
            // rev 77: employee transfers (branch↔branch, master↔subsidiary company)
            // with the same approval chain; applied on the effective date by
            // TransferService (immediately on approval when already due).
            'transfers' => ['table' => 'transfers', 'label' => 'Employee Transfer', 'amount' => null, 'threshold' => 0],
        ];
    }

    /** Add the audit columns to a request table if missing (idempotent). */
    public static function ensureAuditCols(string $table): void
    {
        // rev 77: `transfers` is the only request table with no base migration —
        // self-created here (the hook every list/apply/decide/inbox call passes).
        if ($table === 'transfers' && ! Schema::hasTable('transfers')) {
            Schema::create('transfers', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('tenant_id')->nullable()->index();
                $t->unsignedBigInteger('company_id')->nullable();      // FROM company (at request time)
                $t->unsignedBigInteger('employee_id')->index();
                $t->string('type', 30)->default('Branch transfer');     // Branch transfer | Company transfer
                $t->string('from_company')->nullable();                  // snapshots, filled when APPLIED
                $t->string('from_branch')->nullable();
                $t->string('to_company')->nullable();
                $t->unsignedBigInteger('to_company_id')->nullable();
                $t->string('to_branch')->nullable();
                $t->string('to_department')->nullable();
                $t->date('effective_date')->nullable();
                $t->string('reason', 500)->nullable();
                $t->string('status', 20)->default('pending');            // pending|approved|rejected|applied
                $t->timestamp('applied_at')->nullable();
                $t->string('accept_token', 64)->nullable()->index();     // employee acknowledgement link
                $t->timestamp('accepted_at')->nullable();
                $t->timestamp('order_sent_at')->nullable();              // transfer-order email sent (timeline)
                $t->unsignedBigInteger('approver_id')->nullable();
                $t->string('approver_name')->nullable();
                $t->string('decided_by')->nullable();
                $t->timestamp('decided_at')->nullable();
                $t->string('remarks')->nullable();
                $t->string('fin_year', 10)->nullable();
                $t->timestamps();
            });

            return;
        }
        // Self-heal: columns added after the table may already exist on a deploy
        // (rev 77b acknowledgement; rev 77c department transfers).
        if ($table === 'transfers' && Schema::hasTable('transfers')) {
            try {
                if (! Schema::hasColumn('transfers', 'accept_token')) {
                    Schema::table('transfers', function (Blueprint $t) {
                        $t->string('accept_token', 64)->nullable()->index();
                        $t->timestamp('accepted_at')->nullable();
                    });
                }
                if (! Schema::hasColumn('transfers', 'to_department')) {
                    Schema::table('transfers', fn (Blueprint $t) => $t->string('to_department')->nullable());
                }
                if (! Schema::hasColumn('transfers', 'order_sent_at')) {
                    Schema::table('transfers', fn (Blueprint $t) => $t->timestamp('order_sent_at')->nullable());
                }
            } catch (\Throwable $e) {
            }
        }
        // rev 83 (Ejaz): commission register — purpose / description / gross +
        // TDS 194H breakdown / entered_by audit. `amount` stays the NET payable
        // (what payroll + live salary already read), so old rows keep working.
        if ($table === 'commissions' && Schema::hasTable('commissions')) {
            try {
                $want = [
                    'purpose' => 'string', 'description' => 'string', 'entered_by' => 'string',
                    'portfolio' => 'string', 'cycle_month' => 'string',
                    'gross_amount' => 'decimal', 'tds_rate' => 'decimal', 'tds_amount' => 'decimal',
                    // rev 84 (Ejaz, the USP): payout-date driven payslips + the
                    // LOCK that freezes an entry once it has been paid out.
                    'payout_date' => 'date', 'locked_at' => 'datetime', 'locked_by' => 'string', 'lock_source' => 'string',
                    // rev 85: 'with_salary' (folds into the payslip) or
                    // 'separate' (own dates, paid through the ledger).
                    'payout_method' => 'string',
                ];
                $add = array_filter($want, fn ($t, $c) => ! Schema::hasColumn('commissions', $c), ARRAY_FILTER_USE_BOTH);
                if ($add) {
                    Schema::table('commissions', function (Blueprint $t) use ($add) {
                        foreach ($add as $c => $type) {
                            if ($type === 'decimal') {
                                $t->decimal($c, 12, 2)->nullable();
                            } elseif ($type === 'date') {
                                $t->date($c)->nullable();
                            } elseif ($type === 'datetime') {
                                $t->timestamp($c)->nullable();
                            } else {
                                $t->string($c, 500)->nullable();
                            }
                        }
                    });
                }
            } catch (\Throwable $e) {
            }
        }
        // rev 83b (Ejaz): increment module completion — promotion designation,
        // letter + manual-application stamps. Idempotent self-heal.
        if ($table === 'increments' && Schema::hasTable('increments')) {
            try {
                $wantInc = [
                    'cycle' => 'string', 'reason' => 'string', 'new_designation' => 'string', 'applied_by' => 'string',
                    'old_ctc' => 'decimal', 'new_ctc' => 'decimal', 'pct' => 'decimal',
                    'effective' => 'date', 'applied_at' => 'datetime', 'letter_sent_at' => 'datetime',
                ];
                $addInc = array_filter($wantInc, fn ($t, $c) => ! Schema::hasColumn('increments', $c), ARRAY_FILTER_USE_BOTH);
                if ($addInc) {
                    Schema::table('increments', function (Blueprint $t) use ($addInc) {
                        foreach ($addInc as $c => $type) {
                            if ($type === 'decimal') {
                                $t->decimal($c, 14, 2)->nullable();
                            } elseif ($type === 'date') {
                                $t->date($c)->nullable();
                            } elseif ($type === 'datetime') {
                                $t->timestamp($c)->nullable();
                            } else {
                                $t->string($c, 500)->nullable();
                            }
                        }
                    });
                }
            } catch (\Throwable $e) {
            }
            // rev 85 (Ejaz): the DISBURSEMENT LEDGER — every rupee paid against
            // a commission entry (partial payments, bank/UPI/cash, or the
            // automatic 'payslip' debit when payroll pays it). Append-only.
            try {
                if (! Schema::hasTable('commission_payments')) {
                    Schema::create('commission_payments', function (Blueprint $t) {
                        $t->id();
                        $t->unsignedBigInteger('tenant_id')->nullable()->index();
                        $t->unsignedBigInteger('commission_id')->index();
                        $t->unsignedBigInteger('employee_id')->nullable()->index();
                        $t->date('paid_on')->nullable();
                        $t->decimal('amount', 12, 2);
                        $t->string('mode', 30)->nullable();      // bank|upi|cash|cheque|payslip
                        $t->string('reference')->nullable();     // UTR / cheque no / run #
                        $t->string('note', 500)->nullable();
                        $t->string('by')->nullable();
                        $t->timestamp('created_at')->useCurrent();
                    });
                }
            } catch (\Throwable $e) {
            }
        }
        // J3 (Ejaz): exit clearance checklist — structured offboarding columns on
        // the exits table so Exit & FnF captures ID surrender, asset return,
        // access revoke, DRA-ID return, knowledge transfer + a final clearance
        // verdict. Idempotent self-heal (additive, nullable).
        if ($table === 'exits' && Schema::hasTable('exits')) {
            try {
                $wantExit = [
                    'last_working_day' => 'date',
                    'id_surrendered' => 'string',
                    'access_revoked' => 'string',
                    'dra_id_returned' => 'string',
                    'data_handed_over' => 'string',
                    'knowledge_transfer' => 'string',
                    'final_clearance' => 'string',
                    'cleared_by' => 'string',
                    'cleared_on' => 'date',
                    'clearance_remarks' => 'string',
                ];
                $addExit = array_filter($wantExit, fn ($t, $c) => ! Schema::hasColumn('exits', $c), ARRAY_FILTER_USE_BOTH);
                if ($addExit) {
                    Schema::table('exits', function (Blueprint $t) use ($addExit) {
                        foreach ($addExit as $c => $type) {
                            if ($type === 'bool') {
                                $t->boolean($c)->default(false);
                            } elseif ($type === 'date') {
                                $t->date($c)->nullable();
                            } else {
                                $t->string($c, 500)->nullable();
                            }
                        }
                    });
                }
            } catch (\Throwable $e) {
            }
        }
        // rev 79b (Ejaz found live, 4 Jun 2026): legacy ENUM status columns from
        // the original migrations (e.g. commissions: provisional/confirmed/
        // reversed) cannot store the approval workflow's pending/approved →
        // "Data truncated for column 'status'". Widen to VARCHAR once; the
        // condition is false afterwards so this never runs again.
        try {
            if (Schema::hasColumn($table, 'status')) {
                $col = DB::selectOne("SHOW COLUMNS FROM `{$table}` LIKE 'status'");
                $type = strtolower((string) ($col->Type ?? ''));
                if (str_starts_with($type, 'enum') && (! str_contains($type, "'pending'") || ! str_contains($type, "'approved'"))) {
                    DB::statement("ALTER TABLE `{$table}` MODIFY `status` VARCHAR(20) NOT NULL DEFAULT 'pending'");
                }
            }
        } catch (\Throwable $e) {
            // best-effort; a failed widen surfaces as the original insert error
        }
        if (! Schema::hasTable($table)) {
            return;
        }
        $strs = [];
        foreach (['approver_name', 'decided_by', 'remarks'] as $c) {
            if (! Schema::hasColumn($table, $c)) {
                $strs[] = $c;
            }
        }
        $needApproverId = ! Schema::hasColumn($table, 'approver_id');
        $needDecidedAt = ! Schema::hasColumn($table, 'decided_at');
        if (! $strs && ! $needApproverId && ! $needDecidedAt) {
            return;
        }
        Schema::table($table, function (Blueprint $t) use ($strs, $needApproverId, $needDecidedAt) {
            foreach ($strs as $c) {
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

    /** Keep only keys that are real columns of $table. */
    public static function safeRow(string $table, array $row): array
    {
        return array_intersect_key($row, array_flip(Schema::getColumnListing($table)));
    }

    /**
     * rev 84 (Ejaz, the USP): per-entry HISTORY for commissions. Every touch —
     * created, edited (field diffs), approved/rejected (remarks), reopened,
     * locked, paid in a payslip — gets one immutable log row. Fail-soft:
     * logging must never break the action it describes.
     */
    public static function logCommission(?int $tenantId, int $commissionId, string $action, string $details, string $by): void
    {
        try {
            if (! Schema::hasTable('commission_logs')) {
                Schema::create('commission_logs', function (Blueprint $t) {
                    $t->id();
                    $t->unsignedBigInteger('tenant_id')->nullable()->index();
                    $t->unsignedBigInteger('commission_id')->index();
                    $t->string('action', 40);                 // created|edited|approved|rejected|reopened|locked|payslip
                    $t->string('details', 1000)->nullable();  // human-readable summary / field diffs
                    $t->string('by')->nullable();
                    $t->timestamp('at')->useCurrent();
                });
            }
            DB::table('commission_logs')->insert([
                'tenant_id' => $tenantId,
                'commission_id' => $commissionId,
                'action' => $action,
                'details' => mb_substr($details, 0, 1000),
                'by' => mb_substr($by, 0, 255),
                'at' => now(),
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Commission log failed (#'.$commissionId.' '.$action.'): '.$e->getMessage());
        }
    }

    /**
     * Resolve the approver for an employee + amount.
     * Returns [approver_employee_id|null, approver_name].
     *
     * Rule:
     *  - base = reporting manager (FK → employee; else name; else HR fallback).
     *  - if $amount >= $threshold (and threshold>0), escalate one level up:
     *    base's own manager, else a Branch/Company admin label.
     */
    public static function resolveApprover($emp, ?int $tenantId, ?float $amount = null, float $threshold = 0): array
    {
        if (! $emp) {
            return [null, 'Reporting Manager / HR'];
        }
        $a = (array) $emp;

        // Base approver = reporting manager.
        $mgr = null;
        $mgrName = '';
        if (! empty($a['reporting_manager_id'])) {
            $mgr = DB::table('employees')->where('id', $a['reporting_manager_id'])->first();
            $mgrName = $mgr->name ?? '';
        }
        if (! $mgr && ! empty($a['reporting_manager'])) {
            $mgr = DB::table('employees')
                ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
                ->where('name', $a['reporting_manager'])->first();
            $mgrName = $a['reporting_manager'];
        }

        // No escalation needed.
        if (! ($threshold > 0 && $amount !== null && $amount >= $threshold)) {
            if ($mgr) {
                return [(int) $mgr->id, $mgr->name];
            }

            return [null, $mgrName ?: 'Reporting Manager / HR'];
        }

        // Escalate one level: the manager's manager, else a higher authority label.
        if ($mgr) {
            $mm = (array) $mgr;
            if (! empty($mm['reporting_manager_id'])) {
                $up = DB::table('employees')->where('id', $mm['reporting_manager_id'])->first();
                if ($up) {
                    return [(int) $up->id, $up->name.' (higher approval — amount ₹'.number_format($amount).')'];
                }
            }
        }

        return [null, 'Branch / Company Admin (amount ₹'.number_format((float) $amount).')'];
    }

    /**
     * Server-side role guard for write endpoints. Super Admin is always allowed.
     * Returns a fail-soft JSON 403 response when the user holds none of $roles,
     * else null (allowed). FAILS OPEN (returns null) if the role pivot can't be
     * read, so a schema/pivot hiccup never locks admins out of a live system.
     *
     * Usage in a controller (same namespace, no import needed):
     *   if ($deny = ApprovalService::denyUnlessRole($request, ['admin', 'hr_manager'])) {
     *       return $deny;
     *   }
     */
    public static function denyUnlessRole($request, array $roles)
    {
        try {
            $u = $request->user();
            if ($u && ($u->hasRole('super_admin') || $u->hasAnyRole($roles))) {
                return null;
            }

            return response()->json([
                'ok' => false,
                'error' => 'You do not have permission to perform this action.',
            ], 403);
        } catch (\Throwable $e) {
            return null;   // fail open — availability over strictness on pivot errors
        }
    }
}
