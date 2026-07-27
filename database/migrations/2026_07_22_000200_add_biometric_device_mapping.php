<?php

// F3 — multiple biometric devices: each device row maps to a Branch (name) plus
// an optional free-text location label. Guarded + fail-soft; the controller's
// ensureTable() also self-heals these columns at runtime.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        try {
            if (! Schema::hasTable('biometric_configs')) {
                return;
            }
            foreach (['label' => 120, 'branch' => 120] as $c => $len) {
                if (! Schema::hasColumn('biometric_configs', $c)) {
                    Schema::table('biometric_configs', function (Blueprint $t) use ($c, $len) {
                        $t->string($c, $len)->nullable();
                    });
                }
            }
        } catch (\Throwable $e) {
            // non-fatal — runtime ensureTable() adds them lazily.
        }
    }

    public function down(): void
    {
        // keep the columns.
    }
};
