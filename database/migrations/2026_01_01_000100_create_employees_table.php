<?php

// TDD §4.2 — Org & people.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 28 Aug 2026 (Ejaz) - client install died here with
     *   SQLSTATE[42S01]: Base table or view already exists: 1050
     *   Table 'training_programs' already exists
     * MySQL DDL is NOT transactional: if this migration ever stops part-way
     * (or the target DB already holds these tables - a re-install over an
     * existing database, a restored dump without its `migrations` rows, a
     * wrong DB_DATABASE), the row is never written to `migrations`, so every
     * retry re-runs the whole file and dies on the FIRST table that exists.
     * That is unrecoverable without dropping the database.
     * Creating only what is missing makes the migration re-runnable, so a
     * half-applied install repairs itself on the next `php artisan migrate`.
     * A real error (bad column, no privilege) still throws - only "already
     * there" is skipped.
     */
    private function mk(string $table, \Closure $definition): void
    {
        if (! Schema::hasTable($table)) {
            Schema::create($table, $definition);
        }
    }

    public function up(): void
    {
        $this->mk('companies', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->string('name');
            $t->string('type')->nullable();
            $t->string('pan', 10)->nullable();
            $t->string('gstin', 15)->nullable();
            $t->string('address')->nullable();
            $t->string('phone', 20)->nullable();
            $t->string('email')->nullable();
            $t->string('website')->nullable();
            $t->string('color')->nullable();
            $t->string('logo_path')->nullable();
            $t->string('logo_text')->nullable();
            $t->string('status')->default('active');
            $t->timestamps();
            $t->softDeletes();
        });

        $this->mk('departments', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->string('name');
            $t->unsignedBigInteger('head_employee_id')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        $this->mk('designations', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->string('name');
            $t->unsignedBigInteger('department_id')->nullable()->index();
            $t->timestamps();
            $t->softDeletes();
        });

        $this->mk('branches', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->unsignedBigInteger('company_id')->index();
            $t->string('name');
            $t->string('city')->nullable();
            $t->string('pincode', 10)->nullable();
            $t->decimal('lat', 10, 7)->nullable();
            $t->decimal('lng', 10, 7)->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        $this->mk('banks', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->string('name');
            $t->timestamps();
        });

        $this->mk('teams', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->unsignedBigInteger('company_id')->index();
            $t->string('name');
            $t->string('function')->nullable();
            $t->unsignedBigInteger('manager_id')->nullable();
            $t->unsignedBigInteger('leader_id')->nullable();
            $t->string('status')->default('active');
            $t->timestamps();
            $t->softDeletes();
        });

        $this->mk('employees', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->unsignedBigInteger('company_id')->index();
            $t->string('emp_code');
            $t->string('name');
            $t->date('dob')->nullable();
            $t->string('gender')->nullable();
            $t->string('mobile', 20)->nullable();
            $t->boolean('mobile_verified')->default(false);
            $t->string('whatsapp', 20)->nullable();
            $t->boolean('wa_verified')->default(false);
            $t->string('email')->nullable();
            $t->boolean('email_verified')->default(false);
            $t->string('address')->nullable();
            $t->unsignedBigInteger('department_id')->nullable()->index();
            $t->unsignedBigInteger('designation_id')->nullable()->index();
            $t->unsignedBigInteger('branch_id')->nullable()->index();
            $t->unsignedBigInteger('team_id')->nullable()->index();
            $t->enum('type', ['office', 'field'])->default('office');
            $t->unsignedBigInteger('reporting_manager_id')->nullable();
            $t->date('doj')->nullable();
            $t->decimal('ctc', 12, 2)->default(0);
            $t->enum('salary_type', ['only_salary', 'salary_commission', 'only_commission'])->default('only_salary');
            $t->decimal('comm_pct', 5, 2)->nullable();
            $t->unsignedBigInteger('schedule_id')->nullable();
            $t->boolean('pf_applicable')->default(false);
            $t->enum('esi_applicable', ['auto', 'yes', 'no'])->default('auto');
            $t->string('pt_state')->nullable();
            $t->string('uan')->nullable();
            $t->string('pan', 10)->nullable();
            $t->enum('dra_status', ['verified', 'pending'])->nullable();
            $t->date('dra_expiry')->nullable();
            $t->enum('pcc_status', ['submitted', 'pending', 'overdue'])->nullable();
            $t->date('pcc_expiry')->nullable();
            $t->date('pcc_deadline')->nullable();
            $t->enum('docs_status', ['approved', 'pending', 'rejected'])->nullable();
            $t->boolean('docs_stored')->default(false);
            $t->decimal('home_lat', 10, 7)->nullable();
            $t->decimal('home_lng', 10, 7)->nullable();
            $t->enum('geo_start', ['home', 'office'])->nullable();
            $t->decimal('geo_radius_km', 4, 2)->nullable();
            $t->enum('geo_outside', ['strict', '1km', '2km'])->nullable();
            $t->string('bank_name')->nullable();
            $t->string('bank_acc')->nullable();
            $t->string('ifsc')->nullable();
            $t->string('device_user_id')->nullable()->index();
            $t->enum('status', ['active', 'notice', 'exited'])->default('active');
            $t->timestamps();
            $t->softDeletes();

            $t->unique(['tenant_id', 'emp_code']);
            $t->index(['company_id', 'status']);
        });

        $this->mk('employee_company', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('employee_id')->index();
            $t->unsignedBigInteger('company_id')->index();
        });

        $this->mk('employee_references', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('employee_id')->index();
            $t->string('name');
            $t->string('relation')->nullable();
            $t->string('aadhaar')->nullable();
            $t->string('pan', 10)->nullable();
            $t->string('mobile', 20)->nullable();
            $t->string('doc_path')->nullable();
            $t->boolean('verify_email')->default(false);
            $t->boolean('verify_sms')->default(false);
            $t->boolean('verify_call')->default(false);
            $t->boolean('verify_whatsapp')->default(false);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['employee_references', 'employee_company', 'employees', 'teams', 'banks', 'branches', 'designations', 'departments', 'companies'] as $tbl) {
            Schema::dropIfExists($tbl);
        }
    }
};
