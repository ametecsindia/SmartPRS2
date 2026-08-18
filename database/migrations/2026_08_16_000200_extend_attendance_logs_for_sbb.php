<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SBB — extend attendance_logs so a punch carries its provenance, and make
 * de-duplication a DATABASE guarantee instead of a hopeful SELECT.
 *
 * Every new column is nullable, so every existing row stays valid and every
 * existing writer (PushController -> ETimeOfficeService::import, the eTimeOffice
 * / eTimeTrackLite / generic pulls) keeps working untouched.
 *
 * TWO unique indexes are added:
 *
 *   attlog_tenant_external_unique (tenant_id, external_id)
 *       The SENDER's idempotency key. SBB is at-least-once: it will re-send a
 *       batch it never saw an ack for. This index is what makes the re-send a
 *       no-op instead of a duplicate punch. Existing rows have external_id NULL
 *       and NULL never collides in a unique index (MySQL and SQLite alike), so
 *       history is unaffected.
 *
 *   attlog_natural_unique (tenant_id, emp_code, punch_at, source)
 *       The punch's real identity, and the same key ETimeOfficeService::import
 *       and attendance:dedupe already treat as identity (rev172/rev173g).
 *
 * ORDER MATTERS. Production data already contains duplicates on the natural
 * key — that is exactly what attendance:dedupe exists to repair — so the
 * duplicates are cleared BEFORE the unique index is created. If the index were
 * created first, the migration would abort on any real install.
 */
return new class extends Migration
{
    private const NEW_COLUMNS = ['device_sn', 'device_user_id', 'external_id', 'verify_mode'];

    public function up(): void
    {
        if (! Schema::hasTable('attendance_logs')) {
            // attendance_logs is created by 2026_06_24_000000_ensure_runtime_feature_tables.
            // If it is genuinely absent there is nothing to extend and the SBB path
            // cannot work; fail loudly rather than pretend to have migrated.
            throw new RuntimeException(
                'attendance_logs is missing. Run the earlier migrations first, then re-run this one.'
            );
        }

        // 1) Provenance columns — all nullable, all backwards compatible.
        $missing = array_values(array_filter(
            self::NEW_COLUMNS,
            fn ($c) => ! Schema::hasColumn('attendance_logs', $c)
        ));
        if ($missing) {
            Schema::table('attendance_logs', function (Blueprint $t) use ($missing) {
                foreach ($missing as $c) {
                    match ($c) {
                        'device_sn' => $t->string('device_sn', 64)->nullable()->index(),
                        'device_user_id' => $t->string('device_user_id', 64)->nullable(),
                        'external_id' => $t->string('external_id', 96)->nullable(),
                        'verify_mode' => $t->string('verify_mode', 24)->nullable(),
                    };
                }
            });
        }

        // 2) direction: string(4) -> string(8). 'unknown' does not fit in 4 chars,
        //    and a silently truncated direction is a wrong attendance record.
        if (Schema::hasColumn('attendance_logs', 'direction')) {
            try {
                Schema::table('attendance_logs', function (Blueprint $t) {
                    $t->string('direction', 8)->change();
                });
            } catch (\Throwable $e) {
                // Widening is not destructive anywhere it succeeds; on an install
                // whose driver refuses the ALTER we keep going — the ingest path
                // stores 'in'/'out'/'unknown' and only 'unknown' needs the width.
                report($e);
            }
        }

        // 3) Clear existing duplicates BEFORE the unique index is created.
        $this->dedupeNaturalKey();

        // 4) The indexes themselves.
        $this->addUnique(
            ['tenant_id', 'external_id'],
            'attlog_tenant_external_unique'
        );
        $this->addUnique(
            ['tenant_id', 'emp_code', 'punch_at', 'source'],
            'attlog_natural_unique'
        );
    }

    public function down(): void
    {
        foreach (['attlog_tenant_external_unique', 'attlog_natural_unique'] as $name) {
            try {
                Schema::table('attendance_logs', function (Blueprint $t) use ($name) {
                    $t->dropUnique($name);
                });
            } catch (\Throwable $e) {
                // index may not exist
            }
        }

        $present = array_values(array_filter(
            self::NEW_COLUMNS,
            fn ($c) => Schema::hasColumn('attendance_logs', $c)
        ));
        if ($present) {
            Schema::table('attendance_logs', function (Blueprint $t) use ($present) {
                $t->dropColumn($present);
            });
        }
    }

    /**
     * Run the existing repair command; fall back to the same logic inline if it
     * is unavailable. The unique index below CANNOT be created while duplicates
     * remain, so this step is not optional.
     */
    private function dedupeNaturalKey(): void
    {
        try {
            Artisan::call('attendance:dedupe');

            return;
        } catch (\Throwable $e) {
            report($e);
        }

        // Inline fallback — identical identity + keep-newest rule to
        // App\Console\Commands\DedupeAttendance.
        $keep = [];
        $doomed = [];
        DB::table('attendance_logs')
            ->orderBy('id')
            ->select(['id', 'tenant_id', 'emp_code', 'punch_at', 'source'])
            ->chunk(2000, function ($rows) use (&$keep, &$doomed) {
                foreach ($rows as $r) {
                    $key = ($r->tenant_id ?? 'null').'|'.$r->emp_code.'|'.$r->punch_at.'|'.($r->source ?? '');
                    if (isset($keep[$key])) {
                        // ids ascend, so the row already held is older.
                        $doomed[] = $keep[$key];
                    }
                    $keep[$key] = $r->id;
                }
            });

        foreach (array_chunk($doomed, 500) as $chunk) {
            DB::table('attendance_logs')->whereIn('id', $chunk)->delete();
        }
    }

    /** Create one unique index, turning a collision into an actionable message. */
    private function addUnique(array $columns, string $name): void
    {
        try {
            Schema::table('attendance_logs', function (Blueprint $t) use ($columns, $name) {
                $t->unique($columns, $name);
            });
        } catch (\Throwable $e) {
            if ($this->indexExists($name)) {
                return;   // already applied — re-running the migration is safe
            }

            throw new RuntimeException(
                "Could not create unique index {$name} on attendance_logs (".implode(', ', $columns).'). '
                .'This normally means duplicate punches remain. Run "php artisan attendance:dedupe --dry" '
                .'to inspect them, then "php artisan attendance:dedupe", then re-run this migration. '
                .'Original error: '.$e->getMessage(),
                0,
                $e
            );
        }
    }

    private function indexExists(string $name): bool
    {
        try {
            foreach (Schema::getIndexes('attendance_logs') as $index) {
                if (strcasecmp((string) ($index['name'] ?? ''), $name) === 0) {
                    return true;
                }
            }
        } catch (\Throwable $e) {
            // Schema::getIndexes is Laravel 11+; if it is unavailable we simply
            // cannot confirm, and the caller rethrows.
        }

        return false;
    }
};
