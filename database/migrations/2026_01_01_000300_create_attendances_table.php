<?php

// TDD §4.4 — Payroll.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salary_schedules', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->unsignedBigInteger('company_id')->index();
            $t->string('name');
            $t->string('pay_cycle')->nullable();
            $t->json('lots')->nullable();
            $t->string('applicable_to')->nullable();
            $t->string('status')->default('active');
            $t->timestamps();
        });

        Schema::create('salary_components', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->string('code');
            $t->string('name');
            $t->enum('ctype', ['earning', 'deduction'])->default('earning');
            $t->enum('calc_type', ['fixed', 'percent', 'formula'])->default('fixed');
            $t->string('calc_value')->nullable();
            $t->boolean('taxable')->default(true);
            $t->timestamps();
        });

        Schema::create('payroll_runs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->unsignedBigInteger('company_id')->index();
            $t->unsignedBigInteger('schedule_id')->nullable();
            $t->integer('lot')->nullable();
            $t->string('cycle_label')->nullable();
            $t->date('pay_date')->nullable();
            $t->enum('status', ['draft', 'locked', 'approved', 'paid'])->default('draft');
            $t->unsignedInteger('employees_count')->default(0);
            $t->decimal('net_total', 14, 2)->default(0);
            $t->timestamp('locked_at')->nullable();
            $t->unsignedBigInteger('approved_by')->nullable();
            $t->timestamps();
        });

        Schema::create('payslips', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->unsignedBigInteger('company_id')->index();
            $t->unsignedBigInteger('employee_id')->index();
            $t->unsignedBigInteger('run_id')->nullable()->index();
            $t->string('month', 7)->nullable();
            $t->json('earnings')->nullable();
            $t->json('deductions')->nullable();
            $t->decimal('gross', 12, 2)->default(0);
            $t->decimal('total_ded', 12, 2)->default(0);
            $t->decimal('net', 12, 2)->default(0);
            $t->string('net_words')->nullable();
            $t->timestamp('emailed_at')->nullable();
            $t->string('pdf_path')->nullable();
            $t->timestamps();
        });

        Schema::create('commissions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->unsignedBigInteger('company_id')->index();
            $t->unsignedBigInteger('employee_id')->index();
            $t->string('portfolio')->nullable();
            $t->string('month', 7)->nullable();
            $t->decimal('amount', 12, 2)->default(0);
            $t->enum('status', ['provisional', 'confirmed', 'reversed'])->default('provisional');
            $t->timestamps();
        });

        Schema::create('incentive_schemes', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->string('name');
            $t->string('portfolio')->nullable();
            $t->string('basis')->nullable();
            $t->json('slabs')->nullable();
            $t->boolean('clawback')->default(false);
            $t->string('status')->default('active');
            $t->timestamps();
        });

        Schema::create('clawbacks', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->unsignedBigInteger('employee_id')->index();
            $t->string('portfolio')->nullable();
            $t->string('month', 7)->nullable();
            $t->decimal('amount', 12, 2)->default(0);
            $t->string('reason')->nullable();
            $t->string('status')->default('pending');
            $t->timestamps();
        });

        Schema::create('advances', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->unsignedBigInteger('employee_id')->index();
            $t->decimal('amount', 12, 2)->default(0);
            $t->decimal('per_month', 12, 2)->default(0);
            $t->decimal('balance', 12, 2)->default(0);
            $t->string('status')->default('active');
            $t->timestamps();
        });

        Schema::create('loans', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->unsignedBigInteger('employee_id')->index();
            $t->decimal('amount', 12, 2)->default(0);
            $t->decimal('emi', 12, 2)->default(0);
            $t->integer('tenure')->default(1);
            $t->decimal('outstanding', 12, 2)->default(0);
            $t->string('status')->default('active');
            $t->timestamps();
        });

        Schema::create('expenses', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->unsignedBigInteger('employee_id')->index();
            $t->string('type')->nullable();
            $t->decimal('amount', 12, 2)->default(0);
            $t->date('date')->nullable();
            $t->string('status')->default('pending');
            $t->unsignedBigInteger('approver_id')->nullable();
            $t->timestamps();
        });

        Schema::create('deductions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->unsignedBigInteger('employee_id')->index();
            $t->enum('type', ['late', 'pcc', 'advance_emi', 'escalation', 'lop', 'other'])->default('other');
            $t->decimal('amount', 12, 2)->default(0);
            $t->string('month', 7)->nullable();
            $t->string('source_ref')->nullable();
            $t->enum('status', ['pending', 'applied', 'waived'])->default('pending');
            $t->timestamps();
        });

        Schema::create('bonus_encashment', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->unsignedBigInteger('employee_id')->index();
            $t->string('type')->nullable();
            $t->string('basis')->nullable();
            $t->decimal('amount', 12, 2)->default(0);
            $t->string('month', 7)->nullable();
            $t->string('status')->default('pending');
            $t->timestamps();
        });

        Schema::create('payout_recon', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->unsignedBigInteger('company_id')->index();
            $t->string('bank')->nullable();
            $t->string('portfolio')->nullable();
            $t->string('month', 7)->nullable();
            $t->decimal('expected', 14, 2)->default(0);
            $t->decimal('received', 14, 2)->default(0);
            $t->decimal('variance', 14, 2)->default(0);
            $t->string('status')->default('open');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['payout_recon', 'bonus_encashment', 'deductions', 'expenses', 'loans', 'advances', 'clawbacks', 'incentive_schemes', 'commissions', 'payslips', 'payroll_runs', 'salary_components', 'salary_schedules'] as $tbl) {
            Schema::dropIfExists($tbl);
        }
    }
};
