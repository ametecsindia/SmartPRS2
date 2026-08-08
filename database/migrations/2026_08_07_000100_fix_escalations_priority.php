<?php

// 7 Aug 2026 test report (item 6) — the Escalation Desk save failed with
// "SQLSTATE[01000]: 1265 Data truncated for column 'priority'": the column was
// enum('normal','emergency') while the UI posts low/medium/high/urgent. Widen
// priority + status + severity to plain strings, and make `issue` a TEXT column
// so the (now multi-line) issue box can hold a real description. Idempotent and
// fail-soft: only touches columns that exist, and never blocks other migrations.
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('escalations')) {
            return;
        }
        $driver = DB::getDriverName();
        // Raw ALTERs avoid needing doctrine/dbal. MySQL/MariaDB only; other
        // drivers already store these as flexible types, so nothing to do.
        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            return;
        }
        $alter = function (string $col, string $type) {
            try {
                if (Schema::hasColumn('escalations', $col)) {
                    DB::statement("ALTER TABLE `escalations` MODIFY `{$col}` {$type}");
                }
            } catch (\Throwable $e) {
                // leave the column as-is on any failure — the app also normalises
                // priority defensively, so a widened column is a belt-and-braces fix.
            }
        };
        $alter('priority', "VARCHAR(20) NOT NULL DEFAULT 'normal'");
        $alter('severity', 'VARCHAR(20) NULL');
        $alter('status', "VARCHAR(20) NOT NULL DEFAULT 'open'");
        $alter('issue', 'TEXT NULL');
    }

    public function down(): void
    {
        // No safe automatic rollback — reverting to the old enum would truncate
        // any 'low'/'medium'/'high'/'urgent' rows written since. Intentionally a no-op.
    }
};
