<?php

namespace App\Http\Controllers;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Daily Attendance Report — aggregates raw punch logs (from biometric devices,
 * the mobile app, or manual entry) into a per-employee daily summary: First In,
 * Last Out, total worked time, total break/idle time, IN/OUT counts and shift
 * variance. "Show Logs" returns every punch with the break after it so a manager
 * can spot people coming/going repeatedly or over-breaking. Managers can also
 * save a 1–10 rating and remarks per employee per day.
 *
 * Tables are created on first use (no migration required); demo punches are
 * generated for any day in range that has no logs yet, so the screen always
 * shows data until real device logs arrive.
 */
class AttendanceReportController extends Controller
{
    // Standard shift used for variance columns (configurable later via Settings).
    private const SHIFT_START = '09:30';
    private const SHIFT_END = '18:30';
    private const STANDARD_WORK_MIN = 480; // 8h net of break

    private function ensureTables(): void
    {
        if (! Schema::hasTable('attendance_logs')) {
            Schema::create('attendance_logs', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('tenant_id')->nullable()->index();
                $t->unsignedBigInteger('company_id')->nullable()->index();
                $t->string('emp_code')->index();
                $t->string('emp_name')->nullable();
                $t->date('log_date')->index();
                $t->dateTime('punch_at');
                $t->string('direction', 4); // in | out
                $t->string('source')->default('biometric');
                $t->timestamps();
                $t->index(['emp_code', 'log_date']);
            });
        }
        if (! Schema::hasTable('attendance_ratings')) {
            Schema::create('attendance_ratings', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('tenant_id')->nullable()->index();
                $t->string('emp_code')->index();
                $t->date('log_date')->index();
                $t->unsignedTinyInteger('rating')->nullable();
                $t->text('remarks')->nullable();
                $t->string('rated_by')->nullable();
                $t->timestamps();
                $t->unique(['emp_code', 'log_date']);
            });
        }
    }

    /** Daily report for a date range (defaults to today). */
    public function report(Request $request)
    {
        // Whole-body guard: the attendance screen hangs on "Loading…" if this
        // endpoint 500s (the fetch swallows errors). Always return JSON — on
        // failure, empty rows + an 'error' string the UI can surface, instead of
        // a 500 HTML page. This keeps the screen usable even on schema mismatch.
        try {
            return $this->buildReport($request);
        } catch (\Throwable $e) {
            return response()->json([
                'from' => now()->toDateString(),
                'to' => now()->toDateString(),
                'shift' => ['start' => self::SHIFT_START, 'end' => self::SHIFT_END],
                'rows' => [],
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function buildReport(Request $request)
    {
        $this->ensureTables();
        $tenantId = $request->user()->tenant_id;
        $from = $request->query('from') ?: now()->toDateString();
        $to = $request->query('to') ?: $from;
        try {
            $from = Carbon::parse($from)->toDateString();
            $to = Carbon::parse($to)->toDateString();
        } catch (\Exception $e) {
            $from = $to = now()->toDateString();
        }
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        try {
            $this->ensureDemo($tenantId, $from, $to);
        } catch (\Throwable $e) {
            // demo seeding is best-effort; real logs still render
        }

        $selfScope = \App\Http\Controllers\AppDataController::selfScope($request);
        $logsQ = DB::table('attendance_logs')
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->when($selfScope, fn ($q) => $q->where('emp_code', $selfScope['code']))
            ->whereBetween('log_date', [$from, $to])
            ->orderBy('emp_code')->orderBy('punch_at');
        $logs = $logsQ->get();

        // Existing ratings keyed by emp_code|date.
        $ratings = DB::table('attendance_ratings')
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->whereBetween('log_date', [$from, $to])->get()
            ->keyBy(fn ($r) => $r->emp_code.'|'.$r->log_date);

        // Group punches by employee + day.
        $groups = [];
        foreach ($logs as $l) {
            $key = $l->emp_code.'|'.$l->log_date;
            $groups[$key][] = $l;
        }

        try {
            $meta = $this->employeeMeta($tenantId);
        } catch (\Throwable $e) {
            $meta = [];   // hierarchy is a nice-to-have; never break the report over it
        }
        $policies = $this->loadLatePolicies($tenantId);
        // rev173 — Working Shifts: named timings + roster overrides. Each row is
        // judged against ITS OWN resolved shift (roster > employee default),
        // falling back to the Late Policy timings, then the legacy 09:30-18:30.
        $shiftDefs = \App\Services\ShiftResolver::shifts($tenantId);
        $rosterMap = $shiftDefs ? \App\Services\ShiftResolver::rosterMap($tenantId, $from, $to) : [];
        $rows = [];
        foreach ($groups as $key => $punches) {
            [$code, $date] = explode('|', $key);
            $m = $meta[$code] ?? [];
            $empName = ($m['name'] ?? '') ?: ($punches[0]->emp_name ?: $code);
            $sh = $shiftDefs
                ? \App\Services\ShiftResolver::resolve($shiftDefs, $rosterMap, $empName, $m['shift'] ?? null, $date)
                : null;
            $summary = $this->summarise($punches, $sh);
            $rt = $ratings->get($key);
            // Late / break flags from this employee's effective policy (visibility only).
            $flags = $this->attendanceFlags($policies, $m['company'] ?? '', $m['team'] ?? '', $code, $summary, $sh);
            $rows[] = array_merge([
                'emp_code' => $code,
                'emp_name' => $punches[0]->emp_name ?: $code,
                'date' => $date,
                'company' => $m['company'] ?? '',
                'branch' => $m['branch'] ?? '',
                'team' => $m['team'] ?? '',
                'reporting' => $m['reporting'] ?? '',
                'leader' => $m['leader'] ?? '',
                'shift_name' => $sh['name'] ?? '',                          // rev173
                'weekoff' => (bool) ($sh['off'] ?? false),                  // rev173 — roster week-off
                'rating' => $rt->rating ?? null,
                'remarks' => $rt->remarks ?? '',
            ], $summary, $flags);
        }

        // Sort by employee name then date.
        usort($rows, fn ($a, $b) => [$a['emp_name'], $a['date']] <=> [$b['emp_name'], $b['date']]);

        return response()->json([
            'from' => $from,
            'to' => $to,
            'shift' => ['start' => self::SHIFT_START, 'end' => self::SHIFT_END],
            'rows' => $rows,
        ]);
    }

    /**
     * Per-employee org metadata keyed by emp_code: company name + branch / team /
     * reporting manager / team leader. Tolerant of missing hierarchy columns
     * (returns blanks) so it works before the columns are created.
     */
    private function employeeMeta(?int $tenantId): array
    {
        if (! Schema::hasTable('employees')) {
            return [];
        }
        // Only select columns that actually exist in this deployment's schema —
        // the live employees table may predate the hierarchy columns.
        $want = ['emp_code', 'company_id', 'name', 'branch_id', 'team_id', 'reporting_manager_id',
            'branch', 'team', 'reporting_manager', 'team_leader', 'shift'];
        $cols = array_values(array_filter($want, fn ($c) => Schema::hasColumn('employees', $c)));
        if (! in_array('emp_code', $cols, true)) {
            return [];
        }
        $has = fn ($c) => in_array($c, $cols, true);

        $companies = Schema::hasTable('companies') ? DB::table('companies')->pluck('name', 'id') : collect();
        $branchNames = Schema::hasTable('branches') ? DB::table('branches')->pluck('name', 'id') : collect();
        $teamNames = collect();
        $teamLeaderId = collect();
        if (Schema::hasTable('teams')) {
            $teamCols = ['id', 'name'];
            if (Schema::hasColumn('teams', 'leader_id')) {
                $teamCols[] = 'leader_id';
            }
            $teamRows = DB::table('teams')->get($teamCols);
            $teamNames = $teamRows->pluck('name', 'id');
            $teamLeaderId = Schema::hasColumn('teams', 'leader_id') ? $teamRows->pluck('leader_id', 'id') : collect();
        }

        $emps = DB::table('employees')
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->whereNull('deleted_at')->get($cols);

        // id → name map for manager/leader resolution.
        $idName = DB::table('employees')
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->whereNull('deleted_at')->pluck('name', 'id');

        $out = [];
        foreach ($emps as $e) {
            $branch = ($has('branch') ? ($e->branch ?? null) : null)
                ?: (($has('branch_id') && $e->branch_id) ? ($branchNames[$e->branch_id] ?? '') : '');
            $team = ($has('team') ? ($e->team ?? null) : null)
                ?: (($has('team_id') && $e->team_id) ? ($teamNames[$e->team_id] ?? '') : '');
            $reporting = ($has('reporting_manager') ? ($e->reporting_manager ?? null) : null)
                ?: (($has('reporting_manager_id') && $e->reporting_manager_id) ? ($idName[$e->reporting_manager_id] ?? '') : '');
            $leader = ($has('team_leader') ? ($e->team_leader ?? null) : null)
                ?: (($has('team_id') && $e->team_id && ($teamLeaderId[$e->team_id] ?? null)) ? ($idName[$teamLeaderId[$e->team_id]] ?? '') : '');
            $out[$e->emp_code] = [
                'company' => $companies[$e->company_id] ?? '',
                'branch' => $branch,
                'team' => $team,
                'reporting' => $reporting,
                'leader' => $leader,
                'name' => $e->name ?? '',                                     // rev173 — roster rows match by name
                'shift' => $has('shift') ? ($e->shift ?? '') : '',            // rev173 — default Working Shift
            ];
        }

        return $out;
    }

    /**
     * Self-punch from inside the app (web / mobile / desktop), with optional GPS.
     * Writes into the same attendance_logs the report reads. Direction auto-
     * toggles from the employee's last punch that day (first punch = IN).
     */
    public function punch(Request $request)
    {
        try {
            $this->ensureTables();
            $this->ensurePunchColumns();
        } catch (\Throwable $e) {
            // column add can fail on some MySQL grants; not fatal for the insert below
        }
        $user = $request->user();
        $tenantId = $user->tenant_id;

        $v = $request->validate([
            'lat' => ['nullable', 'numeric'],
            'lng' => ['nullable', 'numeric'],
            'source' => ['nullable', 'string', 'max:20'],
        ]);

        // Resolve which employee this user is. Prefer the real users.employee_id
        // link; fall back to email, then name (legacy accounts).
        $emp = null;
        if (! empty($user->employee_id)) {
            $emp = DB::table('employees')->where('id', $user->employee_id)->whereNull('deleted_at')->first();
        }
        if (! $emp) {
            $emp = DB::table('employees')
                ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
                ->whereNull('deleted_at')
                ->where(function ($q) use ($user) {
                    $q->where('email', $user->email)->orWhere('name', $user->name);
                })->first();
        }

        $code = $emp->emp_code ?? ('USER-'.$user->id);
        $name = $emp->name ?? $user->name;
        $companyId = $emp->company_id ?? DB::table('companies')->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))->value('id');
        $today = now()->toDateString();

        // Enforce the employee's geofence rule (if any). A JsonResponse means "blocked".
        $geoStatus = $this->checkGeofence($emp, $tenantId, $v['lat'] ?? null, $v['lng'] ?? null);
        if ($geoStatus instanceof \Illuminate\Http\JsonResponse) {
            return $geoStatus;
        }

        $last = DB::table('attendance_logs')
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId)) // rev172 — emp codes repeat across tenants
            ->where('emp_code', $code)->where('log_date', $today)
            ->orderByDesc('punch_at')->orderByDesc('id')->first();
        $direction = ($last && $last->direction === 'in') ? 'out' : 'in';

        // Build the row from only the columns that exist (lat/lng are optional and
        // may be absent on an older attendance_logs table). Wrap the insert so a
        // schema mismatch returns a clean JSON error instead of a 500 HTML page.
        $row = [
            'tenant_id' => $tenantId,
            'company_id' => $companyId,
            'emp_code' => $code,
            'emp_name' => $name,
            'log_date' => $today,
            'punch_at' => now(),
            'direction' => $direction,
            'source' => $v['source'] ?? 'web',
            'created_at' => now(),
            'updated_at' => now(),
        ];
        if (Schema::hasColumn('attendance_logs', 'lat')) {
            $row['lat'] = $v['lat'] ?? null;
        }
        if (Schema::hasColumn('attendance_logs', 'lng')) {
            $row['lng'] = $v['lng'] ?? null;
        }
        if ($geoStatus && Schema::hasColumn('attendance_logs', 'geo_status')) {
            $row['geo_status'] = $geoStatus;
        }
        try {
            DB::table('attendance_logs')->insert($row);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => 'Could not save punch: '.$e->getMessage()], 422);
        }

        \App\Services\Audit::record($tenantId ? (int) $tenantId : null, $user->id, 'punch_'.$direction, 'attendance', 0, ['emp_code' => $code, 'direction' => $direction], $request->ip());

        return response()->json([
            'ok' => true,
            'direction' => $direction,
            'time' => now()->format('H:i'),
            'emp_code' => $code,
            'emp_name' => $name,
        ]);
    }

    /** Report the current user's punch state for today (for the punch button label). */
    public function punchStatus(Request $request)
    {
        $this->ensureTables();
        $user = $request->user();
        $tenantId = $user->tenant_id;
        $emp = null;
        if (! empty($user->employee_id)) {
            $emp = DB::table('employees')->where('id', $user->employee_id)->whereNull('deleted_at')->first();
        }
        if (! $emp) {
            $emp = DB::table('employees')
                ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
                ->whereNull('deleted_at')
                ->where(function ($q) use ($user) {
                    $q->where('email', $user->email)->orWhere('name', $user->name);
                })->first();
        }
        $code = $emp->emp_code ?? ('USER-'.$user->id);
        $last = DB::table('attendance_logs')
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId)) // rev172 — emp codes repeat across tenants
            ->where('emp_code', $code)->where('log_date', now()->toDateString())
            ->orderByDesc('punch_at')->orderByDesc('id')->first();
        $next = ($last && $last->direction === 'in') ? 'out' : 'in';

        return response()->json([
            'next' => $next,
            'last' => $last ? ['direction' => $last->direction, 'time' => Carbon::parse($last->punch_at)->format('H:i')] : null,
            'emp_name' => $emp->name ?? $user->name,
        ]);
    }

    /** Add lat/lng columns to attendance_logs if missing (for GPS-tagged punches). */
    private function ensurePunchColumns(): void
    {
        $add = [];
        if (! Schema::hasColumn('attendance_logs', 'lat')) {
            $add[] = 'lat';
        }
        if (! Schema::hasColumn('attendance_logs', 'lng')) {
            $add[] = 'lng';
        }
        if ($add) {
            Schema::table('attendance_logs', function (Blueprint $t) use ($add) {
                foreach ($add as $c) {
                    $t->decimal($c, 10, 6)->nullable();
                }
            });
        }
        // Geofence verdict for the punch (within / outside / no-gps / blocked).
        if (! Schema::hasColumn('attendance_logs', 'geo_status')) {
            Schema::table('attendance_logs', function (Blueprint $t) {
                $t->string('geo_status', 20)->nullable();
            });
        }
    }

    /** Great-circle distance between two lat/lng points, in kilometres. */
    private function haversineKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $r = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * Enforce the employee's geofence rule on a punch.
     * Returns the geo verdict string, or a JsonResponse to abort the punch.
     */
    private function checkGeofence($emp, $tenantId, $lat, $lng)
    {
        if (! $emp || ! Schema::hasTable('geofence_rules')) {
            return null; // no employee / no fences configured
        }
        $rule = DB::table('geofence_rules')
            ->where('employee_id', $emp->id)
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->first();
        if (! $rule || $rule->lat === null || $rule->lng === null) {
            return null; // this employee has no fence
        }
        $outside = $rule->outside ?: 'strict';
        if ($lat === null || $lng === null) {
            // No location captured. Strict fences require it; lenient ones let it pass.
            if ($outside === 'strict') {
                return response()->json(['ok' => false, 'error' => 'Location required: turn on GPS/location to punch — your account has a strict geofence.'], 422);
            }

            return 'no-gps';
        }
        $dist = $this->haversineKm((float) $rule->lat, (float) $rule->lng, (float) $lat, (float) $lng);
        $grace = ['strict' => 0.0, '1km' => 1.0, '2km' => 2.0][$outside] ?? 0.0;
        $allowed = (float) $rule->radius_km + $grace;
        if ($dist > $allowed) {
            return response()->json(['ok' => false, 'error' => 'Outside your allowed area: you are '.round($dist, 2).' km from your '.($rule->start ?: 'start').' point (limit '.round($allowed, 2).' km). Punch blocked.'], 422);
        }

        return 'within';
    }

    /** Every punch for one employee on one day + break breakdown + saved rating. */
    public function logs(Request $request, string $code, string $date)
    {
        $this->ensureTables();
        $tenantId = $request->user()->tenant_id;
        try {
            $date = Carbon::parse($date)->toDateString();
        } catch (\Exception $e) {
            $date = now()->toDateString();
        }

        $punches = DB::table('attendance_logs')
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->where('emp_code', $code)->where('log_date', $date)
            ->orderBy('punch_at')->get();

        $pp = $this->pairPunches($punches);
        $remaining = max(0, self::STANDARD_WORK_MIN - $pp['total_work']);

        $rt = DB::table('attendance_ratings')
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->where('emp_code', $code)->where('log_date', $date)->first();

        $emp = DB::table('attendance_logs')->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))->where('emp_code', $code)->where('log_date', $date)->value('emp_name');

        return response()->json([
            'emp_code' => $code,
            'emp_name' => $emp ?: $code,
            'date' => $date,
            'pairs' => array_values($pp['pairs']),
            'first_in' => $pp['first_in'] ? $pp['first_in']->format('H:i') : '—',
            'last_out' => $pp['last_out'] ? $pp['last_out']->format('H:i') : '—',
            'total_work' => $pp['total_work'],
            'total_work_h' => $this->hm($pp['total_work']),
            'total_break' => $pp['total_break'],
            'total_break_h' => $this->hm($pp['total_break']),
            'remaining_min' => $remaining,
            'remaining_h' => $this->hm($remaining),
            'open' => $pp['open'],
            'in_count' => $pp['in_count'],
            'out_count' => $pp['out_count'],
            'rating' => $rt->rating ?? null,
            'remarks' => $rt->remarks ?? '',
        ]);
    }

    public function saveRating(Request $request)
    {
        if ($deny = ApprovalService::denyUnlessRole($request, ['admin', 'hr_manager'])) {
            return $deny;
        }
        $this->ensureTables();
        $v = $request->validate([
            'emp_code' => ['required', 'string'],
            'date' => ['required', 'string'],
            'rating' => ['nullable', 'integer', 'min:1', 'max:10'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);
        $date = Carbon::parse($v['date'])->toDateString();
        $tenantId = $request->user()->tenant_id;

        DB::table('attendance_ratings')->updateOrInsert(
            ['emp_code' => $v['emp_code'], 'log_date' => $date],
            [
                'tenant_id' => $tenantId,
                'rating' => $v['rating'] ?? null,
                'remarks' => $v['remarks'] ?? '',
                'rated_by' => $request->user()->name,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        return response()->json(['ok' => true]);
    }

    /** Daily attendance report as a downloadable PDF (mirrors the CSV/screen). */
    public function reportPdf(Request $request)
    {
        $this->ensureTables();
        $tenantId = $request->user()->tenant_id;
        $from = $request->query('from') ?: now()->toDateString();
        $to = $request->query('to') ?: $from;
        try {
            $from = Carbon::parse($from)->toDateString();
            $to = Carbon::parse($to)->toDateString();
        } catch (\Exception $e) {
            $from = $to = now()->toDateString();
        }
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        $this->ensureDemo($tenantId, $from, $to);

        $selfScope = \App\Http\Controllers\AppDataController::selfScope($request);
        $logs = DB::table('attendance_logs')
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->when($selfScope, fn ($q) => $q->where('emp_code', $selfScope['code']))
            ->whereBetween('log_date', [$from, $to])
            ->orderBy('emp_code')->orderBy('punch_at')->get();

        $ratings = DB::table('attendance_ratings')
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->whereBetween('log_date', [$from, $to])->get()
            ->keyBy(fn ($r) => $r->emp_code.'|'.$r->log_date);

        $groups = [];
        foreach ($logs as $l) {
            $groups[$l->emp_code.'|'.$l->log_date][] = $l;
        }

        try {
            $meta = $this->employeeMeta($tenantId);
        } catch (\Throwable $e) {
            $meta = [];
        }
        // rev173 — resolve each row's Working Shift so the PDF matches the screen.
        $shiftDefs = \App\Services\ShiftResolver::shifts($tenantId);
        $rosterMap = $shiftDefs ? \App\Services\ShiftResolver::rosterMap($tenantId, $from, $to) : [];
        $rows = [];
        foreach ($groups as $key => $punches) {
            [$code, $date] = explode('|', $key);
            $m = $meta[$code] ?? [];
            $empName = ($m['name'] ?? '') ?: ($punches[0]->emp_name ?: $code);
            $sh = $shiftDefs
                ? \App\Services\ShiftResolver::resolve($shiftDefs, $rosterMap, $empName, $m['shift'] ?? null, $date)
                : null;
            $summary = $this->summarise($punches, $sh);
            $rt = $ratings->get($key);
            $rows[] = array_merge([
                'emp_code' => $code,
                'emp_name' => $punches[0]->emp_name ?: $code,
                'date' => $date,
                'company' => $m['company'] ?? '',
                'branch' => $m['branch'] ?? '',
                'team' => $m['team'] ?? '',
                'reporting' => $m['reporting'] ?? '',
                'leader' => $m['leader'] ?? '',
                'shift_name' => $sh['name'] ?? '',
                'rating' => $rt->rating ?? null,
                'remarks' => $rt->remarks ?? '',
            ], $summary);
        }

        // Optional filters (mirror the on-screen dropdowns) so the PDF matches.
        foreach (['company', 'branch', 'team', 'reporting', 'leader'] as $fk) {
            $val = $request->query($fk);
            if ($val !== null && $val !== '') {
                $rows = array_values(array_filter($rows, fn ($r) => ($r[$fk] ?? '') === $val));
            }
        }

        usort($rows, fn ($a, $b) => [$a['emp_name'], $a['date']] <=> [$b['emp_name'], $b['date']]);

        $company = DB::table('companies')
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))->first();
        $brand = \App\Http\Controllers\ConfigController::brandFor($tenantId, $company->id ?? null);

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('attendance-report-pdf', [
            'rows' => $rows,
            'from' => $from,
            'to' => $to,
            'shift' => ['start' => self::SHIFT_START, 'end' => self::SHIFT_END],
            'company' => $company,
            'brand' => $brand,
        ])->setPaper('a4', 'landscape');

        return $pdf->download('attendance-report-'.$from.($to !== $from ? '_'.$to : '').'.pdf');
    }

    /**
     * rev158 — pair a day's punches into IN/OUT sessions by chronological
     * ALTERNATION: the 1st punch is IN, the 2nd OUT, the 3rd IN, and so on. This
     * is the universal, reliable way to read raw biometric punches and is immune
     * to a device (e.g. eTimeOffice) that reports a missing or wrong in/out
     * direction — which was making every In/Out/Break/Total figure incorrect.
     * Worked = sum of each IN->OUT span; break = each gap between one pair's OUT
     * and the next pair's IN. A trailing unpaired IN (odd count) is an OPEN
     * session shown with no OUT and zero worked.
     */
    private function pairPunches($punches): array
    {
        // rev173g — DIRECTION-AWARE pairing when the day's punches carry BOTH an
        // 'in' and an 'out' (reliable directions, e.g. separate In/Out machines
        // via the rev173e Machine IDs): an IN opens a session (repeat INs while
        // open are double-taps → ignored), an OUT closes it (orphan OUTs ignored).
        // Otherwise (directions missing/one-sided) fall back to the rev158
        // chronological ALTERNATION, which is immune to wrong direction flags.
        // Payroll's dayStats applies the SAME rule so the two never disagree.
        $rows = [];
        foreach ($punches as $p) {
            $rows[] = ['t' => Carbon::parse($p->punch_at), 'dir' => strtolower(trim((string) ($p->direction ?? '')))];
        }
        usort($rows, fn ($a, $b) => $a['t']->getTimestamp() <=> $b['t']->getTimestamp());
        $n = count($rows);
        $hasIn = false;
        $hasOut = false;
        foreach ($rows as $r) {
            if ($r['dir'] === 'in') {
                $hasIn = true;
            } elseif ($r['dir'] === 'out') {
                $hasOut = true;
            }
        }

        $sessions = [];   // list of ['in' => Carbon, 'out' => Carbon|null]
        if ($hasIn && $hasOut) {
            $openAt = null;
            foreach ($rows as $r) {
                if ($r['dir'] === 'out') {
                    if ($openAt !== null) {
                        $sessions[] = ['in' => $openAt, 'out' => $r['t']];
                        $openAt = null;
                    }
                    // orphan OUT (no open session) → ignore
                } else {   // 'in' or unknown → treat as in
                    if ($openAt === null) {
                        $openAt = $r['t'];
                    }
                    // repeated IN while open → double-tap, ignore (keep first)
                }
            }
            if ($openAt !== null) {
                $sessions[] = ['in' => $openAt, 'out' => null];
            }
        } else {
            for ($i = 0; $i < $n; $i += 2) {
                $sessions[] = ['in' => $rows[$i]['t'], 'out' => ($i + 1 < $n) ? $rows[$i + 1]['t'] : null];
            }
        }

        $pairs = [];
        $totalWork = 0;
        $totalBreak = 0;
        $prevOut = null;
        foreach ($sessions as $s) {
            $work = $s['out'] ? $this->mins($s['in'], $s['out']) : 0;
            $totalWork += $work;
            $pairs[] = [
                'no' => count($pairs) + 1,
                'in' => $s['in']->format('H:i'),
                'out' => $s['out'] ? $s['out']->format('H:i') : '—',
                'worked' => $work,
                'break_after' => null,
            ];
            if ($prevOut !== null) {
                $brk = $this->mins($prevOut, $s['in']);
                $pairs[count($pairs) - 2]['break_after'] = $brk;
                $totalBreak += $brk;
            }
            $prevOut = $s['out'];
        }

        $closed = array_values(array_filter($sessions, fn ($s) => $s['out'] !== null));
        $last = $sessions ? $sessions[count($sessions) - 1] : null;
        $open = $last && $last['out'] === null;
        $firstIn = $sessions ? $sessions[0]['in'] : null;
        $lastOut = $closed ? $closed[count($closed) - 1]['out'] : null;
        $lastIn = $last ? $last['in'] : null;

        return [
            'pairs' => $pairs,
            'first_in' => $firstIn,
            'last_in' => $lastIn,
            'last_out' => $lastOut,
            'total_work' => $totalWork,
            'total_break' => $totalBreak,
            'open' => $open,
            'in_count' => count($sessions),
            'out_count' => count($closed),
        ];
    }

    /**
     * Build the daily summary metrics from an ordered list of punches.
     * rev173 — optional resolved Working Shift ($shift from ShiftResolver):
     * its end time replaces the default 18:30 for the early-exit / overtime
     * variance; a NIGHT shift's end lands on the NEXT calendar day.
     */
    private function summarise(array $punches, ?array $shift = null): array
    {
        $pp = $this->pairPunches($punches);
        $firstIn = $pp['first_in'];
        $lastIn = $pp['last_in'];
        $lastOut = $pp['last_out'];
        $totalWork = $pp['total_work'];
        $totalBreak = $pp['total_break'];

        $endHm = ($shift && ! empty($shift['end'])) ? $shift['end'] : self::SHIFT_END;
        $shiftEnd = $firstIn ? Carbon::parse($firstIn->toDateString().' '.$endHm) : null;
        if ($shiftEnd && $shift && ! empty($shift['night'])) {
            $shiftEnd = $shiftEnd->addDay();   // night shift ends next morning
        }
        $earlyMin = ($lastOut && $shiftEnd && $lastOut < $shiftEnd) ? $this->mins($lastOut, $shiftEnd) : 0;
        $overtimeMin = ($lastOut && $shiftEnd && $lastOut > $shiftEnd) ? $this->mins($shiftEnd, $lastOut) : 0;
        $fullMin = ($shift && ! empty($shift['full_hours'])) ? (int) round($shift['full_hours'] * 60) : self::STANDARD_WORK_MIN;
        $drawbackMin = max(0, $fullMin - $totalWork);

        return [
            'first_in' => $firstIn ? $firstIn->format('H:i') : '—',
            'first_in_min' => $firstIn ? ($firstIn->hour * 60 + $firstIn->minute) : null,
            'last_out' => $lastOut ? $lastOut->format('H:i') : '—',
            'last_in' => $lastIn ? $lastIn->format('H:i') : '—',
            'total_time' => $this->hm($totalWork),
            'work_min' => $totalWork,
            'break_time' => $this->hm($totalBreak),
            'break_min' => $totalBreak,
            'drawback_time' => $this->hm($drawbackMin),
            'early_min' => $earlyMin,
            'overtime_min' => $overtimeMin,
            'in_count' => $pp['in_count'],
            'out_count' => $pp['out_count'],
        ];
    }

    /** Whole-minute gap between two times (version-safe across Carbon 2/3). */
    private function mins(Carbon $a, Carbon $b): int
    {
        return (int) abs($a->diffInMinutes($b));
    }

    /** All late-policy rows for the tenant (resolved per employee in attendanceFlags). */
    private function loadLatePolicies(?int $tenantId)
    {
        if (! Schema::hasTable('late_policy')) {
            return collect();
        }
        try {
            return DB::table('late_policy')
                ->when($tenantId && Schema::hasColumn('late_policy', 'tenant_id'), fn ($q) => $q->where('tenant_id', $tenantId))
                ->get();
        } catch (\Throwable $e) {
            return collect();
        }
    }

    /**
     * Late-tier + break-over flags for one row, from the employee's effective policy.
     * rev173 — a resolved Working Shift overrides the policy's start time (and
     * grace / break budget when set on the shift); a roster week-off day is
     * never flagged late.
     */
    private function attendanceFlags($policies, string $companyName, string $teamName, string $empCode, array $summary, ?array $shift = null): array
    {
        $out = ['late_min' => 0, 'late_level' => '', 'break_over' => false, 'break_budget' => 0];
        if ($shift && ! empty($shift['off'])) {
            return $out;   // roster says week-off — no late/break flags
        }
        if (! $policies || $policies->isEmpty()) {
            // No Late Policy — a resolved shift can still flag plain lateness.
            if ($shift && ! empty($shift['start']) && $summary['first_in_min'] !== null) {
                $sm = \App\Services\ShiftResolver::toMin($shift['start']) + (int) ($shift['grace'] ?? 0);
                $lateMin = max(0, ((int) $summary['first_in_min']) - $sm);
                $out['late_min'] = $lateMin;
                $out['late_level'] = $lateMin > 0 ? 'Late' : '';
            }

            return $out;
        }
        $pick = null;
        $rank = -1;
        foreach ($policies as $r) {
            $a = (array) $r;
            $cn = (string) ($a['company_name'] ?? '');
            if ($cn !== '' && strcasecmp($cn, $companyName) !== 0) {
                continue; // policy for a different company
            }
            $scope = $a['scope'] ?? 'company';
            $target = (string) ($a['scope_target'] ?? '');
            $tr = -1;
            if ($scope === 'employee' && $empCode && strcasecmp($target, $empCode) === 0) {
                $tr = 2;
            } elseif ($scope === 'team' && $teamName && $target !== '' && strcasecmp($target, $teamName) === 0) {
                $tr = 1;
            } elseif ($scope === 'company' || $scope === '' || $scope === null) {
                $tr = 0;
            }
            if ($tr > $rank) {
                $rank = $tr;
                $pick = $a;
            }
        }
        if (! $pick) {
            return $out;
        }
        // rev173 — resolved Working Shift timings beat the policy's; the shift's
        // own grace / break budget apply only when explicitly set on the shift.
        $shiftStart = ($shift && ! empty($shift['start'])) ? $shift['start'] : ($pick['shift_start'] ?: self::SHIFT_START);
        $parts = array_pad(explode(':', $shiftStart), 2, '0');
        $shiftMin = ((int) $parts[0]) * 60 + (int) $parts[1];
        $grace = ($shift && $shift['grace'] !== null) ? (int) $shift['grace'] : (int) ($pick['grace_min'] ?? 0);
        $out['break_budget'] = ($shift && $shift['break_budget'] !== null) ? (int) $shift['break_budget'] : (int) ($pick['break_budget'] ?? 0);

        if ($summary['first_in_min'] !== null) {
            $lateMin = max(0, ((int) $summary['first_in_min']) - ($shiftMin + $grace));
            $out['late_min'] = $lateMin;
            if ($lateMin > 0) {
                $l1 = (int) ($pick['l1_min'] ?? 0);
                $l2 = (int) ($pick['l2_min'] ?? 0);
                $l3 = (int) ($pick['l3_min'] ?? 0);
                if ($l3 > 0 && $lateMin >= $l3) {
                    $out['late_level'] = 'L3';
                } elseif ($l2 > 0 && $lateMin >= $l2) {
                    $out['late_level'] = 'L2';
                } elseif ($l1 > 0 && $lateMin >= $l1) {
                    $out['late_level'] = 'L1';
                } else {
                    $out['late_level'] = 'Late';
                }
            }
        }
        if ($out['break_budget'] > 0 && (int) ($summary['break_min'] ?? 0) > $out['break_budget']) {
            $out['break_over'] = true;
        }

        return $out;
    }

    private function hm(int $minutes): string
    {
        $minutes = max(0, $minutes);

        return intdiv($minutes, 60).'h '.($minutes % 60).'m';
    }

    /**
     * Is demo-attendance fabrication allowed? OFF by default and HARD-BLOCKED in
     * production. Enable only on dev with SMARTPRS_DEMO_DATA=true in .env so a demo
     * environment can show populated reports. Never writes fake punches on a real
     * deployment (which would contaminate payroll/points reading attendance_logs).
     */
    public static function demoEnabled(): bool
    {
        if (app()->environment('production')) {
            return false;
        }

        return filter_var(env('SMARTPRS_DEMO_DATA', false), FILTER_VALIDATE_BOOLEAN);
    }

    /** Generate realistic demo punches for any (employee, day) with no logs yet. */
    private function ensureDemo(?int $tenantId, string $from, string $to): void
    {
        if (! self::demoEnabled()) {
            return; // production / not opted-in: never fabricate attendance
        }
        $start = Carbon::parse($from);
        $end = Carbon::parse($to);
        if ($start->diffInDays($end) > 31) {
            return; // safety cap
        }

        // Active employees = not soft-deleted and not "exited" (case-insensitive,
        // so it works regardless of how status casing/collation is stored).
        $emps = DB::table('employees')
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->whereNull('deleted_at')
            ->where(function ($q) {
                $q->whereNull('status')->orWhereRaw('LOWER(status) <> ?', ['exited']);
            })
            ->orderBy('emp_code')->get(['emp_code', 'name', 'company_id', 'tenant_id']);
        if ($emps->isEmpty()) {
            return;
        }

        $today = now()->toDateString();
        $insert = [];
        for ($d = $start->copy(); $d->lte($end); $d->addDay()) {
            $date = $d->toDateString();
            if ($date >= $today) {
                // rev 81b (Ejaz, 5 Jun 2026): NEVER fabricate punches for TODAY
                // (or the future) — demo history fills past days only, so live
                // Punch In/Out testing and demos on the current day stay clean.
                continue;
            }
            // Which employees already have logs that day?
            $have = DB::table('attendance_logs')
                ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
                ->where('log_date', $date)->pluck('emp_code')->flip();

            foreach ($emps as $e) {
                if ($have->has($e->emp_code)) {
                    continue;
                }
                // Deterministic per (emp, day) so demo data is stable across reloads.
                mt_srand(crc32($e->emp_code.$date));
                // ~10% absent.
                if (mt_rand(1, 100) <= 10) {
                    continue;
                }
                $cur = 9 * 60 + mt_rand(0, 45);          // first IN ~09:00–09:45
                $pairs = mt_rand(3, 6);                    // comings/goings
                for ($i = 0; $i < $pairs; $i++) {
                    $work = mt_rand(40, 120);
                    $in = $cur;
                    $out = min($cur + $work, 19 * 60);     // never past 19:00
                    $insert[] = $this->demoPunch($e, $date, $in, 'in');
                    $insert[] = $this->demoPunch($e, $date, $out, 'out');
                    $brk = mt_rand(5, 70);
                    $cur = $out + $brk;
                    if ($cur >= 18 * 60 + 30) {
                        break;
                    }
                }
            }
            // Flush per day to keep memory low.
            if (count($insert) >= 500) {
                DB::table('attendance_logs')->insert($insert);
                $insert = [];
            }
        }
        mt_srand();
        if ($insert) {
            DB::table('attendance_logs')->insert($insert);
        }
    }

    private function demoPunch($e, string $date, int $minutes, string $dir): array
    {
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;
        $ts = sprintf('%s %02d:%02d:00', $date, $h, $m);

        return [
            'tenant_id' => $e->tenant_id,
            'company_id' => $e->company_id,
            'emp_code' => $e->emp_code,
            'emp_name' => $e->name,
            'log_date' => $date,
            'punch_at' => $ts,
            'direction' => $dir,
            'source' => 'demo',   // tagged so it is identifiable + purgeable (attendance:purge-demo)
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
