<?php

// TDD §4.8 — users: tenant_id NULL = super admin (platform).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->unsignedBigInteger('tenant_id')->nullable()->after('id')->index();
            $t->unsignedBigInteger('employee_id')->nullable()->after('tenant_id')->index();
            $t->string('status')->default('active')->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->dropColumn(['tenant_id', 'employee_id', 'status']);
        });
    }
};
