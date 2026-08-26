<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SmartEPT webhook receiver — one row per configured sender.
 *
 * SmartEPT pushes with OutboundPusher::pushTo, which sends ONLY an
 * HMAC-SHA256 signature (X-SmartEPT-Signature) — no API key header. So the
 * credential cannot be an api_keys row: that table stores a sha256 and we need
 * the shared secret in the clear to recompute the HMAC.
 *
 * Hence this table. `slug` travels in the receiver URL and says WHICH row to
 * check; `secret` is the shared secret, encrypted at rest with APP_KEY. The
 * tenant is read off this row and nothing else — the pushed body carries
 * SmartEPT's own `company_id`, which is a foreign, unauthenticated integer and
 * must never be allowed to choose a tenant in SmartPRS.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('smartept_webhook_endpoints')) {
            return;
        }

        Schema::create('smartept_webhook_endpoints', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->nullable()->index();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->string('name');                          // "SmartEPT — Head Office"
            $t->string('slug', 32)->unique();            // appears in the receiver URL
            $t->text('secret');                          // encrypted; needed in clear to verify HMAC
            $t->json('events')->nullable();              // ['attendance.punch','attendance.daily']
            $t->boolean('active')->default(true);

            // Delivery health, so the screen can answer "is it actually arriving?"
            $t->timestamp('last_received_at')->nullable();
            $t->string('last_event', 64)->nullable();
            $t->string('last_status', 191)->nullable();
            $t->unsignedInteger('received_count')->default(0);
            $t->unsignedInteger('accepted_count')->default(0);
            $t->unsignedInteger('rejected_count')->default(0);

            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('smartept_webhook_endpoints');
    }
};
