<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** rev183d — 3-day grace period before an employee backup runs. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('employees')) {
            Schema::table('employees', function (Blueprint $t) {
                if (! Schema::hasColumn('employees', 'backup_due_at')) {
                    $t->timestamp('backup_due_at')->nullable()->index();
                }
                if (! Schema::hasColumn('employees', 'backup_by')) {
                    $t->string('backup_by')->nullable();
                }
            });
        }
    }

    public function down(): void
    {
    }
};
