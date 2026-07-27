<?php

// Superseded: `devices` is now created in the Attendance migration (§4.3).
// Kept as a no-op so migrate:fresh stays clean without deleting the file.

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void {}

    public function down(): void {}
};
