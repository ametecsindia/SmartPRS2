<?php

namespace App\Support;

use Closure;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * P0 Foundation — idempotent schema helper.
 *
 * SmartPRS deliberately favours SELF-HEALING schema: tables and columns are
 * created/patched on first use with hasTable/hasColumn guards, so a deploy that
 * runs the same code twice (or on a database that pre-dates a feature) never
 * fails. See [[smartprs-runtime-tables-gotcha]] for why: several tables were
 * historically created at runtime, and a hard migration that assumed they were
 * absent broke import/payroll/approvals on a live client.
 *
 * This helper standardises that pattern in ONE place so every P1–P5 feature
 * writes its ensure*() the same way. It is additive and best-effort: a failure
 * to patch is logged, never thrown, matching Audit/MailService/ApprovalService.
 *
 * These helpers are safe to call from BOTH a real migration's up() and from a
 * controller's runtime ensure() — they are the same idempotent primitive.
 */
class SchemaHelper
{
    /**
     * Create $table via $definition only if it does not already exist.
     * Returns true when the table exists (created now or earlier), false on error.
     */
    public static function ensureTable(string $table, Closure $definition): bool
    {
        try {
            if (Schema::hasTable($table)) {
                return true;
            }
            Schema::create($table, $definition);

            return true;
        } catch (\Throwable $e) {
            Log::warning("SchemaHelper::ensureTable({$table}) failed: ".$e->getMessage());

            return Schema::hasTable($table);
        }
    }

    /**
     * Add any missing columns to an existing table. $columns maps
     * column-name => Closure(Blueprint $t): adds the column. Only columns that
     * are genuinely absent are added, so this is safe to re-run every request.
     *
     * Example:
     *   SchemaHelper::ensureColumns('pay_cycles', [
     *       'start_day' => fn ($t) => $t->unsignedTinyInteger('start_day')->nullable(),
     *       'end_day'   => fn ($t) => $t->unsignedTinyInteger('end_day')->nullable(),
     *   ]);
     *
     * Returns the list of columns actually added.
     */
    public static function ensureColumns(string $table, array $columns): array
    {
        $added = [];
        try {
            if (! Schema::hasTable($table)) {
                return $added;
            }
            $missing = [];
            foreach ($columns as $name => $add) {
                if (! Schema::hasColumn($table, $name)) {
                    $missing[$name] = $add;
                }
            }
            if (! $missing) {
                return $added;
            }
            Schema::table($table, function (Blueprint $t) use ($missing, &$added) {
                foreach ($missing as $name => $add) {
                    $add($t);
                    $added[] = $name;
                }
            });
        } catch (\Throwable $e) {
            Log::warning("SchemaHelper::ensureColumns({$table}) failed: ".$e->getMessage());
        }

        return $added;
    }

    /** True when EVERY named column exists on the table. */
    public static function hasColumns(string $table, array $columns): bool
    {
        if (! Schema::hasTable($table)) {
            return false;
        }
        foreach ($columns as $c) {
            if (! Schema::hasColumn($table, $c)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Drop a table only if it exists — the mirror image of ensureTable, for a
     * migration's down(). Best-effort so a rollback never wedges on a missing
     * table.
     */
    public static function dropTableIfExists(string $table): void
    {
        try {
            Schema::dropIfExists($table);
        } catch (\Throwable $e) {
            Log::warning("SchemaHelper::dropTableIfExists({$table}) failed: ".$e->getMessage());
        }
    }

    /**
     * Drop the named columns from a table if present — for a migration's down().
     * Wrapped per-column so one un-droppable column (e.g. still indexed) does
     * not abort the rest of the rollback.
     */
    public static function dropColumnsIfExist(string $table, array $columns): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }
        foreach ($columns as $c) {
            try {
                if (Schema::hasColumn($table, $c)) {
                    Schema::table($table, fn (Blueprint $t) => $t->dropColumn($c));
                }
            } catch (\Throwable $e) {
                Log::warning("SchemaHelper::dropColumnsIfExist({$table}.{$c}) failed: ".$e->getMessage());
            }
        }
    }
}
