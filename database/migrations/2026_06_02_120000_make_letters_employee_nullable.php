<?php

// rev 45 fix — Letter TEMPLATES have no employee, but letters.employee_id was
// NOT NULL with no default, so saving a template failed (SQLSTATE 1364).
// Make it nullable. Uses a raw ALTER on MySQL (reliable, no doctrine/dbal),
// native change() elsewhere (sqlite tests). Wrapped so it can never abort a deploy
// — the app-layer default in MasterController covers templates either way.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        try {
            if (! Schema::hasTable('letters') || ! Schema::hasColumn('letters', 'employee_id')) {
                return;
            }
            if (Schema::getConnection()->getDriverName() === 'mysql') {
                DB::statement('ALTER TABLE `letters` MODIFY `employee_id` BIGINT UNSIGNED NULL');
            } else {
                Schema::table('letters', function (Blueprint $t) {
                    $t->unsignedBigInteger('employee_id')->nullable()->change();
                });
            }
        } catch (\Throwable $e) {
            // non-fatal — MasterController sets a default employee_id for templates.
        }
    }

    public function down(): void
    {
        // no-op
    }
};
