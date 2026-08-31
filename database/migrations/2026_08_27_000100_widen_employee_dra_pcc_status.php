<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 27 Aug 2026 (Ejaz) — DRA / PCC status parity between the Employee Import
 * template and the Directory → Employee → Documents tab.
 *
 * The employees table shipped these as narrow ENUMs:
 *   dra_status ENUM('verified','pending')
 *   pcc_status ENUM('submitted','pending','overdue')
 *
 * The import template has offered Pending / Submitted / Verified for BOTH since
 * 19 Aug, so on a strict-mode MySQL (Laragon / XAMPP / most client servers) an
 * imported "Submitted" DRA or "Verified" PCC threw
 *   SQLSTATE[01000]: Data truncated for column 'dra_status' at row 1
 * and on a non-strict server it was silently blanked. Same class of bug as
 * rev183's employees.status ENUM.
 *
 * Widen both to VARCHAR(20) so the one canonical set — pending | submitted |
 * verified (lowercase, what ComplianceController compares against) — is valid
 * on every install, exactly like the other status columns.
 *
 * 'overdue' is retired as a STORED value: it was never in the import template
 * and Compliance Alerts already derives "overdue" from PCC Deadline / PCC
 * Expiry, so an existing 'overdue' row is rolled back to 'pending' (the
 * deadline is what makes it overdue, not the flag).
 *
 * Idempotent and best-effort — an ALTER privilege/engine quirk must never abort
 * the install.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('employees')) {
            return;
        }

        foreach (['dra_status', 'pcc_status'] as $col) {
            if (! Schema::hasColumn('employees', $col)) {
                continue;
            }
            try {
                DB::statement("ALTER TABLE `employees` MODIFY `{$col}` VARCHAR(20) NULL");
            } catch (\Throwable $e) {
                // best-effort; do not break the install on an ALTER failure
            }
            // Normalise casing so the Documents-tab dropdown and
            // ComplianceController (=== 'verified') always agree.
            try {
                DB::statement("UPDATE `employees` SET `{$col}` = LOWER(TRIM(`{$col}`)) WHERE `{$col}` IS NOT NULL AND `{$col}` <> ''");
                DB::table('employees')->where($col, '')->update([$col => null]);
            } catch (\Throwable $e) {
            }
        }

        // 'overdue' is no longer a stored PCC status — the deadline decides.
        try {
            if (Schema::hasColumn('employees', 'pcc_status')) {
                DB::table('employees')->where('pcc_status', 'overdue')->update(['pcc_status' => 'pending']);
            }
        } catch (\Throwable $e) {
        }

        // The Documents tab now captures DRA / PCC expiry, and the import
        // template has carried both columns since 19 Aug. Guarantee they exist
        // (the original create-table has them; older patched installs may not).
        foreach (['dra_expiry', 'pcc_expiry', 'pcc_deadline'] as $d) {
            if (Schema::hasColumn('employees', $d)) {
                continue;
            }
            try {
                Schema::table('employees', function ($t) use ($d) {
                    $t->date($d)->nullable();
                });
            } catch (\Throwable $e) {
            }
        }
    }

    public function down(): void
    {
        // Keep the wider columns — reverting to the ENUMs would truncate
        // 'submitted' on DRA and 'verified' on PCC.
    }
};
