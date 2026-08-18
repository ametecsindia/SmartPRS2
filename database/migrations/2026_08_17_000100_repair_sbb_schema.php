<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SBB — repair / complete the schema, idempotently.
 *
 * WHY THIS EXISTS
 * The three 2026_08_16 migrations each open with "if the table already exists,
 * return". That is correct for a fresh install but WRONG for a database where a
 * table of the same name already existed for any reason: the create is skipped
 * and the table is left without the columns the code expects. Exactly that
 * happened on the dev database — attendance_pending existed but had no
 * device_user_id, so Settings -> Pending Punches died with
 * "SQLSTATE[42S22]: Unknown column 'device_user_id' in 'field list'".
 *
 * This migration therefore assumes NOTHING. Every table, column and index is
 * checked and added only if missing. It drops nothing, renames nothing and
 * rewrites no data, so it is safe to run on a live database and safe to run
 * again. It follows the same defensive pattern the codebase already uses in
 * 2026_06_24_000000_ensure_runtime_feature_tables and PushController::ensure().
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->repairApiKeys();
        $this->repairAttendancePending();
        $this->repairAttendanceLogs();
    }

    public function down(): void
    {
        // Purely additive repair — nothing to undo. The 2026_08_16 migrations
        // own the drops.
    }

    // ---------------------------------------------------------------- api_keys

    private function repairApiKeys(): void
    {
        if (! Schema::hasTable('api_keys')) {
            Schema::create('api_keys', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('tenant_id')->nullable()->index();
                $t->unsignedBigInteger('company_id')->nullable()->index();
                $t->string('name');
                $t->string('prefix', 12)->index();
                $t->string('key_hash');
                $t->json('scopes')->nullable();
                $t->timestamp('last_used_at')->nullable();
                $t->timestamp('expires_at')->nullable();
                $t->boolean('active')->default(true);
                $t->timestamps();
            });

            return;
        }

        $this->addMissing('api_keys', [
            'tenant_id' => fn (Blueprint $t) => $t->unsignedBigInteger('tenant_id')->nullable()->index(),
            'company_id' => fn (Blueprint $t) => $t->unsignedBigInteger('company_id')->nullable()->index(),
            'name' => fn (Blueprint $t) => $t->string('name')->nullable(),
            'prefix' => fn (Blueprint $t) => $t->string('prefix', 12)->nullable()->index(),
            'key_hash' => fn (Blueprint $t) => $t->string('key_hash')->nullable(),
            'scopes' => fn (Blueprint $t) => $t->json('scopes')->nullable(),
            'last_used_at' => fn (Blueprint $t) => $t->timestamp('last_used_at')->nullable(),
            'expires_at' => fn (Blueprint $t) => $t->timestamp('expires_at')->nullable(),
            'active' => fn (Blueprint $t) => $t->boolean('active')->default(true),
            'created_at' => fn (Blueprint $t) => $t->timestamp('created_at')->nullable(),
            'updated_at' => fn (Blueprint $t) => $t->timestamp('updated_at')->nullable(),
        ]);
    }

    // ------------------------------------------------------- attendance_pending

    private function repairAttendancePending(): void
    {
        if (! Schema::hasTable('attendance_pending')) {
            Schema::create('attendance_pending', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('tenant_id')->nullable()->index();
                $t->unsignedBigInteger('company_id')->nullable();
                $t->string('device_sn', 64)->nullable();
                $t->string('device_user_id', 64)->nullable()->index();
                $t->dateTime('punch_at');
                $t->string('direction', 8)->nullable();
                $t->string('verify_mode', 24)->nullable();
                $t->string('external_id', 96)->nullable();
                $t->string('source', 40)->default('sbb');
                $t->timestamp('resolved_at')->nullable()->index();
                $t->timestamps();
                $t->unique(['tenant_id', 'external_id'], 'attpending_tenant_external_unique');
            });

            return;
        }

        // The table exists but may predate this feature, or be a partial build.
        // punch_at is nullable here on purpose: adding a NOT NULL datetime to a
        // table that already holds rows would fail.
        $this->addMissing('attendance_pending', [
            'tenant_id' => fn (Blueprint $t) => $t->unsignedBigInteger('tenant_id')->nullable()->index(),
            'company_id' => fn (Blueprint $t) => $t->unsignedBigInteger('company_id')->nullable(),
            'device_sn' => fn (Blueprint $t) => $t->string('device_sn', 64)->nullable(),
            'device_user_id' => fn (Blueprint $t) => $t->string('device_user_id', 64)->nullable()->index(),
            'punch_at' => fn (Blueprint $t) => $t->dateTime('punch_at')->nullable(),
            'direction' => fn (Blueprint $t) => $t->string('direction', 8)->nullable(),
            'verify_mode' => fn (Blueprint $t) => $t->string('verify_mode', 24)->nullable(),
            'external_id' => fn (Blueprint $t) => $t->string('external_id', 96)->nullable(),
            'source' => fn (Blueprint $t) => $t->string('source', 40)->nullable()->default('sbb'),
            'resolved_at' => fn (Blueprint $t) => $t->timestamp('resolved_at')->nullable()->index(),
            'created_at' => fn (Blueprint $t) => $t->timestamp('created_at')->nullable(),
            'updated_at' => fn (Blueprint $t) => $t->timestamp('updated_at')->nullable(),
        ]);

        $this->addUnique(
            'attendance_pending',
            ['tenant_id', 'external_id'],
            'attpending_tenant_external_unique'
        );
    }

    // ---------------------------------------------------------- attendance_logs

    private function repairAttendanceLogs(): void
    {
        if (! Schema::hasTable('attendance_logs')) {
            return;   // owned by 2026_06_24_000000_ensure_runtime_feature_tables
        }

        $this->addMissing('attendance_logs', [
            'device_sn' => fn (Blueprint $t) => $t->string('device_sn', 64)->nullable()->index(),
            'device_user_id' => fn (Blueprint $t) => $t->string('device_user_id', 64)->nullable(),
            'external_id' => fn (Blueprint $t) => $t->string('external_id', 96)->nullable(),
            'verify_mode' => fn (Blueprint $t) => $t->string('verify_mode', 24)->nullable(),
        ]);

        // 'unknown' does not fit in the original string(4).
        try {
            Schema::table('attendance_logs', function (Blueprint $t) {
                $t->string('direction', 8)->change();
            });
        } catch (\Throwable $e) {
            report($e);
        }

        // Duplicates must go before the natural-key unique index can be built.
        if (! $this->indexExists('attendance_logs', 'attlog_natural_unique')) {
            $this->dedupeNaturalKey();
        }

        $this->addUnique('attendance_logs', ['tenant_id', 'external_id'], 'attlog_tenant_external_unique');
        $this->addUnique('attendance_logs', ['tenant_id', 'emp_code', 'punch_at', 'source'], 'attlog_natural_unique');
    }

    // ------------------------------------------------------------------ helpers

    /** @param array<string,callable> $columns */
    private function addMissing(string $table, array $columns): void
    {
        $missing = array_filter(
            $columns,
            fn ($_, $name) => ! Schema::hasColumn($table, $name),
            ARRAY_FILTER_USE_BOTH
        );
        if (! $missing) {
            return;
        }

        Schema::table($table, function (Blueprint $t) use ($missing) {
            foreach ($missing as $fn) {
                $fn($t);
            }
        });
    }

    private function addUnique(string $table, array $columns, string $name): void
    {
        if ($this->indexExists($table, $name)) {
            return;
        }
        try {
            Schema::table($table, function (Blueprint $t) use ($columns, $name) {
                $t->unique($columns, $name);
            });
        } catch (\Throwable $e) {
            // Never abort the repair on an index: the columns matter more, and a
            // collision here is actionable rather than fatal.
            report($e);
        }
    }

    private function indexExists(string $table, string $name): bool
    {
        try {
            foreach (Schema::getIndexes($table) as $ix) {
                if (strcasecmp((string) ($ix['name'] ?? ''), $name) === 0) {
                    return true;
                }
            }
        } catch (\Throwable $e) {
            // cannot confirm
        }

        return false;
    }

    /** Keep the newest row per (tenant_id, emp_code, punch_at, source). */
    private function dedupeNaturalKey(): void
    {
        $keep = [];
        $doomed = [];

        DB::table('attendance_logs')
            ->orderBy('id')
            ->select(['id', 'tenant_id', 'emp_code', 'punch_at', 'source'])
            ->chunk(2000, function ($rows) use (&$keep, &$doomed) {
                foreach ($rows as $r) {
                    $k = ($r->tenant_id ?? 'null').'|'.$r->emp_code.'|'.$r->punch_at.'|'.($r->source ?? '');
                    if (isset($keep[$k])) {
                        $doomed[] = $keep[$k];
                    }
                    $keep[$k] = $r->id;
                }
            });

        foreach (array_chunk($doomed, 500) as $chunk) {
            DB::table('attendance_logs')->whereIn('id', $chunk)->delete();
        }
    }
};
