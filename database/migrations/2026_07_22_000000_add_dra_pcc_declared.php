<?php

// F5 — self-onboarding compliance self-declaration: DRA Status (Yes/No) and
// PCC Status (Yes/No). Guarded + fail-soft so it can never abort a deploy;
// AppDataController::ensureEmployeeColumns() also self-heals these at runtime.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        try {
            if (! Schema::hasTable('employees')) {
                return;
            }
            foreach (['dra_declared', 'pcc_declared'] as $c) {
                if (! Schema::hasColumn('employees', $c)) {
                    Schema::table('employees', function (Blueprint $t) use ($c) {
                        $t->string($c, 8)->nullable();   // 'Yes' | 'No' | null
                    });
                }
            }
        } catch (\Throwable $e) {
            // non-fatal — runtime ensureEmployeeColumns() will add them lazily.
        }
    }

    public function down(): void
    {
        // Keep the columns — they hold captured onboarding declarations.
    }
};
