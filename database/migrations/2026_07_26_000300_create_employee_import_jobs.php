<?php

use App\Support\SchemaHelper;
use Illuminate\Database\Migrations\Migration;

/**
 * F9 — employee import wizard job history.
 *
 * Idempotent (SchemaHelper) and mirrors EmployeeImportService::ensureSchema().
 */
return new class extends Migration
{
    public function up(): void
    {
        SchemaHelper::ensureTable('employee_import_jobs', function ($t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->nullable()->index();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('token', 64)->unique();
            $t->string('filename')->nullable();
            $t->string('status', 20)->default('staged');    // staged|imported|failed
            $t->string('dup_mode', 20)->default('update');  // skip|update|create
            $t->unsignedInteger('total_rows')->default(0);
            $t->unsignedInteger('created_count')->default(0);
            $t->unsignedInteger('updated_count')->default(0);
            $t->unsignedInteger('skipped_count')->default(0);
            $t->unsignedInteger('error_count')->default(0);
            $t->text('mapping')->nullable();
            $t->text('errors')->nullable();
            $t->string('imported_by')->nullable();
            $t->timestamp('imported_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        SchemaHelper::dropTableIfExists('employee_import_jobs');
    }
};
