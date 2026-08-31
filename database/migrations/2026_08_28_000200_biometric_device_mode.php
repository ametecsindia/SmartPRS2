<?php

/**
 * 28 Aug 2026 (Ejaz) — BIOMETRIC DEVICE SETUP: 1 device vs 2 devices.
 *
 * A location may run ONE reader (which must alternate IN/OUT by punch order)
 * or TWO (entry reader = IN, exit reader = OUT). Until now only the two-device
 * case was expressible, via in_machine_id / out_machine_id.
 *
 * One additive nullable column, no data migration:
 *
 *   'single'  -> ETimeOfficeService::import derives the direction from the
 *                punch's rank in that employee's day (1st IN, 2nd OUT, ...).
 *   'dual'    -> in_machine_id / out_machine_id decide, else the feed's flag.
 *   NULL      -> every row that existed before this migration. Read as 'dual',
 *                which is byte-for-byte the behaviour those rows already had,
 *                so no configured site needs reconfiguring.
 *
 * Re-runnable: MySQL DDL is not transactional, so a migration that stops
 * half-way is never recorded and is retried from the top. hasColumn guards it.
 * See the same note on 2026_08_28_000100_employee_field_parity.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('biometric_configs')) {
            return;   // created with the column by BiometricConfigController::ensureTable()
        }
        if (! Schema::hasColumn('biometric_configs', 'device_mode')) {
            Schema::table('biometric_configs', function (Blueprint $t) {
                $t->string('device_mode', 10)->nullable();
            });
        }
    }

    public function down(): void
    {
        // Keep the column — dropping it silently reverts every 1-device location
        // to two-device direction handling.
    }
};
