<?php

// TDD §4.8 — System (users handled in 000002; spatie tables via their own published migrations).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->nullable()->index();
            $t->unsignedBigInteger('user_id')->nullable()->index();
            $t->string('action')->nullable();
            $t->string('entity')->nullable();
            $t->unsignedBigInteger('entity_id')->nullable();
            $t->text('detail')->nullable();
            $t->string('ip', 45)->nullable();
            $t->timestamp('created_at')->nullable();
        });

        Schema::create('settings', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->nullable()->index();
            $t->string('key');
            $t->json('value')->nullable();
            $t->timestamps();

            $t->unique(['tenant_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
        Schema::dropIfExists('activity_logs');
    }
};
