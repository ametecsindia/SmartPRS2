<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SBB — quarantine for punches whose device PIN maps to no employee yet.
 *
 * Until now ETimeOfficeService::import() DISCARDED these permanently: the punch
 * was dropped and only a counter survived in biometric_unmapped. That loses real
 * attendance during every go-live, because mapping the device IDs to employees
 * is exactly the thing that has not happened yet on day one.
 *
 * A punch that lands here is not lost — it is held, returned to the sender as
 * "pending", listed for the admin, and promoted into attendance_logs the moment
 * the device ID is mapped (see PunchIngestService::replayPending, called from
 * BiometricConfigController::mapEmployee).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('attendance_pending')) {
            return;
        }

        Schema::create('attendance_pending', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->nullable()->index();
            $t->unsignedBigInteger('company_id')->nullable();
            $t->string('device_sn', 64)->nullable();
            $t->string('device_user_id', 64)->nullable()->index();
            $t->dateTime('punch_at');
            $t->string('direction', 8)->nullable();
            $t->string('verify_mode', 24)->nullable();
            $t->string('external_id', 96)->nullable();
            $t->string('source', 40)->default('sbb');
            $t->timestamp('resolved_at')->nullable()->index();
            $t->timestamps();

            $t->unique(['tenant_id', 'external_id'], 'attpending_tenant_external_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_pending');
    }
};
