<?php

// TDD §4.3 — Attendance & leave.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->unsignedBigInteger('company_id')->index();
            $t->unsignedBigInteger('branch_id')->nullable();
            $t->string('name');
            $t->string('unique_id')->unique();
            $t->enum('direction', ['in', 'out'])->default('in');
            $t->string('api_url')->nullable();
            $t->text('api_key')->nullable();   // encrypted at app layer
            $t->string('location')->nullable();
            $t->timestamp('last_sync_at')->nullable();
            $t->enum('status', ['active', 'error', 'offline'])->default('offline');
            $t->timestamps();
        });

        Schema::create('geofence_rules', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->unsignedBigInteger('employee_id')->index();
            $t->enum('start', ['home', 'office'])->default('office');
            $t->decimal('lat', 10, 7)->nullable();
            $t->decimal('lng', 10, 7)->nullable();
            $t->decimal('radius_km', 4, 2)->nullable();
            $t->enum('outside', ['strict', '1km', '2km'])->default('strict');
            $t->timestamps();
        });

        Schema::create('attendance', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->unsignedBigInteger('company_id')->index();
            $t->unsignedBigInteger('employee_id')->index();
            $t->date('date');
            $t->dateTime('punch_in')->nullable();
            $t->dateTime('punch_out')->nullable();
            $t->enum('source', ['device', 'geo_app', 'manual'])->default('manual');
            $t->decimal('hours', 4, 2)->default(0);
            $t->integer('lates_mtd')->default(0);
            $t->enum('status', ['full', 'half', 'absent', 'compoff'])->default('full');
            $t->unsignedBigInteger('device_id')->nullable();
            $t->boolean('geo_ok')->default(true);
            $t->boolean('biometric_ok')->default(true);
            $t->timestamps();

            $t->unique(['employee_id', 'date']);
        });

        Schema::create('late_policy', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->unsignedBigInteger('company_id')->index();
            $t->integer('grace_min')->default(15);
            $t->decimal('full_day_hours', 4, 2)->default(9);
            $t->integer('lates_before_cut')->default(3);
            // Free-text notes (legacy: enum half/full — repurposed by the Late
            // Policy wizard; see 2026_06_03_120000_fix_late_policy_addl_late).
            $t->string('addl_late')->nullable();
            $t->string('weekoff_action')->nullable();
            $t->boolean('no_cut_on_weekoff')->default(true);
            $t->timestamps();
        });

        Schema::create('leave_types', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->string('name');
            $t->decimal('days_per_year', 5, 1)->default(0);
            $t->boolean('carry_forward')->default(false);
            $t->boolean('paid')->default(true);
            $t->timestamps();
        });

        Schema::create('leaves', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->unsignedBigInteger('company_id')->index();
            $t->unsignedBigInteger('employee_id')->index();
            $t->unsignedBigInteger('type_id')->nullable();
            $t->date('from_date');
            $t->date('to_date');
            $t->decimal('days', 4, 1)->default(1);
            $t->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $t->unsignedBigInteger('approver_id')->nullable();
            $t->string('reason')->nullable();
            $t->timestamps();
        });

        Schema::create('holidays', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->date('date');
            $t->string('name');
            $t->string('applies_to')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['holidays', 'leaves', 'leave_types', 'late_policy', 'attendance', 'geofence_rules', 'devices'] as $tbl) {
            Schema::dropIfExists($tbl);
        }
    }
};
