<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * lat / lng / geo_status on attendance_logs.
 *
 * AttendanceReportController::ensurePunchColumns already adds these lazily on
 * the first in-app self punch. The Caller field-force API writes them too, and
 * must not depend on someone having self-punched first — so they are made
 * permanent here. Idempotent: an install that already has them is untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('attendance_logs')) {
            return;
        }
        foreach (['lat', 'lng'] as $c) {
            if (! Schema::hasColumn('attendance_logs', $c)) {
                Schema::table('attendance_logs', fn (Blueprint $t) => $t->decimal($c, 10, 6)->nullable());
            }
        }
        if (! Schema::hasColumn('attendance_logs', 'geo_status')) {
            Schema::table('attendance_logs', fn (Blueprint $t) => $t->string('geo_status', 20)->nullable());
        }
    }

    public function down(): void
    {
        // Shared with the self-punch feature — never dropped.
    }
};
