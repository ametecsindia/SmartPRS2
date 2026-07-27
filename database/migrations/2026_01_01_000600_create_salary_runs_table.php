<?php

// TDD §4.7 — Training / Letters / Comms / Points / Workflow.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('training_programs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->string('name');
            $t->string('category')->nullable();
            $t->string('mode')->nullable();
            $t->boolean('mandatory')->default(false);
            $t->string('validity')->nullable();
            $t->string('status')->default('active');
            $t->timestamps();
        });

        Schema::create('training_records', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->unsignedBigInteger('employee_id')->index();
            $t->unsignedBigInteger('program_id')->index();
            $t->string('status')->default('assigned');
            $t->date('completed_on')->nullable();
            $t->decimal('score', 5, 2)->nullable();
            $t->date('expiry')->nullable();
            $t->timestamps();
        });

        Schema::create('training_subjects', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('program_id')->index();
            $t->string('module')->nullable();
            $t->string('subject')->nullable();
            $t->decimal('hours', 5, 1)->nullable();
            $t->text('content')->nullable();
            $t->timestamps();
        });

        Schema::create('tests', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->string('name');
            $t->string('category')->nullable();
            $t->string('target_type')->nullable();
            $t->string('target')->nullable();
            $t->unsignedInteger('questions')->default(0);
            $t->decimal('pass_mark', 5, 2)->default(0);
            $t->date('scheduled_on')->nullable();
            $t->unsignedBigInteger('linked_program_id')->nullable();
            $t->timestamps();
        });

        Schema::create('test_attempts', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->unsignedBigInteger('employee_id')->index();
            $t->unsignedBigInteger('test_id')->index();
            $t->string('status')->default('pending');
            $t->decimal('score', 5, 2)->nullable();
            $t->date('attempted_on')->nullable();
            $t->timestamps();
        });

        Schema::create('code_of_conduct_ack', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->unsignedBigInteger('employee_id')->index();
            $t->boolean('acknowledged')->default(false);
            $t->date('ack_date')->nullable();
            $t->timestamps();
        });

        Schema::create('faqs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->string('category')->nullable();
            $t->string('question');
            $t->text('answer')->nullable();
            $t->timestamps();
        });

        Schema::create('letters', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->unsignedBigInteger('company_id')->index();
            $t->unsignedBigInteger('employee_id')->index();
            $t->string('letter_type')->nullable();
            $t->date('issued_on')->nullable();
            $t->string('status')->default('draft');
            $t->string('pdf_path')->nullable();
            $t->timestamps();
        });

        Schema::create('documents', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->unsignedBigInteger('employee_id')->index();
            $t->string('kind')->nullable();
            $t->string('status')->default('pending');
            $t->date('expiry')->nullable();
            $t->string('file_path')->nullable();
            $t->timestamps();
        });

        Schema::create('wa_settings', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->unsignedBigInteger('company_id')->index();
            $t->string('provider')->nullable();
            $t->string('api_url')->nullable();
            $t->text('api_key')->nullable();
            $t->string('sender_number')->nullable();
            $t->string('waba_id')->nullable();
            $t->string('status')->default('inactive');
            $t->timestamps();
        });

        Schema::create('sms_settings', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->unsignedBigInteger('company_id')->index();
            $t->string('provider')->nullable();
            $t->string('api_url')->nullable();
            $t->text('api_key')->nullable();
            $t->string('sender_id')->nullable();
            $t->string('dlt_entity_id')->nullable();
            $t->string('dlt_principal_id')->nullable();
            $t->string('status')->default('inactive');
            $t->timestamps();
        });

        Schema::create('sms_templates', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->unsignedBigInteger('company_id')->index();
            $t->string('name');
            $t->string('type')->nullable();
            $t->string('dlt_template_id')->nullable();
            $t->text('content')->nullable();
            $t->string('status')->default('active');
            $t->timestamps();
        });

        Schema::create('company_emails', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->unsignedBigInteger('company_id')->index();
            $t->string('department')->nullable();
            $t->string('from_name')->nullable();
            $t->string('from_email')->nullable();
            $t->text('signature')->nullable();
            $t->string('status')->default('active');
            $t->timestamps();
        });

        Schema::create('messages_log', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->unsignedBigInteger('company_id')->index();
            $t->string('target')->nullable();
            $t->unsignedInteger('recipients')->default(0);
            $t->string('channels')->nullable();
            $t->text('message')->nullable();
            $t->unsignedBigInteger('sent_by')->nullable();
            $t->timestamp('sent_at')->nullable();
            $t->timestamps();
        });

        Schema::create('notifications_feed', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->unsignedBigInteger('user_id')->index();
            $t->string('type')->nullable();
            $t->string('message')->nullable();
            $t->string('link')->nullable();
            $t->timestamp('read_at')->nullable();
            $t->timestamps();
        });

        Schema::create('point_rules', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->string('event');
            $t->enum('category', ['positive', 'negative'])->default('positive');
            $t->integer('points')->default(0);
            $t->string('note')->nullable();
            $t->timestamps();
        });

        Schema::create('points_ledger', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->unsignedBigInteger('company_id')->index();
            $t->unsignedBigInteger('employee_id')->index();
            $t->string('event')->nullable();
            $t->enum('category', ['positive', 'negative'])->default('positive');
            $t->integer('points')->default(0);
            $t->date('date')->nullable();
            $t->string('note')->nullable();
            $t->string('source_ref')->nullable();
            $t->timestamps();
        });

        Schema::create('helpdesk', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->unsignedBigInteger('company_id')->index();
            $t->unsignedBigInteger('employee_id')->index();
            $t->string('category')->nullable();
            $t->string('subject')->nullable();
            $t->string('priority')->nullable();
            $t->string('status')->default('open');
            $t->unsignedBigInteger('assigned_to')->nullable();
            $t->timestamps();
        });

        Schema::create('increments', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->unsignedBigInteger('company_id')->index();
            $t->unsignedBigInteger('employee_id')->index();
            $t->string('cycle')->nullable();
            $t->decimal('old_ctc', 12, 2)->default(0);
            $t->decimal('new_ctc', 12, 2)->default(0);
            $t->decimal('pct', 5, 2)->default(0);
            $t->date('effective')->nullable();
            $t->string('status')->default('pending');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach ([
            'increments', 'helpdesk', 'points_ledger', 'point_rules', 'notifications_feed', 'messages_log',
            'company_emails', 'sms_templates', 'sms_settings', 'wa_settings', 'documents', 'letters',
            'faqs', 'code_of_conduct_ack', 'test_attempts', 'tests', 'training_subjects', 'training_records', 'training_programs',
        ] as $tbl) {
            Schema::dropIfExists($tbl);
        }
    }
};
