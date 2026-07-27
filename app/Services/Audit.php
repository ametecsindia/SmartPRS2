<?php

namespace App\Services;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * J2 — Immutable activity / audit trail.
 *
 * A single append-only ledger (`activity_logs`) made tamper-evident with a
 * SHA-256 hash chain: every row stores the previous row's hash plus its own
 * hash computed over (prev_hash | tenant | user | action | entity | id |
 * detail | timestamp). Altering or deleting any historic row breaks the chain
 * from that point on, which verify() detects and reports.
 *
 * record() is best-effort and NEVER throws — auditing must never block the
 * business action that triggered it.
 */
class Audit
{
    /** Add the hash-chain columns to activity_logs if missing (idempotent). */
    public static function ensure(): bool
    {
        if (! Schema::hasTable('activity_logs')) {
            return false;
        }
        try {
            if (! Schema::hasColumn('activity_logs', 'prev_hash')) {
                Schema::table('activity_logs', function (Blueprint $t) {
                    $t->string('prev_hash', 64)->nullable();
                });
            }
            if (! Schema::hasColumn('activity_logs', 'row_hash')) {
                Schema::table('activity_logs', function (Blueprint $t) {
                    $t->string('row_hash', 64)->nullable()->index();
                });
            }
        } catch (\Throwable $e) {
            // best-effort
        }

        return true;
    }

    /** Canonical string a row's hash is computed over. */
    private static function payload(string $prev, $tenantId, $userId, string $action, string $entity, int $entityId, ?string $detail, string $when): string
    {
        return implode('|', [
            $prev,
            $tenantId ?? '',
            $userId ?? '',
            $action,
            $entity,
            $entityId,
            $detail ?? '',
            $when,
        ]);
    }

    /**
     * Append a tamper-evident audit entry. Best-effort: never throws.
     *
     * @param  mixed  $detail  array|string|null — arrays are JSON-encoded.
     */
    public static function record(?int $tenantId, ?int $userId, string $action, string $entity, $entityId = 0, $detail = null, ?string $ip = null): void
    {
        try {
            if (! self::ensure()) {
                return;
            }
            $when = now();
            $whenStr = $when->toDateTimeString();
            $eid = is_numeric($entityId) ? (int) $entityId : 0;
            $detailJson = is_string($detail) ? $detail : ($detail === null ? null : json_encode($detail));

            $row = [
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'action' => $action,
                'entity' => $entity,
                'entity_id' => $eid,
                'detail' => $detailJson,
                'ip' => $ip,
                'created_at' => $when,
            ];

            if (Schema::hasColumn('activity_logs', 'row_hash')) {
                $prev = DB::table('activity_logs')->orderByDesc('id')->value('row_hash') ?: 'GENESIS';
                $row['prev_hash'] = $prev;
                $row['row_hash'] = hash('sha256', self::payload($prev, $tenantId, $userId, $action, $entity, $eid, $detailJson, $whenStr));
            }

            DB::table('activity_logs')->insert($row);
        } catch (\Throwable $e) {
            // auditing is best-effort; never block the caller
        }
    }

    /**
     * Recompute the whole chain and report integrity.
     * Returns ['ok'=>bool, 'checked'=>int, 'broken_at'=>id (0 = intact)].
     */
    public static function verify(): array
    {
        if (! Schema::hasTable('activity_logs') || ! Schema::hasColumn('activity_logs', 'row_hash')) {
            return ['ok' => false, 'checked' => 0, 'broken_at' => 0, 'reason' => 'chain-not-initialised'];
        }
        $rows = DB::table('activity_logs')->orderBy('id')->get();
        $prev = 'GENESIS';
        $checked = 0;
        foreach ($rows as $r) {
            // rows written before the chain existed have no row_hash — skip,
            // but keep their (null) hash as the running "prev" so later rows
            // that chained onto null still validate.
            if ($r->row_hash === null || $r->row_hash === '') {
                $prev = $r->row_hash ?: 'GENESIS';
                continue;
            }
            $when = $r->created_at ? Carbon::parse($r->created_at)->toDateTimeString() : '';
            $calc = hash('sha256', self::payload($prev, $r->tenant_id, $r->user_id, (string) $r->action, (string) $r->entity, (int) $r->entity_id, $r->detail, $when));
            $checked++;
            if (! hash_equals($calc, (string) $r->row_hash)) {
                return ['ok' => false, 'checked' => $checked, 'broken_at' => (int) $r->id];
            }
            $prev = $r->row_hash;
        }

        return ['ok' => true, 'checked' => $checked, 'broken_at' => 0];
    }
}
