<?php

namespace App\Http\Controllers;

use App\Services\AuditTrail;
use App\Services\EffectiveDated;
use App\Services\FeaturePermissions;
use App\Services\StatutoryConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * F1 — Statutory Configuration screen.
 *
 * The Statutory Rate Settings modal that already ships edits ONE tenant-wide set
 * of rates with no history. This screen sits beside it and adds the three things
 * a group operating across states actually needs:
 *
 *   SCOPE       a rate can apply to one branch, one location (branch city),
 *               one company, one state, or the whole tenant — narrowest
 *               winning, in that order (branch > location > company > state >
 *               tenant). Branch and location exist so a group can hold a
 *               different position at a single site or city; PT still varies by
 *               state in law.
 *
 *               Worth being plain about WHY the other kinds are scoped at all:
 *               only PROFESSIONAL TAX genuinely varies by state in law. PF, ESI
 *               and TDS are central Acts and identical across India, so their
 *               scope rows exist for a company's own business choices, never
 *               because the law differs.
 *   DATES       every change is effective from a date, so regenerating March
 *               after an April rate change still uses March's rates.
 *   PT SLABS    the 16 built-in state slabs and 10 PT-free states become
 *               editable, so a state that revises its slab — or one that is not
 *               compiled in at all — is a configuration change, not a release.
 *
 * Nothing here changes behaviour until a row is saved: with no rows, every
 * lookup returns the same rates the engine uses today.
 */
class StatutoryConfigController extends Controller
{
    /**
     * Which rate keys belong to each kind. A kind with no keys is not offered
     * for editing — 'tds_incentive' has no rate of its own yet and 'lwf' has no
     * keys in the rate blob, so neither appears in the picker rather than
     * showing an empty form that silently saves nothing.
     */
    public const FIELDS = [
        'pf' => [
            'pf_wage_cap' => ['PF wage cap (₹)', 'number'],
            'pf_rate' => ['PF rate (% each side)', 'number'],
        ],
        'esi' => [
            'esi_threshold' => ['ESI threshold (gross ≤ ₹)', 'number'],
            'esi_employee_rate' => ['ESI employee (%)', 'number'],
            'esi_employer_rate' => ['ESI employer (%)', 'number'],
        ],
        'pt' => [
            'pt_amount' => ['Professional Tax / month (₹) — fallback only', 'number'],
            'pt_female_exempt' => ['PT — exempt women (Maharashtra)', 'toggle'],
            'pt_female_exempt_upto' => ['PT female exemption up to (₹)', 'number'],
        ],
        'pt_slabs' => [],       // handled by the slab editor, not plain fields
        'tds_salary' => [
            'std_deduction' => ['Standard deduction (₹)', 'number'],
            'rebate_87a_limit' => ['87A rebate up to (₹)', 'number'],
            'cess_rate' => ['Health & education cess (%)', 'number'],
            'no_pan_tds_rate' => ['No-PAN higher TDS (%)', 'number'],
        ],
        'tds_commission' => [
            'comm_tds_rate' => ['Commission TDS 194H (%)', 'number'],
        ],
        'bonus' => [
            'bonus_pct' => ['Statutory bonus % (8.33–20)', 'number'],
            'conveyance_enabled' => ['Conveyance allowance', 'toggle'],
            'conveyance_rate' => ['Conveyance rate (% of basic, PF cap)', 'number'],
        ],
    ];

    /** Everything the screen needs to draw itself. */
    public function boot(Request $request)
    {
        if ($deny = FeaturePermissions::guard($request, 'statutory.config')) {
            return $deny;
        }
        try {
            StatutoryConfig::ensureSchema();
            $tid = $request->user()->tenant_id;

            $kinds = [];
            foreach (StatutoryConfig::KINDS as $k => $label) {
                if ($k === 'pt_slabs' || ! empty(self::FIELDS[$k])) {
                    $kinds[] = ['key' => $k, 'label' => $label, 'fields' => self::fieldList($k)];
                }
            }

            return response()->json([
                'ok' => true,
                'scopes' => StatutoryConfig::SCOPES,
                'kinds' => $kinds,
                'companies' => $this->companies($tid),
                'branches' => $this->branches($tid),
                'locations' => $this->locations($tid),
                'states' => $this->states($tid),
                'current' => $this->flatten(StatutoryConfig::resolve($tid, null, null)),
                'slabs' => $this->slabView($tid, null, null),
                'history' => StatutoryConfig::history($tid),
                'month' => now()->format('Y-m'),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => 'Could not load the statutory configuration.'], 200);
        }
    }

    /**
     * The rates in force for one scope on one date — refreshed whenever the
     * scope picker changes, so the form always shows what it is about to
     * override rather than a tenant-wide guess.
     */
    public function effective(Request $request)
    {
        if ($deny = FeaturePermissions::guard($request, 'statutory.config')) {
            return $deny;
        }
        try {
            $tid = $request->user()->tenant_id;
            $companyId = ((int) $request->query('company_id', 0)) ?: null;
            $on = (string) $request->query('on', '') ?: null;
            $ctx = [
                'state' => trim((string) $request->query('state', '')),
                'branch_id' => ((int) $request->query('branch_id', 0)) ?: null,
                'branch_city' => trim((string) $request->query('location', '')),
            ];

            return response()->json([
                'ok' => true,
                'current' => $this->flatten(StatutoryConfig::resolve($tid, $companyId, $on, $ctx)),
                'slabs' => $this->slabView($tid, $companyId, $on, $ctx),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => 'Could not resolve those rates.'], 200);
        }
    }

    /** Save one effective-dated, scoped override row. */
    public function save(Request $request)
    {
        if ($deny = FeaturePermissions::guard($request, 'statutory.config')) {
            return $deny;
        }
        try {
            $tid = $request->user()->tenant_id;
            $kind = (string) $request->input('kind', '');
            $eff = (string) $request->input('effective_from', '');

            // A rate change must not rewrite a month that is already paid.
            if ($deny = EffectiveDated::guardFinalisedPeriod(
                $request, $eff, $tid, ((int) $request->input('company_id', 0)) ?: null
            )) {
                return $deny;
            }

            $payload = $request->input('payload', []);
            $payload = is_array($payload) ? $payload : [];
            // Numeric fields arrive as strings from the form; cast so a "12"
            // does not become a string rate the engine then juggles.
            foreach ($payload as $k => $v) {
                if (is_string($v) && is_numeric($v)) {
                    $payload[$k] = $v + 0;
                }
            }

            $res = StatutoryConfig::save([
                'tenant_id' => $tid,
                'company_id' => $request->input('company_id'),
                'scope' => (string) $request->input('scope', 'tenant'),
                'scope_value' => (string) $request->input('scope_value', ''),
                'kind' => $kind,
                'payload' => $payload,
                'effective_from' => $eff,
                'note' => (string) $request->input('note', ''),
                'by' => optional($request->user())->name,
            ]);
            if (! ($res['ok'] ?? false)) {
                return response()->json(['ok' => false, 'error' => $res['error'] ?? 'Could not save.'], 200);
            }

            AuditTrail::log(
                $request,
                'statutory_config.save',
                'statutory_configs',
                null,
                trim($kind.' · '.(string) $request->input('scope', 'tenant').' '
                    .(string) $request->input('scope_value', '').' · from '.($res['effective_from'] ?? '')
                    .' · '.json_encode($res['saved'] ?? []))
            );

            return response()->json([
                'ok' => true,
                'message' => 'Saved. In force from '.($res['effective_from'] ?? '').'.',
                'history' => StatutoryConfig::history($tid),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => 'Could not save the configuration.'], 200);
        }
    }

    /** What a proposed change would do to one month's payslips. Read-only. */
    public function preview(Request $request)
    {
        if ($deny = FeaturePermissions::guard($request, 'statutory.config')) {
            return $deny;
        }
        try {
            $tid = $request->user()->tenant_id;
            $payload = $request->input('payload', []);
            $payload = is_array($payload) ? $payload : [];
            foreach ($payload as $k => $v) {
                if (is_string($v) && is_numeric($v)) {
                    $payload[$k] = $v + 0;
                }
            }
            $month = (string) $request->input('month', '') ?: now()->format('Y-m');
            if (! preg_match('/^\d{4}-\d{2}$/', $month)) {
                $month = now()->format('Y-m');
            }

            return response()->json(StatutoryConfig::preview(
                $tid,
                ((int) $request->input('company_id', 0)) ?: null,
                $payload,
                $month
            ));
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => 'Could not compute the preview.'], 200);
        }
    }

    /**
     * Save a Professional Tax slab table for one state. Written through the same
     * effective-dated, scoped store as every other rate, under the 'pt_slabs'
     * kind, so a slab correction has the same history and the same protection
     * against rewriting a finalised month.
     */
    public function saveSlab(Request $request)
    {
        if ($deny = FeaturePermissions::guard($request, 'statutory.config')) {
            return $deny;
        }
        try {
            $tid = $request->user()->tenant_id;
            $state = trim((string) $request->input('state', ''));
            if ($state === '') {
                return response()->json(['ok' => false, 'error' => 'Name the state this slab applies to.'], 200);
            }
            $eff = (string) $request->input('effective_from', '');
            if ($deny = EffectiveDated::guardFinalisedPeriod($request, $eff, $tid, null)) {
                return $deny;
            }

            $free = (bool) $request->input('pt_free', false);
            if ($free) {
                $payload = ['pt_free_states' => [mb_strtolower($state)]];
            } else {
                $bands = $request->input('bands', []);
                $rows = [];
                foreach (is_array($bands) ? $bands : [] as $b) {
                    $upto = (float) ($b['upto'] ?? 0);
                    $amt = (float) ($b['amt'] ?? 0);
                    if ($amt < 0 || $upto < 0) {
                        return response()->json(['ok' => false, 'error' => 'A slab cannot carry a negative amount.'], 200);
                    }
                    $rows[] = [$upto, $amt];
                }
                if (! $rows) {
                    return response()->json(['ok' => false, 'error' => 'Add at least one band.'], 200);
                }
                $payload = ['pt_state_slabs' => [mb_strtolower($state) => $rows]];
            }

            $res = StatutoryConfig::save([
                'tenant_id' => $tid,
                'company_id' => $request->input('company_id'),
                'scope' => (string) $request->input('scope', 'tenant'),
                'scope_value' => (string) $request->input('scope_value', ''),
                'kind' => 'pt_slabs',
                'payload' => $payload,
                'effective_from' => $eff,
                'note' => (string) $request->input('note', '') ?: ('PT slab — '.$state),
                'by' => optional($request->user())->name,
            ]);
            if (! ($res['ok'] ?? false)) {
                return response()->json(['ok' => false, 'error' => $res['error'] ?? 'Could not save.'], 200);
            }

            AuditTrail::log($request, 'statutory_config.slab', 'statutory_configs', null,
                $state.' · '.($free ? 'PT-free' : json_encode($payload['pt_state_slabs'] ?? [])).' · from '.($res['effective_from'] ?? ''));

            return response()->json([
                'ok' => true,
                'message' => 'PT slab saved for '.$state.', in force from '.($res['effective_from'] ?? '').'.',
                'slabs' => $this->slabView($tid, null, null),
                'history' => StatutoryConfig::history($tid),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => 'Could not save the slab.'], 200);
        }
    }

    // ---------------------------------------------------------------- helpers

    private static function fieldList(string $kind): array
    {
        $out = [];
        foreach (self::FIELDS[$kind] ?? [] as $key => [$label, $type]) {
            $out[] = ['key' => $key, 'label' => $label, 'type' => $type];
        }

        return $out;
    }

    /** Only the keys this screen edits — the full blob is far larger. */
    private function flatten(array $rates): array
    {
        $out = [];
        foreach (self::FIELDS as $keys) {
            foreach ($keys as $key => $_) {
                $out[$key] = $rates[$key] ?? null;
            }
        }

        return $out;
    }

    /**
     * The PT slab table as it stands: every built-in state, plus any state added
     * by configuration, each marked with where its figures come from so the
     * screen can show "built in" against "changed by you".
     */
    private function slabView(?int $tid, ?int $companyId, $on = null, array $ctx = []): array
    {
        $rates = StatutoryConfig::resolve($tid, $companyId, $on, $ctx);
        $custom = is_array($rates['pt_state_slabs'] ?? null) ? $rates['pt_state_slabs'] : [];
        $customFree = is_array($rates['pt_free_states'] ?? null) ? $rates['pt_free_states'] : [];
        $customFree = array_map(fn ($s) => mb_strtolower(trim((string) $s)), $customFree);

        $builtIn = AppDataController::ptStateSlabs();
        $builtInFree = AppDataController::ptFreeStates();

        $rows = [];
        foreach ($builtIn as $state => $bands) {
            $overridden = isset($custom[$state]);
            $rows[$state] = [
                'state' => $state,
                'bands' => array_map(fn ($b) => ['upto' => (float) $b[0], 'amt' => (float) $b[1]],
                    $overridden ? $custom[$state] : $bands),
                'pt_free' => in_array($state, $customFree, true),
                'source' => $overridden ? 'configured' : 'built-in',
            ];
        }
        foreach ($builtInFree as $state) {
            if (! isset($rows[$state])) {
                $rows[$state] = ['state' => $state, 'bands' => [], 'pt_free' => true, 'source' => 'built-in'];
            }
        }
        foreach ($custom as $state => $bands) {
            if (! isset($rows[$state])) {
                $rows[$state] = [
                    'state' => $state,
                    'bands' => array_map(fn ($b) => ['upto' => (float) $b[0], 'amt' => (float) $b[1]], $bands),
                    'pt_free' => false,
                    'source' => 'added',
                ];
            }
        }
        foreach ($customFree as $state) {
            if (! isset($rows[$state])) {
                $rows[$state] = ['state' => $state, 'bands' => [], 'pt_free' => true, 'source' => 'added'];
            } else {
                $rows[$state]['pt_free'] = true;
            }
        }
        ksort($rows);

        return array_values($rows);
    }

    private function companies(?int $tid): array
    {
        try {
            if (! Schema::hasTable('companies')) {
                return [];
            }

            return DB::table('companies')
                ->when($tid, fn ($q) => $q->where('tenant_id', $tid))
                ->orderBy('name')->limit(200)
                ->get(['id', 'name'])
                ->map(fn ($c) => ['id' => (int) $c->id, 'name' => (string) $c->name])->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** States worth offering: those the engine knows, plus those in use. */
    private function states(?int $tid): array
    {
        $out = array_merge(
            array_keys(AppDataController::ptStateSlabs()),
            AppDataController::ptFreeStates()
        );
        try {
            if (Schema::hasTable('employees') && Schema::hasColumn('employees', 'pt_state')) {
                $used = DB::table('employees')
                    ->when($tid, fn ($q) => $q->where('tenant_id', $tid))
                    ->whereNotNull('pt_state')->where('pt_state', '<>', '')
                    ->distinct()->limit(100)->pluck('pt_state')->all();
                foreach ($used as $s) {
                    $out[] = mb_strtolower(trim((string) $s));
                }
            }
        } catch (\Throwable $e) {
            // a missing column must not empty the picker
        }
        $out = array_values(array_unique(array_filter($out)));
        sort($out);

        return $out;
    }

    /** Branches for the picker (branch scope). Value saved is the branch id. */
    private function branches(?int $tid): array
    {
        try {
            if (! Schema::hasTable('branches')) {
                return [];
            }

            return DB::table('branches')
                ->when($tid, fn ($q) => $q->where('tenant_id', $tid))
                ->when(Schema::hasColumn('branches', 'deleted_at'), fn ($q) => $q->whereNull('deleted_at'))
                ->orderBy('name')->limit(500)
                ->get(['id', 'name', 'city', 'company_id'])
                ->map(fn ($b) => [
                    'id' => (int) $b->id,
                    'name' => (string) $b->name,
                    'city' => (string) ($b->city ?? ''),
                    'company_id' => (int) ($b->company_id ?? 0),
                ])->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** Distinct branch cities for the picker (location scope). */
    private function locations(?int $tid): array
    {
        try {
            if (! Schema::hasTable('branches')) {
                return [];
            }
            $out = DB::table('branches')
                ->when($tid, fn ($q) => $q->where('tenant_id', $tid))
                ->whereNotNull('city')->where('city', '<>', '')
                ->distinct()->limit(300)->pluck('city')
                ->map(fn ($c) => trim((string) $c))->filter()->unique()->values()->all();
            sort($out);

            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

}
