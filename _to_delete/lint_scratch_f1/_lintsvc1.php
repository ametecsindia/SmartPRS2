<?php

namespace App\Services;

use App\Support\SchemaHelper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * F1 — Statutory configuration: effective-dated, scoped rate overrides.
 *
 * WHAT ALREADY EXISTS (and is deliberately not rebuilt): statutory rates are
 * already editable and already drive payroll. `SettingsController::defaults()`
 * merges the saved `statutory_settings` blob over the shipped defaults, and the
 * engine reads PF/ESI/PT/TDS from that. The Statutory Rate Settings screen has
 * been in production for months. Professional Tax is ALREADY state-aware:
 * `AppDataController::ptForGross()` picks a slab from the employee's pt_state.
 *
 * WHAT WAS MISSING, and is what this class adds:
 *   1. EFFECTIVE DATING — today there is exactly one current blob. Change a rate
 *      and it silently applies to every past month you regenerate. There is no
 *      "PF was 12% until March, 13% from April".
 *   2. SCOPE — rates are tenant-wide. A group running companies in two states
 *      cannot hold two PT positions. NOTE: only PROFESSIONAL TAX
 *      actually varies by state in law. PF, ESI and TDS are central and
 *      identical nationwide, so their scope rows exist for a company's own
 *      business choices, never for legal variation.
 *   3. TDS SPLIT — salary TDS comes off the slab table and commission TDS from
 *      `comm_tds_rate`, but incentive has no separate rate of its own.
 *   4. EDITABLE PT SLABS — the 16 state slabs and 10 PT-free states are compiled
 *      into the engine. A state changing its slab, or a state that is not built
 *      in at all, needed a code change. `pt_state_slabs` / `pt_free_states`
 *      overrides now ride through this same layer.
 *   5. A PAYROLL-IMPACT PREVIEW before saving.
 *   6. A guard so a rate change cannot rewrite a finalised month (decision #2).
 *
 * Design: this is an OVERRIDE layer, not a replacement. With no rows saved,
 * every method returns its input untouched, so a tenant that never opens the
 * screen behaves EXACTLY as it does today. That invariant is tested.
 */
class StatutoryConfig
{
    /** Scope kinds, most specific first — that is also the precedence order. */
    public const SCOPES = ['branch', 'location', 'company', 'state', 'tenant'];

    /** The rate groups a row may carry, so the UI can edit one area at a time. */
    public const KINDS = [
        'pf' => 'Provident Fund',
        'esi' => 'ESI',
        'pt' => 'Professional Tax',
        'pt_slabs' => 'Professional Tax — state slabs',
        'tds_salary' => 'TDS — salary',
        'tds_incentive' => 'TDS — incentive',
        'tds_commission' => 'TDS — commission',
        'bonus' => 'Statutory bonus & conveyance',
        'lwf' => 'Labour Welfare Fund',
    ];

    /**
     * Keys a row may carry that are NOT in the flat defaults blob. These are the
     * structured PT overrides; everything else must be a known rate name so a
     * typo cannot become a silent, meaningless setting.
     */
    public const EXTRA_KEYS = ['pt_state_slabs', 'pt_free_states'];

    /** Per-request memo: [tid|date => rows]. Configuration, not transactions. */
    private static array $rowCache = [];

    /** Per-request memo of fully merged scope results. */
    private static array $mergeCache = [];

    /** Per-request memo: does this install have ANY override rows at all? */
    private static ?bool $anyRows = null;

    public static function ensureSchema(): void
    {
        SchemaHelper::ensureTable('statutory_configs', function ($t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->nullable()->index();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->string('scope', 20)->default('tenant');   // see SCOPES
            $t->string('scope_value')->nullable();        // state name
            $t->string('kind', 30);                       // see KINDS
            $t->text('payload')->nullable();              // JSON of rate keys -> values
            $t->date('effective_from')->nullable()->index();
            $t->string('note', 500)->nullable();
            $t->string('created_by')->nullable();
            $t->timestamps();
            $t->index(['tenant_id', 'kind', 'effective_from']);
        });
        self::flushCache();
    }

    /** Drop the per-request memo — called after every save. */
    public static function flushCache(): void
    {
        self::$rowCache = [];
        self::$mergeCache = [];
        self::$anyRows = null;
    }

    /**
     * THE ENGINE HOOK. Merge any in-force scoped overrides on top of the rate
     * blob the caller already assembled, for one employee's context.
     *
     * This is called per payslip, so it is written to cost nothing when there is
     * nothing to do: an install with no override rows short-circuits on a single
     * cached existence check and returns the same array it was given.
     *
     * @param  array  $r    the rates the caller already resolved (tenant blob)
     * @param  array  $ctx  statutory context — pt_state, month,
     *                      tenant_id, company_id. Extra keys are ignored.
     */
    public static function applyScope(array $r, array $ctx): array
    {
        if (! $ctx) {
            return $r;
        }
        try {
            if (! self::hasAnyRows()) {
                return $r;   // the overwhelmingly common case
            }
            $tid = isset($ctx['tenant_id']) ? (int) $ctx['tenant_id'] : null;
            $companyId = isset($ctx['company_id']) ? (int) $ctx['company_id'] : null;
            $state = trim((string) ($ctx['pt_state'] ?? ''));
            $branchId = isset($ctx['branch_id']) ? (((int) $ctx['branch_id']) ?: null) : null;
            $branchCity = trim((string) ($ctx['branch_city'] ?? ''));
            $date = EffectiveDated::toDate(
                $ctx['on'] ?? (! empty($ctx['month']) ? $ctx['month'].'-01' : null)
            );

            $ck = implode('|', [$tid, $companyId, mb_strtolower($state), $branchId, mb_strtolower($branchCity), $date]);
            if (! array_key_exists($ck, self::$mergeCache)) {
                self::$mergeCache[$ck] = self::mergedOverrides($tid, $companyId, $state, $branchId, $branchCity, $date);
            }
            $over = self::$mergeCache[$ck];

            return $over ? self::mergeValues($r, $over) : $r;
        } catch (\Throwable $e) {
            Log::warning('StatutoryConfig::applyScope failed: '.$e->getMessage());

            return $r;   // fail SOFT — a config problem must never break payroll
        }
    }

    /**
     * Every override in force for one context, already merged in precedence
     * order. Broadest first so the narrowest scope wins:
     * tenant -> state -> company -> location -> branch.
     *
     * Resolved per KIND as well as per scope: a PF row and a PT row at the same
     * scope are independent, and the newest row of each kind wins on its own.
     * (Resolving by scope alone would let whichever kind was saved last shadow
     * every other kind at that scope.)
     */
    private static function mergedOverrides(?int $tid, ?int $companyId, string $state, ?int $branchId, string $branchCity, string $date): array
    {
        $rows = self::rowsInForce($tid, $date);
        if (! $rows) {
            return [];
        }

        // Keep only the newest row per (scope, scope_value, kind). $rows arrives
        // oldest-first, so a later row simply overwrites its predecessor.
        $winner = [];
        foreach ($rows as $row) {
            $scope = (string) $row->scope;
            if (! in_array($scope, self::SCOPES, true)) {
                continue;
            }
            if ($scope === 'company') {
                if (! $companyId || (int) $row->company_id !== $companyId) {
                    continue;
                }
            } elseif ($scope === 'state') {
                if ($state === '' || ! self::looseMatch($state, (string) $row->scope_value)) {
                    continue;
                }
            } elseif ($scope === 'branch') {
                if (! $branchId || (int) $row->scope_value !== $branchId) {
                    continue;
                }
            } elseif ($scope === 'location') {
                if ($branchCity === '' || ! self::looseMatch($branchCity, (string) $row->scope_value)) {
                    continue;
                }
            }
            $winner[$scope.'|'.$row->kind] = $row;
        }
        if (! $winner) {
            return [];
        }

        $out = [];
        foreach (array_reverse(self::SCOPES) as $scope) {     // tenant, state, company, location, branch
            foreach ($winner as $key => $row) {
                if (strpos($key, $scope.'|') !== 0) {
                    continue;
                }
                $vals = json_decode((string) ($row->payload ?: '{}'), true);
                if (is_array($vals) && $vals) {
                    $out = self::mergeValues($out, $vals);
                }
            }
        }

        return $out;
    }

    /**
     * Merge override values over a base. Flat rates replace outright; the PT
     * state-slab map merges PER STATE, so a company row that corrects Bihar does
     * not wipe a tenant row that corrected Kerala.
     */
    private static function mergeValues(array $base, array $over): array
    {
        $slabKey = 'pt_state_slabs';
        if (isset($base[$slabKey], $over[$slabKey]) && is_array($base[$slabKey]) && is_array($over[$slabKey])) {
            $merged = array_merge($base[$slabKey], $over[$slabKey]);
            $base = array_merge($base, $over);
            $base[$slabKey] = $merged;

            return $base;
        }

        return array_merge($base, $over);
    }

    /** State names are typed by humans — compare loosely, exactly as PT does. */
    private static function looseMatch(string $ctxValue, string $rowValue): bool
    {
        $a = mb_strtolower(trim($ctxValue));
        $b = mb_strtolower(trim($rowValue));
        if ($a === '' || $b === '') {
            return false;
        }

        return $a === $b || str_contains($a, $b) || str_contains($b, $a);
    }

    /** All rows already in force on $date for a tenant, oldest first. One query. */
    private static function rowsInForce(?int $tid, string $date): array
    {
        $ck = $tid.'|'.$date;
        if (array_key_exists($ck, self::$rowCache)) {
            return self::$rowCache[$ck];
        }
        $rows = [];
        try {
            if (Schema::hasTable('statutory_configs')) {
                $rows = DB::table('statutory_configs')
                    ->when($tid !== null, fn ($q) => $q->where('tenant_id', $tid))
                    ->where(function ($w) use ($date) {
                        $w->whereNull('effective_from')->orWhere('effective_from', '<=', $date);
                    })
                    ->orderByRaw("COALESCE(effective_from, '1900-01-01') ASC")
                    ->orderBy('id')
                    ->get()->all();
            }
        } catch (\Throwable $e) {
            $rows = [];
        }

        return self::$rowCache[$ck] = $rows;
    }

    /** Cheap existence check so a tenant with no overrides pays almost nothing. */
    private static function hasAnyRows(): bool
    {
        if (self::$anyRows !== null) {
            return self::$anyRows;
        }
        try {
            self::$anyRows = Schema::hasTable('statutory_configs')
                && DB::table('statutory_configs')->limit(1)->exists();
        } catch (\Throwable $e) {
            self::$anyRows = false;
        }

        return self::$anyRows;
    }

    /**
     * The rates in force for a company on a date: the existing defaults blob
     * with every applicable override merged over it. Used by the preview and by
     * screens that want to show "what applies here"; the payroll engine uses
     * {@see applyScope} instead, because it already holds the tenant blob.
     *
     * @param  mixed  $on  date the rates should be judged on — pass the PAYROLL
     *                     PERIOD date, never "now", or a mid-month edit would
     *                     retro-change an open run.
     */
    public static function resolve(?int $tenantId, ?int $companyId, $on = null, array $ctx = []): array
    {
        $base = [];
        try {
            $base = \App\Http\Controllers\SettingsController::defaults();
            $saved = self::savedBlob($tenantId);
            if ($saved) {
                $base = array_merge($base, $saved);
            }
        } catch (\Throwable $e) {
            $base = is_array($base) ? $base : [];
        }

        return self::applyScope($base, [
            'tenant_id' => $tenantId,
            'company_id' => $companyId,
            'pt_state' => $ctx['state'] ?? ($ctx['pt_state'] ?? ''),
            'branch_id' => $ctx['branch_id'] ?? null,
            'branch_city' => $ctx['branch_city'] ?? '',
            'on' => $on,
        ]);
    }

    /** The current tenant-wide blob from the existing statutory_settings table. */
    private static function savedBlob(?int $tenantId): array
    {
        try {
            if (! Schema::hasTable('statutory_settings')) {
                return [];
            }
            $raw = DB::table('statutory_settings')->where('tenant_id', $tenantId ?? 0)->value('value');
            if (! $raw) {
                return [];
            }
            $v = is_array($raw) ? $raw : json_decode($raw, true);

            return is_array($v) ? $v : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Save an effective-dated override. Returns ['ok'=>bool,'error'=>?string].
     * The caller is responsible for the role check and for the finalised-period
     * guard ({@see EffectiveDated::guardFinalisedPeriod}).
     */
    public static function save(array $in): array
    {
        self::ensureSchema();
        try {
            $kind = (string) ($in['kind'] ?? '');
            if (! array_key_exists($kind, self::KINDS)) {
                return ['ok' => false, 'error' => 'Choose which rates this row covers.'];
            }
            $scope = (string) ($in['scope'] ?? 'tenant');
            if (! in_array($scope, self::SCOPES, true)) {
                return ['ok' => false, 'error' => 'Invalid scope.'];
            }
            if (in_array($scope, ['state', 'branch', 'location'], true) && trim((string) ($in['scope_value'] ?? '')) === '') {
                return ['ok' => false, 'error' => 'Name the '.$scope.' this row applies to.'];
            }
            if ($scope === 'company' && empty($in['company_id'])) {
                return ['ok' => false, 'error' => 'Choose the company this row applies to.'];
            }
            $eff = EffectiveDated::toDate($in['effective_from'] ?? null);
            $payload = $in['payload'] ?? [];
            if (! is_array($payload) || ! $payload) {
                return ['ok' => false, 'error' => 'Nothing to save — no rate values were provided.'];
            }

            // Keep only keys the rate blob actually recognises (plus the
            // structured PT overrides), so a typo cannot introduce a silent,
            // meaningless setting.
            $known = array_merge(
                array_keys(\App\Http\Controllers\SettingsController::defaults()),
                self::EXTRA_KEYS
            );
            $clean = array_intersect_key($payload, array_flip($known));
            if (isset($clean['pt_state_slabs'])) {
                $clean['pt_state_slabs'] = self::cleanSlabs($clean['pt_state_slabs']);
                if ($clean['pt_state_slabs'] === null) {
                    return ['ok' => false, 'error' => 'The state slab table is not in the expected shape.'];
                }
                if (! $clean['pt_state_slabs']) {
                    unset($clean['pt_state_slabs']);
                }
            }
            if (isset($clean['pt_free_states'])) {
                $free = is_array($clean['pt_free_states']) ? $clean['pt_free_states'] : [];
                $free = array_values(array_filter(array_map(
                    fn ($s) => mb_strtolower(trim((string) $s)),
                    $free
                )));
                $clean['pt_free_states'] = $free;
            }
            if (! $clean) {
                return ['ok' => false, 'error' => 'None of those rate names are recognised.'];
            }

            DB::table('statutory_configs')->insert([
                'tenant_id' => $in['tenant_id'] ?? null,
                'company_id' => $scope === 'company' ? (int) $in['company_id'] : null,
                'scope' => $scope,
                'scope_value' => in_array($scope, ['state', 'branch', 'location'], true) ? trim((string) $in['scope_value']) : null,
                'kind' => $kind,
                'payload' => json_encode($clean),
                'effective_from' => $eff,
                'note' => isset($in['note']) ? mb_substr((string) $in['note'], 0, 500) : null,
                'created_by' => $in['by'] ?? null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            self::flushCache();

            return ['ok' => true, 'error' => null, 'saved' => $clean, 'effective_from' => $eff];
        } catch (\Throwable $e) {
            Log::warning('StatutoryConfig::save failed: '.$e->getMessage());

            return ['ok' => false, 'error' => 'Could not save the configuration.'];
        }
    }

    /**
     * Validate a PT state-slab map into the shape the engine reads:
     *   ['maharashtra' => [[7500, 0], [10000, 175], [0, 200]], ...]
     * where each pair is [gross upto, monthly PT] and an "upto" of 0 means
     * "everything above". Returns null when the input is not usable at all.
     */
    private static function cleanSlabs($raw): ?array
    {
        if (! is_array($raw)) {
            return null;
        }
        $out = [];
        foreach ($raw as $state => $rows) {
            $key = mb_strtolower(trim((string) $state));
            if ($key === '' || ! is_array($rows)) {
                continue;
            }
            $clean = [];
            foreach ($rows as $row) {
                $row = is_array($row) ? array_values($row) : null;
                if (! $row || count($row) < 2) {
                    continue;
                }
                $upto = (float) $row[0];
                $amt = (float) $row[1];
                if ($upto < 0 || $amt < 0) {
                    return null;   // negative PT or a negative ceiling is never right
                }
                $clean[] = [$upto, $amt];
            }
            if (! $clean) {
                continue;
            }
            // Bands must climb, with the open-ended band (0) last, or the engine's
            // first-match walk would return the wrong figure.
            $open = array_filter($clean, fn ($b) => $b[0] <= 0);
            $closed = array_values(array_filter($clean, fn ($b) => $b[0] > 0));
            usort($closed, fn ($a, $b) => $a[0] <=> $b[0]);
            $out[$key] = array_merge($closed, array_values($open));
        }

        return $out;
    }

    /** Every override row for a tenant, newest first — the history timeline. */
    public static function history(?int $tenantId, ?string $kind = null): array
    {
        self::ensureSchema();
        try {
            return DB::table('statutory_configs')
                ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
                ->when($kind, fn ($q) => $q->where('kind', $kind))
                ->orderByDesc('effective_from')->orderByDesc('id')
                ->limit(200)->get()
                ->map(fn ($r) => [
                    'id' => (int) $r->id,
                    'kind' => $r->kind,
                    'kind_label' => self::KINDS[$r->kind] ?? $r->kind,
                    'scope' => $r->scope,
                    'scope_value' => $r->scope_value,
                    'company_id' => $r->company_id,
                    'effective_from' => $r->effective_from,
                    'values' => json_decode($r->payload ?: '{}', true) ?: [],
                    'note' => $r->note,
                    'by' => $r->created_by,
                    'at' => (string) $r->created_at,
                ])->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Payroll-impact preview: what a proposed change would do to one month's
     * payslips, WITHOUT saving anything.
     *
     * Recomputes each active employee's slip with the current rates and again
     * with the proposal merged on top, and reports the deltas. Read-only.
     *
     * @return array ['ok','employees','before','after','delta','rows'=>[...]]
     */
    public static function preview(?int $tenantId, ?int $companyId, array $proposal, string $month, int $limit = 200): array
    {
        try {
            if (! Schema::hasTable('employees')) {
                return ['ok' => false, 'error' => 'No employees table.'];
            }
            $known = array_merge(
                array_keys(\App\Http\Controllers\SettingsController::defaults()),
                self::EXTRA_KEYS
            );
            $delta = array_intersect_key($proposal, array_flip($known));

            $emps = DB::table('employees')
                ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
                ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
                ->whereNull('deleted_at')->where('status', 'active')
                ->limit($limit)->get();

            $branchCity = [];
            try {
                if (Schema::hasTable('branches')) {
                    $branchCity = DB::table('branches')
                        ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
                        ->pluck('city', 'id')->toArray();
                }
            } catch (\Throwable $ex) {
                $branchCity = [];
            }

            $rows = [];
            $sumBefore = 0.0;
            $sumAfter = 0.0;
            $counted = 0;
            foreach ($emps as $e) {
                $ctc = (float) ($e->ctc ?? 0);
                if ($ctc <= 0) {
                    continue;
                }
                $counted++;
                $stage = (string) ($e->employment_stage ?? '');
                // Each employee is priced under the rates that ACTUALLY apply to
                // them — their own state — so the preview reflects
                // scope precedence rather than a tenant-wide average.
                $bid = ((int) ($e->branch_id ?? 0)) ?: null;
                $bcity = $bid ? (string) ($branchCity[$bid] ?? '') : '';
                $now = self::resolve($tenantId, $companyId ?: ($e->company_id ?? null), $month.'-01', [
                    'state' => (string) ($e->pt_state ?? ''),
                    'branch_id' => $bid,
                    'branch_city' => $bcity,
                ]);
                $then = self::mergeValues($now, $delta);
                $ctx = [
                    'tenant_id' => $tenantId,
                    'company_id' => $companyId ?: ($e->company_id ?? null),
                    'pt_state' => (string) ($e->pt_state ?? ''),
                    'gender' => (string) ($e->gender ?? ''),
                    'branch_id' => $bid,
                    'branch_city' => $bcity,
                    'month' => $month,
                ];
                $a = \App\Http\Controllers\AppDataController::computeSlip($ctc, $now, $stage, $ctx);
                $b = \App\Http\Controllers\AppDataController::computeSlip($ctc, $then, $stage, $ctx);
                $sumBefore += (float) $a['net'];
                $sumAfter += (float) $b['net'];
                if (round((float) $a['net'], 2) !== round((float) $b['net'], 2)) {
                    $rows[] = [
                        'employee_id' => (int) $e->id,
                        'name' => $e->name ?? '',
                        'state' => (string) ($e->pt_state ?? ''),
                        'net_before' => round((float) $a['net'], 2),
                        'net_after' => round((float) $b['net'], 2),
                        'delta' => round((float) $b['net'] - (float) $a['net'], 2),
                    ];
                }
            }

            return [
                'ok' => true,
                'month' => $month,
                'employees' => $counted,
                'affected' => count($rows),
                'before' => round($sumBefore, 2),
                'after' => round($sumAfter, 2),
                'delta' => round($sumAfter - $sumBefore, 2),
                'rows' => array_slice($rows, 0, 100),
            ];
        } catch (\Throwable $e) {
            Log::warning('StatutoryConfig::preview failed: '.$e->getMessage());

            return ['ok' => false, 'error' => 'Could not compute the preview.'];
        }
    }
}
