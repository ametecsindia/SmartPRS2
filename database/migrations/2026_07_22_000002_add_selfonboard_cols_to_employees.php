<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $cols = ['esic_no', 'employment_type', 'marital_status', 'father', 'nationality', 'aadhaar', 'permanent_address', 'emergency_name', 'emergency_phone', 'blood_group', 'category'];

    public function up(): void
    {
        if (! Schema::hasTable('employees')) {
            return;
        }
        foreach ($this->cols as $c) {
            if (! Schema::hasColumn('employees', $c)) {
                Schema::table('employees', function (Blueprint $t) use ($c) {
                    $t->string($c)->nullable();
                });
            }
        }
    }

    public function down(): void
    {
        // Non-destructive: leave columns in place (other modules may use them).
    }
};
