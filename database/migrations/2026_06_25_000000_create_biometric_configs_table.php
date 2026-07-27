<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * rev157 — frontend-managed biometric/cloud-attendance API config.
 * Moves the eTimeOffice (and future vendors') connection settings out of .env
 * and into a per-tenant DB row editable from the "Biometric Device Setup" screen.
 * The password is stored encrypted at the app layer (Crypt). emp_prefix lets the
 * device's bare code (e.g. 12345) be matched to the SmartPRS Employee Code
 * (e.g. A12345).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('biometric_configs')) {
            // rev173e — In/Out Machine ID columns for installs that already have
            // the table (separate entry/exit devices → punch direction by machine).
            foreach (['in_machine_id', 'out_machine_id'] as $c) {
                if (! Schema::hasColumn('biometric_configs', $c)) {
                    Schema::table('biometric_configs', function (Blueprint $t) use ($c) {
                        $t->string($c, 40)->nullable();
                    });
                }
            }

            return;
        }
        Schema::create('biometric_configs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->nullable()->index();
            $t->string('provider', 40)->default('etimeoffice');
            $t->boolean('enabled')->default(false);
            $t->string('base_url')->nullable();
            $t->string('endpoint')->nullable();
            $t->string('corp_id')->nullable();
            $t->string('username')->nullable();
            $t->text('password')->nullable();          // encrypted at app layer
            $t->string('empcode')->default('ALL');
            $t->string('emp_prefix', 20)->nullable();   // prepended to device code
            $t->string('in_machine_id', 40)->nullable();   // rev173e — entry device machine no. → IN
            $t->string('out_machine_id', 40)->nullable();  // rev173e — exit device machine no. → OUT
            $t->timestamp('last_sync_at')->nullable();
            $t->string('last_status')->nullable();
            $t->integer('last_count')->default(0);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        // Keep the table — it may hold a live connection the client depends on.
    }
};
