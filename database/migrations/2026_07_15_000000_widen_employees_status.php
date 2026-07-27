<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * rev183 — employees.status was ENUM('active','notice','exited'), so the new
 * Active/Inactive toggle (setEmployeeStatus) writing 'inactive' throws, on a
 * strict-mode MySQL (Laragon/XAMPP):
 *   SQLSTATE[01000]: Data truncated for column 'status' at row 1
 * Widen the column to VARCHAR so 'inactive' (and any future workflow state) is
 * valid, matching how status is stored on the other tables (string, not enum).
 *
 * Idempotent and best-effort — an ALTER privilege/engine quirk must never abort
 * the install.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('employees') && Schema::hasColumn('employees', 'status')) {
            try {
                DB::statement("ALTER TABLE `employees` MODIFY `status` VARCHAR(20) NOT NULL DEFAULT 'active'");
            } catch (\Throwable $e) {
                // best-effort; do not break the install on an ALTER failure
            }

            // Repair any rows a prior non-strict MySQL had blanked on a truncated
            // write, so they read as active again.
            try {
                DB::table('employees')->where('status', '')->update(['status' => 'active']);
            } catch (\Throwable $e) {
            }
        }
    }

    public function down(): void
    {
        // Keep the wider column — reverting to the ENUM would truncate 'inactive'.
    }
};
