<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * rev183b — "Backed up / Old data": archive a single employee out of the active
 * directory while keeping ALL their data intact. Adds employees.archived_at /
 * archived_by and an employee_archives table holding a full JSON snapshot that
 * the Old-data tab downloads. Idempotent + best-effort.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('employees')) {
            Schema::table('employees', function (Blueprint $t) {
                if (! Schema::hasColumn('employees', 'archived_at')) {
                    $t->timestamp('archived_at')->nullable()->index();
                }
                if (! Schema::hasColumn('employees', 'archived_by')) {
                    $t->string('archived_by')->nullable();
                }
            });
        }

        if (! Schema::hasTable('employee_archives')) {
            Schema::create('employee_archives', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->unsignedBigInteger('tenant_id')->nullable()->index();
                $t->unsignedBigInteger('employee_id')->nullable();
                $t->string('emp_code')->index();
                $t->string('name')->nullable();
                $t->longText('snapshot')->nullable();
                $t->string('archived_by')->nullable();
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        // Keep archived data — dropping it would lose the Old-data backups.
    }
};
