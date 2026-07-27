<?php

// rev 70 fix — late_policy.addl_late was created as enum('half','full') NOT NULL,
// but the Late Policy wizard (rev 58) uses it as OPTIONAL free-text notes, so
// saving a policy with no notes failed (SQLSTATE 1048 'addl_late' cannot be null;
// and in strict mode any non-enum text would fail too). Convert to a nullable
// VARCHAR. Guarded + fail-soft so it can never abort a deploy (same pattern as
// 2026_06_02_120000_make_letters_employee_nullable).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        try {
            if (! Schema::hasTable('late_policy') || ! Schema::hasColumn('late_policy', 'addl_late')) {
                return;
            }
            if (Schema::getConnection()->getDriverName() === 'mysql') {
                DB::statement('ALTER TABLE `late_policy` MODIFY `addl_late` VARCHAR(255) NULL');
            } else {
                Schema::table('late_policy', function (Blueprint $t) {
                    $t->string('addl_late')->nullable()->change();
                });
            }
        } catch (\Throwable $e) {
            // non-fatal — the wizard can also save with notes filled in.
        }
    }

    public function down(): void
    {
        // no-op
    }
};
