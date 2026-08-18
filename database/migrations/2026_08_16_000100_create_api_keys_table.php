<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SBB (Smart Biometric Bridge) — API keys for the authenticated JSON ingest path.
 *
 * Deliberately mirrors SmartEPT's api_keys design so both products behave the
 * same way for an installer, with ONE addition SmartEPT lacks: expires_at.
 *
 * The full secret is shown ONCE at creation and never stored in plaintext —
 * only `prefix` (the human-visible handle, e.g. "sk_prs_ab12") and `key_hash`
 * (sha256 of the whole secret) are persisted. A lost key is replaced, not
 * recovered.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('api_keys')) {
            return;
        }

        Schema::create('api_keys', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->nullable()->index();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->string('name');                       // "SBB - Chennai Plant"
            $t->string('prefix', 12)->index();        // shown in the UI, e.g. "sk_prs_ab12"
            $t->string('key_hash');                   // sha256 of the full secret
            $t->json('scopes')->nullable();           // ['ingest','read']
            $t->timestamp('last_used_at')->nullable();
            $t->timestamp('expires_at')->nullable();  // SmartEPT lacks this; we want it
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_keys');
    }
};
