<?php

use App\Support\SchemaHelper;
use Illuminate\Database\Migrations\Migration;

/**
 * F2 — attendance correction (regularisation) requests.
 *
 * Idempotent (SchemaHelper) and mirrors AttendanceCorrectionService::ensureSchema(),
 * so the feature works whether or not this migration has run. down() drops it.
 */
return new class extends Migration
{
    public function up(): void
    {
        SchemaHelper::ensureTable('attendance_corrections', function ($t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->nullable()->index();
            $t->unsignedBigInteger('company_id')->nullable();
            $t->unsignedBigInteger('employee_id')->index();
            $t->string('emp_code')->nullable()->index();
            $t->string('emp_name')->nullable();
            $t->date('log_date')->index();
            $t->string('sub_type', 30);
            $t->time('orig_in')->nullable();
            $t->time('orig_out')->nullable();
            $t->time('req_in')->nullable();
            $t->time('req_out')->nullable();
            $t->time('app_in')->nullable();
            $t->time('app_out')->nullable();
            $t->string('reason', 1000)->nullable();
            $t->string('status', 20)->default('pending')->index();
            $t->unsignedBigInteger('approver_id')->nullable();
            $t->string('approver_name')->nullable();
            $t->string('decided_by')->nullable();
            $t->timestamp('decided_at')->nullable();
            $t->string('remarks', 1000)->nullable();
            $t->timestamp('applied_at')->nullable();
            $t->boolean('payroll_locked')->default(false);
            $t->timestamps();
            $t->index(['tenant_id', 'employee_id', 'log_date']);
        });
    }

    public function down(): void
    {
        SchemaHelper::dropTableIfExists('attendance_corrections');
    }
};
