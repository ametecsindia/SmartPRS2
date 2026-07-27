<?php

use App\Support\SchemaHelper;
use Illuminate\Database\Migrations\Migration;

/**
 * P0 Foundation — the notification_log table that backs the in-app inbox and
 * records email delivery for every enhancement feature (F6/F8/F10 and beyond).
 *
 * Written through SchemaHelper so it is IDEMPOTENT: running it on a database
 * where NotificationService::ensureTable() already self-healed the table is a
 * no-op, and re-running never errors (see [[smartprs-runtime-tables-gotcha]]).
 * down() is a clean, reversible drop.
 */
return new class extends Migration
{
    public function up(): void
    {
        SchemaHelper::ensureTable('notification_log', function ($t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->nullable()->index();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->unsignedBigInteger('user_id')->nullable()->index();       // in-app recipient
            $t->unsignedBigInteger('employee_id')->nullable()->index();   // subject of the event
            $t->string('kind')->nullable()->index();
            $t->string('title')->nullable();
            $t->text('body')->nullable();
            $t->string('url')->nullable();
            $t->string('channels', 40)->nullable();       // 'in_app,email'
            $t->string('email_status', 20)->nullable();   // queued|sent|failed|skipped
            $t->unsignedBigInteger('mail_log_id')->nullable();
            $t->string('dedupe_key')->nullable()->index();
            $t->text('meta')->nullable();
            $t->timestamp('read_at')->nullable();
            $t->timestamps();
        });

        // Self-heal safety: if the table pre-existed from ensureTable() but is
        // missing any column added later, patch it here too.
        SchemaHelper::ensureColumns('notification_log', [
            'company_id' => fn ($t) => $t->unsignedBigInteger('company_id')->nullable()->index(),
            'employee_id' => fn ($t) => $t->unsignedBigInteger('employee_id')->nullable()->index(),
            'dedupe_key' => fn ($t) => $t->string('dedupe_key')->nullable()->index(),
            'mail_log_id' => fn ($t) => $t->unsignedBigInteger('mail_log_id')->nullable(),
            'read_at' => fn ($t) => $t->timestamp('read_at')->nullable(),
        ]);
    }

    public function down(): void
    {
        SchemaHelper::dropTableIfExists('notification_log');
    }
};
