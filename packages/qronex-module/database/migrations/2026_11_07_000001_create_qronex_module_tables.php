<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * QRONEX attendance module. Every table is qx_* and keyed by the host's tenant id (PRS2 tenant / EPT company),
 * with no foreign keys into host tables, so the host schema is never touched and the module can be disabled or removed safely.
 * Same columns and rules as the QRONEX app.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qx_settings', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->unique();
            $t->boolean('enabled')->default(false);
            $t->unsignedTinyInteger('qr_interval_s')->default(3);
            $t->unsignedTinyInteger('grace_s')->default(5);
            $t->boolean('require_device_approval')->default(true);
            $t->unsignedSmallInteger('duplicate_window_min')->default(2);
            $t->unsignedSmallInteger('gps_accuracy_m')->default(50);
            $t->boolean('notify_device_requests')->default(true);
            $t->boolean('notify_remote_requests')->default(true);
            $t->text('location_notice')->nullable();
            $t->timestamp('employees_synced_at')->nullable();
            $t->timestamps();
        });

        Schema::create('qx_offices', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->string('code', 20);
            $t->string('slug', 60);
            $t->string('name');
            $t->string('address')->nullable();
            $t->string('city')->nullable();
            $t->decimal('lat', 10, 7);
            $t->decimal('lng', 10, 7);
            $t->unsignedInteger('geofence_radius_m')->default(150);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['tenant_id', 'code']);
            $t->unique(['tenant_id', 'slug']);
        });

        Schema::create('qx_gates', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->foreignId('office_id')->constrained('qx_offices')->cascadeOnDelete();
            $t->string('code', 30);
            $t->string('name');
            $t->string('display_secret', 64);
            $t->timestamp('display_last_seen_at')->nullable();
            $t->string('display_agent')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['office_id', 'code']);
        });

        // Mirror of the host's employees (synced), plus what only attendance needs: office/gate binding, scan scope, phone exception, activation.
        Schema::create('qx_employees', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id');
            $t->unsignedBigInteger('host_employee_id');
            $t->string('employee_code', 40);
            $t->string('name');
            $t->string('email')->nullable();
            $t->string('mobile', 20)->nullable();
            $t->string('department')->nullable();
            $t->string('designation')->nullable();
            $t->string('status', 10)->default('active');
            $t->foreignId('office_id')->nullable()->constrained('qx_offices')->nullOnDelete();
            $t->foreignId('gate_id')->nullable()->constrained('qx_gates')->nullOnDelete();
            $t->string('scan_scope', 10)->default('office');
            $t->unsignedTinyInteger('max_devices')->nullable();
            $t->string('activation_code_hash')->nullable();
            $t->timestamp('activation_expires_at')->nullable();
            $t->timestamps();
            $t->unique(['tenant_id', 'host_employee_id']);
            $t->index(['tenant_id', 'employee_code']);
        });

        Schema::create('qx_devices', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id');
            $t->foreignId('employee_id')->constrained('qx_employees')->cascadeOnDelete();
            $t->string('platform', 10);
            $t->string('model')->nullable();
            $t->string('os_version')->nullable();
            $t->string('install_ref', 80)->unique();
            $t->text('public_key')->nullable();
            $t->string('token_hash', 64)->nullable()->unique();
            $t->string('approval_code', 9)->nullable();
            $t->unsignedBigInteger('replaces_device_id')->nullable();
            $t->string('status', 10)->default('pending');
            $t->unsignedBigInteger('approved_by')->nullable();
            $t->timestamp('approved_at')->nullable();
            $t->timestamp('last_seen_at')->nullable();
            $t->timestamps();
            $t->index(['tenant_id', 'status']);
        });

        Schema::create('qx_device_events', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id');
            $t->foreignId('device_id')->constrained('qx_devices')->cascadeOnDelete();
            $t->string('action', 32);
            $t->unsignedBigInteger('actor_user_id')->nullable();
            $t->string('reason')->nullable();
            $t->json('meta')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['device_id', 'created_at']);
        });

        Schema::create('qx_remote_permissions', function (Blueprint $t) {
            $t->id();
            $t->string('ref', 20)->unique();
            $t->unsignedBigInteger('tenant_id');
            $t->foreignId('employee_id')->constrained('qx_employees')->cascadeOnDelete();
            $t->string('type', 20);
            $t->date('from_date');
            $t->date('to_date');
            $t->string('location_label')->nullable();
            $t->decimal('lat', 10, 7)->nullable();
            $t->decimal('lng', 10, 7)->nullable();
            $t->unsignedInteger('radius_m')->nullable();
            $t->boolean('location_waived')->default(false);
            $t->string('reason', 255);
            $t->string('status', 10)->default('pending');
            $t->string('requested_via', 10)->default('portal');
            $t->unsignedBigInteger('decided_by')->nullable();
            $t->timestamp('decided_at')->nullable();
            $t->timestamps();
            $t->index(['tenant_id', 'status']);
            $t->index(['employee_id', 'from_date', 'to_date']);
        });

        Schema::create('qx_punch_corrections', function (Blueprint $t) {
            $t->id();
            $t->string('ref', 20)->unique();
            $t->unsignedBigInteger('tenant_id');
            $t->foreignId('employee_id')->constrained('qx_employees')->cascadeOnDelete();
            $t->string('type', 3);
            $t->dateTime('punch_at'); // UTC
            $t->string('reason', 255);
            $t->string('status', 10)->default('pending');
            $t->string('requested_via', 10)->default('app');
            $t->unsignedBigInteger('decided_by')->nullable();
            $t->timestamp('decided_at')->nullable();
            $t->timestamps();
            $t->index(['tenant_id', 'status']);
        });

        Schema::create('qx_attendance_events', function (Blueprint $t) {
            $t->id();
            $t->string('ref', 20)->unique();
            $t->unsignedBigInteger('tenant_id');
            $t->foreignId('employee_id')->constrained('qx_employees')->cascadeOnDelete();
            $t->foreignId('device_id')->nullable()->constrained('qx_devices')->nullOnDelete();
            $t->foreignId('office_id')->nullable()->constrained('qx_offices')->nullOnDelete();
            $t->foreignId('gate_id')->nullable()->constrained('qx_gates')->nullOnDelete();
            $t->foreignId('remote_permission_id')->nullable()->constrained('qx_remote_permissions')->nullOnDelete();
            $t->foreignId('punch_correction_id')->nullable()->constrained('qx_punch_corrections')->nullOnDelete();
            $t->string('type', 3);
            $t->string('mode', 24)->default('OFFICE_QR');
            $t->boolean('adjusted')->default(false);
            $t->unsignedBigInteger('qr_window')->nullable();
            $t->decimal('lat', 10, 7)->nullable();
            $t->decimal('lng', 10, 7)->nullable();
            $t->unsignedSmallInteger('accuracy_m')->nullable();
            $t->unsignedInteger('distance_m')->nullable();
            $t->timestamp('recorded_at');
            $t->timestamp('host_synced_at')->nullable(); // written into the host's attendance (PRS2 attendance_logs / EPT biometric_logs)
            $t->string('host_sync_error')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['tenant_id', 'recorded_at']);
            $t->index(['employee_id', 'recorded_at']);
            $t->unique(['employee_id', 'gate_id', 'qr_window']); // replay guard
        });

        Schema::create('qx_attendance_rejections', function (Blueprint $t) {
            $t->id();
            $t->string('ref', 20)->unique();
            $t->unsignedBigInteger('tenant_id')->nullable();
            $t->unsignedBigInteger('employee_id')->nullable();
            $t->unsignedBigInteger('device_id')->nullable();
            $t->unsignedBigInteger('gate_id')->nullable();
            $t->string('reason_code', 40);
            $t->string('reason');
            $t->json('checks')->nullable();
            $t->decimal('lat', 10, 7)->nullable();
            $t->decimal('lng', 10, 7)->nullable();
            $t->unsignedSmallInteger('accuracy_m')->nullable();
            $t->string('ip', 45)->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['tenant_id', 'created_at']);
        });

        Schema::create('qx_audit_logs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->nullable()->index();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('action', 60);
            $t->string('subject')->nullable();
            $t->json('meta')->nullable();
            $t->string('ip', 45)->nullable();
            $t->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        foreach (['qx_audit_logs', 'qx_attendance_rejections', 'qx_attendance_events', 'qx_punch_corrections', 'qx_remote_permissions',
            'qx_device_events', 'qx_devices', 'qx_employees', 'qx_gates', 'qx_offices', 'qx_settings'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
