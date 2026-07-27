<?php

// TDD §4.5 — Statutory (India compliance).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pf_esi_settings', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->unsignedBigInteger('company_id')->index();
            $t->decimal('pf_ee_pct', 5, 2)->default(12);
            $t->decimal('pf_er_pct', 5, 2)->default(12);
            $t->decimal('eps_pct', 5, 2)->default(8.33);
            $t->decimal('esi_ee_pct', 5, 2)->default(0.75);
            $t->decimal('esi_er_pct', 5, 2)->default(3.25);
            $t->decimal('esi_ceiling', 12, 2)->default(21000);
            $t->timestamps();
        });

        Schema::create('tds_rules', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->unsignedBigInteger('company_id')->index();
            $t->decimal('incentive_pct', 5, 2)->default(0);
            $t->decimal('commission_pct', 5, 2)->default(0);
            $t->string('salary_tds_mode')->nullable();
            $t->boolean('higher_if_no_pan')->default(true);
            $t->timestamps();
        });

        Schema::create('pt_slabs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->string('state');
            $t->decimal('wage_from', 12, 2)->default(0);
            $t->decimal('wage_to', 12, 2)->nullable();
            $t->decimal('amount', 10, 2)->default(0);
            $t->timestamps();
        });

        Schema::create('tds_returns', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->unsignedBigInteger('company_id')->index();
            $t->string('quarter')->nullable();
            $t->unsignedInteger('deductees')->default(0);
            $t->decimal('tax_deducted', 14, 2)->default(0);
            $t->decimal('deposited', 14, 2)->default(0);
            $t->date('due_date')->nullable();
            $t->string('status')->default('pending');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['tds_returns', 'pt_slabs', 'tds_rules', 'pf_esi_settings'] as $tbl) {
            Schema::dropIfExists($tbl);
        }
    }
};
