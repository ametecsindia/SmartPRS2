<?php

// TDD §4.1 — Platform tables (no tenant scope). SaaS billing backbone.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->decimal('base_price', 10, 2)->default(0);
            $t->decimal('per_user_price', 10, 2)->default(0);
            $t->enum('billing_cycle', ['monthly', 'quarterly', 'annual'])->default('monthly');
            $t->unsignedInteger('seat_max')->nullable();
            $t->json('features')->nullable();
            $t->string('status')->default('active');
            $t->timestamps();
        });

        Schema::create('tenants', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->string('name');
            $t->unsignedBigInteger('plan_id')->nullable()->index();
            $t->enum('status', ['trial', 'active', 'suspended', 'churned'])->default('trial');
            $t->unsignedInteger('seats_used')->default(0);
            $t->unsignedInteger('seats_licensed')->default(0);
            $t->decimal('mrr', 12, 2)->default(0);
            $t->enum('deployment', ['saas', 'onprem'])->default('saas');
            $t->string('owner_email')->nullable();
            $t->string('subdomain')->unique()->nullable();
            $t->timestamp('trial_ends_at')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('subscriptions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->unsignedBigInteger('plan_id')->index();
            $t->unsignedInteger('seats')->default(0);
            $t->string('cycle')->nullable();
            $t->decimal('amount', 12, 2)->default(0);
            $t->enum('status', ['trialing', 'active', 'suspended', 'cancelled'])->default('trialing');
            $t->date('current_period_end')->nullable();
            $t->date('next_renewal')->nullable();
            $t->timestamps();
        });

        Schema::create('invoices', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->string('number')->unique();
            $t->decimal('amount', 12, 2)->default(0);
            $t->decimal('tax', 12, 2)->default(0);
            $t->enum('status', ['draft', 'due', 'paid', 'overdue', 'void'])->default('draft');
            $t->date('issued_on')->nullable();
            $t->date('due_on')->nullable();
            $t->string('gateway')->nullable();
            $t->timestamps();
        });

        Schema::create('payments', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->unsignedBigInteger('invoice_id')->nullable()->index();
            $t->decimal('amount', 12, 2)->default(0);
            $t->string('gateway')->nullable();
            $t->string('method')->nullable();
            $t->string('gateway_txn_id')->nullable();
            $t->enum('status', ['success', 'failed', 'refunded', 'pending'])->default('pending');
            $t->timestamp('paid_at')->nullable();
            $t->timestamps();
        });

        Schema::create('payment_gateways', function (Blueprint $t) {
            $t->id();
            $t->string('gateway');
            $t->enum('mode', ['test', 'live'])->default('test');
            $t->string('key_id')->nullable();
            $t->text('secret')->nullable();   // encrypted at app layer
            $t->string('webhook_url')->nullable();
            $t->string('status')->default('active');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['payment_gateways', 'payments', 'invoices', 'subscriptions', 'tenants', 'plans'] as $tbl) {
            Schema::dropIfExists($tbl);
        }
    }
};
