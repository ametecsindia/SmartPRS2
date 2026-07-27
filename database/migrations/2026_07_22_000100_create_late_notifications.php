<?php

// F4 — de-dup ledger for immediate late-arrival emails (one email per
// employee-day). Guarded + fail-soft; LateArrivalService also self-creates it.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        try {
            if (Schema::hasTable('late_notifications')) {
                return;
            }
            Schema::create('late_notifications', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('tenant_id')->nullable()->index();
                $t->unsignedBigInteger('employee_id')->nullable()->index();
                $t->string('emp_code')->nullable();
                $t->date('log_date')->nullable();
                $t->integer('late_min')->default(0);
                $t->string('channel', 20)->default('email');
                $t->timestamp('sent_at')->nullable();
                $t->timestamps();
                $t->unique(['tenant_id', 'employee_id', 'log_date'], 'late_notif_unique');
            });
        } catch (\Throwable $e) {
            // non-fatal — the service self-creates the table on first use.
        }
    }

    public function down(): void
    {
        // keep the ledger.
    }
};
