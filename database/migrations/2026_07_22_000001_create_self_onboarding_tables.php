<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Candidate & existing-employee SELF-ONBOARDING.
 * A token-secured record anchored by a Temp-EMP ID until it is verified,
 * approved and injected into the employees master.
 *
 * Guarded (idempotent): these tables can be created at runtime before the
 * migration runs, so each create is skipped when the table already exists —
 * otherwise `migrate` fails with "table already exists" (SQLSTATE 42S01/1050).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('self_onboarding')) {
            Schema::create('self_onboarding', function (Blueprint $t) {
                $t->id();
                $t->uuid('uuid')->unique();
                $t->string('token', 64)->unique();
                $t->string('temp_emp_code')->index();
                $t->unsignedBigInteger('tenant_id')->nullable()->index();
                $t->unsignedBigInteger('company_id')->nullable()->index();
                $t->unsignedBigInteger('candidate_id')->nullable()->index();
                $t->unsignedBigInteger('employee_id')->nullable()->index();
                $t->string('mode')->default('new');            // new | existing | bulk
                $t->string('name')->nullable();
                $t->string('email')->nullable();
                $t->boolean('email_verified')->default(false);
                $t->string('mobile', 20)->nullable();
                $t->boolean('mobile_verified')->default(false);
                $t->string('whatsapp', 20)->nullable();
                $t->boolean('wa_verified')->default(false);
                $t->json('data')->nullable();                  // progressive section payload
                $t->json('flags')->nullable();                 // HR field-level corrections
                $t->string('selfie_path')->nullable();
                $t->unsignedTinyInteger('progress')->default(0);
                $t->string('status')->default('link_sent');    // link_sent|opened|verifying|in_progress|submitted|correction|verified|approved|injected|hold|withdrawn
                $t->string('pin_hash')->nullable();
                $t->timestamp('link_expires_at')->nullable();
                $t->timestamp('link_disabled_at')->nullable(); // archived-not-deleted on approval
                $t->timestamp('submitted_at')->nullable();
                $t->timestamp('approved_at')->nullable();
                $t->timestamps();
                $t->softDeletes();
            });
        }

        if (! Schema::hasTable('self_onboarding_otps')) {
            Schema::create('self_onboarding_otps', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('self_onboarding_id')->index();
                $t->string('channel', 10);                     // email | mobile | whatsapp
                $t->string('code_hash');
                $t->unsignedTinyInteger('attempts')->default(0);
                $t->timestamp('expires_at')->nullable();
                $t->timestamp('verified_at')->nullable();
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('self_onboarding_docs')) {
            Schema::create('self_onboarding_docs', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('self_onboarding_id')->index();
                $t->string('kind');                            // id|address|education|experience|bank|other
                $t->string('path');
                $t->string('status')->default('pending');      // pending|accepted|rejected
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        foreach (['self_onboarding_docs', 'self_onboarding_otps', 'self_onboarding'] as $tbl) {
            Schema::dropIfExists($tbl);
        }
    }
};
