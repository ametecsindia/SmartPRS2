<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 18 Aug 2026 HOTFIX — attendance_pending for the /iclock ingest path.
 *
 * The table already exists (created 16 Aug for the SBB JSON path). This
 * migration is therefore ADDITIVE: it adds the two things the /iclock path
 * needs and nothing else. It creates the table only if it is genuinely absent,
 * so a fresh customer server gets the full shape in one step.
 *
 *   device_code       the code the MAPPING works in — emp_prefix applied — i.e.
 *                     the same value recordUnmapped() writes to
 *                     biometric_unmapped.device_code, so the admin's Map action
 *                     lines up with what is held. Distinct from device_user_id,
 *                     which keeps the RAW code the device sent.
 *   attpending_unique (tenant_id, device_code, punch_at, source) — stops a
 *                     re-import piling up duplicate held punches.
 *
 * Nothing is dropped, renamed or rewritten, and it is safe to run twice.
 *
 * NOTE: this deliberately does NOT touch attendance_logs. Adding the unique
 * index there is explicitly out of scope for tonight — production still holds
 * duplicates and a failed CREATE UNIQUE INDEX mid-deploy is unrecoverable in
 * the window available. Logged for next week.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('attendance_pending')) {
            Schema::create('attendance_pending', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('tenant_id')->nullable()->index();
                $t->unsignedBigInteger('company_id')->nullable();
                $t->string('device_sn', 64)->nullable()->index();
                $t->string('device_code', 64)->nullable()->index();
                $t->string('device_user_id', 64)->nullable()->index();
                $t->dateTime('punch_at')->nullable();
                $t->string('direction', 8)->nullable();
                $t->string('verify_mode', 24)->nullable();
                $t->string('external_id', 96)->nullable();
                $t->string('source', 32)->nullable()->default('device');
                $t->timestamp('resolved_at')->nullable()->index();
                $t->timestamps();
                $t->unique(['tenant_id', 'device_code', 'punch_at', 'source'], 'attpending_unique');
            });

            return;
        }

        // --- existing table: add only what is missing ---------------------
        $add = [
            'device_code' => fn (Blueprint $t) => $t->string('device_code', 64)->nullable()->index(),
            'device_sn' => fn (Blueprint $t) => $t->string('device_sn', 64)->nullable()->index(),
            'device_user_id' => fn (Blueprint $t) => $t->string('device_user_id', 64)->nullable()->index(),
            'direction' => fn (Blueprint $t) => $t->string('direction', 8)->nullable(),
            'source' => fn (Blueprint $t) => $t->string('source', 32)->nullable()->default('device'),
            'resolved_at' => fn (Blueprint $t) => $t->timestamp('resolved_at')->nullable()->index(),
            'company_id' => fn (Blueprint $t) => $t->unsignedBigInteger('company_id')->nullable(),
        ];
        $missing = array_filter($add, fn ($_, $c) => ! Schema::hasColumn('attendance_pending', $c), ARRAY_FILTER_USE_BOTH);
        if ($missing) {
            Schema::table('attendance_pending', function (Blueprint $t) use ($missing) {
                foreach ($missing as $fn) {
                    $fn($t);
                }
            });
        }

        // Backfill device_code from the raw PIN for anything already held, so
        // the mapping card can reach rows written before this migration.
        try {
            if (Schema::hasColumn('attendance_pending', 'device_user_id')) {
                \Illuminate\Support\Facades\DB::table('attendance_pending')
                    ->whereNull('device_code')
                    ->whereNotNull('device_user_id')
                    ->update(['device_code' => \Illuminate\Support\Facades\DB::raw('device_user_id')]);
            }
        } catch (\Throwable $e) {
            report($e);
        }

        if (! $this->indexExists('attpending_unique')) {
            try {
                Schema::table('attendance_pending', function (Blueprint $t) {
                    $t->unique(['tenant_id', 'device_code', 'punch_at', 'source'], 'attpending_unique');
                });
            } catch (\Throwable $e) {
                // A collision here is not worth failing a hotfix deploy over:
                // quarantine writes use updateOrInsert and stay correct without
                // the index. Surfaced in the log for follow-up.
                report($e);
            }
        }
    }

    public function down(): void
    {
        // Additive only — nothing to reverse.
    }

    private function indexExists(string $name): bool
    {
        try {
            foreach (Schema::getIndexes('attendance_pending') as $ix) {
                if (strcasecmp((string) ($ix['name'] ?? ''), $name) === 0) {
                    return true;
                }
            }
        } catch (\Throwable $e) {
            // cannot confirm
        }

        return false;
    }
};
