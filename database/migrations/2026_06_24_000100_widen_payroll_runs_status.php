<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * rev155 — payroll_runs.status was ENUM('draft','locked','approved','paid'), but
 * the two-step salary-approval workflow (SalaryApprovalController) also writes
 * 'hr_approved', 'rejected' and 'acknowledged'. On a strict-mode MySQL (the
 * client's XAMPP) writing a value outside the ENUM throws
 *   SQLSTATE[01000]: Data truncated for column 'status'
 * and the HR/Finance approval fails; on the non-strict dev box it silently blanked
 * the value instead. Widen the column to VARCHAR so every workflow state is valid.
 *
 * Idempotent (safe to run again) and best-effort (a privilege/engine quirk must
 * never abort the install).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('payroll_runs') && Schema::hasColumn('payroll_runs', 'status')) {
            try {
                DB::statement("ALTER TABLE `payroll_runs` MODIFY `status` VARCHAR(30) NOT NULL DEFAULT 'draft'");
            } catch (\Throwable $e) {
                // best-effort; do not break the install on an ALTER failure
            }

            // Repair any rows the old non-strict MySQL had blanked on a prior
            // truncated write, so they re-enter the workflow as drafts.
            try {
                DB::table('payroll_runs')->where('status', '')->update(['status' => 'draft']);
            } catch (\Throwable $e) {
            }
        }
    }

    public function down(): void
    {
        // Keep the wider column — reverting to the ENUM would truncate live
        // workflow states (hr_approved / rejected / acknowledged).
    }
};
