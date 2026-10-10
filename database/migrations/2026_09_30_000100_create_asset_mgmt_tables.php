<?php

use App\Http\Controllers\AssetController;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * 30 Sep 2026 (Ejaz) — Asset Stock + Asset Issue & Returns.
 * Re-runnable: AssetController::ensure() creates only the tables that are
 * missing (see the 28-Aug "table already exists" install failure).
 */
return new class extends Migration
{
    public function up(): void
    {
        AssetController::ensure();
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_issues');
        Schema::dropIfExists('asset_items');
    }
};
