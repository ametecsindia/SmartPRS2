<?php

// TDD §4.6 — Compliance / Field force.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offroll_agents', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->unsignedBigInteger('company_id')->index();
            $t->string('name');
            $t->string('vendor')->nullable();
            $t->string('mobile', 20)->nullable();
            $t->string('payout_type')->nullable();
            $t->decimal('rate', 12, 2)->nullable();
            $t->string('dra')->nullable();
            $t->string('pcc')->nullable();
            $t->string('status')->default('active');
            $t->timestamps();
        });

        Schema::create('agent_authorizations', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->unsignedBigInteger('company_id')->index();
            $t->unsignedBigInteger('employee_id')->nullable()->index();
            $t->string('bank')->nullable();
            $t->string('portfolio')->nullable();
            $t->string('auth_no')->nullable();
            $t->date('valid_to')->nullable();
            $t->string('status')->default('active');
            $t->timestamps();
        });

        Schema::create('assets', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->unsignedBigInteger('company_id')->index();
            $t->string('asset_type')->nullable();
            $t->string('asset_id')->nullable();
            $t->unsignedBigInteger('issued_to_employee_id')->nullable();
            $t->date('issue_date')->nullable();
            $t->date('return_date')->nullable();
            $t->string('status')->default('issued');
            $t->timestamps();
        });

        Schema::create('complaints', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->unsignedBigInteger('company_id')->index();
            $t->unsignedBigInteger('employee_id')->nullable()->index();
            $t->string('source')->nullable();
            $t->string('channel')->nullable();
            $t->string('severity')->nullable();
            $t->text('description')->nullable();
            $t->string('status')->default('open');
            $t->date('date')->nullable();
            $t->timestamps();
        });

        Schema::create('bgv', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->unsignedBigInteger('company_id')->index();
            $t->unsignedBigInteger('employee_id')->index();
            $t->string('agency')->nullable();
            $t->string('checks')->nullable();
            $t->string('status')->default('pending');
            $t->date('completed_on')->nullable();
            $t->timestamps();
        });

        Schema::create('exits', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->unsignedBigInteger('company_id')->index();
            $t->unsignedBigInteger('employee_id')->index();
            $t->date('last_day')->nullable();
            $t->decimal('advances_recovered', 12, 2)->default(0);
            $t->decimal('dues', 12, 2)->default(0);
            $t->decimal('fnf_amount', 12, 2)->default(0);
            $t->boolean('assets_returned')->default(false);
            $t->boolean('exit_interview')->default(false);
            $t->enum('rehire', ['eligible', 'do_not_rehire'])->nullable();
            $t->string('reason')->nullable();
            $t->string('status')->default('pending');
            $t->timestamps();
        });

        Schema::create('escalations', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->unsignedBigInteger('company_id')->index();
            $t->date('date')->nullable();
            $t->string('bank')->nullable();
            $t->string('team')->nullable();
            $t->string('issue')->nullable();
            $t->text('reason')->nullable();
            $t->string('level')->nullable();
            $t->string('severity')->nullable();
            $t->enum('priority', ['normal', 'emergency'])->default('normal');
            $t->string('status')->default('open');
            $t->decimal('penalty', 12, 2)->nullable();
            $t->string('action_taken')->nullable();
            $t->unsignedBigInteger('handled_by')->nullable();
            $t->timestamp('updated_on')->nullable();
            $t->timestamp('resolved_on')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['escalations', 'exits', 'bgv', 'complaints', 'assets', 'agent_authorizations', 'offroll_agents'] as $tbl) {
            Schema::dropIfExists($tbl);
        }
    }
};
