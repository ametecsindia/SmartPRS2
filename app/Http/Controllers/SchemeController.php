<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * rev 115 (8 Jun 2026) — COMMISSION & INCENTIVE SCHEMES (Ejaz's "depth engine").
 * Predefined offers that managers publish and agents claim against:
 *   - Created by Admin/HR (anyone), Manager (own reportees), Team Leader (own team)
 *     — hierarchy-scoped, mirroring the approval chain.
 *   - Rate types: percent (agent enters collected base → gross auto), fixed ₹
 *     per claim, open (prefill template, agent types gross).
 *   - Validity window + Withdraw anytime; optional per-employee caps
 *     (max claims / max ₹ gross).
 *   - On publish: targeted employees notified (email + WhatsApp 'announcement'
 *     template + Notice Board) and the Live Salary card shows an orange ribbon.
 *   - Claims flow through the EXISTING commission entry + hierarchy approval;
 *     entries are stamped scheme_id so each scheme reports its own ROI.
 */
class SchemeController extends Controller
{
    // ---- schema -------------------------------------------------------------
    public static function ensure(): void
    {
        try {
            if (! Schema::hasTable('incentive_schemes')) {
                Schema::create('incentive_schemes', function (Blueprint $t) {
                    $t->id();
                    $t->unsignedBigInteger('tenant_id')->nullable()->index();
                    $t->string('title', 150);
                    $t->string('purpose', 80)->nullable();        // prefills the claim Purpose
                    $t->string('portfolio', 120)->nullable();     // prefills Portfolio / Bank
                    $t->string('rate_type', 10)->default('open'); // percent | fixed | open
                    $t->decimal('rate_value', 12, 2)->nullable(); // % or ₹ per claim
                    $t->decimal('tds_rate', 5, 2)->nullable();    // null = claim default
                    $t->string('payout_method', 15)->default('with_salary'); // with_salary | separate
                    $t->date('valid_from')->nullable();
                    $t->date('valid_till')->nullable();
                    $t->string('applies_to', 10)->default('all'); // all | team | selected
                    $t->string('team', 120)->nullable();          // team name when applies_to=team
                    $t->text('employee_ids')->nullable();         // CSV ids when applies_to=selected
                    $t->integer('max_claims')->nullable();        // per employee; null = unlimited
                    $t->decimal('max_amount', 14, 2)->nullable(); // per employee gross cap
                    $t->string('status', 12)->default('active');  // active | withdrawn
                    $t->string('notes', 255)->nullable();
                    $t->string('created_by', 120)->nullable();
                    $t->unsignedBigInteger('created_by_emp')->nullable();
                    $t->timestamps();
                });
            }
            // rev 115e: the PRE-rev-51 'incentive-schemes' MASTER may have created
            // this table already (name/portfolio/basis/clawback shape) — heal EVERY
            // column individually instead of assuming our Schema::create ran.
            if (Schema::hasTable('incentive_schemes')) {
                $cols = [
                    'title' => fn (Blueprint $t) => $t->string('title', 150)->nullable(),
                    'purpose' => fn (Blueprint $t) => $t->string('purpose', 80)->nullable(),
                    'portfolio' => fn (Blueprint $t) => $t->string('portfolio', 120)->nullable(),
                    'rate_type' => fn (Blueprint $t) => $t->string('rate_type', 10)->default('open'),
                    'rate_value' => fn (Blueprint $t) => $t->decimal('rate_value', 12, 2)->nullable(),
                    'tds_rate' => fn (Blueprint $t) => $t->decimal('tds_rate', 5, 2)->nullable(),
                    'payout_method' => fn (Blueprint $t) => $t->string('payout_method', 15)->default('with_salary'),
                    'valid_from' => fn (Blueprint $t) => $t->date('valid_from')->nullable(),
                    'valid_till' => fn (Blueprint $t) => $t->date('valid_till')->nullable(),
                    'applies_to' => fn (Blueprint $t) => $t->string('applies_to', 10)->default('all'),
                    'team' => fn (Blueprint $t) => $t->string('team', 120)->nullable(),
                    'employee_ids' => fn (Blueprint $t) => $t->text('employee_ids')->nullable(),
                    'max_claims' => fn (Blueprint $t) => $t->integer('max_claims')->nullable(),
                    'max_amount' => fn (Blueprint $t) => $t->decimal('max_amount', 14, 2)->nullable(),
                    'status' => fn (Blueprint $t) => $t->string('status', 12)->default('active'),
                    'notes' => fn (Blueprint $t) => $t->string('notes', 255)->nullable(),
                    'created_by' => fn (Blueprint $t) => $t->string('created_by', 120)->nullable(),
                    'created_by_emp' => fn (Blueprint $t) => $t->unsignedBigInteger('created_by_emp')->nullable(),
                    'tenant_id' => fn (Blueprint $t) => $t->unsignedBigInteger('tenant_id')->nullable()->index(),
                    // rev 115d approval hierarchy:
                    'approval_status' => fn (Blueprint $t) => $t->string('approval_status', 12)->default('approved'),
                    'approver' => fn (Blueprint $t) => $t->string('approver', 120)->nullable(),
                    'approver_emp' => fn (Blueprint $t) => $t->unsignedBigInteger('approver_emp')->nullable(),
                    'decided_by' => fn (Blueprint $t) => $t->string('decided_by', 120)->nullable(),
                    'decided_at' => fn (Blueprint $t) => $t->timestamp('decided_at')->nullable(),
                    'reject_reason' => fn (Blueprint $t) => $t->string('reject_reason', 255)->nullable(),
                    // rev 116b: per-channel announcement choice (CSV: email,wa,notice).
                    'notify_channels' => fn (Blueprint $t) => $t->string('notify_channels', 40)->nullable(),
                ];
                foreach ($cols as $name => $add) {
                    if (! Schema::hasColumn('incentive_schemes', $name)) {
                        try {
                            Schema::table('incentive_schemes', $add);
                        } catch (\Throwable $e) {
                        }
                    }
                }
                // Legacy columns (name/basis/clawback/company_*) may be NOT NULL —
                // relax them, and widen any legacy ENUM status so 'withdrawn' fits
                // (rev 79b lesson: original-migration enums are the fresh-install minefield).
                try {
                    foreach (DB::select('SHOW COLUMNS FROM incentive_schemes') as $c) {
                        $f = $c->Field ?? '';
                        $type = (string) ($c->Type ?? '');
                        if ($f === 'status' && stripos($type, 'enum') === 0) {
                            DB::statement('ALTER TABLE incentive_schemes MODIFY `status` VARCHAR(20) NULL DEFAULT \'active\'');
                            continue;
                        }
                        if (in_array($f, ['name', 'basis', 'clawback', 'company_id', 'company_name'], true)
                            && ($c->Null ?? '') === 'NO' && ($c->Key ?? '') !== 'PRI') {
                            DB::statement('ALTER TABLE incentive_schemes MODIFY `'.$f.'` '.$type.' NULL');
                        }
                    }
                } catch (\Throwable $e) {
                    Log::warning('incentive_schemes legacy relax: '.$e->getMessage());
                }
            }
            // Claims carry the scheme + the collected base they were computed from.
            if (Schema::hasTable('commissions')) {
                if (! Schema::hasColumn('commissions', 'scheme_id')) {
                    Schema::table('commissions', fn (Blueprint $t) => $t->unsignedBigInteger('scheme_id')->nullable()->index());
                }
                if (! Schema::hasColumn('commissions', 'base_amount')) {
                    Schema::table('commissions', fn (Blueprint $t) => $t->decimal('base_amount', 14, 2)->nullable());
                }
            }
        } catch (\Throwable $e) {
            Log::warning('SchemeController ensure: '.$e->getMessage());
        }
    }

    // ---- helpers ------------------------------------------------------------
    private function tid(Request $request): ?int
    {
        return $request->user()->tenant_id ?? DB::table('tenants')->value('id');
    }

    private function isAdminish(Request $request): bool
    {
        return $request->user()->hasAnyRole(['super_admin', 'admin', 'hr_manager']);
    }

    /** The signed-in user's employee record (id link first, email/name fallback). */
    private function me(Request $request)
    {
        $user = $request->user();
        $tid = $user->tenant_id;
        if (! empty($user->employee_id)) {
            $byId = DB::table('employees')->where('id', $user->employee_id)->whereNull('deleted_at')->first();
            if ($byId) {
                return $byId;
            }
        }

        return DB::table('employees')
            ->when($tid, fn ($q) => $q->where('tenant_id', $tid))
            ->whereNull('deleted_at')
            ->where(function ($q) use ($user) {
                $q->whereRaw('LOWER(email) = ?', [strtolower((string) $user->email)])
                    ->orWhere('name', $user->name);
            })->first();
    }

    /** Employees this person LEADS — own reportees + own team members. */
    private function myPeople(?object $me, ?int $tid)
    {
        if (! $me) {
            return collect();
        }

        return DB::table('employees')
            ->when($tid, fn ($q) => $q->where('tenant_id', $tid))
            ->whereNull('deleted_at')
            ->where(function ($q) use ($me) {
                $q->where('reporting_manager_id', $me->id)
                    ->orWhere('reporting_manager', $me->name)
                    ->orWhere('team_leader', $me->name);
            })->get(['id', 'name', 'emp_code', 'team', 'mobile', 'email']);
    }

    /** Is the scheme live today (approved + active + inside its window)? */
    private static function isLive(object $s): bool
    {
        if (($s->status ?? '') !== 'active') {
            return false;
        }
        // rev 115d: pending/rejected schemes are never claimable (legacy null = approved).
        if (($s->approval_status ?? 'approved') !== 'approved') {
            return false;
        }
        $today = now()->toDateString();
        if ($s->valid_from && $today < $s->valid_from) {
            return false;
        }
        if ($s->valid_till && $today > $s->valid_till) {
            return false;
        }

        return true;
    }

    /** Does the scheme apply to this employee? */
    public static function appliesTo(object $s, object $emp): bool
    {
        if ($s->applies_to === 'all') {
            return true;
        }
        if ($s->applies_to === 'team') {
            return trim(strtolower((string) ($emp->team ?? ''))) === trim(strtolower((string) $s->team));
        }
        $ids = array_filter(array_map('intval', explode(',', (string) $s->employee_ids)));

        return in_array((int) $emp->id, $ids, true);
    }

    /** Remaining cap for an employee on a scheme: [claimsLeft|null, amountLeft|null]. */
    public static function capLeft(object $s, int $empId): array
    {
        $claimsLeft = null;
        $amountLeft = null;
        if ($s->max_claims || $s->max_amount) {
            $q = DB::table('commissions')->where('scheme_id', $s->id)->where('employee_id', $empId)
                ->where(function ($w) {
                    $w->whereNull('status')->orWhere('status', '<>', 'rejected');
                });
            if ($s->max_claims) {
                $claimsLeft = max(0, (int) $s->max_claims - (clone $q)->count());
            }
            if ($s->max_amount) {
                $amountLeft = max(0.0, round((float) $s->max_amount - (float) (clone $q)->sum('gross_amount'), 2));
            }
        }

        return [$claimsLeft, $amountLeft];
    }

    /**
     * Called by RequestController::apply when a claim carries scheme_id.
     * Validates + returns server-computed overrides. Throws RuntimeException
     * with a FRIENDLY message on any violation.
     */
    public static function validateClaim(int $schemeId, object $emp, array $fields): array
    {
        self::ensure();
        $s = DB::table('incentive_schemes')->where('id', $schemeId)->first();
        if (! $s) {
            throw new \RuntimeException('That scheme no longer exists — refresh and try again.');
        }
        if (! self::isLive($s)) {
            throw new \RuntimeException('The scheme "'.$s->title.'" is not active any more (expired or withdrawn).');
        }
        if (! self::appliesTo($s, $emp)) {
            throw new \RuntimeException('The scheme "'.$s->title.'" does not apply to '.$emp->name.'.');
        }
        [$claimsLeft, $amountLeft] = self::capLeft($s, (int) $emp->id);
        if ($claimsLeft !== null && $claimsLeft <= 0) {
            throw new \RuntimeException('Claim limit reached — "'.$s->title.'" allows '.$s->max_claims.' claim(s) per person.');
        }

        $out = ['scheme_id' => (int) $s->id];
        if ($s->purpose) {
            $out['purpose'] = $s->purpose;
        }
        if ($s->portfolio && empty($fields['portfolio'])) {
            $out['portfolio'] = $s->portfolio;
        }
        if ($s->tds_rate !== null) {
            $out['tds_rate'] = (float) $s->tds_rate;
        }
        $out['payout_method'] = $s->payout_method ?: 'with_salary';

        // The money — computed HERE, never trusted from the browser.
        if ($s->rate_type === 'percent') {
            $base = isset($fields['base_amount']) && is_numeric($fields['base_amount']) ? (float) $fields['base_amount'] : 0.0;
            if ($base <= 0) {
                throw new \RuntimeException('Enter the collected amount — "'.$s->title.'" pays '.rtrim(rtrim(number_format((float) $s->rate_value, 2), '0'), '.').'% of it.');
            }
            $out['base_amount'] = round($base, 2);
            $out['gross_amount'] = round($base * (float) $s->rate_value / 100, 2);
        } elseif ($s->rate_type === 'fixed') {
            $out['gross_amount'] = round((float) $s->rate_value, 2);
        } else {
            $gross = isset($fields['gross_amount']) && is_numeric($fields['gross_amount']) ? (float) $fields['gross_amount'] : 0.0;
            if ($gross <= 0) {
                throw new \RuntimeException('Enter the gross amount for this claim.');
            }
            $out['gross_amount'] = round($gross, 2);
        }
        if ($amountLeft !== null && $out['gross_amount'] > $amountLeft + 0.005) {
            throw new \RuntimeException('This claim (₹'.number_format($out['gross_amount'], 2).') crosses the scheme\'s per-person cap — ₹'.number_format($amountLeft, 2).' remaining for '.$emp->name.'.');
        }

        return $out;
    }

    // ---- endpoints ----------------------------------------------------------

    /** GET /app/schemes — management list (admins: all; others: own-created). */
    public function index(Request $request)
    {
        self::ensure();
        $tid = $this->tid($request);
        $me = $this->me($request);
        $adminish = $this->isAdminish($request);
        $rows = DB::table('incentive_schemes')
            ->when($tid, fn ($q) => $q->where('tenant_id', $tid))
            ->when(! $adminish, function ($q) use ($me, $request) {
                $q->where(function ($w) use ($me, $request) {
                    $w->where('created_by', (string) ($request->user()->name ?? ''));
                    if ($me) {
                        $w->orWhere('created_by_emp', $me->id);
                        // rev 115d: approvers see schemes waiting on THEM.
                        $w->orWhere('approver_emp', $me->id);
                    }
                });
            })
            ->orderByDesc('id')->limit(300)->get();

        // Per-scheme claim stats in ONE grouped query (+ pending count for the
        // withdraw decision; rev 115g).
        $stats = DB::table('commissions')->whereIn('scheme_id', $rows->pluck('id')->all() ?: [0])
            ->selectRaw("scheme_id, COUNT(*) c, SUM(CASE WHEN status='approved' THEN 1 ELSE 0 END) ap, SUM(CASE WHEN status='approved' THEN COALESCE(gross_amount,0) ELSE 0 END) apg, SUM(CASE WHEN status='pending' OR status IS NULL THEN 1 ELSE 0 END) pc")
            ->groupBy('scheme_id')->get()->keyBy('scheme_id');

        // rev 115g: emp ids → codes for edit-prefill (the boot UI is code-keyed).
        $allIds = [];
        foreach ($rows as $r0) {
            foreach (array_filter(array_map('intval', explode(',', (string) $r0->employee_ids))) as $i0) {
                $allIds[$i0] = 1;
            }
        }
        $codeOf = $allIds ? DB::table('employees')->whereIn('id', array_keys($allIds))->pluck('emp_code', 'id') : collect();

        $today = now()->toDateString();
        $meName = (string) ($request->user()->name ?? '');
        $out = $rows->map(function ($s) use ($stats, $today, $adminish, $me, $codeOf, $meName) {
            $st = $stats[$s->id] ?? null;
            $appr = $s->approval_status ?? 'approved';
            $state = $s->status !== 'active' ? 'withdrawn'
                : ($appr === 'pending' ? 'pending'
                : ($appr === 'rejected' ? 'rejected'
                : (($s->valid_till && $today > $s->valid_till) ? 'expired'
                : (($s->valid_from && $today < $s->valid_from) ? 'upcoming' : 'active'))));

            $ids = array_filter(array_map('intval', explode(',', (string) $s->employee_ids)));
            $claims = (int) ($st->c ?? 0);
            $own = $s->created_by === $meName || ($me && (int) ($s->created_by_emp ?? 0) === (int) $me->id);

            return [
                'approvalStatus' => $appr, 'approver' => $s->approver ?? null,
                'rejectReason' => $s->reject_reason ?? null, 'decidedBy' => $s->decided_by ?? null,
                'decidedAt' => ! empty($s->decided_at) ? \Carbon\Carbon::parse($s->decided_at)->format('d M Y H:i') : null,
                'createdAt' => ! empty($s->created_at) ? \Carbon\Carbon::parse($s->created_at)->format('d M Y H:i') : null,
                'canDecide' => $appr === 'pending' && ($adminish || ($me && (int) ($s->approver_emp ?? 0) === (int) $me->id)),
                // rev 117: edit allowed even with claims (unlocked ones recalc);
                // creator or admin. Withdrawn schemes get Re-open instead.
                'canEdit' => $s->status === 'active' && ($adminish || $own),
                'canReopen' => $s->status === 'withdrawn' && ($adminish || $own),
                'id' => $s->id, 'title' => $s->title, 'purpose' => $s->purpose, 'portfolio' => $s->portfolio,
                'rateType' => $s->rate_type, 'rateValue' => (float) ($s->rate_value ?? 0), 'tdsRate' => $s->tds_rate !== null ? (float) $s->tds_rate : null,
                'payoutMethod' => $s->payout_method, 'from' => $s->valid_from, 'till' => $s->valid_till,
                'appliesTo' => $s->applies_to, 'team' => $s->team,
                'employeeIds' => $ids,
                'employeeCodes' => array_values(array_filter(array_map(fn ($i) => $codeOf[$i] ?? null, $ids))),
                'maxClaims' => $s->max_claims, 'maxAmount' => $s->max_amount !== null ? (float) $s->max_amount : null,
                'notifyChannels' => array_filter(explode(',', (string) ($s->notify_channels ?? ''))),
                'state' => $state, 'notes' => $s->notes, 'createdBy' => $s->created_by,
                'claims' => $claims, 'approved' => (int) ($st->ap ?? 0), 'approvedGross' => (float) ($st->apg ?? 0),
                'pendingClaims' => (int) ($st->pc ?? 0),
            ];
        })->values();

        return response()->json(['ok' => true, 'rows' => $out, 'canAll' => $adminish, 'me' => $me ? $me->name : null]);
    }

    /** POST /app/schemes — create + announce. Hierarchy-scoped. */
    public function save(Request $request)
    {
        try {
            self::ensure();
            $tid = $this->tid($request);
            $me = $this->me($request);
            $adminish = $this->isAdminish($request);
            $v = $request->validate([
                'id' => ['nullable', 'integer'],   // rev 115g: present = EDIT
                'title' => ['required', 'string', 'max:150'],
                'purpose' => ['nullable', 'string', 'max:80'],
                'portfolio' => ['nullable', 'string', 'max:120'],
                'rate_type' => ['required', 'in:percent,fixed,open'],
                'rate_value' => ['nullable', 'numeric', 'min:0'],
                'tds_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
                'payout_method' => ['nullable', 'in:with_salary,separate'],
                'valid_from' => ['nullable', 'date'],
                'valid_till' => ['nullable', 'date'],
                'applies_to' => ['required', 'in:all,team,selected'],
                'team' => ['nullable', 'string', 'max:120'],
                'employee_ids' => ['nullable', 'array'],
                'max_claims' => ['nullable', 'integer', 'min:1'],
                'max_amount' => ['nullable', 'numeric', 'min:1'],
                'notes' => ['nullable', 'string', 'max:255'],
                'notify' => ['nullable'],
                // rev 116b (Ejaz): per-channel announcement selection.
                'notify_email' => ['nullable'],
                'notify_wa' => ['nullable'],
                'notify_notice' => ['nullable'],
                // The boot UI knows employees by emp_code (DB.employees[].id IS the
                // code — rev 81b lesson); we resolve codes → numeric ids here.
                'employee_codes' => ['nullable', 'array'],
            ]);
            // Channel choice (back-compat: old 'notify'=true means all three).
            $channels = [];
            if ($request->boolean('notify_email')) {
                $channels[] = 'email';
            }
            if ($request->boolean('notify_wa')) {
                $channels[] = 'wa';
            }
            if ($request->boolean('notify_notice')) {
                $channels[] = 'notice';
            }
            if (! $channels && $request->boolean('notify') && ! $request->has('notify_email')) {
                $channels = ['email', 'wa', 'notice'];
            }
            $channelsCsv = implode(',', $channels);
            if (! empty($v['employee_codes'])) {
                $codes = array_filter(array_map('strval', (array) $v['employee_codes']));
                $resolved = DB::table('employees')
                    ->when($tid, fn ($q) => $q->where('tenant_id', $tid))
                    ->whereNull('deleted_at')->whereIn('emp_code', $codes)->pluck('id')->map(fn ($i) => (int) $i)->all();
                $v['employee_ids'] = array_values(array_unique(array_merge((array) ($v['employee_ids'] ?? []), $resolved)));
            }
            if (in_array($v['rate_type'], ['percent', 'fixed'], true) && (float) ($v['rate_value'] ?? 0) <= 0) {
                return response()->json(['ok' => false, 'error' => $v['rate_type'] === 'percent' ? 'Enter the percentage the scheme pays.' : 'Enter the fixed ₹ amount per claim.'], 422);
            }
            if ($v['rate_type'] === 'percent' && (float) $v['rate_value'] > 100) {
                return response()->json(['ok' => false, 'error' => 'A percentage rate cannot exceed 100.'], 422);
            }
            if (! empty($v['valid_from']) && ! empty($v['valid_till']) && $v['valid_till'] < $v['valid_from']) {
                return response()->json(['ok' => false, 'error' => 'The end date is before the start date.'], 422);
            }

            // HIERARCHY SCOPE (Ejaz): TL/manager may target ONLY their own people.
            $selIds = array_filter(array_map('intval', (array) ($v['employee_ids'] ?? [])));
            if (! $adminish) {
                if (! $me) {
                    return response()->json(['ok' => false, 'error' => 'Your login is not linked to an employee record — ask HR to link it.'], 403);
                }
                $mine = $this->myPeople($me, $tid);
                if ($mine->isEmpty()) {
                    return response()->json(['ok' => false, 'error' => 'Only managers / team leaders with reportees can create schemes.'], 403);
                }
                if ($v['applies_to'] === 'all') {
                    return response()->json(['ok' => false, 'error' => '"All employees" schemes need HR or Admin. You can target your own team / reportees.'], 403);
                }
                if ($v['applies_to'] === 'team') {
                    $myTeams = $mine->pluck('team')->filter()->map(fn ($t) => strtolower(trim($t)))->unique();
                    if (! $myTeams->contains(strtolower(trim((string) ($v['team'] ?? ''))))) {
                        return response()->json(['ok' => false, 'error' => 'You can create team schemes only for a team you lead.'], 403);
                    }
                }
                if ($v['applies_to'] === 'selected') {
                    $mineIds = $mine->pluck('id')->map(fn ($i) => (int) $i)->all();
                    $outside = array_diff($selIds, $mineIds);
                    if ($outside) {
                        return response()->json(['ok' => false, 'error' => 'You can select only your own reportees / team members.'], 403);
                    }
                }
            }
            if ($v['applies_to'] === 'team' && empty($v['team'])) {
                return response()->json(['ok' => false, 'error' => 'Pick the team this scheme is for.'], 422);
            }
            if ($v['applies_to'] === 'selected' && ! $selIds) {
                return response()->json(['ok' => false, 'error' => 'Select at least one employee.'], 422);
            }

            // rev 115d: APPROVAL HIERARCHY — Admin/HR schemes are live instantly;
            // a TL/Manager's scheme waits for their OWN reporting manager
            // (fallback: Admin/HR when no manager is linked).
            $apprStatus = 'approved';
            $apprName = null;
            $apprEmp = null;
            if (! $adminish) {
                $apprStatus = 'pending';
                $apprName = 'Admin / HR';
                if ($me && ! empty($me->reporting_manager_id)) {
                    $mgr = DB::table('employees')->where('id', $me->reporting_manager_id)->whereNull('deleted_at')->first();
                    if ($mgr) {
                        $apprName = $mgr->name;
                        $apprEmp = (int) $mgr->id;
                    }
                } elseif ($me && ! empty($me->reporting_manager)) {
                    $apprName = (string) $me->reporting_manager;
                    $mgr2 = DB::table('employees')->when($tid, fn ($q) => $q->where('tenant_id', $tid))
                        ->whereNull('deleted_at')->where('name', $me->reporting_manager)->first();
                    if ($mgr2) {
                        $apprEmp = (int) $mgr2->id;
                    }
                }
            }

            // ---- rev 115g: EDIT — only while nothing is claimed; a non-admin's
            // edit goes BACK through approval (rates may have changed).
            if (! empty($v['id'])) {
                $old = DB::table('incentive_schemes')->where('id', (int) $v['id'])
                    ->when($tid, fn ($q) => $q->where('tenant_id', $tid))->first();
                if (! $old) {
                    return response()->json(['ok' => false, 'error' => 'Scheme not found.'], 404);
                }
                $own = $old->created_by === (string) ($request->user()->name ?? '') || ($me && (int) ($old->created_by_emp ?? 0) === (int) $me->id);
                if (! $adminish && ! $own) {
                    return response()->json(['ok' => false, 'error' => 'Only the scheme creator or an Admin can edit it.'], 403);
                }
                // rev 117 (Ejaz): editing WITH claims is allowed — unlocked claims
                // are RECALCULATED to the new rates (approved ones go back to
                // Pending for re-approval; locked/payslip ones never change).
                $upd = ApprovalService::safeRow('incentive_schemes', [
                    'title' => trim($v['title']), 'name' => trim($v['title']),
                    'purpose' => $v['purpose'] ?? null, 'portfolio' => $v['portfolio'] ?? null,
                    'rate_type' => $v['rate_type'], 'rate_value' => $v['rate_value'] ?? null,
                    'tds_rate' => $v['tds_rate'] ?? null,
                    'payout_method' => $v['payout_method'] ?? 'with_salary',
                    'valid_from' => $v['valid_from'] ?? $old->valid_from,
                    'valid_till' => $v['valid_till'] ?? null,
                    'applies_to' => $v['applies_to'], 'team' => $v['team'] ?? null,
                    'employee_ids' => $selIds ? implode(',', $selIds) : null,
                    'max_claims' => $v['max_claims'] ?? null, 'max_amount' => $v['max_amount'] ?? null,
                    'status' => 'active',
                    'notify_channels' => $channelsCsv,
                    'updated_at' => now(),
                ]);
                if (! $adminish) {
                    // Re-approval: same approver resolution as a fresh scheme.
                    $upd['approval_status'] = 'pending';
                    $upd['approver'] = $apprName;
                    $upd['approver_emp'] = $apprEmp;
                    $upd['decided_by'] = null;
                    $upd['decided_at'] = null;
                    $upd['reject_reason'] = null;
                } else {
                    $upd['approval_status'] = 'approved';
                }
                DB::table('incentive_schemes')->where('id', $old->id)->update($upd);

                if (! $adminish) {
                    // Recalc waits for the approver — rates aren't official yet.
                    return response()->json(['ok' => true, 'id' => $old->id, 'message' => 'Scheme updated and sent for approval to '.$apprName.'. Existing claims will be recalculated when the new rates are approved.']);
                }
                $fresh = DB::table('incentive_schemes')->where('id', $old->id)->first();
                $rc = $this->recalcClaims($tid, $fresh, (string) ($request->user()->name ?? ''));
                $notified = 0;
                if ($request->boolean('notify', false)) {
                    $notified = $this->announce($tid, $fresh);
                }

                return response()->json(['ok' => true, 'id' => $old->id, 'message' => 'Scheme updated.'
                    .($rc['recalced'] ? ' '.$rc['recalced'].' claim(s) recalculated'.($rc['reapprove'] ? ' — '.$rc['reapprove'].' sent back for re-approval' : '').'.' : '')
                    .($rc['skipped'] ? ' '.$rc['skipped'].' locked/paid claim(s) untouched.' : '')
                    .($notified ? ' '.$notified.' people re-announced.' : '')]);
            }

            $id = DB::table('incentive_schemes')->insertGetId(ApprovalService::safeRow('incentive_schemes', [
                'tenant_id' => $tid, 'title' => trim($v['title']),
                'name' => trim($v['title']),   // legacy master col (NOT NULL on old tables)
                'purpose' => $v['purpose'] ?? null, 'portfolio' => $v['portfolio'] ?? null,
                'rate_type' => $v['rate_type'], 'rate_value' => $v['rate_value'] ?? null,
                'tds_rate' => $v['tds_rate'] ?? null,
                'payout_method' => $v['payout_method'] ?? 'with_salary',
                'valid_from' => $v['valid_from'] ?? now()->toDateString(),
                'valid_till' => $v['valid_till'] ?? null,
                'applies_to' => $v['applies_to'], 'team' => $v['team'] ?? null,
                'employee_ids' => $selIds ? implode(',', $selIds) : null,
                'max_claims' => $v['max_claims'] ?? null, 'max_amount' => $v['max_amount'] ?? null,
                'status' => 'active', 'notes' => $v['notes'] ?? null,
                'notify_channels' => $channelsCsv,
                'approval_status' => $apprStatus, 'approver' => $apprName, 'approver_emp' => $apprEmp,
                'created_by' => (string) ($request->user()->name ?? ''), 'created_by_emp' => $me->id ?? null,
                'created_at' => now(), 'updated_at' => now(),
            ]));

            if ($apprStatus === 'pending') {
                // Tell the approver — announcement to agents waits for approval.
                try {
                    $apprEmail = $apprEmp ? DB::table('employees')->where('id', $apprEmp)->value('email') : null;
                    if ($apprEmail) {
                        \App\Services\MailService::queue([
                            'tenant_id' => $tid, 'kind' => 'scheme.approval', 'to' => $apprEmail,
                            'subject' => 'Scheme awaiting your approval — '.trim($v['title']),
                            'heading' => 'A scheme needs your approval',
                            'intro' => (string) ($request->user()->name ?? 'A team leader').' has proposed the incentive scheme "'.trim($v['title']).'". It goes live (and the team is announced) only after you approve it.',
                            'lines' => ['Review it under Compensation → Incentive Schemes in SmartPRS.'],
                        ]);
                    }
                } catch (\Throwable $e) {
                    Log::warning('scheme approval mail: '.$e->getMessage());
                }

                return response()->json(['ok' => true, 'id' => $id, 'notified' => 0, 'message' => 'Scheme sent for approval to '.$apprName.' — it goes live and announces itself once approved.']);
            }

            // Announce to the targeted people (best-effort, never blocks creation).
            $notified = 0;
            if ($channels) {
                $notified = $this->announce($tid, DB::table('incentive_schemes')->where('id', $id)->first());
            }

            return response()->json(['ok' => true, 'id' => $id, 'notified' => $notified, 'message' => 'Scheme published'.($notified ? ' — '.$notified.' people notified' : '').'.']);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['ok' => false, 'error' => implode(' ', collect($e->errors())->flatten()->all())], 422);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /** POST /app/schemes/{id}/withdraw — creator or admin. Claims already made stay. */
    public function withdraw(Request $request, int $id)
    {
        self::ensure();
        $tid = $this->tid($request);
        $s = DB::table('incentive_schemes')->where('id', $id)->when($tid, fn ($q) => $q->where('tenant_id', $tid))->first();
        if (! $s) {
            return response()->json(['ok' => false, 'error' => 'Scheme not found.'], 404);
        }
        $me = $this->me($request);
        $own = $s->created_by === (string) ($request->user()->name ?? '') || ($me && (int) ($s->created_by_emp ?? 0) === (int) $me->id);
        if (! $this->isAdminish($request) && ! $own) {
            return response()->json(['ok' => false, 'error' => 'Only the scheme creator or an Admin can withdraw it.'], 403);
        }
        DB::table('incentive_schemes')->where('id', $id)->update(['status' => 'withdrawn', 'updated_at' => now()]);

        // rev 115g (Ejaz): mid-way withdrawal — APPROVED claims always stay (that
        // money is committed; corrections go through clawback). PENDING claims are
        // the withdrawer's choice: keep them in the approval queue, or reject all.
        $rejected = 0;
        if ($request->boolean('reject_pending')) {
            try {
                $by = (string) ($request->user()->name ?? '');
                $pend = DB::table('commissions')->where('scheme_id', $id)
                    ->where(function ($w) {
                        $w->whereNull('status')->orWhere('status', 'pending');
                    })->get(['id']);
                foreach ($pend as $p) {
                    DB::table('commissions')->where('id', $p->id)->update(ApprovalService::safeRow('commissions', [
                        'status' => 'rejected', 'decided_by' => $by, 'decided_at' => now(),
                        'remarks' => 'Scheme "'.$s->title.'" was withdrawn', 'updated_at' => now(),
                    ]));
                    try {
                        ApprovalService::logCommission($tid, (int) $p->id, 'rejected', 'scheme withdrawn by '.$by, $by);
                    } catch (\Throwable $e) {
                    }
                    $rejected++;
                }
            } catch (\Throwable $e) {
                Log::warning('scheme withdraw reject-pending: '.$e->getMessage());
            }
        }

        return response()->json(['ok' => true, 'rejected' => $rejected, 'message' => 'Scheme withdrawn — it disappears from claim forms immediately.'
            .($rejected ? ' '.$rejected.' pending claim(s) were rejected with the reason "scheme withdrawn".' : ' Claims already raised stay in the normal approval flow.')
            .' Approved claims are never touched — corrections, if any, go through Clawbacks.']);
    }

    /**
     * rev 115d: POST /app/schemes/{id}/decide — approve/reject a pending scheme.
     * Allowed: Admin/HR, or the named approver (the creator's reporting manager).
     * Approval = scheme goes LIVE + the announcement fires.
     */
    public function decide(Request $request, int $id)
    {
        try {
            self::ensure();
            $tid = $this->tid($request);
            $v = $request->validate([
                'action' => ['required', 'in:approve,reject'],
                'remarks' => ['nullable', 'string', 'max:255'],
            ]);
            $s = DB::table('incentive_schemes')->where('id', $id)->when($tid, fn ($q) => $q->where('tenant_id', $tid))->first();
            if (! $s) {
                return response()->json(['ok' => false, 'error' => 'Scheme not found.'], 404);
            }
            if (($s->approval_status ?? 'approved') !== 'pending') {
                return response()->json(['ok' => false, 'error' => 'This scheme is not awaiting approval.'], 422);
            }
            $me = $this->me($request);
            $isApprover = $me && (int) ($s->approver_emp ?? 0) === (int) $me->id;
            if (! $this->isAdminish($request) && ! $isApprover) {
                return response()->json(['ok' => false, 'error' => 'Only '.$s->approver.' or an Admin can decide this scheme.'], 403);
            }
            $by = (string) ($request->user()->name ?? '');
            if ($v['action'] === 'approve') {
                DB::table('incentive_schemes')->where('id', $id)->update([
                    'approval_status' => 'approved', 'decided_by' => $by, 'decided_at' => now(), 'updated_at' => now(),
                ]);
                $fresh = DB::table('incentive_schemes')->where('id', $id)->first();
                // rev 117: a TL-edited scheme recalcs its claims only NOW —
                // when the new rates become official.
                $rc = $this->recalcClaims($tid, $fresh, $by);
                $notified = $this->announce($tid, $fresh);

                return response()->json(['ok' => true, 'message' => 'Scheme approved — it is live'
                    .($notified ? ' and '.$notified.' people were announced' : '')
                    .($rc['recalced'] ? ' · '.$rc['recalced'].' claim(s) recalculated to the new rates' : '').'.']);
            }
            DB::table('incentive_schemes')->where('id', $id)->update([
                'approval_status' => 'rejected', 'decided_by' => $by, 'decided_at' => now(),
                'reject_reason' => $v['remarks'] ?? null, 'updated_at' => now(),
            ]);
            // Tell the creator (fail-soft).
            try {
                $cEmail = $s->created_by_emp ? DB::table('employees')->where('id', $s->created_by_emp)->value('email') : null;
                if ($cEmail) {
                    \App\Services\MailService::queue([
                        'tenant_id' => $tid, 'kind' => 'scheme.rejected', 'to' => $cEmail,
                        'subject' => 'Scheme not approved — '.$s->title,
                        'heading' => 'Your scheme was not approved',
                        'intro' => $by.' did not approve "'.$s->title.'".'.(! empty($v['remarks']) ? ' Reason: '.$v['remarks'] : ''),
                        'lines' => ['You can adjust and submit a fresh scheme any time.'],
                    ]);
                }
            } catch (\Throwable $e) {
                Log::warning('scheme reject mail: '.$e->getMessage());
            }

            return response()->json(['ok' => true, 'message' => 'Scheme rejected — the creator has been informed.']);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['ok' => false, 'error' => implode(' ', collect($e->errors())->flatten()->all())], 422);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /**
     * GET /app/schemes/for-me[?employee=name|code] — live schemes a claim can be
     * raised against. rev 115h (Ejaz live: Demo Admin saw no picker): the claim
     * form passes the SELECTED employee — Admin/HR may ask for anyone, others
     * for themselves or their own reportees (mirrors the claim rules).
     */
    public function forMe(Request $request)
    {
        self::ensure();
        $tid = $this->tid($request);
        $me = $this->me($request);
        $target = null;
        $q = trim((string) $request->query('employee', ''));
        if ($q !== '') {
            $cand = DB::table('employees')
                ->when($tid, fn ($w) => $w->where('tenant_id', $tid))
                ->whereNull('deleted_at')
                ->where(function ($w) use ($q) {
                    $w->where('name', $q)->orWhere('emp_code', $q);
                })->first();
            if ($cand) {
                $allowed = $this->isAdminish($request)
                    || ($me && (int) $me->id === (int) $cand->id)
                    || ($me && ((int) ($cand->reporting_manager_id ?? 0) === (int) $me->id
                        || (! empty($cand->reporting_manager) && trim((string) $cand->reporting_manager) === trim((string) $me->name))
                        || (! empty($cand->team_leader) && trim((string) $cand->team_leader) === trim((string) $me->name))));
                if ($allowed) {
                    $target = $cand;
                }
            }
        }
        $me = $target ?: $me;
        if (! $me) {
            return response()->json(['ok' => true, 'rows' => []]);
        }
        $rows = DB::table('incentive_schemes')
            ->when($tid, fn ($q) => $q->where('tenant_id', $tid))
            ->where('status', 'active')->orderByDesc('id')->limit(100)->get()
            ->filter(fn ($s) => self::isLive($s) && self::appliesTo($s, $me))
            ->map(function ($s) use ($me) {
                [$claimsLeft, $amountLeft] = self::capLeft($s, (int) $me->id);

                return [
                    'id' => $s->id, 'title' => $s->title, 'purpose' => $s->purpose, 'portfolio' => $s->portfolio,
                    'rateType' => $s->rate_type, 'rateValue' => (float) ($s->rate_value ?? 0),
                    'tdsRate' => $s->tds_rate !== null ? (float) $s->tds_rate : null,
                    'payoutMethod' => $s->payout_method, 'till' => $s->valid_till,
                    'claimsLeft' => $claimsLeft, 'amountLeft' => $amountLeft,
                ];
            })->values();

        return response()->json(['ok' => true, 'rows' => $rows]);
    }

    /**
     * rev 117: RECALCULATE the scheme's claims to its current rates.
     * Touches only UNLOCKED, non-rejected claims; percent needs the row's
     * base_amount; 'open' schemes never recalc (the rate wasn't scheme-driven).
     * Approved rows whose money changed go BACK TO PENDING (Ejaz's rule);
     * rows already part-paid beyond the new net are skipped (clawback path).
     */
    private function recalcClaims(?int $tid, object $s, string $by): array
    {
        $out = ['recalced' => 0, 'reapprove' => 0, 'skipped' => 0];
        try {
            if (! in_array($s->rate_type, ['percent', 'fixed'], true)) {
                return $out;
            }
            $rows = DB::table('commissions')->where('scheme_id', $s->id)
                ->when($tid, fn ($q) => $q->where('tenant_id', $tid))
                ->whereNull('locked_at')
                ->where(function ($w) {
                    $w->whereNull('status')->orWhere('status', '<>', 'rejected');
                })->get();
            foreach ($rows as $r) {
                $gross = null;
                if ($s->rate_type === 'percent') {
                    $base = (float) ($r->base_amount ?? 0);
                    if ($base <= 0) {
                        $out['skipped']++;
                        continue;   // no base recorded — cannot recompute a %
                    }
                    $gross = round($base * (float) $s->rate_value / 100, 2);
                } else {
                    $gross = round((float) $s->rate_value, 2);
                }
                $rate = $s->tds_rate !== null ? (float) $s->tds_rate : (float) ($r->tds_rate ?? 5);
                $tds = round($gross * $rate / 100, 2);
                $net = round($gross - $tds, 2);
                if (abs($gross - (float) ($r->gross_amount ?? 0)) < 0.005 && abs($rate - (float) ($r->tds_rate ?? 0)) < 0.005) {
                    continue;   // nothing changed for this row
                }
                // Part-paid beyond the new net → cannot shrink under payments.
                $paid = 0.0;
                try {
                    if (\Illuminate\Support\Facades\Schema::hasTable('commission_payments')) {
                        $paid = (float) DB::table('commission_payments')->where('commission_id', $r->id)->sum('amount');
                    }
                } catch (\Throwable $e) {
                }
                if ($paid > $net + 0.005) {
                    $out['skipped']++;
                    continue;
                }
                $wasApproved = ($r->status ?? '') === 'approved';
                $upd = ApprovalService::safeRow('commissions', [
                    'gross_amount' => $gross, 'tds_rate' => $rate, 'tds_amount' => $tds, 'amount' => $net,
                    'status' => $wasApproved ? 'pending' : ($r->status ?? 'pending'),
                    'decided_by' => $wasApproved ? null : ($r->decided_by ?? null),
                    'decided_at' => $wasApproved ? null : ($r->decided_at ?? null),
                    'updated_at' => now(),
                ]);
                DB::table('commissions')->where('id', $r->id)->update($upd);
                try {
                    ApprovalService::logCommission($tid, (int) $r->id, 'edited',
                        'Scheme "'.$s->title.'" rates changed — recalculated: gross ₹'.number_format((float) ($r->gross_amount ?? 0), 2).' → ₹'.number_format($gross, 2)
                        .' (TDS '.$rate.'%, net ₹'.number_format($net, 2).')'
                        .($wasApproved ? ' · was Approved → back to Pending for re-approval' : ''), $by);
                } catch (\Throwable $e) {
                }
                $out['recalced']++;
                if ($wasApproved) {
                    $out['reapprove']++;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('scheme recalc: '.$e->getMessage());
        }

        return $out;
    }

    /**
     * rev 117: POST /app/schemes/{id}/reopen — undo a mistaken withdrawal.
     * Claims auto-rejected by the full-withdraw are RESTORED to Pending.
     * A non-admin's reopen goes back through approval (like a fresh scheme).
     */
    public function reopen(Request $request, int $id)
    {
        try {
            self::ensure();
            $tid = $this->tid($request);
            $s = DB::table('incentive_schemes')->where('id', $id)->when($tid, fn ($q) => $q->where('tenant_id', $tid))->first();
            if (! $s) {
                return response()->json(['ok' => false, 'error' => 'Scheme not found.'], 404);
            }
            if ($s->status !== 'withdrawn') {
                return response()->json(['ok' => false, 'error' => 'Only a withdrawn scheme can be re-opened.'], 422);
            }
            $me = $this->me($request);
            $adminish = $this->isAdminish($request);
            $own = $s->created_by === (string) ($request->user()->name ?? '') || ($me && (int) ($s->created_by_emp ?? 0) === (int) $me->id);
            if (! $adminish && ! $own) {
                return response()->json(['ok' => false, 'error' => 'Only the scheme creator or an Admin can re-open it.'], 403);
            }
            $by = (string) ($request->user()->name ?? '');
            DB::table('incentive_schemes')->where('id', $id)->update(ApprovalService::safeRow('incentive_schemes', [
                'status' => 'active',
                'approval_status' => $adminish ? 'approved' : 'pending',
                'updated_at' => now(),
            ]));
            // Restore claims the withdrawal rejected (matched by our own remark).
            $restored = 0;
            try {
                $hit = DB::table('commissions')->where('scheme_id', $id)
                    ->where('status', 'rejected')
                    ->where('remarks', 'like', '%withdrawn%')->get(['id']);
                foreach ($hit as $h) {
                    DB::table('commissions')->where('id', $h->id)->update(ApprovalService::safeRow('commissions', [
                        'status' => 'pending', 'decided_by' => null, 'decided_at' => null,
                        'remarks' => null, 'updated_at' => now(),
                    ]));
                    try {
                        ApprovalService::logCommission($tid, (int) $h->id, 'reopened', 'Scheme re-opened by '.$by.' — claim restored to Pending', $by);
                    } catch (\Throwable $e) {
                    }
                    $restored++;
                }
            } catch (\Throwable $e) {
                Log::warning('scheme reopen restore: '.$e->getMessage());
            }
            $expired = $s->valid_till && now()->toDateString() > $s->valid_till;

            return response()->json(['ok' => true, 'message' => 'Scheme re-opened'
                .($adminish ? '' : ' — awaiting approval by your manager')
                .($restored ? ' · '.$restored.' claim(s) restored to Pending' : '')
                .($expired ? ' · NOTE: its validity window has passed — Edit the dates to make it claimable again.' : '').'.']);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    // ---- announcements ------------------------------------------------------

    /** Resolve targets + announce on the scheme's CHOSEN channels. Returns count. */
    private function announce(?int $tid, object $s): int
    {
        try {
            // rev 116b: per-channel selection (legacy null/'' = all three).
            $ch = array_filter(explode(',', (string) ($s->notify_channels ?? '')));
            if (! $ch) {
                $ch = ['email', 'wa', 'notice'];
            }
            $doEmail = in_array('email', $ch, true);
            $doWa = in_array('wa', $ch, true);
            $doNotice = in_array('notice', $ch, true);
            $targets = DB::table('employees')
                ->when($tid, fn ($q) => $q->where('tenant_id', $tid))
                ->whereNull('deleted_at')->where('status', 'active')
                ->get(['id', 'name', 'email', 'mobile', 'team'])
                ->filter(fn ($e) => self::appliesTo($s, $e));

            $rateTxt = $s->rate_type === 'percent'
                ? rtrim(rtrim(number_format((float) $s->rate_value, 2), '0'), '.').'% of the collected amount'
                : ($s->rate_type === 'fixed' ? '₹'.number_format((float) $s->rate_value).' per claim' : 'as per entry');
            $tillTxt = $s->valid_till ? Carbon::parse($s->valid_till)->format('d M Y') : 'until withdrawn';

            // 1) Notice Board (shows on the dashboard notice card).
            try {
                $doNotice && DB::table('notices')->insert(ApprovalService::safeRow('notices', [
                    'tenant_id' => $tid, 'title' => '🎯 New incentive: '.$s->title,
                    'body' => 'Earn '.$rateTxt.' — valid till '.$tillTxt.'. Claim from Commission Entries (pick the scheme, the form fills itself). Published by '.($s->created_by ?: 'management').'.',
                    'status' => 'active', 'posted_on' => now()->toDateString(),
                    'created_at' => now(), 'updated_at' => now(),
                ]));
            } catch (\Throwable $e) {
                Log::warning('scheme notice: '.$e->getMessage());
            }

            // 2) Email + WhatsApp per target (fail-soft each; capped for sanity).
            $n = 0;
            foreach ($targets->take(500) as $t) {
                try {
                    if ($doEmail && ! empty($t->email)) {
                        \App\Services\MailService::queue([
                            'tenant_id' => $tid, 'kind' => 'scheme.announce', 'to' => $t->email,
                            'subject' => 'New incentive scheme for you — '.$s->title,
                            'heading' => 'A new way to earn: '.$s->title,
                            'intro' => 'Dear '.$t->name.', a new incentive scheme has been announced for you.',
                            'lines' => array_filter([
                                'Earning: '.$rateTxt,
                                $s->portfolio ? 'Portfolio: '.$s->portfolio : null,
                                'Valid till: '.$tillTxt,
                                $s->max_claims ? 'Limit: '.$s->max_claims.' claim(s) per person' : null,
                                'How to claim: open Commission Entries → New Entry → pick "'.$s->title.'" — the form fills itself.',
                            ]),
                        ]);
                    }
                    if ($doWa && ! empty($t->mobile)) {
                        \App\Services\WaService::sendTemplate([
                            'tenant_id' => $tid, 'mobile' => $t->mobile,
                            'template' => \App\Services\WaService::templateNameFor('announcement', $tid),
                            'bodyValues' => [$t->name, $s->title, $rateTxt, $tillTxt, 'Commission Entries in SmartPRS'],
                            'kind' => 'scheme.announce',
                        ]);
                    }
                    $n++;
                } catch (\Throwable $e) {
                    // one bad contact never stops the rest
                }
            }

            return $n;
        } catch (\Throwable $e) {
            Log::warning('scheme announce: '.$e->getMessage());

            return 0;
        }
    }
}
