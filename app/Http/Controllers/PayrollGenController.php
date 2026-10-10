<?php

namespace App\Http\Controllers;

use App\Services\PeriodResolver;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Generate Payroll (rev 36) — the FRONT of the payroll flow, now real.
 *
 * Creates a real `payroll_runs` row (status=draft) plus one `payslips` row per
 * active employee for a chosen company + month, computing each salary from the
 * employee CTC and the tenant's configured statutory rates (reusing
 * AppDataController::computeSlip so the math matches payslips/PF/ESIC/TDS
 * everywhere else). The draft run then flows straight into Salary Approval
 * (HR → Finance → disburse → bank file), all of which is already real.
 *
 * Attendance (LOP): optional. When 'lop' is on, each employee's pay is prorated
 * by present-days / working-days for the month (present-days = distinct punch
 * dates in attendance_logs; working-days = calendar days minus Sundays). An
 * employee with NO punches at all is treated as FULL-paid, so a company that
 * doesn't use biometric attendance is never accidentally zeroed. Public
 * holidays / approved-leave nuance is intentionally NOT modelled yet (that needs
 * the Holidays + Leave-Types master data, still on the backlog as C2) — LOP here
 * is a transparent, opt-in proration, off by default.
 *
 * Conventions honoured: tenant-scoped, admin/HR guarded, fail-soft JSON,
 * schema-safe inserts via ApprovalService::safeRow, idempotent per month/company.
 */
class PayrollGenController extends Controller
{
    // Default shift window used when a company's Late Policy doesn't set its own.
    private const SHIFT_START = '09:30';
    private const SHIFT_END = '18:30';

    /** Resolve a company the current tenant may run payroll for. */
    private function resolveCompany(Request $request, $companyId): ?object
    {
        $tid = $request->user()->tenant_id;

        return DB::table('companies')
            ->when($tid, fn ($q) => $q->where('tenant_id', $tid))
            ->where('id', (int) $companyId)
            ->whereNull('deleted_at')
            ->first();
    }

    /** Validate + normalise a 'YYYY-MM' month string; null if invalid. */
    private function normMonth(?string $month): ?string
    {
        if (! $month) {
            return null;
        }
        try {
            return Carbon::createFromFormat('Y-m', substr($month, 0, 7))->format('Y-m');
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * rev172 — configurable weekly offs (Ejaz): is this date a weekly off per
     * Statutory Settings? weekly_off_day (default sunday) + sat_off_mode
     * (none | all | 2_4 | 1_3 — Nth Saturdays of the month off).
     */
    /**
     * 8 Oct 2026 (Ejaz) — salary day-value basis. Weekly offs, holidays and
     * paid leave are always PAID days; the old 'working' basis (which left them
     * out of the paid-day count) is retired and reads as 'calendar'.
     */
    public static function lopBasisOf(array $r, ?string $override = null): string
    {
        $b = strtolower(trim((string) ($override ?: ($r['lop_basis'] ?? ''))));

        return $b === 'fixed30' ? 'fixed30' : 'calendar';
    }

    public static function isWeekOff(Carbon $d, array $r): bool
    {
        $map = ['sunday' => 0, 'monday' => 1, 'tuesday' => 2, 'wednesday' => 3, 'thursday' => 4, 'friday' => 5, 'saturday' => 6];
        $off = $map[strtolower((string) ($r['weekly_off_day'] ?? 'sunday'))] ?? 0;
        if ($d->dayOfWeek === $off) {
            return true;
        }
        if ($d->dayOfWeek === Carbon::SATURDAY) {
            $mode = (string) ($r['sat_off_mode'] ?? 'none');
            if ($mode === 'all') {
                return true;
            }
            $nth = (int) ceil($d->day / 7); // 1st–5th Saturday of the month
            if ($mode === '2_4' || $mode === '1_3') {
                return $mode === '2_4' ? ($nth === 2 || $nth === 4) : ($nth === 1 || $nth === 3);
            }
            // 7 Oct 2026 (Ejaz) — CUSTOM: any set of Saturdays (1st..5th) off.
            if ($mode === 'custom') {
                return in_array($nth, array_map('intval', (array) ($r['sat_off_weeks'] ?? [])), true);
            }
        }

        return false;
    }

    /**
     * 7 Oct 2026 (Ejaz) — approved Exit & FnF requests: employee id => last
     * working day ('Y-m-d'). The payroll pays a leaver only up to that date.
     */
    private function exitDates(?int $tid): array
    {
        try {
            if (! Schema::hasTable('exits')) {
                return [];
            }
            $col = Schema::hasColumn('exits', 'last_working_day') ? 'last_working_day' : (Schema::hasColumn('exits', 'last_day') ? 'last_day' : null);
            if (! $col) {
                return [];
            }
            $out = [];
            foreach (DB::table('exits')->when($tid && Schema::hasColumn('exits', 'tenant_id'), fn ($q) => $q->where('tenant_id', $tid))
                ->where('status', 'approved')->whereNotNull($col)->orderBy('id')->get(['employee_id', $col.' as lwd']) as $x) {
                $out[(int) $x->employee_id] = substr((string) $x->lwd, 0, 10);
            }

            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** rev181 — run-scoped attendance cut-off day. 0/1 = calendar month (1..end).
     *  N (2..28) = a monthly run counts from the Nth of the PREVIOUS month to the
     *  (N-1)th of the run month, e.g. 21 -> 21st..20th. Set once per run in compute();
     *  0 for previews so Live Salary / Simulator stay on the calendar month. */
    private int $runCutoff = 0;

    /** Period START date for a run month, honouring $this->runCutoff. */
    private function periodStart(string $month): string
    {
        $c = (int) $this->runCutoff;

        return PeriodResolver::resolve($month, ($c >= 2 && $c <= 28) ? $c : null)['start'];
    }

    /** Cut-off day (2..28) for a company; 0 = calendar month.
     *  rev191 — the scan itself now lives in PeriodResolver::masterDay so the
     *  payroll engine, the pay date, ESS, Reports, Live Salary and the audit
     *  command cannot drift apart. Behaviour is unchanged: salary_schedules
     *  before pay_cycles, company-specific row before an all-company one. */
    private function resolveCutoffDay($tid, object $company): int
    {
        return PeriodResolver::masterDay($tid, (string) $company->name, 'cutoff_day');
    }

    /** [startDate, daysInPeriod, workingDays(excl. Sundays), endDateString] for a run month (cut-off aware). */
    private function monthMeta(string $month): array
    {
        $c = (int) $this->runCutoff;
        $p = PeriodResolver::resolve($month, ($c >= 2 && $c <= 28) ? $c : null);
        $start = Carbon::parse($p['start'])->startOfDay();
        $end = Carbon::parse($p['end'])->startOfDay();
        $working = 0;
        $cur = $start->copy();
        while ($cur->lte($end)) {
            if ($cur->dayOfWeek !== Carbon::SUNDAY) {
                $working++;
            }
            $cur->addDay();
        }

        return [$start, (int) $p['days'], max(1, $working), $p['end']];
    }

    /**
     * rev191 — working days in a period, and how many of them have elapsed.
     *
     * Replaces four copies of `for ($d = 1; $d <= $daysInMonth; $d++)` in
     * compute(), liveSalary(), liveSalaryAll() and simulate(). That loop
     * indexed days off $start->month, so for a 21 Aug – 20 Sep period it walked
     * 1–31 AUGUST: the wrong dates, the wrong weekly offs, and an elapsed count
     * that compared a period index against a calendar day-of-month. A date walk
     * is correct for a calendar month and a cut-off cycle alike.
     *
     * @return array [workingDays, workingDaysElapsedOnOrBefore($upto)]
     */
    private function workingSpan(Carbon $start, string $end, string $upto, array $rates, array $holidays): array
    {
        $total = 0;
        $soFar = 0;
        $cur = $start->copy();
        $last = Carbon::parse($end);
        while ($cur->lte($last)) {
            if (! (self::isWeekOff($cur, $rates) || isset($holidays[$cur->toDateString()]))) {
                $total++;
                if ($cur->toDateString() <= $upto) {
                    $soFar++;
                }
            }
            $cur->addDay();
        }

        return [max(1, $total), $soFar];
    }

    /**
     * rev191 — does this company have ANY punch at all in the run window?
     *
     * Since rev191 an employee with no punches is scored as absent, which is the
     * correct answer for one person and a catastrophe for a whole company: a dead
     * biometric sync, a mismatched emp_code or a wrong tenant_id would otherwise
     * zero everybody's pay in silence. generate() calls this BEFORE it touches any
     * existing draft and refuses the run when it comes back false.
     *
     * Fail-soft returns TRUE (never block payroll because a probe threw).
     */
    private function hasAnyAttendance($tid, object $company, string $month, string $endDate): bool
    {
        try {
            if (! Schema::hasTable('attendance_logs')) {
                return true;   // attendance isn't tracked at all in this install
            }
            $codes = DB::table('employees')
                ->when($tid, fn ($q) => $q->where('tenant_id', $tid))
                ->where('company_id', $company->id)
                ->where('status', 'active')
                ->whereNull('deleted_at')
                ->pluck('emp_code')->filter()->all();
            if (! $codes) {
                return true;   // nothing to pay anyway; the empty-rows check handles it
            }

            return DB::table('attendance_logs')
                ->when($tid, fn ($q) => $q->where('tenant_id', $tid))
                ->whereIn('emp_code', $codes)
                ->whereBetween('log_date', [$this->periodStart($month), $endDate])
                ->exists();
        } catch (\Throwable $e) {
            return true;
        }
    }

    /** Distinct punch dates for an employee in the month (0 if none / no table). */
    private function presentDays(string $empCode, string $month, string $endDate, ?int $tid = null): int
    {
        if (! $empCode || ! Schema::hasTable('attendance_logs')) {
            return 0;
        }
        try {
            // rev172 — tenant-scoped: emp codes (EMP-XXXX) repeat across tenants,
            // so without this filter one tenant's payroll could count another
            // tenant's punches.
            return (int) DB::table('attendance_logs')
                ->when($tid, fn ($q) => $q->where('tenant_id', $tid))
                ->where('emp_code', $empCode)
                ->whereBetween('log_date', [$this->periodStart($month), $endDate])
                ->distinct()
                ->count('log_date');
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /** rev173 — the distinct punch DATES themselves (for night-allowance counting). */
    private function presentDates(string $empCode, string $month, string $endDate, ?int $tid = null): array
    {
        if (! $empCode || ! Schema::hasTable('attendance_logs')) {
            return [];
        }
        try {
            return DB::table('attendance_logs')
                ->when($tid, fn ($q) => $q->where('tenant_id', $tid))
                ->where('emp_code', $empCode)
                ->whereBetween('log_date', [$this->periodStart($month), $endDate])
                ->distinct()
                ->pluck('log_date')
                ->map(fn ($d) => substr((string) $d, 0, 10))
                ->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** Load every late-policy row that could apply to this company (resolved per-employee later). */
    private function latePolicyRows($company, $tid)
    {
        if (! Schema::hasTable('late_policy')) {
            return collect();
        }

        // company_name / company_id are wizard-added columns — guard each so a
        // fresh DB (no Late Policy saved yet) never 42S22s (Ejaz, 4 Jun 2026).
        $hasCoId = Schema::hasColumn('late_policy', 'company_id');
        $hasCoName = Schema::hasColumn('late_policy', 'company_name');

        return DB::table('late_policy')
            ->when($tid && Schema::hasColumn('late_policy', 'tenant_id'), fn ($q) => $q->where('tenant_id', $tid))
            ->when($hasCoId || $hasCoName, function ($q) use ($company, $hasCoId, $hasCoName) {
                $q->where(function ($w) use ($company, $hasCoId, $hasCoName) {
                    $first = true;
                    if ($hasCoId) {
                        $w->where('company_id', $company->id)->orWhereNull('company_id');
                        $first = false;
                    }
                    if ($hasCoName) {
                        $first ? $w->where('company_name', $company->name) : $w->orWhere('company_name', $company->name);
                    }
                });
            })
            ->get();
    }

    /** Pick the most specific policy (employee > team > company) and normalise it. */
    private function resolvePolicy($rows, ?string $empCode, ?string $teamName): ?array
    {
        if ($rows->isEmpty()) {
            return null;
        }
        $pick = null;
        $rank = -1;
        foreach ($rows as $r) {
            $a = (array) $r;
            $scope = $a['scope'] ?? 'company';
            $target = (string) ($a['scope_target'] ?? '');
            $thisRank = -1;
            if ($scope === 'employee' && $empCode && strcasecmp($target, $empCode) === 0) {
                $thisRank = 2;
            } elseif ($scope === 'team' && $teamName && $target !== '' && strcasecmp($target, $teamName) === 0) {
                $thisRank = 1;
            } elseif ($scope === 'company' || $scope === '' || $scope === null) {
                $thisRank = 0;
            }
            if ($thisRank > $rank) {
                $rank = $thisRank;
                $pick = $a;
            }
        }
        if (! $pick || $rank < 0) {
            return null;
        }

        return [
            'mode' => $pick['mode'] ?: 'simple',
            'shift_start' => $pick['shift_start'] ?: self::SHIFT_START,
            'shift_end' => $pick['shift_end'] ?: self::SHIFT_END,
            'grace' => (int) ($pick['grace_min'] ?? 0),
            'free' => (int) ($pick['lates_before_cut'] ?? 0),
            'cut_mode' => $pick['cut_mode'] ?: 'none',
            'cut_n' => max(1, (int) ($pick['cut_n'] ?? 3)),
            'full_min' => ((float) ($pick['full_day_hours'] ?: 9)) * 60,
            'half_min' => ((float) ($pick['half_day_hours'] ?: 4.5)) * 60,
            'l1_min' => (int) ($pick['l1_min'] ?? 0),
            'l1_cut' => (float) ($pick['l1_cut'] ?? 0),
            'l2_min' => (int) ($pick['l2_min'] ?? 0),
            'l2_cut' => (float) ($pick['l2_cut'] ?? 0),
            'l3_min' => (int) ($pick['l3_min'] ?? 0),
            'l3_cut' => (float) ($pick['l3_cut'] ?? 0),
            'break_budget' => (int) ($pick['break_budget'] ?? 0),
            'break_cut' => $pick['break_cut'] ?: 'none',
        ];
    }

    /** Per-day stats for an employee: 'Y-m-d' => ['firstIn'=>Carbon|null,'worked'=>min,'break'=>min]. */
    private function dayStats(string $empCode, string $month, string $endDate, ?int $tid = null): array
    {
        $out = [];
        if (! $empCode || ! Schema::hasTable('attendance_logs')) {
            return $out;
        }
        try {
            $punches = DB::table('attendance_logs')
                ->when($tid, fn ($q) => $q->where('tenant_id', $tid)) // rev172 — tenant-scoped (see presentDays)
                ->where('emp_code', $empCode)
                ->whereBetween('log_date', [$this->periodStart($month), $endDate])
                ->orderBy('punch_at')
                ->get(['log_date', 'punch_at', 'direction']);
        } catch (\Throwable $e) {
            return $out;
        }
        $byDay = [];
        foreach ($punches as $p) {
            if (! $p->punch_at) {
                continue;
            }
            $d = substr((string) $p->log_date, 0, 10);
            $byDay[$d][] = $p;
        }
        foreach ($byDay as $d => $ps) {
            // rev173g — SAME pairing rule as the Attendance Report (pairPunches):
            // direction-aware only when the day has BOTH an 'in' and an 'out'
            // (repeat INs while open = double-tap → ignored; orphan OUTs ignored);
            // otherwise chronological alternation. Previously this trusted the
            // direction flag blindly — a day whose punches were all marked 'in'
            // (wrong device flags) computed ZERO worked minutes and payroll cut pay.
            $rows = [];
            foreach ($ps as $p) {
                $rows[] = ['t' => Carbon::parse($p->punch_at), 'dir' => strtolower(trim((string) ($p->direction ?? '')))];
            }
            usort($rows, fn ($a, $b) => $a['t']->getTimestamp() <=> $b['t']->getTimestamp());
            $hasIn = false;
            $hasOut = false;
            foreach ($rows as $r) {
                if ($r['dir'] === 'in') {
                    $hasIn = true;
                } elseif ($r['dir'] === 'out') {
                    $hasOut = true;
                }
            }
            $firstIn = null;
            $worked = 0;
            $break = 0;
            if ($hasIn && $hasOut) {
                $open = null;
                $prevOut = null;
                foreach ($rows as $r) {
                    if ($r['dir'] === 'out') {
                        if ($open !== null) {
                            $worked += max(0, $open->diffInMinutes($r['t']));
                            $open = null;
                            $prevOut = $r['t'];
                        }
                    } else {
                        if ($firstIn === null) {
                            $firstIn = $r['t'];
                        }
                        if ($open === null) {
                            if ($prevOut !== null) {
                                $break += max(0, $prevOut->diffInMinutes($r['t']));
                                $prevOut = null;
                            }
                            $open = $r['t'];
                        }
                        // repeated IN while open → double-tap, ignore
                    }
                }
            } else {
                $nD = count($rows);
                $firstIn = $nD ? $rows[0]['t'] : null;
                for ($i = 0; $i + 1 < $nD; $i += 2) {
                    $worked += max(0, $rows[$i]['t']->diffInMinutes($rows[$i + 1]['t']));
                    if ($i + 2 < $nD) {
                        $break += max(0, $rows[$i + 1]['t']->diffInMinutes($rows[$i + 2]['t']));
                    }
                }
            }
            $out[$d] = ['firstIn' => $firstIn, 'worked' => $worked, 'break' => $break];
        }

        return $out;
    }

    /** Apply a resolved policy to a month of day-stats → ['cut','lateCut','breakCut','late']. */
    private function attendanceCut(array $days, array $pol, array $dayShifts = []): array
    {
        ksort($days);
        $mode = $pol['mode'];
        $lateCut = 0.0;
        $breakCut = 0.0;
        $lateCount = 0;
        $lateSeen = 0;
        $free = max(0, $pol['free']);
        foreach ($days as $d => $st) {
            if (! $st['firstIn']) {
                continue;
            }
            // rev173 — per-day Working Shift override (roster > employee default).
            // The shift supplies TIMINGS (+ optional grace/hours/break overrides);
            // the Late Policy keeps supplying the RULES. A roster week-off skips
            // late/break evaluation for that day entirely.
            $sh = $dayShifts[$d] ?? null;
            if ($sh && ! empty($sh['off'])) {
                continue;
            }
            $dayStart = ($sh && ! empty($sh['start'])) ? $sh['start'] : $pol['shift_start'];
            $dayGrace = ($sh && $sh['grace'] !== null) ? $sh['grace'] : $pol['grace'];
            $dayFullMin = ($sh && $sh['full_hours']) ? $sh['full_hours'] * 60 : $pol['full_min'];
            $dayHalfMin = ($sh && $sh['half_hours']) ? $sh['half_hours'] * 60 : $pol['half_min'];
            $dayBreakBudget = ($sh && $sh['break_budget'] !== null) ? $sh['break_budget'] : $pol['break_budget'];
            $cutoff = Carbon::parse($d.' '.$dayStart)->addMinutes($dayGrace);
            $isLate = $st['firstIn']->gt($cutoff);
            $lateMin = $isLate ? $cutoff->diffInMinutes($st['firstIn']) : 0;

            if ($mode === 'net_hours') {
                // Made-up time: full pay if total worked meets the full-day target, even if late.
                $w = $st['worked'];
                if ($w < $dayHalfMin) {
                    $lateCut += 1.0;
                } elseif ($w < $dayFullMin) {
                    $lateCut += 0.5;
                }
            } elseif ($mode === 'tiered') {
                if ($isLate) {
                    $lateCount++;
                    $lateSeen++;
                    if ($lateSeen > $free) {
                        $lateCut += $this->tierCut($lateMin, $pol);
                    }
                }
            } else { // simple
                if ($isLate) {
                    $lateCount++;
                }
            }

            // Break-budget deduction (any mode).
            if ($pol['break_cut'] !== 'none' && $dayBreakBudget > 0 && $st['break'] > $dayBreakBudget) {
                if ($pol['break_cut'] === 'half_day') {
                    $breakCut += 0.5;
                } elseif ($pol['break_cut'] === 'per_30min') {
                    $excess = $st['break'] - $dayBreakBudget;
                    $breakCut += ceil($excess / 30) * 0.25;
                }
            }
        }

        if ($mode === 'simple') {
            $lateCut = $this->simpleCut(max(0, $lateCount - $free), $pol);
        }

        return ['cut' => $lateCut + $breakCut, 'lateCut' => $lateCut, 'breakCut' => $breakCut, 'late' => $lateCount];
    }

    /** Tiered: day-cut for how many minutes late (highest crossed level wins). */
    private function tierCut(int $lateMin, array $pol): float
    {
        if ($pol['l3_min'] > 0 && $lateMin >= $pol['l3_min']) {
            return $pol['l3_cut'];
        }
        if ($pol['l2_min'] > 0 && $lateMin >= $pol['l2_min']) {
            return $pol['l2_cut'];
        }
        if ($pol['l1_min'] > 0 && $lateMin >= $pol['l1_min']) {
            return $pol['l1_cut'];
        }

        return $pol['l1_cut']; // late beyond grace but below L1 threshold → treat as L1
    }

    /** Simple: excess lates → day-cut by the flat rule. */
    private function simpleCut(int $excessLates, array $pol): float
    {
        if ($excessLates <= 0) {
            return 0.0;
        }
        switch ($pol['cut_mode']) {
            case 'half_day_per_late':
                return $excessLates * 0.5;
            case 'full_day_per_late':
                return (float) $excessLates;
            case 'one_day_per_n':
                return (float) intdiv($excessLates, $pol['cut_n']);
            default:
                return 0.0;
        }
    }

    /** Plain-English explanation of how one employee's pay was computed (for the payslip + sheet). */
    private function calcNote(float $ctc, array $s, bool $lop, int $present, float $leave, int $working, float $factor, int $lateDays, float $lateCut, float $breakCut, ?array $pol, float $commission = 0.0, array $commLines = [], ?float $dispPaid = null, ?float $dispDen = null, ?string $attLine = null): string
    {
        $rs = fn ($x) => 'Rs '.number_format((float) $x, 0);
        $dy = fn ($x) => rtrim(rtrim(number_format((float) $x, 2), '0'), '.');
        $p = [];
        $p[] = 'Monthly gross from CTC '.$rs($ctc).' / 12 = '.$rs(round($ctc / 12, 2)).'.';
        if ($lop) {
            $att = 'Attendance: '.$present.' present day(s)';
            if ($leave > 0) {
                $att .= ' + '.$dy($leave).' paid-leave day(s)';
            }
            $att .= ' of '.$working.' working days.';
            $p[] = $attLine ?: $att;   // 8 Oct 2026 — paid-day / LOP breakdown from the scorer
            if ($lateCut > 0) {
                $mode = $pol['mode'] ?? 'simple';
                $modeLabel = $mode === 'tiered' ? 'tiered L1/L2/L3' : ($mode === 'net_hours' ? 'net working hours' : 'simple late rule');
                $p[] = 'Late: '.$lateDays.' late day(s) -> -'.$dy($lateCut).' day cut ('.$modeLabel.').';
            }
            if ($breakCut > 0) {
                $p[] = 'Breaks over budget -> -'.$dy($breakCut).' day cut.';
            }
            if ($factor < 0.9999) {
                // rev177 — under calendar/fixed30 the caller passes the paid/denominator
                // pair in that basis; default (working) keeps the original arithmetic.
                $p[] = 'Paid '.$dy($dispPaid !== null ? $dispPaid : $factor * $working).' of '.$dy($dispDen !== null ? $dispDen : $working).' days, so gross is prorated to '.$rs($s['gross']).' (x'.number_format($factor, 3).').';
            } else {
                $p[] = 'Full attendance - no proration.';
            }
        }
        if (! empty($s['earnings'])) {
            $ep = [];
            foreach ($s['earnings'] as $en => $ea) {
                $ep[] = $en.' '.$rs($ea);
            }
            $p[] = 'Earnings (per salary structure): '.implode(', ', $ep).'.';
        } else {
            $p[] = 'Earnings: Basic '.$rs($s['basic']).' (50% of gross), HRA '.$rs($s['hra']).' (40% of basic), Special Allowance '.$rs($s['special']).'.';
        }
        if ($commission > 0) {
            $p[] = 'Commission added (approved, due this month): '.$rs($commission).'.';
            // rev 84 (Ejaz): line summary of every commission inside this slip.
            if ($commLines) {
                $p[] = 'Commission detail: '.implode(' | ', $commLines).'.';
            }
        }
        if (! empty($s['deductions'])) {
            $dp = [];
            foreach ($s['deductions'] as $dn => $da) {
                $dp[] = $dn.' '.$rs($da);
            }
            $p[] = 'Deductions: '.implode(', ', $dp).' = -'.$rs($s['total_ded']).'.';
        } else {
            $p[] = 'Deductions: PF '.$rs($s['pf']).', ESI '.$rs($s['esi']).', PT '.$rs($s['pt']).', TDS '.$rs($s['tds']).' = -'.$rs($s['total_ded']).'.';
        }
        if ($commission > 0) {
            $p[] = 'Net pay = gross + commission - deductions = '.$rs($s['net'] + $commission).'.';
        } else {
            $p[] = 'Net pay = gross - deductions = '.$rs($s['net']).'.';
        }
        $p[] = 'Incentive schemes (if any) are processed separately.';

        return implode(' ', $p);
    }

    /**
     * APPROVED commissions due in this payroll month, keyed by employee_id.
     * rev 84 (Ejaz): the PAYOUT DATE decides which month's payslip pays an
     * entry; entries without a payout date fall back to their earned month
     * (cycle_month) — full backward compatibility.
     * Returns ['sum' => [empId => net total], 'rows' => [empId => [entry...]]].
     */
    private function commissionByEmployee(object $company, ?int $tid, string $month): array
    {
        $out = ['sum' => [], 'rows' => []];
        if (! Schema::hasTable('commissions') || ! Schema::hasColumn('commissions', 'amount')) {
            return $out;
        }
        $hasCycle = Schema::hasColumn('commissions', 'cycle_month');
        try {
            $cols = ['id', 'employee_id', 'amount'];
            foreach (['cycle_month', 'payout_date', 'payout_method', 'purpose', 'portfolio', 'gross_amount', 'tds_amount'] as $c) {
                if (Schema::hasColumn('commissions', $c)) {
                    $cols[] = $c;
                }
            }
            $rows = DB::table('commissions')
                ->when($tid && Schema::hasColumn('commissions', 'tenant_id'), fn ($q) => $q->where('tenant_id', $tid))
                ->when(Schema::hasColumn('commissions', 'company_id'), fn ($q) => $q->where('company_id', $company->id))
                ->where('status', 'approved')
                // rev172 (M3) — a commission already LOCKED into a payslip must never
                // fold into another month's run (e.g. if its payout_date is edited
                // after locking). Regenerating the SAME month first clears the lock,
                // so this still lets a re-generated month re-include correctly.
                ->when(Schema::hasColumn('commissions', 'locked_at'), fn ($q) => $q->whereNull('locked_at'))
                ->get($cols);
            foreach ($rows as $r) {
                if (! $r->employee_id) {
                    continue;
                }
                // rev 85 (Ejaz): 'separate' payout entries NEVER fold into the
                // payslip — they are paid through the disbursement ledger.
                if ((($r->payout_method ?? '') ?: 'with_salary') === 'separate') {
                    continue;
                }
                $payout = trim((string) ($r->payout_date ?? ''));
                if ($payout !== '') {
                    // Payout date rules: pay in the payroll whose PERIOD holds the
                    // date (7 Oct 2026 — with a cut-off cycle, a date after the
                    // cut-off belongs to the next payroll).
                    $c = (int) $this->runCutoff;
                    if (PeriodResolver::payrollMonthFor(substr($payout, 0, 10), ($c >= 2 && $c <= 28) ? $c : null) !== $month) {
                        continue;
                    }
                } elseif ($hasCycle) {
                    // No payout date → earned month (legacy behaviour).
                    $cm = trim((string) ($r->cycle_month ?? ''));
                    if ($cm === '') {
                        continue;
                    }
                    try {
                        if (Carbon::parse($cm)->format('Y-m') !== $month) {
                            continue;
                        }
                    } catch (\Throwable $e) {
                        continue;
                    }
                }
                $eid = (int) $r->employee_id;
                $out['sum'][$eid] = ($out['sum'][$eid] ?? 0) + (float) $r->amount;
                $out['rows'][$eid][] = [
                    'id' => (int) $r->id,
                    'label' => trim((string) (($r->purpose ?? '') ?: 'Commission'))
                        .(! empty($r->portfolio) ? ' ('.$r->portfolio.')' : '')
                        .(! empty($r->gross_amount) ? ': gross Rs '.number_format((float) $r->gross_amount, 2).' - TDS Rs '.number_format((float) ($r->tds_amount ?? 0), 2).' =' : ':')
                        .' Rs '.number_format((float) $r->amount, 2)
                        .($payout !== '' ? ' (payout '.substr($payout, 0, 10).')' : ''),
                    'purpose' => trim((string) (($r->purpose ?? '') ?: 'Commission')) ?: 'Commission',
                    'amount' => (float) $r->amount,
                ];
            }
        } catch (\Throwable $e) {
            return ['sum' => [], 'rows' => []];
        }

        return $out;
    }

    /**
     * rev176 — Loan EMIs currently DUE for an employee, tolerant of BOTH loan
     * schemas: the live request-module (`emi`, `tenure_months`) and the legacy
     * loans screen (`installment_amount`, `installments_total`). The previous
     * sum('emi') silently returned 0 on the legacy schema. A loan is due while
     * its status is approved/active, its start month (legacy schedule) has
     * been reached, and its recovered installment count is below the total
     * (unknown total → due until manually closed).
     * Returns [['id' => loanId, 'emi' => amount], ...].
     */
    // 30 Sep 2026 — public: EmiReminderService quotes the exact figure payroll will deduct.
    public function loanEmiDue(int $employeeId, $tid, string $month): array
    {
        try {
            if (! Schema::hasTable('loans')) {
                return [];
            }
            $rows = DB::table('loans')->where('employee_id', $employeeId)
                ->when($tid && Schema::hasColumn('loans', 'tenant_id'), fn ($q) => $q->where('tenant_id', $tid))
                ->whereIn('status', ['approved', 'active'])
                ->limit(50)->get();
            $out = [];
            foreach ($rows as $ln) {
                $emi = (float) (($ln->emi ?? null) ?: ($ln->installment_amount ?? 0));
                if ($emi <= 0) {
                    continue;
                }
                // legacy schedule: recovery starts only from start_month (Y-m).
                $startM = substr(trim((string) ($ln->start_month ?? '')), 0, 7);
                if ($startM !== '' && $startM > $month) {
                    continue;
                }
                $total = (int) (($ln->installments_total ?? null) ?: ($ln->tenure_months ?? 0));
                $paid = (int) ($ln->installments_paid ?? 0);
                if ($total > 0 && $paid >= $total) {
                    continue;   // fully recovered (rev172 H1 safety — both schemas)
                }
                if (property_exists($ln, 'outstanding') && $ln->outstanding !== null && (float) $ln->outstanding <= 0) {
                    continue;
                }
                // rev180 — carry the record type so legacy loans of type 'advance'
                // show as their own "Advance EMI" line instead of "Loan EMI".
                $out[] = ['id' => (int) $ln->id, 'emi' => round($emi, 2),
                    'type' => strtolower(trim((string) ($ln->type ?? 'loan'))) === 'advance' ? 'advance' : 'loan'];
            }

            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * rev180 — SANDWICH RULE support: working-day dates covered by APPROVED
     * PAID leave inside the month (weekly offs / holidays inside the span are
     * skipped, unpaid Loss-of-Pay types contribute nothing) — mirrors the
     * paidLeaveDays() rules but returns the actual dates. Fail-soft: [].
     */
    private function paidLeaveDates(int $empId, $tid, string $month, string $endDate, array $holidays, array $rates): array
    {
        return array_keys($this->leaveDates($empId, $tid, $this->periodStart($month), $endDate, $holidays, $rates)['paid']);
    }

    /**
     * 7 Oct 2026 (Ejaz) — approved leave in [from, to] on WORKING days, split
     * into paid and unpaid ('Y-m-d' => true). Leaves store the type by NAME
     * (leaves.type_name); the old check read leaves.type_id, which the leave
     * form never writes, so every Loss of Pay leave was paid as a leave day.
     */
    private function leaveDates(int $empId, $tid, string $from, string $to, array $holidays, array $rates): array
    {
        $out = ['paid' => [], 'unpaid' => []];
        if ($from > $to || ! Schema::hasTable('leaves')) {
            return $out;
        }
        try {
            $rows = DB::table('leaves')->where('employee_id', $empId)
                ->when($tid && Schema::hasColumn('leaves', 'tenant_id'), fn ($q) => $q->where('tenant_id', $tid))
                ->where('status', 'approved')
                ->where('from_date', '<=', $to)
                ->where('to_date', '>=', $from)
                ->limit(60)->get();
            foreach ($rows as $lv) {
                $bucket = self::leaveIsPaid($lv, $tid) ? 'paid' : 'unpaid';
                $f = Carbon::parse(max(substr((string) $lv->from_date, 0, 10), $from));
                $t = Carbon::parse(min(substr((string) $lv->to_date, 0, 10), $to));
                for ($d = $f->copy(); $d->lte($t); $d->addDay()) {
                    if (! self::isWeekOff($d, $rates) && ! isset($holidays[$d->toDateString()])) {
                        $out[$bucket][$d->toDateString()] = true;
                    }
                }
            }
            $out['paid'] = array_diff_key($out['paid'], $out['unpaid']);   // overlapping LOP wins
        } catch (\Throwable $e) {
        }

        return $out;
    }

    /** Is this approved leave a PAID type? leave_types by id, else by name; "Loss of Pay" / LWP / unpaid names are unpaid. */
    private static function leaveIsPaid(object $lv, $tid): bool
    {
        static $maps = [];
        $k = (int) $tid;
        if (! isset($maps[$k])) {
            $maps[$k] = ['id' => [], 'name' => []];
            try {
                if (Schema::hasTable('leave_types')) {
                    foreach (DB::table('leave_types')
                        ->when($tid && Schema::hasColumn('leave_types', 'tenant_id'), fn ($q) => $q->where(fn ($w) => $w->where('tenant_id', $tid)->orWhereNull('tenant_id')))
                        ->get() as $lt) {
                        $paid = (int) ($lt->paid ?? 1) === 1;
                        $maps[$k]['id'][(int) $lt->id] = $paid;
                        $maps[$k]['name'][strtolower(trim((string) ($lt->name ?? '')))] = $paid;
                    }
                }
            } catch (\Throwable $e) {
            }
        }
        $id = (int) ($lv->type_id ?? 0);
        if ($id && isset($maps[$k]['id'][$id])) {
            return $maps[$k]['id'][$id];
        }
        $name = strtolower(trim((string) ($lv->type_name ?? '')));
        if ($name !== '' && isset($maps[$k]['name'][$name])) {
            return $maps[$k]['name'][$name];
        }

        return ! preg_match('/loss\s*of\s*pay|\blop\b|\blwp\b|unpaid|without\s*pay/i', $name);
    }

    /**
     * rev180 — SANDWICH RULE: count weekly-off / holiday days whose nearest
     * working day on BOTH sides (within the month) is uncovered — neither a
     * punch date nor an approved paid-leave date. Each such off-day becomes
     * one extra LOP day. Fail-soft: 0.
     */
    private function sandwichDays(string $empCode, int $empId, $tid, string $month, int $daysInMonth, Carbon $start, string $endDate, array $holidays, array $rates, int $dojDay = 1): int
    {
        try {
            $covered = [];
            foreach ($this->presentDates($empCode, $month, $endDate, $tid) as $d) {
                $covered[$d] = true;
            }
            foreach ($this->paidLeaveDates($empId, $tid, $month, $endDate, $holidays, $rates) as $d) {
                $covered[$d] = true;
            }
            $isOff = function (int $day) use ($start, $rates, $holidays): bool {
                $cur = $start->copy()->addDays($day - 1);   // rev191 — period index

                return self::isWeekOff($cur, $rates) || isset($holidays[$cur->toDateString()]);
            };
            $count = 0;
            // DOJ-aware: days before a mid-month joiner's DOJ are not absences,
            // so scanning starts after the DOJ and the previous working day must
            // itself be on/after the DOJ (else a fully-present joiner would be
            // docked for pre-joining weekends).
            for ($d = max(2, $dojDay + 1); $d < $daysInMonth; $d++) {
                if (! $isOff($d)) {
                    continue;
                }
                $p = $d - 1;
                while ($p >= 1 && $isOff($p)) {
                    $p--;
                }
                $n = $d + 1;
                while ($n <= $daysInMonth && $isOff($n)) {
                    $n++;
                }
                if ($p < $dojDay || $p < 1 || $n > $daysInMonth) {
                    continue;   // month/DOJ edge — no expected working day on one side, not a sandwich
                }
                $pd = $start->copy()->addDays($p - 1)->toDateString();   // rev191 — period index
                $nd = $start->copy()->addDays($n - 1)->toDateString();
                if ($nd > $endDate) {
                    continue;   // 7 Oct 2026 — the next working day has not happened yet: not an absence
                }
                if (empty($covered[$pd]) && empty($covered[$nd])) {
                    $count++;
                }
            }

            return $count;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * PAY DATE — 7 Oct 2026 (Ejaz): the first Pay Day AFTER the attendance
     * period ends (PeriodResolver::window). The pay day is never a day of the
     * period it pays; no Pay Day configured → the period end.
     */
    private function resolvePayDate($tid, object $company, string $month, string $fallbackEnd): string
    {
        return AppDataController::payDateFor($tid, (string) $company->name, $month);
    }

    /** 30 Sep 2026 — public: EmiReminderService quotes the exact figure payroll will deduct. */
    public function advanceRecovery(int $employeeId, $tid, string $month): float
    {
        return round(array_sum(array_column($this->adhocDue('advances', $employeeId, $tid, $month), 'amount')), 2);
    }

    /**
     * 7 Oct 2026 (Ejaz) — one-off approved money items settled through payroll:
     * expense reimbursements and bonus / leave encashment are PAID (+), salary
     * advances are RECOVERED (−).
     *
     * An item belongs to the payroll whose PERIOD holds its approval date (a date
     * after the cut-off goes to the next payroll). One period of catch-up: an
     * item approved in the previous period AFTER that period's run was generated
     * (or with no run at all) comes into this one, so nothing approved late is
     * ever lost. Run-keyed (paid_run_id) — a regenerate reverses it cleanly.
     */
    private const ADHOC = [
        'expenses' => ['sign' => 1, 'label' => 'Expense Reimbursement', 'kind' => null],
        'bonus_encashment' => ['sign' => 1, 'label' => 'Bonus / Encashment', 'kind' => 'kind'],
        'advances' => ['sign' => -1, 'label' => 'Salary Advance Recovery', 'kind' => null],
    ];

    /** @return array<int,array{id:int,amount:float,label:string}> */
    private function adhocDue(string $table, int $empId, $tid, string $month): array
    {
        static $ctx = [];
        try {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'amount') || ! Schema::hasColumn($table, 'employee_id')) {
                return [];
            }
            $co = DB::table('employees')->where('id', $empId)->first(['company_id']);
            $cid = (int) ($co->company_id ?? 0);
            $key = $tid.'|'.$cid.'|'.$month;
            if (! isset($ctx[$key])) {
                $cname = (string) (DB::table('companies')->where('id', $cid)->value('name') ?? '');
                $cut = PeriodResolver::masterDay($tid, $cname, 'cutoff_day');
                $prev = Carbon::parse($month.'-01')->subMonthNoOverflow()->format('Y-m');
                $ctx[$key] = [
                    'cut' => ($cut >= 2 && $cut <= 28) ? $cut : null,
                    'prev' => $prev,
                    'prevRunAt' => (string) (DB::table('payroll_runs')->where('company_id', $cid)
                        ->when($tid, fn ($q) => $q->where('tenant_id', $tid))
                        ->where('cycle_label', $prev)->min('created_at') ?? ''),
                ];
            }
            $x = $ctx[$key];
            $cfg = self::ADHOC[$table];
            $rows = DB::table($table)->where('employee_id', $empId)
                ->when($tid && Schema::hasColumn($table, 'tenant_id'), fn ($q) => $q->where('tenant_id', $tid))
                ->where('status', 'approved')
                ->when(Schema::hasColumn($table, 'paid_run_id'), fn ($q) => $q->whereNull('paid_run_id'))
                ->when($table === 'expenses' && Schema::hasColumn($table, 'accounts_status'), fn ($q) => $q->where(fn ($w) => $w->whereNull('accounts_status')->orWhere('accounts_status', '')->orWhere('accounts_status', 'confirmed')))
                ->where('amount', '>', 0)->orderBy('id')->limit(100)->get();
            $out = [];
            foreach ($rows as $r) {
                $when = (string) (($r->decided_at ?? null) ?: ($r->created_at ?? ''));
                if ($when === '') {
                    continue;
                }
                $m = PeriodResolver::payrollMonthFor(substr($when, 0, 10), $x['cut']);
                $due = $m === $month || ($m === $x['prev'] && ($x['prevRunAt'] === '' || $when > $x['prevRunAt']));
                if (! $due) {
                    continue;
                }
                $kind = $cfg['kind'] ? trim((string) ($r->{$cfg['kind']} ?? '')) : '';
                $out[] = ['id' => (int) $r->id, 'amount' => round((float) $r->amount, 2), 'label' => $kind !== '' ? $kind : $cfg['label']];
            }

            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * rev181 — APPROVED Clawbacks / Reversals DUE this payroll month, per
     * employee. Until now the Clawbacks module recorded reversals but NOTHING
     * consumed them — an approved clawback never actually deducted from pay.
     * Rules (Ejaz, 10 Jul 2026):
     *   - due month = the clawback's cycle_month (fallback: its created month);
     *   - ONE-MONTH-ONLY recovery: the engine attempts recovery in the due
     *     month's run, takes what fits in the net (net never goes negative) and
     *     records it; any shortfall stays OPEN on the row for manual handling
     *     (edit the clawback's month to retry, or recover outside payroll);
     *   - run-keyed: recovered_run_id/-amount/-at are stamped on generate and
     *     cleared when that draft is regenerated (same pattern as loan EMIs).
     * Returns [['id' => clawbackId, 'due' => amount], ...]. Fail-soft: [].
     */
    private function clawbacksDue(int $employeeId, $tid, string $month): array
    {
        try {
            if (! Schema::hasTable('clawbacks') || ! Schema::hasColumn('clawbacks', 'amount')) {
                return [];
            }
            $rows = DB::table('clawbacks')->where('employee_id', $employeeId)
                ->when($tid && Schema::hasColumn('clawbacks', 'tenant_id'), fn ($q) => $q->where('tenant_id', $tid))
                ->where('status', 'approved')
                ->when(Schema::hasColumn('clawbacks', 'recovered_run_id'), fn ($q) => $q->whereNull('recovered_run_id'))
                ->limit(50)->get();
            $out = [];
            foreach ($rows as $cb) {
                $amt = round((float) ($cb->amount ?? 0), 2);
                if ($amt <= 0) {
                    continue;
                }
                $due = substr(trim((string) ($cb->cycle_month ?? '')), 0, 7);
                if ($due === '' || ! preg_match('/^\d{4}-\d{2}$/', $due)) {
                    $due = substr((string) ($cb->created_at ?? ''), 0, 7);
                }
                if ($due !== $month) {
                    continue;
                }
                $out[] = ['id' => (int) $cb->id, 'due' => $amt];
            }

            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * rev176 — APPROVED Overtime Register entries dated inside the month, per
     * employee. The register already valued OT (hours × multiplier × CTC/12/26/8)
     * but payroll never paid it; now each approved entry is paid on the payslip
     * and marked 'paid' on generate (reversed if the draft is regenerated).
     * Returns ['sum' => [empId => amount], 'ids' => [empId => [otId...]]].
     */
    private function overtimeByEmployee($tid, string $month, string $endDate): array
    {
        $out = ['sum' => [], 'ids' => []];
        try {
            if (! Schema::hasTable('overtime') || ! Schema::hasColumn('overtime', 'employee_id')) {
                return $out;
            }
            $rows = DB::table('overtime')
                ->when($tid && Schema::hasColumn('overtime', 'tenant_id'), fn ($q) => $q->where('tenant_id', $tid))
                ->where('status', 'approved')
                ->whereNotNull('employee_id')
                ->where('amount', '>', 0)
                ->whereBetween('ot_date', [$this->periodStart($month), $endDate])
                ->get(['id', 'employee_id', 'amount']);
            foreach ($rows as $r) {
                $eid = (int) $r->employee_id;
                $out['sum'][$eid] = round(($out['sum'][$eid] ?? 0) + (float) $r->amount, 2);
                $out['ids'][$eid][] = (int) $r->id;
            }
        } catch (\Throwable $e) {
            return ['sum' => [], 'ids' => []];
        }

        return $out;
    }

    /** All candidate salary components for the company + tenant-wide (resolved per-employee in the loop). */
    private function componentRows($company, $tid)
    {
        if (! Schema::hasTable('salary_components')) {
            return collect();
        }
        try {
            return DB::table('salary_components')
                ->when($tid && Schema::hasColumn('salary_components', 'tenant_id'), fn ($x) => $x->where('tenant_id', $tid))
                ->when(Schema::hasColumn('salary_components', 'company_name'), function ($x) use ($company) {
                    $x->where(function ($y) use ($company) {
                        $y->where('company_name', $company->name)->orWhereNull('company_name')->orWhere('company_name', '');
                    });
                })
                ->get();
        } catch (\Throwable $e) {
            return collect();
        }
    }

    /** Most-specific component set for an employee: employee > team > company/tenant-wide. */
    private function resolveComponents($rows, ?string $empCode, ?string $teamName)
    {
        if ($rows->isEmpty()) {
            return collect();
        }
        $scopeOf = fn ($r) => strtolower((string) (((array) $r)['scope'] ?? '')) ?: 'company';
        $targetOf = fn ($r) => (string) (((array) $r)['scope_target'] ?? '');

        if ($empCode) {
            $emp = $rows->filter(fn ($r) => $scopeOf($r) === 'employee' && strcasecmp($targetOf($r), $empCode) === 0)->values();
            if ($emp->isNotEmpty()) {
                return $emp;
            }
        }
        if ($teamName) {
            $team = $rows->filter(fn ($r) => $scopeOf($r) === 'team' && $targetOf($r) !== '' && strcasecmp($targetOf($r), $teamName) === 0)->values();
            if ($team->isNotEmpty()) {
                return $team;
            }
        }

        return $rows->filter(fn ($r) => in_array($scopeOf($r), ['company', ''], true))->values();
    }

    /** Working-day company holidays in the month: map of 'Y-m-d' => true. */
    private function holidayDates(?int $tid, string $month, string $endDate, array $rates = []): array
    {
        if (! Schema::hasTable('holidays')) {
            return [];
        }
        try {
            // 7 Oct 2026 — company-scoped holidays (Applies To); a rates array that
            // carries no company (tenant-wide screens) keeps every holiday.
            $set = \App\Services\WorkCalendar::holidays($tid, $this->periodStart($month), $endDate);
            $co = array_key_exists('__company', $rates) ? $rates['__company'] : null;
            $rows = $co === null ? array_keys($set) : array_keys(\App\Services\WorkCalendar::holidaySetFor($set, $co));
            $out = [];
            foreach ($rows as $d) {
                $ds = substr((string) $d, 0, 10);
                // A holiday on a weekly off is already a non-working day; only count
                // working-day holidays (they remove a day from the LOP denominator).
                if (! self::isWeekOff(Carbon::parse($ds), $rates)) {
                    $out[$ds] = true;
                }
            }

            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** Approved PAID-leave working-days for an employee within the month. */
    private function paidLeaveDays(int $empId, ?int $tid, string $month, string $endDate, array $holidays, array $rates = []): float
    {
        return (float) count($this->leaveDates($empId, $tid, $this->periodStart($month), $endDate, $holidays, $rates)['paid']);
    }

    /**
     * 7 Oct 2026 (Ejaz) — ONE attendance scorer for payroll, Live Salary and
     * Live Salary (all): service window (DOJ → approved exit's last working
     * day), present days on WORKING days only, paid vs unpaid leave, late /
     * break cuts, sandwich rule, LOP basis → proration factor and the payslip's
     * Paid / LOP days. Returns null when the employee is not in service at all
     * inside the period.
     *
     * $x: tid, month, start (Carbon), periodEnd, endDate, daysInMonth, working,
     *     rates, holidays, lopBasis, sandwichOn, polRows, shiftDefs, rosterMap,
     *     lop, lwd (?string)
     */
    private function scoreEmployee(object $e, array $x): ?array
    {
        ['tid' => $tid, 'month' => $month, 'start' => $start, 'periodEnd' => $periodEnd, 'endDate' => $endDate,
            'daysInMonth' => $daysInMonth, 'working' => $working, 'rates' => $rates, 'holidays' => $holidays,
            'lopBasis' => $lopBasis, 'sandwichOn' => $sandwichOn, 'polRows' => $polRows, 'shiftDefs' => $shiftDefs,
            'rosterMap' => $rosterMap, 'lop' => $lop, 'lwd' => $lwd] = $x;
        // A holiday scoped to this employee's BRANCH (Leave → Holidays → Applies To)
        // is a paid non-working day for them too, not an absence.
        $br = trim((string) ($e->branch ?? ''));
        if ($lop && $br !== '' && ! empty($x['holRaw'])) {
            $more = 0;
            foreach (\App\Services\WorkCalendar::holidaySetFor($x['holRaw'], $rates['__company'] ?? null, $br) as $hd => $_) {
                if (! isset($holidays[$hd]) && ! self::isWeekOff(Carbon::parse($hd), $rates)) {
                    $holidays[$hd] = true;
                    $more++;
                }
            }
            if ($more) {
                [$working] = $this->workingSpan($start, $periodEnd, $periodEnd, $rates, $holidays);
            }
        }
        // 7 Oct 2026 (Ejaz) — SERVICE WINDOW inside the period: from the date of
        // joining (if later) to the approved exit's last working day (if
        // earlier). Days outside it are neither paid nor absences.
        $svcFrom = $start->copy();
        $dojDay = 1;
        $dojField = property_exists($e, 'doj') ? $e->doj : null;
        if ($dojField) {
            try {
                $doj = Carbon::parse(substr((string) $dojField, 0, 10))->startOfDay();
                if ($doj->gt($start)) {
                    $svcFrom = $doj;
                    $dojDay = (int) $start->diffInDays($doj) + 1;
                }
            } catch (\Throwable $e2) {
                // unparseable DOJ → whole period
            }
        }
        $svcTo = ($lwd !== null && $lwd < $periodEnd) ? $lwd : $periodEnd;
        if ($svcFrom->toDateString() > $svcTo) {
            return null;
        }
        $scoreTo = $endDate < $svcTo ? $endDate : $svcTo;
        [$empWorking, $empSoFar] = $this->workingSpan($svcFrom, $svcTo, $scoreTo, $rates, $holidays);
        $empSoFar = min($empWorking, $empSoFar);

        // Attendance on WORKING days only. A punch on a weekly off / holiday is
        // not an extra paid day (those are already paid, or earn Comp-Off / OT);
        // a paid-leave day the employee also punched counts once.
        $present = 0;
        $leave = 0.0;
        $unpaidLeave = 0;
        $offWorked = 0;
        $workDates = [];
        if ($lop) {
            $fromS = $svcFrom->toDateString();
            foreach ($this->presentDates($e->emp_code, $month, $endDate, $tid) as $pd) {
                if ($pd < $fromS || $pd > $scoreTo) {
                    continue;
                }
                if (self::isWeekOff(Carbon::parse($pd), $rates) || isset($holidays[$pd])) {
                    $offWorked++;

                    continue;
                }
                $workDates[$pd] = true;
            }
            $lvd = $this->leaveDates((int) $e->id, $tid, $fromS, $scoreTo, $holidays, $rates);
            $present = count($workDates);
            $leave = (float) count(array_diff_key($lvd['paid'], $workDates));
            $unpaidLeave = count(array_diff_key($lvd['unpaid'], $workDates));
        }
        $lateCut = 0.0;
        $breakCut = 0.0;
        $lateDays = 0;
        $factor = 1.0;
        $pol = null;
        $dispPaid = null;   // rev177 — paid/denominator pair for the calc note, in the chosen basis
        $dispDen = null;
        $sandwichDays = 0;  // rev180 — off-days between absences counted as LOP (sandwich rule)
        $woDays = 0;        // 8 Oct 2026 — paid weekly offs / holidays (calc note)
        $holDays = 0;
        $absentOnly = 0;
        $attLine = null;
        $slipPaid = null;   // 7 Oct 2026 — "Paid X / LOP Y of Z" the payslip prints
        $slipLop = 0.0;
        $slipDen = null;
        if ($lop) {
            // Resolve the most specific policy (employee > team > company), then
            // run the late / break engine on WORKING days inside the service window.
            $team = property_exists($e, 'team') ? $e->team : null;
            $pol = $polRows->isEmpty() ? null : $this->resolvePolicy($polRows, $e->emp_code, $team);
            if ($pol) {
                $stats = array_intersect_key($this->dayStats($e->emp_code, $month, $endDate, $tid), $workDates);
                // rev173 — per-day shift resolution (roster > employee default).
                $dayShifts = [];
                if ($shiftDefs) {
                    $empShift = property_exists($e, 'shift') ? $e->shift : null;
                    foreach (array_keys($stats) as $sd) {
                        $dayShifts[$sd] = \App\Services\ShiftResolver::resolve($shiftDefs, $rosterMap, (string) $e->name, $empShift, $sd);
                    }
                }
                $res = $this->attendanceCut($stats, $pol, $dayShifts);
                $lateCut = $res['lateCut'];
                $breakCut = $res['breakCut'];
                $lateDays = $res['late'];
            }
            // Paid leave counts as present; late / break cuts come off.
            $paidDays = max(0.0, min($empWorking, $present + $leave) - $lateCut - $breakCut);
            // 8 Oct 2026 (Ejaz) — weekly offs, holidays and paid leave are PAID
            // days, exactly like a present day; ONLY leave without pay and
            // absence (plus late cuts, and the sandwich rule when it is switched
            // ON in Settings) are loss of pay. The 'working days' day-value was
            // retired: paid days are now always counted over the calendar span
            // (Paid = Present + Paid leave + Weekly offs + Holidays − cuts).
            if ($sandwichOn) {
                $sandwichDays = $this->sandwichDays($e->emp_code, (int) $e->id, $tid, $month, $daysInMonth, $start, $scoreTo, $holidays, $rates, $dojDay);
            }
            // Weekly offs / holidays inside the service window that have elapsed.
            for ($d = $svcFrom->copy(); $d->toDateString() <= $scoreTo; $d->addDay()) {
                if (self::isWeekOff($d, $rates)) {
                    $woDays++;
                } elseif (isset($holidays[$d->toDateString()])) {
                    $holDays++;
                }
            }
            // Absence is measured over the working days of the service window
            // that have actually elapsed; days still to come are not absences.
            $absentBase = (float) $empSoFar;
            $absentOnly = max(0, $empSoFar - $present - (int) $leave - $unpaidLeave);
            $absentDays = max(0.0, $absentBase - $paidDays) + $sandwichDays;   // = absent + LWP + cuts + sandwich
            $svcCal = $scoreTo < $svcFrom->toDateString() ? 0 : (int) $svcFrom->diffInDays(Carbon::parse($scoreTo)) + 1;
            if ($lopBasis === 'fixed30') {
                $den = 30.0;
                $avail = round(30.0 * $svcCal / max(1, $daysInMonth), 2);
            } else {
                $den = (float) $daysInMonth;
                $avail = (float) $svcCal;
            }
            $factor = max(0.0, min(1.0, ($avail - $absentDays) / max(1.0, $den)));
            // rev191 — no attendance and no approved paid leave means NO pay.
            if ($paidDays <= 0) {
                $factor = 0.0;
            }
            $dispPaid = $paidDays <= 0 ? 0.0 : max(0.0, round($avail - $absentDays, 2));
            $dispDen = $den;
            [$slipPaid, $slipLop, $slipDen] = [$dispPaid, $paidDays <= 0 ? round(min($den, $avail), 2) : round($absentDays, 2), $den];
            $d2 = fn ($x) => rtrim(rtrim(number_format((float) $x, 2, '.', ''), '0'), '.');
            $cuts = $lateCut + $breakCut;
            $attLine = 'Attendance: Present '.$present.' + Paid leave '.$d2($leave).' + Weekly off '.$woDays.' + Holiday '.$holDays
                .($cuts > 0 ? ' - late/break cut '.$d2($cuts) : '').($sandwichDays > 0 ? ' - sandwich '.$sandwichDays : '')
                .' = '.$d2($paidDays <= 0 ? 0 : max(0, $present + $leave + $woDays + $holDays - $cuts - $sandwichDays)).' paid day(s)'
                .($paidDays <= 0 ? ' (no attendance or paid leave in the period, so weekly offs / holidays are not paid either).' : '.')
                .($paidDays <= 0 ? ' Loss of Pay: the whole period, '.$d2($slipLop).' day(s).' : ' Loss of Pay: Absent '.$absentOnly.' + Leave without pay '.$unpaidLeave
                .($cuts > 0 ? ' + late/break cut '.$d2($cuts) : '').($sandwichDays > 0 ? ' + sandwich '.$sandwichDays : '')
                .' = '.$d2($slipLop).' day(s).');
        }

        return compact('svcFrom', 'dojDay', 'lwd', 'scoreTo', 'empWorking', 'empSoFar', 'present', 'leave', 'unpaidLeave', 'offWorked', 'workDates', 'lateCut', 'breakCut', 'lateDays', 'factor', 'pol', 'dispPaid', 'dispDen', 'sandwichDays', 'slipPaid', 'slipLop', 'slipDen', 'woDays', 'holDays', 'absentOnly', 'attLine');
    }

    /**
     * Build the per-employee salary lines for a month. Returns
     * [rows[], totals, skipped, meta]. Pure computation — no writes.
     */
    private function compute(Request $request, object $company, string $month, bool $lop): array
    {
        $tid = $request->user()->tenant_id;
        // 7 Oct 2026 — this company's weekly offs (override) + its holidays only.
        $rates = \App\Services\WorkCalendar::forCompany(SettingsController::rates($tid), $company->id);
        $this->runCutoff = $this->resolveCutoffDay($tid, $company);   // rev181 — attendance cut-off period (0 = calendar month)
        [$start, $daysInMonth, , $periodEnd] = $this->monthMeta($month);

        // rev191 (Ejaz, 23 Sep 2026) — NEVER pay for days that have not happened.
        // Before this, running September's payroll on the 23rd built the window as
        // 1–30 Sep and paid a full 30 days. Now:
        //   • the DENOMINATOR still spans the whole period (a full month is a full
        //     month — so one absent day always costs the same),
        //   • but attendance is only ever counted up to TODAY, so an open period
        //     pays what has been EARNED to date and nothing more.
        // Closed (past) periods are completely unaffected.
        $today = Carbon::today()->toDateString();
        $periodOpen = $periodEnd > $today;
        $endDate = $periodOpen ? $today : $periodEnd;

        // rev177 — LOP basis (Statutory Settings → "Salary / LOP day basis"):
        // what one day of salary is WORTH when prorating.
        //   working  — denominator = working days (month − weekly offs − holidays).
        //              1 absent day costs gross/working-days. The original model.
        //   calendar — denominator = calendar days of the month; weekly offs and
        //              holidays are PAID days. 1 absent day costs gross/31 (etc.)
        //              — matches the common Indian payslip "Total days 31, LOP 2".
        //   fixed30  — every month is a flat 30 days; per-day value = gross/30.
        // Absence itself is always MEASURED in working-day terms (you can only
        // be absent on a day you were expected to work); only the day VALUE changes.
        $lopBasis = self::lopBasisOf($rates);
        // rev180 — optional SANDWICH RULE: a weekly off / holiday that falls
        // BETWEEN two absent working days counts as LOP too. Off by default.
        $sandwichOn = ! empty($rates['sandwich_rule']);

        // Working days = calendar days minus weekly offs (configurable) minus working-day holidays.
        // rev191 — holidays and working days span the WHOLE period ($periodEnd),
        // never the capped attendance window: they are the denominator.
        $holidays = $lop ? $this->holidayDates($tid, $month, $periodEnd, $rates) : [];
        // rev191 — one shared span walk (see workingSpan). Days beyond $endDate
        // have not happened yet and are not loss of pay; the count travels on the
        // calc note so no payslip ever prints them as LOP.
        [$working, $workingSoFar] = $this->workingSpan($start, $periodEnd, $endDate, $rates, $holidays);
        $notElapsed = max(0, $working - $workingSoFar);
        // rev191 — the same question in CALENDAR terms, for the calendar / fixed30
        // day-value bases (where weekly offs are paid days, so the unworked tail of
        // the period has to be measured in calendar days, not working days).
        $calSoFar = $periodOpen ? ((int) $start->diffInDays(Carbon::parse($endDate)) + 1) : $daysInMonth;
        $notElapsedCal = max(0, $daysInMonth - $calSoFar);
        // What the calc note should report, expressed in the SAME unit as the
        // "Paid X of Y days" line the note already prints for this basis.
        $noteNotElapsed = $lopBasis === 'working'
            ? (float) $notElapsed
            : ($lopBasis === 'fixed30' ? round(30.0 * $notElapsedCal / max(1, $daysInMonth), 2) : (float) $notElapsedCal);

        $empSel = ['id', 'emp_code', 'name', 'ctc'];
        if (Schema::hasColumn('employees', 'branch')) {
            $empSel[] = 'branch';   // 7 Oct 2026 — branch-scoped holidays
        }
        if (Schema::hasColumn('employees', 'branch_id')) {
            $empSel[] = 'branch_id'; // F1 branch and location statutory scope
        }
        if (Schema::hasColumn('employees', 'team')) {
            $empSel[] = 'team';
        }
        if (Schema::hasColumn('employees', 'shift')) {
            $empSel[] = 'shift'; // rev173 — default Working Shift (name)
        }
        if (Schema::hasColumn('employees', 'doj')) {
            $empSel[] = 'doj'; // rev172 (M1) — needed to prorate mid-month joiners
        }
        if (Schema::hasColumn('employees', 'employment_stage')) {
            // rev176 (BUGFIX) — without this column in the select, the
            // probation/internship PF-PT-TDS exemption (rev174) NEVER fired in
            // generated runs: $e->employment_stage was always null here even
            // though Live Salary (which selects employees.*) honoured it.
            $empSel[] = 'employment_stage';
        }
        if (Schema::hasColumn('employees', 'pt_state')) {
            $empSel[] = 'pt_state'; // rev180 — state-wise Professional Tax slabs
        }
        if (Schema::hasColumn('employees', 'gender')) {
            $empSel[] = 'gender'; // PT — Maharashtra female exemption (gross ≤ ₹25,000)
        }
        foreach (['designation', 'designation_id'] as $c) {   // 10 Oct 2026 — PT rules by designation
            if (Schema::hasColumn('employees', $c)) {
                $empSel[] = $c;
            }
        }
        // 7 Oct 2026 (Ejaz) — Full & Final: an approved exit's last working day
        // closes the employee's service window. A leaver already marked inactive
        // still gets the final period's salary if they worked part of it.
        $exitLwd = $this->exitDates($tid);
        $leavers = array_keys(array_filter($exitLwd, fn ($d) => $d >= $start->toDateString()));
        $emps = DB::table('employees')
            ->when($tid, fn ($q) => $q->where('tenant_id', $tid))
            ->where('company_id', $company->id)
            ->where(fn ($q) => $q->where('status', 'active')->orWhereIn('id', $leavers ?: [0]))
            ->whereNull('deleted_at')
            ->when(Schema::hasColumn('employees', 'archived_at'), fn ($q) => $q->whereNull('archived_at'))   // rev183b — exclude backed-up
            ->orderBy('emp_code')
            ->get($empSel);

        // All candidate late policies for this company (resolved per-employee in the loop).
        // F1 — branch to city map, for the branch and location statutory scope.
        $branchCityMap = Schema::hasTable('branches')
            ? DB::table('branches')->where('company_id', $company->id)->pluck('city', 'id')->toArray()
            : [];

        $polRows = $lop ? $this->latePolicyRows($company, $tid) : collect();

        // rev173 — Working Shifts: named timings + roster overrides. Resolved
        // per employee per day; a resolved shift's timings replace the Late
        // Policy's shift_start/end (the policy keeps owning the RULES).
        // Night shifts (end <= start) additionally pay night_allowance per
        // night actually worked.
        $shiftDefs = \App\Services\ShiftResolver::shifts($tid);
        $rosterMap = $shiftDefs ? \App\Services\ShiftResolver::rosterMap($tid, $this->periodStart($month), $endDate) : [];
        $anyNight = false;
        foreach ($shiftDefs as $sd) {
            if ($sd['night'] && $sd['allowance'] > 0) {
                $anyNight = true;
                break;
            }
        }

        // Approved commissions DUE in this month (payout date first, earned
        // month fallback) → added to each employee's pay. rev 84.
        $commData = $this->commissionByEmployee($company, $tid, $month);
        $commByEmp = $commData['sum'];
        $commRowsByEmp = $commData['rows'];

        // Candidate salary components (resolved per-employee in the loop: employee > team > company).
        $compRows = $this->componentRows($company, $tid);

        // rev176 — approved Overtime Register entries for the month (paid on the payslip).
        $otData = $this->overtimeByEmployee($tid, $month, $endDate);

        $ctx = ['tid' => $tid, 'month' => $month, 'start' => $start, 'periodEnd' => $periodEnd, 'endDate' => $endDate,
            'daysInMonth' => $daysInMonth, 'working' => $working, 'rates' => $rates, 'holidays' => $holidays,
            'lopBasis' => $lopBasis, 'sandwichOn' => $sandwichOn, 'polRows' => $polRows, 'shiftDefs' => $shiftDefs,
            'rosterMap' => $rosterMap, 'lop' => (bool) $lop,
            'holRaw' => \App\Services\WorkCalendar::holidays($tid, $start->toDateString(), $periodEnd)];
        $rows = [];
        $skipped = 0;
        $tg = 0.0;
        $td = 0.0;
        $tn = 0.0;
        foreach ($emps as $e) {
            $ctc = (float) $e->ctc;
            if ($ctc <= 0) {
                $skipped++;

                continue;   // no CTC set → cannot compute a salary; flagged to the user
            }
            $sc = $this->scoreEmployee($e, $ctx + ['lwd' => $exitLwd[(int) $e->id] ?? null]);
            if ($sc === null) {
                continue;   // joins after this period, or left before it — nothing payable here
            }
            ['svcFrom' => $svcFrom, 'dojDay' => $dojDay, 'lwd' => $lwd, 'present' => $present, 'leave' => $leave, 'unpaidLeave' => $unpaidLeave, 'offWorked' => $offWorked, 'lateCut' => $lateCut, 'breakCut' => $breakCut, 'lateDays' => $lateDays, 'factor' => $factor, 'pol' => $pol, 'dispPaid' => $dispPaid, 'dispDen' => $dispDen, 'sandwichDays' => $sandwichDays, 'slipPaid' => $slipPaid, 'slipLop' => $slipLop, 'slipDen' => $slipDen] = $sc;
            $teamC = property_exists($e, 'team') ? $e->team : null;
            $salComps = $this->resolveComponents($compRows, $e->emp_code, $teamC);
            // rev180 — statutory context: state-wise PT (employee's pt_state,
            // month-aware for Maharashtra's February) + the ESI
            // contribution-period lock (keeps ESI deducting past the ₹21,000
            // ceiling until the Apr–Sep / Oct–Mar period ends).
            // The ESI period lock only matters when the plain rule would DROP
            // ESI (gross above the ceiling) — skip the history query otherwise.
            $grossEst = round($ctc * $factor / 12, 2);
            $stx = [
                'tenant_id' => $tid,                                   // F1 scope
                'company_id' => $company->id ?? null,                   // F1 scope
                'branch_id' => (int) ($e->branch_id ?? 0) ?: null,       // F1 branch scope
                'branch_city' => (string) ($branchCityMap[$e->branch_id ?? 0] ?? ''), // F1 location scope
                'pt_state' => (string) ($e->pt_state ?? ''),
                'gender' => (string) ($e->gender ?? ''),   // PT — MH female exemption
                'month' => $month,
                'doj' => $e->doj ?? null,   // 8 Oct 2026 — statutory deferral (first N months from joining)
                'esi_lock' => $grossEst > (float) ($rates['esi_threshold'] ?? 21000)
                    ? AppDataController::esiPeriodLock((int) $e->id, $month, $rates)
                    : false,
            ];
            $stx += AppDataController::ptCtx($e);   // 10 Oct 2026 — PT rules (branch / designation)
            $s = $salComps->isNotEmpty()
                ? (AppDataController::computeSlipFromComponents($ctc * $factor, $salComps, $rates, (string) ($e->employment_stage ?? ''), $stx) ?: AppDataController::computeSlip($ctc * $factor, $rates, (string) ($e->employment_stage ?? ''), $stx))
                : AppDataController::computeSlip($ctc * $factor, $rates, (string) ($e->employment_stage ?? ''), $stx);
            $commission = (float) ($commByEmp[$e->id] ?? 0.0);
            $commRows = $commRowsByEmp[$e->id] ?? [];
            // rev165 — split commission entries by Purpose so incentive and
            // commission show as SEPARATE variable lines (sum still = $commission).
            $commMap = [];
            foreach ($commRows as $cr) {
                $pu = trim((string) ($cr['purpose'] ?? 'Commission')) ?: 'Commission';
                $commMap[$pu] = round(($commMap[$pu] ?? 0) + (float) ($cr['amount'] ?? 0), 2);
            }
            // rev173 — Night Shift Allowance: Rs per night ACTUALLY worked. A night
            // = a distinct punch date whose resolved shift (roster > employee
            // default) is a night shift with an allowance set, and the roster
            // doesn't mark it a week-off. Independent of the Late Policy.
            $nightAmt = 0.0;
            $nightDays = 0;
            if ($anyNight) {
                $empShiftN = property_exists($e, 'shift') ? $e->shift : null;
                foreach ($this->presentDates($e->emp_code, $month, $endDate, $tid) as $nd) {
                    $shN = \App\Services\ShiftResolver::resolve($shiftDefs, $rosterMap, (string) $e->name, $empShiftN, $nd);
                    if ($shN && empty($shN['off']) && $shN['night'] && $shN['allowance'] > 0) {
                        $nightAmt += $shN['allowance'];
                        $nightDays++;
                    }
                }
                $nightAmt = round($nightAmt, 2);
            }

            // rev176 — overtime: approved register entries dated this month.
            $otAmt = round((float) ($otData['sum'][$e->id] ?? 0), 2);
            $otIds = $otData['ids'][$e->id] ?? [];

            // rev176 — Loan EMI + salary-advance recovery ON THE PAYSLIP (they
            // previously appeared only in the Live Salary preview — the real
            // payslip never recovered them). Full-EMI-only: a loan whose EMI
            // doesn't fit in the remaining net is skipped this month (noted in
            // the calc note), so recoveries always equal whole installments.
            // Advances recover up to the remaining net. Net never goes negative.
            // 7 Oct 2026 — approved one-off earnings (expense reimbursement, bonus /
            // leave encashment) due in this payroll.
            $adhocEarn = [];
            $adhocIds = [];
            $adhocAmt = 0.0;
            foreach (['expenses', 'bonus_encashment'] as $tbl) {
                foreach ($this->adhocDue($tbl, (int) $e->id, $tid, $month) as $it) {
                    $adhocEarn[$it['label']] = round(($adhocEarn[$it['label']] ?? 0) + $it['amount'], 2);
                    $adhocIds[$tbl][] = ['id' => $it['id'], 'amount' => $it['amount']];
                    $adhocAmt = round($adhocAmt + $it['amount'], 2);
                }
            }
            $netAvail = round($s['net'] + $commission + $nightAmt + $otAmt + $adhocAmt, 2);
            $loanRecs = [];
            $emiApplied = 0.0;
            $emiSkipped = 0.0;
            $emiLoanPart = 0.0;   // rev180 — split for distinct payslip lines
            $emiAdvPart = 0.0;    // rev180 — legacy loans of type 'advance'
            foreach ($this->loanEmiDue((int) $e->id, $tid, $month) as $ln) {
                if ($ln['emi'] <= round($netAvail - $emiApplied, 2)) {
                    $emiApplied = round($emiApplied + $ln['emi'], 2);
                    if (($ln['type'] ?? 'loan') === 'advance') {
                        $emiAdvPart = round($emiAdvPart + $ln['emi'], 2);
                    } else {
                        $emiLoanPart = round($emiLoanPart + $ln['emi'], 2);
                    }
                    $loanRecs[] = $ln;
                } else {
                    $emiSkipped = round($emiSkipped + $ln['emi'], 2);
                }
            }
            // Salary advances: recovered advance by advance, as far as the net allows.
            $advDue = 0.0;
            $advApplied = 0.0;
            foreach ($this->adhocDue('advances', (int) $e->id, $tid, $month) as $it) {
                $advDue = round($advDue + $it['amount'], 2);
                $take = round(max(0.0, min($it['amount'], $netAvail - $emiApplied - $advApplied)), 2);
                if ($take > 0) {
                    $advApplied = round($advApplied + $take, 2);
                    $adhocIds['advances'][] = ['id' => $it['id'], 'amount' => $take];
                }
            }
            // rev181 — approved Clawbacks / Reversals due this month, recovered
            // from the slip AFTER loans & advances. Takes what fits (net >= 0);
            // any shortfall stays open on the clawback row (one-month-only rule).
            $cbApplied = 0.0;
            $cbDueTotal = 0.0;
            $cbRecs = [];
            foreach ($this->clawbacksDue((int) $e->id, $tid, $month) as $cb) {
                $cbDueTotal = round($cbDueTotal + $cb['due'], 2);
                $availCb = round($netAvail - $emiApplied - $advApplied - $cbApplied, 2);
                if ($availCb <= 0) {
                    continue;
                }
                $take = round(min($cb['due'], $availCb), 2);
                if ($take > 0) {
                    $cbApplied = round($cbApplied + $take, 2);
                    $cbRecs[] = ['id' => $cb['id'], 'amount' => $take, 'due' => $cb['due']];
                }
            }
            $extraDed = round($emiApplied + $advApplied + $cbApplied, 2);

            $gross = round($s['gross'] + $commission + $nightAmt + $otAmt + $adhocAmt, 2);
            $ded = round($s['total_ded'] + $extraDed, 2);
            $net = round($netAvail - $extraDed, 2);
            $note = $this->calcNote($ctc, $s, (bool) $lop, $present, $leave, $working, $factor, $lateDays, $lateCut, $breakCut, $pol, $commission, array_column($commRows, 'label'), $dispPaid, $dispDen, $sc['attLine'] ?? null);
            if ($defItems = AppDataController::statDeferred($rates, $e->doj ?? null, $month)) {   // 8 Oct 2026 — statutory deferral
                $note .= ' Statutory deferral: '.strtoupper(implode(', ', array_diff($defItems, ['bonus']) ?: $defItems)).' not applied (joined '.\Illuminate\Support\Carbon::parse($e->doj)->format('d-m-Y').'; applies from '.\Illuminate\Support\Carbon::parse(AppDataController::statDeferUntil($rates, $e->doj).'-01')->format('m-Y').').';
            }
            if ($unpaidLeave > 0) {
                $note .= ' Unpaid leave (Loss of Pay): '.$unpaidLeave.' day(s) - not paid.';
            }
            if ($offWorked > 0) {
                $note .= ' Worked '.$offWorked.' weekly-off / holiday day(s): already paid days, not counted again (claim Comp-Off or Overtime).';
            }
            if ($dojDay > 1) {
                $note .= ' Joined '.$svcFrom->format('d-m-Y').': paid from that date.';
            }
            if ($lwd !== null && $lwd <= $periodEnd) {
                $note .= ' Last working day '.Carbon::parse($lwd)->format('d-m-Y').' (Exit & FnF): paid up to that date.';
            }
            if ($sandwichDays > 0) {
                // rev180 — sandwich rule visible on the slip.
                $note .= ' Sandwich rule: '.$sandwichDays.' off-day(s) falling between absent days counted as LOP.';
            }
            if ($lop && $periodOpen) {
                // rev191 — the slip must say out loud that this is an earned-to-date
                // figure. The trailing count is machine-read by
                // AppDataController::payslipData so the unworked remainder of the
                // period is never printed as LOP.
                $note .= ' Period still in progress: attendance counted from '.$start->toDateString().' to '.$endDate
                    .' only (period ends '.$periodEnd.'), so this is salary EARNED TO DATE, not a full period.'
                    .' Not-yet-elapsed working day(s): '.rtrim(rtrim(number_format($noteNotElapsed, 2), '0'), '.').'.';
            }
            if ($lop && $lopBasis !== 'working') {
                // rev177 — make the chosen day-value basis explicit on the slip.
                $note .= ' LOP basis: '.($lopBasis === 'calendar'
                    ? 'calendar days — each LOP day costs gross/'.$daysInMonth.' ('.$daysInMonth.' days this month); weekly offs & holidays are paid days.'
                    : 'fixed 30 days — each LOP day costs gross/30 regardless of the month; weekly offs & holidays are paid days.');
            }
            if ($nightAmt > 0) {
                $note .= ' Night shift allowance: '.$nightDays.' night(s) worked -> Rs '.number_format($nightAmt, 0).' added.';
            }
            if ($otAmt > 0) {
                $note .= ' Overtime (approved register): Rs '.number_format($otAmt, 0).' added ('.count($otIds).' entr'.(count($otIds) === 1 ? 'y' : 'ies').').';
            }
            foreach ($adhocEarn as $al => $aa) {
                $note .= ' '.$al.' (approved): Rs '.number_format($aa, 0).' added.';
            }
            if ($emiApplied > 0) {
                $note .= ' Loan EMI recovered: Rs '.number_format($emiApplied, 0).'.';
            }
            if ($emiSkipped > 0) {
                $note .= ' Loan EMI of Rs '.number_format($emiSkipped, 0).' NOT recovered — net salary this month is not sufficient; it stays due.';
            }
            if ($advApplied > 0) {
                $note .= ' Salary advance recovered: Rs '.number_format($advApplied, 0)
                    .($advDue > $advApplied ? ' (partial — Rs '.number_format($advDue - $advApplied, 0).' left to recover manually)' : '').'.';
            }
            if ($cbApplied > 0) {
                $note .= ' Clawback / reversal recovered: Rs '.number_format($cbApplied, 0)
                    .($cbDueTotal > $cbApplied ? ' of Rs '.number_format($cbDueTotal, 0).' due (the balance stays open on the clawback entry)' : '').'.';
            } elseif ($cbDueTotal > 0) {
                $note .= ' Clawback of Rs '.number_format($cbDueTotal, 0).' due but NOT recovered — no net salary left this month; it stays open on the clawback entry.';
            }
            if ($lop && $slipPaid !== null) {
                // 7 Oct 2026 — machine-read by AppDataController::payslipData for the
                // payslip's "Paid Days / LOP" box (unelapsed or pre-joining /
                // post-exit days are neither paid nor LOP).
                $d2 = fn ($x) => rtrim(rtrim(number_format((float) $x, 2, '.', ''), '0'), '.');
                $note .= ' Payslip days: Paid '.$d2($slipPaid).' / LOP '.$d2($slipLop).' of '.$d2($slipDen).'.';
            }
            $tg += $gross;
            $td += $ded;
            $tn += $net;
            $rows[] = [
                'employee_id' => (int) $e->id,
                'code' => $e->emp_code,
                'name' => $e->name,
                'presentDays' => $lop ? $present : null,
                'leaveDays' => $lop ? round($leave, 1) : null,
                'unpaidLeave' => $lop ? $unpaidLeave : null,
                'lopDays' => $lop ? $slipLop : null,
                'paidDays' => $lop ? $slipPaid : null,                       // 8 Oct 2026 — present + paid leave + offs + holidays
                'offDays' => $lop ? (int) ($sc['woDays'] + $sc['holDays']) : null,
                'lateDays' => $lop ? $lateDays : null,
                'lateCut' => $lop ? round($lateCut, 2) : null,
                'breakCut' => $lop ? round($breakCut, 2) : null,
                'commission' => round($commission, 2),
                'commissionMap' => $commMap,
                'commissionIds' => array_column($commRows, 'id'),
                'nightAllowance' => $nightAmt,   // rev173
                'nightDays' => $nightDays,       // rev173
                'otAmount' => $otAmt,            // rev176 — overtime paid
                'otIds' => $otIds,               // rev176 — OT entries to mark paid on generate
                'adhocEarn' => $adhocEarn,       // 7 Oct 2026 — expense / bonus lines
                'adhocIds' => $adhocIds,         // 7 Oct 2026 — table => [[id, amount]] to stamp on generate
                'loanEmi' => $emiLoanPart,       // rev176/180 — loan-EMI part recovered on this slip
                'advEmi' => $emiAdvPart,         // rev180 — instalment-advance part (legacy loans.type='advance')
                'loanRecs' => $loanRecs,         // rev176 — per-loan recoveries (id + emi + type)
                'advanceRecovered' => $advApplied, // rev176
                'clawback' => $cbApplied,          // rev181 — clawbacks recovered on this slip
                'clawbackRecs' => $cbRecs,         // rev181 — per-clawback recovery (id + amount + due)
                'note' => $note,
                'factor' => round($factor, 4),
                'earnings' => $s['earnings'] ?? null,
                'deductionsMap' => $s['deductions'] ?? null,
                'basic' => $s['basic'],
                'hra' => $s['hra'],
                'special' => $s['special'],
                'conveyance' => $s['conveyance'] ?? 0,
                'pf' => $s['pf'],
                'esi' => $s['esi'],
                'pt' => $s['pt'],
                'tds' => $s['tds'],
                'gross' => $gross,
                'ded' => $ded,
                'net' => $net,
            ];
        }

        return [
            'rows' => $rows,
            'skipped' => $skipped,
            'totals' => [
                'count' => count($rows),
                'gross' => round($tg, 2),
                'ded' => round($td, 2),
                'net' => round($tn, 2),
            ],
            'meta' => [
                'daysInMonth' => $daysInMonth,
                'workingDays' => $working,
                'holidays' => count($holidays),
                // rev191 — open-period facts, used by preview()/generate() and the UI.
                'periodStart' => $start->toDateString(),
                'periodEnd' => $periodEnd,
                'attendanceEnd' => $endDate,       // = periodEnd once the period has closed
                'periodOpen' => $periodOpen,
                'workingSoFar' => $workingSoFar,
                'notElapsed' => $notElapsed,
                'monthLabel' => Carbon::parse($month.'-01')->format('M Y'),   // the payroll month, not the period's first day
                'payDate' => $this->resolvePayDate($tid, $company, $month, $endDate), // rev180 — schedule-aware
                'lopBasis' => $lopBasis, // rev177
            ],
        ];
    }

    /**
     * LIVE SALARY (rev 79, Ejaz 4 Jun 2026) — one employee's running-month
     * salary EARNED TILL TODAY, using the SAME engine as payroll generation
     * (attendance/leave/late-policy/components/statutory), just capped at
     * today's date. Plus this month's approved extras: commissions (+),
     * expense reimbursements (+), loan EMIs (−), advances approved (−).
     *
     * Visibility (strict hierarchy): a logged-in employee sees ONLY their own
     * panel; a reporting manager / team leader additionally gets a dropdown of
     * their direct reportees; Admin / HR / Super Admin see every employee.
     */
    public function liveSalary(Request $request)
    {
        try {
            $user = $request->user();
            $tid = $user->tenant_id;
            $isHr = $user->hasAnyRole(['super_admin', 'admin', 'hr_manager']);

            // Viewer's own employee record (users.employee_id, else email/name).
            $me = null;
            if (! empty($user->employee_id)) {
                $me = DB::table('employees')->where('id', $user->employee_id)->whereNull('deleted_at')->first();
            }
            if (! $me) {
                $me = DB::table('employees')->when($tid, fn ($q) => $q->where('tenant_id', $tid))
                    ->whereNull('deleted_at')
                    ->where(fn ($q) => $q->where('email', $user->email)->orWhere('name', $user->name))
                    ->first();
            }

            // Scope: who may this viewer look at?
            $base = DB::table('employees')->when($tid, fn ($q) => $q->where('tenant_id', $tid))
                ->whereNull('deleted_at')->where('status', 'active');
            if ($isHr) {
                $scope = $base->orderBy('emp_code')->get();
            } elseif ($me) {
                // 7 Oct 2026 — own + the WHOLE reporting line below (any depth) + team members led.
                $down = array_merge([(int) $me->id], \App\Services\Hierarchy::downline((int) $me->id, $tid ? (int) $tid : null));
                $scope = $base->where(function ($q) use ($me, $down) {
                    $q->whereIn('id', $down)->orWhere('team_leader', $me->name);
                })->orderBy('emp_code')->get();
            } else {
                return response()->json(['ok' => false, 'error' => 'Your login is not linked to an employee record — ask HR to link it in User Access.'], 422);
            }
            if ($scope->isEmpty()) {
                return response()->json(['ok' => false, 'error' => 'No employees in your view.'], 422);
            }

            // Target: requested (must be inside the scope) | own record | first in scope.
            $reqId = (int) $request->query('employee_id', 0);
            $target = $reqId ? $scope->firstWhere('id', $reqId) : null;
            if ($reqId && ! $target) {
                return response()->json(['ok' => false, 'error' => 'That employee is not in your view.'], 403);
            }
            if (! $target) {
                $target = ($me ? $scope->firstWhere('id', $me->id) : null) ?: $scope->first();
            }

            $company = DB::table('companies')->where('id', $target->company_id)->first();
            if (! $company) {
                return response()->json(['ok' => false, 'error' => 'Employee has no company set.'], 422);
            }
            $ctc = (float) $target->ctc;
            if ($ctc <= 0) {
                return response()->json(['ok' => false, 'error' => 'No CTC set for '.$target->name.' — set it in their employee profile first.'], 422);
            }

            $rates = \App\Services\WorkCalendar::forCompany(SettingsController::rates($tid), $company->id);   // 7 Oct 2026
            // rev191 — Live Salary runs on the company's OWN pay cycle, exactly
            // like the payroll engine. It used to force the calendar month, so a
            // 21st–20th company saw a window here that no payslip ever matched,
            // and on the 23rd it named the wrong payroll month entirely.
            $this->runCutoff = $this->resolveCutoffDay($tid, $company);
            $month = PeriodResolver::currentMonthFor($tid, (string) $company->name);
            [$start, $daysInMonth, , $periodEnd] = $this->monthMeta($month);
            $today = now()->toDateString();
            // Calendar days of the PERIOD elapsed so far (not day-of-month: with a
            // cut-off cycle the period does not start on the 1st).
            $dayOfMonth = max(1, min($daysInMonth, (int) $start->diffInDays(Carbon::parse($today)) + 1));

            // Working days: whole period + elapsed-till-today (weekly offs/holidays excluded).
            $holidays = $this->holidayDates($tid, $month, $periodEnd, $rates);
            [$working, $workingSoFar] = $this->workingSpan($start, $periodEnd, $today, $rates, $holidays);

            // 7 Oct 2026 — attendance till TODAY scored by the SAME scorer as payroll
            // (service window, working days only, paid / unpaid leave, late cuts,
            // sandwich, LOP basis), so Live Salary always matches the payslip.
            $team = property_exists($target, 'team') ? $target->team : null;
            $shiftDefs = \App\Services\ShiftResolver::shifts($tid);
            $rosterMap = $shiftDefs ? \App\Services\ShiftResolver::rosterMap($tid, $this->periodStart($month), $today) : [];
            $empShiftLv = property_exists($target, 'shift') ? $target->shift : null;
            $lopBasisLv = self::lopBasisOf($rates);
            $sc = $this->scoreEmployee($target, [
                'tid' => $tid, 'month' => $month, 'start' => $start, 'periodEnd' => $periodEnd,
                'endDate' => $today < $periodEnd ? $today : $periodEnd,
                'daysInMonth' => $daysInMonth, 'working' => $working, 'rates' => $rates, 'holidays' => $holidays,
                'lopBasis' => $lopBasisLv, 'sandwichOn' => ! empty($rates['sandwich_rule']),
                'polRows' => $this->latePolicyRows($company, $tid), 'shiftDefs' => $shiftDefs, 'rosterMap' => $rosterMap,
                'lop' => true, 'lwd' => $this->exitDates($tid)[(int) $target->id] ?? null,
                'holRaw' => \App\Services\WorkCalendar::holidays($tid, $start->toDateString(), $periodEnd),
            ]) ?? ['present' => 0, 'leave' => 0.0, 'lateCut' => 0.0, 'breakCut' => 0.0, 'lateDays' => 0, 'factor' => 0.0, 'slipPaid' => 0.0];
            $present = (int) $sc['present'];
            $leave = (float) $sc['leave'];
            $lateCut = (float) $sc['lateCut'];
            $breakCut = (float) $sc['breakCut'];
            $lateDays = (int) $sc['lateDays'];
            $factor = (float) $sc['factor'];
            $paidSoFar = (float) ($sc['slipPaid'] ?? 0);

            // Components — identical resolution to payroll (employee > team > company).
            $compRows = $this->componentRows($company, $tid);
            $salComps = $this->resolveComponents($compRows, $target->emp_code, $team);
            // rev180 — same statutory context as payroll (state PT + ESI period lock).
            $branchCityLv = (! empty($target->branch_id) && Schema::hasTable('branches'))
                ? (string) (DB::table('branches')->where('id', $target->branch_id)->value('city') ?? '')
                : '';
            $stxLv = [
                'tenant_id' => $tid,                                   // F1 scope
                'company_id' => $company->id ?? null,                   // F1 scope
                'branch_id' => (int) ($target->branch_id ?? 0) ?: null,   // F1 branch scope
                'branch_city' => $branchCityLv,                           // F1 location scope
                'pt_state' => (string) ($target->pt_state ?? ''),
                'gender' => (string) ($target->gender ?? ''),   // PT — MH female exemption
                'month' => $month,
                'doj' => $target->doj ?? null,   // 8 Oct 2026 — statutory deferral
                'esi_lock' => round($ctc / 12, 2) > (float) ($rates['esi_threshold'] ?? 21000)
                    ? AppDataController::esiPeriodLock((int) $target->id, $month, $rates)
                    : false,
            ];
            $stxLv += AppDataController::ptCtx($target);   // 10 Oct 2026 — PT rules (branch / designation)
            $sFull = $salComps->isNotEmpty()
                ? (AppDataController::computeSlipFromComponents($ctc, $salComps, $rates, (string) ($target->employment_stage ?? ''), $stxLv) ?: AppDataController::computeSlip($ctc, $rates, (string) ($target->employment_stage ?? ''), $stxLv))
                : AppDataController::computeSlip($ctc, $rates, (string) ($target->employment_stage ?? ''), $stxLv);
            $sNow = $salComps->isNotEmpty()
                ? (AppDataController::computeSlipFromComponents($ctc * $factor, $salComps, $rates, (string) ($target->employment_stage ?? ''), $stxLv) ?: AppDataController::computeSlip($ctc * $factor, $rates, (string) ($target->employment_stage ?? ''), $stxLv))
                : AppDataController::computeSlip($ctc * $factor, $rates, (string) ($target->employment_stage ?? ''), $stxLv);

            // Earnings / deductions component lists (named maps when available).
            $earnings = [];
            foreach (($sNow['earnings'] ?? null) ?: ['Basic' => $sNow['basic'], 'HRA' => $sNow['hra'], 'Special / other allowances' => $sNow['special']] as $label => $amt) {
                if ((float) $amt > 0) {
                    $earnings[] = ['label' => $label, 'amount' => round((float) $amt, 2)];
                }
            }
            $deductions = [];
            foreach (($sNow['deductions'] ?? null) ?: ['PF (employee)' => $sNow['pf'], 'ESI' => $sNow['esi'], 'Professional tax' => $sNow['pt'], 'TDS' => $sNow['tds']] as $label => $amt) {
                if ((float) $amt > 0) {
                    $deductions[] = ['label' => $label, 'amount' => round((float) $amt, 2)];
                }
            }

            // This month's APPROVED extras (payout-date driven, rev 84).
            $commData = $this->commissionByEmployee($company, $tid, $month);
            $commission = (float) ($commData['sum'][$target->id] ?? 0.0);
            if ($commission > 0) {
                $earnings[] = ['label' => 'Commission / incentive (approved, due this month)', 'amount' => round($commission, 2)];
            }
            // rev173 — night shift allowance earned so far this month.
            $nightAmtLv = 0.0;
            if ($shiftDefs) {
                foreach ($this->presentDates($target->emp_code, $month, $today, $tid) as $nd) {
                    $shN = \App\Services\ShiftResolver::resolve($shiftDefs, $rosterMap, (string) $target->name, $empShiftLv, $nd);
                    if ($shN && empty($shN['off']) && $shN['night'] && $shN['allowance'] > 0) {
                        $nightAmtLv += $shN['allowance'];
                    }
                }
                $nightAmtLv = round($nightAmtLv, 2);
                if ($nightAmtLv > 0) {
                    $earnings[] = ['label' => 'Night shift allowance (nights worked)', 'amount' => $nightAmtLv];
                }
            }
            // rev176 — approved overtime for the month (now paid on the payslip).
            $otLv = $this->overtimeByEmployee($tid, $month, $periodEnd);   // rev191 — the period's end, not the calendar month's
            $otAmtLv = round((float) ($otLv['sum'][$target->id] ?? 0), 2);
            if ($otAmtLv > 0) {
                $earnings[] = ['label' => 'Overtime (approved)', 'amount' => $otAmtLv];
            }
            $monthStart = $this->periodStart($month).' 00:00:00';
            $reimb = round(array_sum(array_column(array_merge($this->adhocDue('expenses', (int) $target->id, $tid, $month), $this->adhocDue('bonus_encashment', (int) $target->id, $tid, $month)), 'amount')), 2);   // 7 Oct 2026 — same rule as payroll
            if ($reimb > 0) {
                $earnings[] = ['label' => 'Expense reimbursement / bonus (approved, paid with salary)', 'amount' => round($reimb, 2)];
            }
            // rev176 (BUGFIX) — EMI via the shared, schema-tolerant helper. The
            // live request-module stores the amount in `emi`, the legacy loans
            // screen in `installment_amount`; the old sum('emi') silently
            // returned 0 on the legacy schema. Same rev172 (H1) auto-stop rules.
            $emi = 0.0;
            foreach ($this->loanEmiDue((int) $target->id, $tid, $month) as $lnLv) {
                $emi = round($emi + $lnLv['emi'], 2);
            }
            if ($emi > 0) {
                $deductions[] = ['label' => 'Loan EMI', 'amount' => round($emi, 2)];
            }
            $adv = $this->advanceRecovery((int) $target->id, $tid, $month);   // 7 Oct 2026 — same rule as payroll
            if ($adv > 0) {
                $deductions[] = ['label' => 'Salary advance recovery (this month)', 'amount' => round($adv, 2)];
            }

            // ---- "How you earned it" LOG (rev 79b, Ejaz) — passbook-style
            // entries: every credit/debit with heading, detail, date, status.
            // status: included (in the net) | pending (awaiting approval —
            // visible but NOT counted) | info (explains money NOT earned).
            $entries = [];
            // rev177 — the passbook's per-day value follows the LOP basis, so the
            // late/absent info entries reconcile with the earned-gross figure.
            $dayDen = $lopBasisLv === 'calendar' ? max(1, $daysInMonth) : ($lopBasisLv === 'fixed30' ? 30 : $working);
            $dayValue = round(((float) $sFull['gross']) / $dayDen, 2);
            $entries[] = ['date' => now()->format('d-m-Y'), 'sign' => '+',
                'head' => 'Salary earned till today',
                'detail' => round($paidSoFar, 1).' paid day(s) of '.round((float) ($sc['slipDen'] ?? $working), 1).' days — basic + allowances as per your salary structure',
                'amount' => round((float) $sNow['gross'], 2), 'status' => 'included'];
            if (($lateCut + $breakCut) > 0) {
                $entries[] = ['date' => now()->format('d-m-Y'), 'sign' => '-',
                    'head' => 'Late penalty (late policy)',
                    'detail' => $lateDays.' late mark(s) → '.round($lateCut + $breakCut, 2).' day(s) cut',
                    'amount' => round($dayValue * ($lateCut + $breakCut), 2), 'status' => 'info'];
            }
            if ($present > 0) {
                $missed = max(0.0, $workingSoFar - ($present + $leave));
                if ($missed > 0.5) {
                    $entries[] = ['date' => now()->format('d-m-Y'), 'sign' => '-',
                        'head' => 'Loss of pay — absent / leave without pay',
                        'detail' => round($missed, 1).' working day(s) absent or on leave without pay so far (weekly offs, holidays and paid leave are paid)',
                        'amount' => round($dayValue * $missed, 2), 'status' => 'info'];
                }
            }
            // Commission / incentive entries — entry-wise. rev 84 (Ejaz):
            // in-month test is PAYOUT-DATE first (matches the payslip rule);
            // pending ones are totalled separately for the PROJECTED figure;
            // and EVERY entry (any month) feeds the new "Earnings" tab.
            $pendingComm = 0.0;
            $commList = [];
            try {
                if (Schema::hasTable('commissions')) {
                    $cSel = ['id', 'amount', 'status', 'created_at'];
                    foreach (['portfolio', 'cycle_month', 'payout_date', 'payout_method', 'locked_at', 'lock_source', 'type', 'kind', 'decided_by', 'reason',
                        'purpose', 'description', 'gross_amount', 'tds_rate', 'tds_amount', 'entered_by'] as $cc) {
                        if (Schema::hasColumn('commissions', $cc)) {
                            $cSel[] = $cc;
                        }
                    }
                    $cRows = DB::table('commissions')->where('employee_id', $target->id)
                        ->whereIn('status', ['approved', 'pending', 'rejected'])
                        ->orderByDesc('id')->limit(120)->get($cSel);
                    foreach ($cRows as $cr) {
                        $payout = trim((string) ($cr->payout_date ?? ''));
                        $cm = trim((string) ($cr->cycle_month ?? ''));
                        // Earnings tab: every entry, any month, any status.
                        $commList[] = [
                            'id' => (int) $cr->id,
                            'date' => substr((string) $cr->created_at, 0, 10),
                            'purpose' => (string) (($cr->purpose ?? '') ?: (($cr->kind ?? '') ?: ($cr->type ?? ''))),
                            'portfolio' => (string) ($cr->portfolio ?? ''),
                            'earnedMonth' => $cm,
                            'payoutDate' => $payout !== '' ? substr($payout, 0, 10) : '',
                            'payoutMethod' => (string) ((($cr->payout_method ?? '') ?: 'with_salary')),
                            'gross' => round((float) ($cr->gross_amount ?? 0), 2),
                            'tds' => round((float) ($cr->tds_amount ?? 0), 2),
                            'net' => round((float) $cr->amount, 2),
                            'paid' => 0,        // filled below from the ledger
                            'balance' => round((float) $cr->amount, 2),
                            'status' => (string) $cr->status,
                            'locked' => ! empty($cr->locked_at),
                            'lockSource' => (string) ($cr->lock_source ?? ''),
                            'decidedBy' => (string) ($cr->decided_by ?? ''),
                            'description' => (string) ($cr->description ?? ''),
                        ];
                        if ($cr->status === 'rejected') {
                            continue;   // visible in the tab, never in the panel
                        }
                        // In-month test for the salary panel (payslip rule).
                        if ($payout !== '') {
                            $inMonth = substr($payout, 0, 7) === $month;
                        } elseif ($cm !== '') {
                            try {
                                $inMonth = Carbon::parse($cm)->format('Y-m') === $month;
                            } catch (\Throwable $e) {
                                $inMonth = false;
                            }
                        } else {
                            $inMonth = substr((string) $cr->created_at, 0, 7) === $month;
                        }
                        if (! $inMonth) {
                            continue;
                        }
                        if ($cr->status === 'pending') {
                            $pendingComm += (float) $cr->amount;
                        }
                        $bits = array_filter([
                            ($cr->purpose ?? null) ?: (($cr->kind ?? null) ?: ($cr->type ?? null)),
                            ! empty($cr->portfolio) ? 'portfolio '.$cr->portfolio : null,
                            ! empty($cr->gross_amount) ? 'gross ₹'.number_format((float) $cr->gross_amount, 2).' − TDS ₹'.number_format((float) ($cr->tds_amount ?? 0), 2) : null,
                            $payout !== '' ? 'payout '.substr($payout, 0, 10) : null,
                            ($cr->description ?? null) ?: null,
                            ! empty($cr->entered_by) ? 'entered by '.$cr->entered_by : null,
                            ($cr->status === 'approved' && ! empty($cr->decided_by)) ? 'approved by '.$cr->decided_by : null,
                            $cr->status === 'pending' ? 'awaiting approval' : null,
                            ! empty($cr->locked_at) ? 'LOCKED' : null,
                        ]);
                        $entries[] = ['date' => substr((string) $cr->created_at, 8, 2).' '.Carbon::parse($cr->created_at)->format('M'), 'sign' => '+',
                            'head' => 'Commission / incentive',
                            'detail' => implode(' · ', $bits) ?: 'commission entry',
                            'amount' => round((float) $cr->amount, 2),
                            'status' => $cr->status === 'approved' ? 'included' : 'pending'];
                    }
                    // rev 85: overlay paid/balance from the disbursement ledger.
                    if ($commList && Schema::hasTable('commission_payments')) {
                        try {
                            $payMap = [];
                            foreach (DB::table('commission_payments')->whereIn('commission_id', array_column($commList, 'id'))
                                ->groupBy('commission_id')->selectRaw('commission_id, SUM(amount) s')->get() as $pm) {
                                $payMap[(int) $pm->commission_id] = (float) $pm->s;
                            }
                            foreach ($commList as &$cl) {
                                $cl['paid'] = round($payMap[$cl['id']] ?? 0, 2);
                                $cl['balance'] = $cl['status'] === 'approved' ? round($cl['net'] - $cl['paid'], 2) : $cl['balance'];
                            }
                            unset($cl);
                        } catch (\Throwable $e) {
                        }
                    }
                }
            } catch (\Throwable $e) {
            }
            // Approved expense reimbursements this month — entry-wise.
            try {
                if (Schema::hasTable('expenses')) {
                    $eSel = ['amount', 'created_at'];
                    foreach (['type', 'reason', 'decided_by'] as $ec) {
                        if (Schema::hasColumn('expenses', $ec)) {
                            $eSel[] = $ec;
                        }
                    }
                    foreach (DB::table('expenses')->where('employee_id', $target->id)->where('status', 'approved')
                        ->where('created_at', '>=', $monthStart)->orderByDesc('id')->limit(30)->get($eSel) as $er) {
                        $entries[] = ['date' => Carbon::parse($er->created_at)->format('d-m-Y'), 'sign' => '+',
                            'head' => 'Expense reimbursement',
                            'detail' => implode(' · ', array_filter([$er->type ?? null, $er->reason ?? null, ! empty($er->decided_by) ? 'approved by '.$er->decided_by : null])) ?: 'approved claim',
                            'amount' => round((float) $er->amount, 2), 'status' => 'included'];
                    }
                }
            } catch (\Throwable $e) {
            }
            if ($emi > 0) {
                $entries[] = ['date' => now()->format('d-m-Y'), 'sign' => '-',
                    'head' => 'Loan EMI', 'detail' => 'monthly instalment on your approved loan(s)',
                    'amount' => round($emi, 2), 'status' => 'included'];
            }
            if ($adv > 0) {
                $entries[] = ['date' => now()->format('d-m-Y'), 'sign' => '-',
                    'head' => 'Salary advance recovery', 'detail' => 'advance approved this month, recovered from salary',
                    'amount' => round($adv, 2), 'status' => 'included'];
            }

            $sumE = 0.0;
            foreach ($earnings as $x) {
                $sumE += $x['amount'];
            }
            $sumD = 0.0;
            foreach ($deductions as $x) {
                $sumD += $x['amount'];
            }

            return response()->json([
                'ok' => true,
                'employee' => ['id' => $target->id, 'code' => $target->emp_code, 'name' => $target->name,
                    'company' => $company->name ?? '', 'designation' => $target->designation ?? '', 'ctc' => $ctc],
                'meta' => [
                    'monthLabel' => $start->format('F Y'), 'today' => now()->format('d-m-Y'),
                    'daysInMonth' => $daysInMonth, 'workingDays' => $working, 'workingSoFar' => $workingSoFar,
                    'presentDays' => $present, 'paidLeaveDays' => round($leave, 1), 'lateDays' => $lateDays, 'paidSoFar' => round($paidSoFar, 2),
                    'attendanceTracked' => $present > 0,
                    'lopBasis' => $lopBasisLv, // rev177
                    'factorPct' => round($factor * 100, 1),
                    'fullMonthGross' => round((float) $sFull['gross'], 2),
                    'fullMonthNet' => round((float) $sFull['net'], 2),
                ],
                'earnings' => $earnings,
                'deductions' => $deductions,
                'entries' => $entries,
                'gross' => round($sumE, 2),
                'totalDeductions' => round($sumD, 2),
                'net' => round($sumE - $sumD, 2),
                // rev 84 (Ejaz): TWO totals — certain vs awaiting approval.
                'pendingCommission' => round($pendingComm, 2),
                'projectedNet' => round($sumE - $sumD + $pendingComm, 2),
                'commList' => $commList,
                // rev 115: live incentive schemes for THIS employee — the Live
                // Salary card shows them as the orange "earn more" ribbon.
                'schemes' => $this->liveSchemesFor($target),
                'canPick' => $scope->count() > 1,
                'employees' => $scope->count() > 1
                    ? $scope->map(fn ($x) => ['id' => $x->id, 'name' => $x->name, 'code' => $x->emp_code])->values()
                    : [],
            ]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /**
     * 7 Aug 2026 test report (item 4) — Live Salary "All Employees" overview.
     * An ADDITIVE summary (never touches the trusted single-employee liveSalary
     * above): for every employee in the viewer's scope, the full-month projected
     * NET and the base salary earned till today (basic + allowances − statutory),
     * pro-rated by attendance with the SAME computeSlip engine and LOP basis as
     * payroll. Per-employee approved extras (commission / OT / EMI / reimbursement)
     * are shown in each employee's detailed Live Salary, not aggregated here.
     */
    public function liveSalaryAll(Request $request)
    {
        try {
            $user = $request->user();
            $tid = $user->tenant_id;
            $isHr = $user->hasAnyRole(['super_admin', 'admin', 'hr_manager']);

            $me = null;
            if (! empty($user->employee_id)) {
                $me = DB::table('employees')->where('id', $user->employee_id)->whereNull('deleted_at')->first();
            }
            if (! $me) {
                $me = DB::table('employees')->when($tid, fn ($q) => $q->where('tenant_id', $tid))->whereNull('deleted_at')
                    ->where(fn ($q) => $q->where('email', $user->email)->orWhere('name', $user->name))->first();
            }
            $base = DB::table('employees')->when($tid, fn ($q) => $q->where('tenant_id', $tid))
                ->whereNull('deleted_at')->where('status', 'active')
                ->when(Schema::hasColumn('employees', 'archived_at'), fn ($q) => $q->whereNull('archived_at'));
            if ($isHr) {
                $scope = $base->orderBy('emp_code')->get();
            } elseif ($me) {
                // 7 Oct 2026 — own + the WHOLE reporting line below (any depth) + team members led.
                $down = array_merge([(int) $me->id], \App\Services\Hierarchy::downline((int) $me->id, $tid ? (int) $tid : null));
                $scope = $base->where(function ($q) use ($me, $down) {
                    $q->whereIn('id', $down)->orWhere('team_leader', $me->name);
                })->orderBy('emp_code')->get();
            } else {
                return response()->json(['ok' => false, 'error' => 'Your login is not linked to an employee record.'], 422);
            }
            if ($scope->isEmpty()) {
                return response()->json(['ok' => false, 'error' => 'No employees in your view.'], 422);
            }

            $baseRates = SettingsController::rates($tid);
            $today = now()->toDateString();
            // 7 Oct 2026 (Ejaz) — each company on its OWN pay cycle and weekly offs:
            // with only company-specific cycles the tenant-level lookup fell back to
            // the calendar month and credited days that belong to the next cycle.
            $spans = [];
            $spanFor = function (object $co) use (&$spans, $tid, $baseRates, $today) {
                if (! isset($spans[$co->id])) {
                    $this->runCutoff = PeriodResolver::masterDay($tid, (string) $co->name, 'cutoff_day');
                    $m = PeriodResolver::currentMonthFor($tid, (string) $co->name);
                    [$st, $dim, , $pe] = $this->monthMeta($m);
                    $r = \App\Services\WorkCalendar::forCompany($baseRates, $co->id);
                    $hol = $this->holidayDates($tid, $m, $pe, $r);
                    [$w] = $this->workingSpan($st, $pe, $today, $r, $hol);
                    $spans[$co->id] = ['cut' => $this->runCutoff, 'month' => $m, 'dim' => $dim, 'rates' => $r, 'hol' => $hol, 'w' => $w, 'st' => $st, 'pe' => $pe,
                        'holRaw' => \App\Services\WorkCalendar::holidays($tid, $st->toDateString(), $pe),
                        'pol' => $this->latePolicyRows($co, $tid),
                    ];
                }
                $this->runCutoff = $spans[$co->id]['cut'];   // presentDays / paidLeaveDays read it

                return $spans[$co->id];
            };
            $lopBasis = self::lopBasisOf($baseRates);
            $exitLwd = $this->exitDates($tid);

            $companyCache = [];
            $compCache = [];
            $rows = [];
            $totFull = 0.0;
            $totEarned = 0.0;
            $capped = false;
            $limit = 300;   // no silent unbounded loop; log if we hit it
            $i = 0;
            foreach ($scope as $e) {
                if ($i++ >= $limit) {
                    $capped = true;
                    break;
                }
                $ctc = (float) $e->ctc;
                $cid = $e->company_id;
                if (! array_key_exists($cid, $companyCache)) {
                    $companyCache[$cid] = DB::table('companies')->where('id', $cid)->first();
                }
                $company = $companyCache[$cid];
                if ($ctc <= 0 || ! $company) {
                    $rows[] = ['id' => $e->id, 'code' => $e->emp_code, 'name' => $e->name, 'company' => $company->name ?? '—', 'fullMonthNet' => 0, 'earnedNet' => 0, 'factorPct' => 0, 'present' => 0, 'note' => $ctc <= 0 ? 'No CTC set' : 'No company'];
                    continue;
                }
                if (! array_key_exists($company->id, $compCache)) {
                    $compCache[$company->id] = $this->componentRows($company, $tid);
                }
                $team = property_exists($e, 'team') ? $e->team : null;
                $salComps = $this->resolveComponents($compCache[$company->id], $e->emp_code, $team);
                $sp = $spanFor($company);
                [$month, $daysInMonth, $rates, $holidays, $working] = [$sp['month'], $sp['dim'], $sp['rates'], $sp['hol'], $sp['w']];
                $stx = [
                    'tenant_id' => $tid, 'company_id' => $company->id ?? null,
                    'branch_id' => (int) ($e->branch_id ?? 0) ?: null,
                    'pt_state' => (string) ($e->pt_state ?? ''), 'gender' => (string) ($e->gender ?? ''),
                    'month' => $month, 'esi_lock' => false,
                    'doj' => $e->doj ?? null,   // 8 Oct 2026 — statutory deferral
                ];
                $stx += AppDataController::ptCtx($e);   // 10 Oct 2026 — PT rules (branch / designation)
                $stage = (string) ($e->employment_stage ?? '');
                $sFull = $salComps->isNotEmpty()
                    ? (AppDataController::computeSlipFromComponents($ctc, $salComps, $rates, $stage, $stx) ?: AppDataController::computeSlip($ctc, $rates, $stage, $stx))
                    : AppDataController::computeSlip($ctc, $rates, $stage, $stx);
                $fullNet = (float) ($sFull['net'] ?? 0);

                // 7 Oct 2026 — the same scorer as payroll (see scoreEmployee).
                $sc = $this->scoreEmployee($e, [
                    'tid' => $tid, 'month' => $month, 'start' => $sp['st'], 'periodEnd' => $sp['pe'],
                    'endDate' => $today < $sp['pe'] ? $today : $sp['pe'],
                    'daysInMonth' => $daysInMonth, 'working' => $working, 'rates' => $rates, 'holidays' => $holidays,
                    'lopBasis' => $lopBasis, 'sandwichOn' => ! empty($rates['sandwich_rule']),
                    'polRows' => $sp['pol'], 'shiftDefs' => [], 'rosterMap' => [], 'lop' => true,
                    'lwd' => $exitLwd[(int) $e->id] ?? null,
                    'holRaw' => $sp['holRaw'],   // 8 Oct 2026 — branch-scoped holidays too
                ]);
                $present = (int) ($sc['present'] ?? 0);
                $factor = (float) ($sc['factor'] ?? 0);
                $earned = round($fullNet * $factor, 2);
                $rows[] = ['id' => $e->id, 'code' => $e->emp_code, 'name' => $e->name, 'company' => $company->name ?? '—', 'fullMonthNet' => round($fullNet, 2), 'earnedNet' => $earned, 'factorPct' => (int) round($factor * 100), 'present' => $present];
                $totFull += $fullNet;
                $totEarned += $earned;
            }
            if ($capped) {
                \Illuminate\Support\Facades\Log::info('liveSalaryAll capped at '.$limit.' employees for tenant '.$tid);
            }

            return response()->json([
                'ok' => true,
                'monthLabel' => now()->format('F Y'),
                'today' => now()->format('d-m-Y'),
                'rows' => $rows,
                'totFull' => round($totFull, 2),
                'totEarned' => round($totEarned, 2),
                'count' => count($rows),
                'capped' => $capped,
                'limit' => $limit,
                'note' => 'Base salary earned till today (basic + allowances − statutory), pro-rated by attendance. Approved commission, overtime, loan EMI and reimbursements show in each employee\'s detailed Live Salary.',
            ]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /**
     * rev178 — SALARY SIMULATOR (Ejaz): "what would the salary be?" for ANY
     * hypothetical inputs, computed by the SAME engine as real payroll
     * (computeSlip / company components / statutory rates / LOP basis).
     * Read-only — writes nothing — and open to every logged-in role:
     * employees explore their own numbers, HR designs offers, and Recruitment
     * explains CTC → in-hand during interviews.
     */
    public function simulate(Request $request)
    {
        try {
            $tid = $request->user()->tenant_id;
            $rates = SettingsController::rates($tid);
            if ($request->input('company_id')) {
                $rates = \App\Services\WorkCalendar::forCompany($rates, (int) $request->input('company_id'));   // 7 Oct 2026
            }

            $ctc = (float) $request->input('ctc', 0);
            if ($ctc <= 0) {
                return response()->json(['ok' => false, 'error' => 'Enter an annual CTC first.'], 200);
            }
            // rev191 — the Simulator answers "what would payroll do", so it has to
            // use the same period the payroll engine would. The selected company's
            // pay cycle drives it; with none selected, the tenant-level cycle does.
            $simCo = $request->input('company_id')
                ? DB::table('companies')->when($tid, fn ($q) => $q->where('tenant_id', $tid))->where('id', (int) $request->input('company_id'))->value('name')
                : '';
            $this->runCutoff = PeriodResolver::masterDay($tid, (string) $simCo, 'cutoff_day');
            $month = $this->normMonth((string) $request->input('month', '')) ?: PeriodResolver::currentMonthFor($tid, (string) $simCo);
            [$start, $daysInMonth, , $endDate] = $this->monthMeta($month);

            // Working days of the period (weekly offs + holidays honoured).
            $holidays = $this->holidayDates($tid, $month, $endDate, $rates);
            [$working, ] = $this->workingSpan($start, $endDate, $endDate, $rates, $holidays);

            // LOP basis — tenant setting by default, overridable per simulation.
            $basis = self::lopBasisOf($rates, (string) $request->input('lop_basis', ''));
            if ($basis === 'calendar') {
                $den = (float) $daysInMonth;
            } elseif ($basis === 'fixed30') {
                $den = 30.0;
            } else {
                $den = (float) $working;
            }
            $lopDays = min(max(0.0, (float) $request->input('lop_days', 0)), $den);
            $factor = max(0.0, min(1.0, ($den - $lopDays) / max(1.0, $den)));

            // Employment stage (probation/internship → no PF/PT/TDS, rev174/176).
            $stage = strtolower(trim((string) $request->input('stage', '')));
            if (! in_array($stage, ['probation', 'internship'], true)) {
                $stage = '';
            }

            // rev180 — statutory context: optional PT state (state-wise slabs) +
            // month (Maharashtra February). No ESI period lock in a simulation.
            $stxSim = [
                'tenant_id' => $tid,                                                    // F1 scope
                'company_id' => ((int) $request->input('company_id', 0)) ?: null,        // F1 scope
                'branch_id' => ((int) $request->input('branch_id', 0)) ?: null,   // F1 branch scope
                'branch_city' => trim((string) $request->input('branch_city', '')), // F1 location scope
                'pt_state' => trim((string) $request->input('pt_state', '')),
                'gender' => trim((string) $request->input('gender', '')),   // PT — MH female exemption
                'month' => $month,
            ];
            // 10 Oct 2026 — PT rules: branch from the picked branch, designation as typed.
            $stxSim += AppDataController::ptCtx(['branch_id' => $stxSim['branch_id'], 'designation' => trim((string) $request->input('designation', ''))]);

            // Optional: apply a company's Salary Structure (company-wide rows).
            $s = null;
            $structureUsed = '';
            $companyId = (int) $request->input('company_id', 0);
            if ($companyId > 0) {
                $simCompany = DB::table('companies')
                    ->when($tid, fn ($q) => $q->where('tenant_id', $tid))
                    ->where('id', $companyId)->whereNull('deleted_at')->first();
                if ($simCompany) {
                    $simComps = $this->resolveComponents($this->componentRows($simCompany, $tid), null, null);
                    if ($simComps->isNotEmpty()) {
                        $s = AppDataController::computeSlipFromComponents($ctc * $factor, $simComps, $rates, $stage, $stxSim);
                        $structureUsed = $s ? (string) $simCompany->name : '';
                    }
                }
            }
            if (! $s) {
                $s = AppDataController::computeSlip($ctc * $factor, $rates, $stage, $stxSim);
            }

            // Variable additions — same order as the engine: on top, not prorated.
            $commission = max(0.0, (float) $request->input('commission', 0));
            $otHours = max(0.0, (float) $request->input('ot_hours', 0));
            $otMult = (float) $request->input('ot_mult', 2);
            $otMult = $otMult > 0 ? $otMult : 2.0;
            $otAmt = round($otHours * $otMult * ($ctc / 12 / 26 / 8), 2);   // OT register formula
            $nights = max(0, (int) $request->input('nights', 0));
            $nightRate = max(0.0, (float) $request->input('night_rate', 0));
            $nightAmt = round($nights * $nightRate, 2);

            // Recoveries — capped so net never goes negative (engine rule).
            $netAvail = round($s['net'] + $commission + $otAmt + $nightAmt, 2);
            $emiWanted = max(0.0, (float) $request->input('loan_emi', 0));
            $emi = round(min($emiWanted, $netAvail), 2);
            $advWanted = max(0.0, (float) $request->input('advance', 0));
            $adv = round(min($advWanted, round($netAvail - $emi, 2)), 2);
            $adv = max(0.0, $adv);

            // Assemble the payslip-style maps.
            $earnings = ! empty($s['earnings']) ? $s['earnings']
                : ['Basic' => $s['basic'], 'HRA' => $s['hra'], 'Special Allowance' => $s['special']];
            if ($commission > 0) {
                $earnings['Commission / Incentive'] = round(($earnings['Commission / Incentive'] ?? 0) + $commission, 2);
            }
            if ($otAmt > 0) {
                $otKey = 'Overtime ('.rtrim(rtrim(number_format($otHours, 2), '0'), '.').'h x '.rtrim(rtrim(number_format($otMult, 2), '0'), '.').'x)';
                $earnings[$otKey] = round(($earnings[$otKey] ?? 0) + $otAmt, 2);
            }
            if ($nightAmt > 0) {
                $nKey = 'Night Shift Allowance ('.$nights.' nights)';
                $earnings[$nKey] = round(($earnings[$nKey] ?? 0) + $nightAmt, 2);
            }
            $deductions = ! empty($s['deductions']) ? $s['deductions']
                : array_filter([
                    'PF (employee)' => $s['pf'], 'ESI' => $s['esi'],
                    'Professional Tax' => $s['pt'], 'TDS' => $s['tds'],
                    'Labour Welfare Fund' => $s['lwf'] ?? 0,
                    'Conveyance' => $s['conveyance'] ?? 0,
                ], fn ($v) => (float) $v > 0);
            if ($emi > 0) {
                $deductions['Loan EMI'] = round(($deductions['Loan EMI'] ?? 0) + $emi, 2);
            }
            if ($adv > 0) {
                $deductions['Salary Advance Recovery'] = round(($deductions['Salary Advance Recovery'] ?? 0) + $adv, 2);
            }

            $gross = round($s['gross'] + $commission + $otAmt + $nightAmt, 2);
            $totalDed = round($s['total_ded'] + $emi + $adv, 2);
            $net = round(max(0.0, $gross - $totalDed), 2);

            return response()->json([
                'ok' => true,
                'month' => $month,
                'monthLabel' => $start->format('F Y'),
                'basis' => $basis,
                'daysInMonth' => $daysInMonth,
                'workingDays' => $working,
                'den' => $den,
                'lopDays' => $lopDays,
                'factor' => round($factor, 4),
                'stage' => $stage,
                'structure' => $structureUsed,
                'monthlyGross' => round($ctc / 12, 2),
                'earnings' => array_map(fn ($v) => round((float) $v, 2), $earnings),
                'deductions' => array_map(fn ($v) => round((float) $v, 2), $deductions),
                'gross' => $gross,
                'totalDed' => $totalDed,
                'net' => $net,
                'employer' => array_filter([
                    'Employer PF' => round((float) ($s['pf_employer'] ?? 0), 2),
                    'EPS (pension)' => round((float) ($s['pf_eps'] ?? 0), 2),
                    'EDLI' => round((float) ($s['pf_edli'] ?? 0), 2),
                    'Employer ESI' => round((float) ($s['esi_employer'] ?? 0), 2),
                ], fn ($v) => $v > 0),
                'capNote' => ($emiWanted > $emi || $advWanted > $adv)
                    ? 'Recoveries were capped - net pay can never go below zero. Unrecovered: '
                        .trim(($emiWanted > $emi ? 'EMI Rs '.number_format($emiWanted - $emi, 2).' ' : '')
                        .($advWanted > $adv ? 'Advance Rs '.number_format($advWanted - $adv, 2) : ''))
                    : '',
            ]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 200);
        }
    }

    /** rev 115: active schemes applicable to an employee (fail-soft, max 5). */
    private function liveSchemesFor(object $emp): array
    {
        try {
            if (! \Illuminate\Support\Facades\Schema::hasTable('incentive_schemes')) {
                return [];
            }
            $today = now()->toDateString();

            return DB::table('incentive_schemes')
                ->when($emp->tenant_id ?? null, fn ($q) => $q->where('tenant_id', $emp->tenant_id))
                ->where('status', 'active')
                ->where(function ($q) use ($today) {
                    $q->whereNull('valid_from')->orWhere('valid_from', '<=', $today);
                })
                ->where(function ($q) use ($today) {
                    $q->whereNull('valid_till')->orWhere('valid_till', '>=', $today);
                })
                ->orderByDesc('id')->limit(25)->get()
                ->filter(fn ($s) => \App\Http\Controllers\SchemeController::appliesTo($s, $emp))
                ->take(5)
                ->map(fn ($s) => [
                    'id' => $s->id, 'title' => $s->title,
                    'rate' => $s->rate_type === 'percent'
                        ? rtrim(rtrim(number_format((float) $s->rate_value, 2), '0'), '.').'% of collections'
                        : ($s->rate_type === 'fixed' ? '₹'.number_format((float) $s->rate_value).' per claim' : 'open amount'),
                    'till' => $s->valid_till ? \Carbon\Carbon::parse($s->valid_till)->format('d-m-Y') : null,
                ])->values()->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** Existing run (if any) for this tenant/company/month. */
    private function existingRun(Request $request, object $company, string $month): ?object
    {
        $tid = $request->user()->tenant_id;

        return DB::table('payroll_runs')
            ->when($tid, fn ($q) => $q->where('tenant_id', $tid))
            ->where('company_id', $company->id)
            ->where('cycle_label', $month)
            ->orderByDesc('id')
            ->first();
    }

    /** PREVIEW — compute the run without writing anything. */
    public function preview(Request $request)
    {
        if ($deny = ApprovalService::denyUnlessRole($request, ['admin', 'hr_manager'])) {
            return $deny;
        }
        try {
            $month = $this->normMonth($request->query('month'));
            if (! $month) {
                return response()->json(['ok' => false, 'error' => 'Pick a valid month.'], 422);
            }
            $company = $this->resolveCompany($request, $request->query('company_id'));
            if (! $company) {
                return response()->json(['ok' => false, 'error' => 'Pick a company you manage.'], 422);
            }
            // rev191 — attendance is no longer optional. Every preview and every
            // run is scored against real attendance; the old `lop` query flag is
            // accepted and ignored so existing links keep working.
            $lop = true;
            $c = $this->compute($request, $company, $month, $lop);

            $existing = $this->existingRun($request, $company, $month);

            return response()->json([
                'ok' => true,
                'company' => $company->name,
                'companyId' => (int) $company->id,
                'month' => $month,
                'monthLabel' => $c['meta']['monthLabel'],
                'lop' => $lop,
                'daysInMonth' => $c['meta']['daysInMonth'],
                'workingDays' => $c['meta']['workingDays'],
                'holidays' => $c['meta']['holidays'],
                'rows' => $c['rows'],
                'totals' => $c['totals'],
                'skipped' => $c['skipped'],
                'exists' => (bool) $existing,
                'existingStatus' => $existing->status ?? null,
                'existingRunId' => $existing->id ?? null,
                // rev191 — let the screen warn before anyone generates.
                'periodOpen' => $c['meta']['periodOpen'],
                'periodStart' => $c['meta']['periodStart'],
                'periodEnd' => $c['meta']['periodEnd'],
                'attendanceEnd' => $c['meta']['attendanceEnd'],
                'workingSoFar' => $c['meta']['workingSoFar'],
                'payDate' => $c['meta']['payDate'],
                'lopBasis' => $c['meta']['lopBasis'],   // 8 Oct 2026 — preview note's day count
                'hasAttendance' => $this->hasAnyAttendance($request->user()->tenant_id, $company, $month, $c['meta']['attendanceEnd']),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 200);
        }
    }

    /**
     * rev191 — UNDO everything a payroll run stamped on the rest of the system,
     * then delete it.
     *
     * This was ~90 lines living inside generate()'s regenerate branch, which
     * meant it existed for exactly one caller. Deleting a run any other way
     * (a purge, a SQL "DELETE FROM payroll_runs") leaves commissions locked to a
     * run that no longer exists, overtime stuck at 'paid', loan instalment
     * counters advanced against recoveries that were reversed, and clawbacks
     * stamped as recovered. One method, both callers.
     *
     * Every step is best-effort: a reversal must never be what fails.
     */
    public function reverseRun(int $runId): void
    {
        DB::table('payslips')->where('run_id', $runId)->delete();
        DB::table('payroll_runs')->where('id', $runId)->delete();
        // rev165 DATA INTEGRITY: also reverse the COMMISSION side of the old
        // run. The first generate inserted a commission_payments debit
        // (reference "run #<id> · …") and set commissions.locked_at; if we
        // don't undo them, regenerating leaves a passbook debit pointing at a
        // deleted run + a lock for a run that no longer exists, and the
        // recompute's whereNull('locked_at') skips those commissions while
        // they still fold into the new payslip. Best-effort; never blocks.
        try {
            if (Schema::hasTable('commission_payments')) {
                $refLike = 'run #'.$runId.' %';
                $freedCids = DB::table('commission_payments')
                    ->where('mode', 'payslip')->where('reference', 'like', $refLike)
                    ->pluck('commission_id')->all();
                DB::table('commission_payments')
                    ->where('mode', 'payslip')->where('reference', 'like', $refLike)->delete();
                if ($freedCids && Schema::hasColumn('commissions', 'locked_at')) {
                    $clear = ['locked_at' => null, 'updated_at' => now()];
                    if (Schema::hasColumn('commissions', 'locked_by')) {
                        $clear['locked_by'] = null;
                    }
                    if (Schema::hasColumn('commissions', 'lock_source')) {
                        $clear['lock_source'] = null;
                    }
                    DB::table('commissions')->whereIn('id', $freedCids)->update($clear);
                }
            }
        } catch (\Throwable $e) {
            // best-effort; a regenerate must not fail on the reversal.
        }
        // rev176 — reverse the OVERTIME side of the old run: entries that
        // run marked 'paid' go back to approved, so the recompute re-pays them.
        try {
            if (Schema::hasTable('overtime') && Schema::hasColumn('overtime', 'paid_run_id')) {
                $updO = ['status' => 'approved', 'paid_run_id' => null];
                if (Schema::hasColumn('overtime', 'updated_at')) {
                    $updO['updated_at'] = now();
                }
                DB::table('overtime')->where('paid_run_id', $runId)->where('status', 'paid')->update($updO);
            }
        } catch (\Throwable $e) {
            // best-effort; a regenerate must not fail on the reversal.
        }
        // rev176 — reverse the LOAN-EMI recoveries of the old run (ledger
        // rows + installment counters; re-open a loan that run auto-closed).
        try {
            if (Schema::hasTable('loan_recoveries')) {
                $recRows = DB::table('loan_recoveries')->where('run_id', $runId)->get(['id', 'loan_id']);
                if ($recRows->count() && Schema::hasColumn('loans', 'installments_paid')) {
                    foreach ($recRows as $rr) {
                        DB::table('loans')->where('id', $rr->loan_id)->where('installments_paid', '>', 0)->decrement('installments_paid');
                        $ln = DB::table('loans')->where('id', $rr->loan_id)->first();
                        $totL = $ln ? (int) (($ln->installments_total ?? null) ?: ($ln->tenure_months ?? 0)) : 0;
                        if ($ln && ($ln->status ?? '') === 'closed' && ($totL <= 0 || (int) ($ln->installments_paid ?? 0) < $totL)) {
                            DB::table('loans')->where('id', $rr->loan_id)->update(['status' => 'active']);
                        }
                    }
                }
                DB::table('loan_recoveries')->where('run_id', $runId)->delete();
            }
        } catch (\Throwable $e) {
            // best-effort; a regenerate must not fail on the reversal.
        }
        // rev181 — reverse the CLAWBACK recoveries of the old run: clear
        // the run-keyed stamp so the recompute recovers them again.
        try {
            if (Schema::hasTable('clawbacks') && Schema::hasColumn('clawbacks', 'recovered_run_id')) {
                $clr = ['recovered_run_id' => null, 'recovered_amount' => null, 'recovered_at' => null];
                if (Schema::hasColumn('clawbacks', 'updated_at')) {
                    $clr['updated_at'] = now();
                }
                DB::table('clawbacks')->where('recovered_run_id', $runId)->update($clr);
            }
        } catch (\Throwable $e) {
            // best-effort; a regenerate must not fail on the reversal.
        }
        // 7 Oct 2026 — release expense / bonus / advance items this run settled.
        foreach (array_keys(self::ADHOC) as $tbl) {
            try {
                if (Schema::hasTable($tbl) && Schema::hasColumn($tbl, 'paid_run_id')) {
                    DB::table($tbl)->where('paid_run_id', $runId)->update(['paid_run_id' => null, 'paid_amount' => null]);
                }
            } catch (\Throwable $e) {
            }
        }
    }

    /** GENERATE — create the draft payroll_run + payslips. */
    public function generate(Request $request)
    {
        if ($deny = ApprovalService::denyUnlessRole($request, ['admin', 'hr_manager'])) {
            return $deny;
        }
        try {
            $v = $request->validate([
                'company_id' => ['required'],
                'month' => ['required', 'string'],
                'lop' => ['nullable'],
                'regenerate' => ['nullable'],
            ]);
            $month = $this->normMonth($v['month']);
            if (! $month) {
                return response()->json(['ok' => false, 'error' => 'Pick a valid month.'], 422);
            }
            $company = $this->resolveCompany($request, $v['company_id']);
            if (! $company) {
                return response()->json(['ok' => false, 'error' => 'Pick a company you manage.'], 422);
            }
            // rev191 — attendance is no longer optional; the posted `lop` flag is
            // accepted and ignored so older clients keep working.
            $lop = true;
            $regenerate = filter_var($request->input('regenerate'), FILTER_VALIDATE_BOOLEAN);
            $tid = $request->user()->tenant_id;

            // ---- rev191 ATTENDANCE-FEED GUARD --------------------------------
            // Scoring every employee against attendance means a company with NO
            // punches at all would be paid nothing. That is never a real month —
            // it is a stopped biometric sync, a renamed emp_code or the wrong
            // tenant. Refuse before anything is deleted or written, and make the
            // override explicit and auditable.
            $this->runCutoff = $this->resolveCutoffDay($tid, $company);
            [, , , $gPeriodEnd] = $this->monthMeta($month);
            $gToday = Carbon::today()->toDateString();
            $gEnd = $gPeriodEnd > $gToday ? $gToday : $gPeriodEnd;
            if (! $request->boolean('confirm_no_attendance') && ! $this->hasAnyAttendance($tid, $company, $month, $gEnd)) {
                return response()->json([
                    'ok' => false,
                    'needs_confirm' => 'no_attendance',
                    'error' => 'No attendance was found for any employee of '.$company->name.' between '
                        .$this->periodStart($month).' and '.$gEnd.'. Salary is now prorated by attendance, so '
                        .'generating now would pay everyone zero. Check the biometric sync / device mapping first. '
                        .'(To pay this company without attendance on purpose, re-submit with confirm_no_attendance=1.)',
                ], 422);
            }

            // Idempotency: one run per company/month. A run already past draft is
            // locked from regeneration; a draft can be replaced only on request.
            $existing = $this->existingRun($request, $company, $month);
            if ($existing) {
                $locked = in_array($existing->status, ['hr_approved', 'approved', 'locked', 'paid'], true);
                if ($locked) {
                    return response()->json(['ok' => false, 'error' => 'A '.$existing->status.' run already exists for '.$month.'. It can no longer be regenerated.'], 409);
                }
                if (! $regenerate) {
                    return response()->json(['ok' => false, 'needsConfirm' => true, 'error' => 'A draft run already exists for '.$month.'. Regenerate to replace it.'], 409);
                }
                // rev172 (M2) — record every draft regeneration so a silently
                // changed payslip always has a trail (who/when/which run replaced).
                try {
                    if (Schema::hasTable('activity_logs')) {
                        $by = trim((string) ($request->user()->name ?? '')) ?: (string) $request->user()->email;
                        DB::table('activity_logs')->insert(ApprovalService::safeRow('activity_logs', [
                            'tenant_id' => $tid,
                            'user_id' => optional($request->user())->id,
                            'action' => 'payroll.regenerate',
                            'entity' => 'payroll_runs',
                            'entity_id' => $existing->id,
                            'detail' => json_encode(['by' => $by, 'company' => $company->name, 'month' => $month, 'replaced_run' => $existing->id]),
                            'ip' => $request->ip(),
                            'created_at' => now(),
                        ]));
                    }
                } catch (\Throwable $e) {
                    // audit is best-effort; never block the regenerate on it
                }
                $this->reverseRun((int) $existing->id);
            }

            $c = $this->compute($request, $company, $month, $lop);
            if (empty($c['rows'])) {
                return response()->json(['ok' => false, 'error' => 'No active employees with a CTC found for this company. '.($c['skipped'] ? $c['skipped'].' employee(s) have no CTC set.' : '')], 422);
            }

            // F5 (rev182): keep the resolved PERIOD, not just the pay date, so the
            // run and every payslip record the window they were actually built
            // from. monthMeta() already honours the Pay Cycle cut-off.
            [$pStart, , , $pEnd] = $this->monthMeta($month);
            $periodStart = $pStart->toDateString();
            // rev191 — a run generated mid-period paid only up to the attendance
            // cut. Store THAT as period_end, not the period's nominal end: the
            // payslip then prints the window it was really built from, and next
            // cycle's period-gap guard flags the days nobody has been paid for
            // instead of letting them disappear.
            $periodEnd = $c['meta']['attendanceEnd'];
            $payDate = $pEnd;
            // rev180 (Gap 7) — pay date from the Salary Schedules / Pay Cycle
            // masters: an active row with pay_day N pays on the Nth of the
            // FOLLOWING month; no configured row → month-end as before.
            $payDate = $this->resolvePayDate($tid, $company, $month, $payDate);

            // ---- F5 TRANSITION GUARD (rev182) -----------------------------
            // The biggest risk when a company changes its pay-cycle cut-off: the
            // new period can start AFTER the last paid one ended, leaving days
            // nobody is paid for (switching calendar -> 21st-20th silently skips
            // 1-20 of the changeover month), or start BEFORE it and pay days
            // twice. Compare this run's window against the most recent run that
            // stored one and refuse until it is acknowledged.
            //
            // Inert for existing data: only runs that carry period_end (written
            // from this release onwards) are compared, so nothing changes for
            // historical runs.
            try {
                if ($periodStart && ! $request->boolean('confirm_period_gap')) {
                    // Compare ONLY with the run for the immediately preceding cycle.
                    // Later runs must be ignored: regenerating an earlier month is a
                    // normal operation and must never be mistaken for an overlap.
                    $prev = DB::table('payroll_runs')
                        ->when($tid, fn ($q) => $q->where('tenant_id', $tid))
                        ->where('company_id', $company->id)
                        ->where('cycle_label', '<', $month)
                        ->whereNotNull('period_end')
                        ->orderByDesc('cycle_label')
                        ->first();
                    if ($prev && $prev->period_end) {
                        // ISO dates compare correctly as strings.
                        $expected = Carbon::parse($prev->period_end)->addDay()->toDateString();
                        if ($periodStart < $expected) {
                            return response()->json([
                                'ok' => false,
                                'needs_confirm' => 'period_overlap',
                                'error' => 'This period starts on ' . $periodStart . ', but payroll for '
                                    . $prev->cycle_label . ' already covered up to ' . $prev->period_end
                                    . '. Those days would be paid twice. Check the Pay Cycle cut-off before continuing.',
                            ], 422);
                        }
                        if ($periodStart > $expected) {
                            $gapDays = (int) Carbon::parse($expected)->diffInDays(Carbon::parse($periodStart));
                            return response()->json([
                                'ok' => false,
                                'needs_confirm' => 'period_gap',
                                'error' => 'Payroll for ' . $prev->cycle_label . ' covered up to ' . $prev->period_end
                                    . ', but this one starts on ' . $periodStart . '. That leaves ' . $gapDays
                                    . ' day(s) (' . $expected . ' to ' . Carbon::parse($periodStart)->subDay()->toDateString()
                                    . ') unpaid — usually caused by a pay-cycle change. '
                                    . 'Run a one-off payroll for those days first, or correct the Pay Cycle cut-off. '
                                    . '(API callers may pass confirm_period_gap=1 to proceed deliberately.)',
                            ], 422);
                        }
                    }
                }
            } catch (\Throwable $eGap) {
                // never block payroll on the guard itself
            }

            $runRow = ApprovalService::safeRow('payroll_runs', [
                'tenant_id' => $tid,
                'company_id' => $company->id,
                'lot' => 1,
                'cycle_label' => $month,
                'pay_date' => $payDate,
                'period_start' => $periodStart,   // F5 — dropped by safeRow if the column is absent
                'period_end' => $periodEnd,
                'status' => 'draft',
                'employees_count' => $c['totals']['count'],
                'net_total' => $c['totals']['net'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            // rev172 (H2) — ensure the calc_note column BEFORE opening the
            // transaction: Schema::table (DDL) implicitly commits in MySQL, so it
            // must not sit inside the atomic block below.
            if (! Schema::hasColumn('payslips', 'calc_note')) {
                try {
                    Schema::table('payslips', function (Blueprint $t) {
                        $t->text('calc_note')->nullable();
                    });
                } catch (\Throwable $e) {
                    // ignore — note simply won't persist if the column can't be added
                }
            }
            // rev176 — more DDL ensures (kept BEFORE the transaction for the same
            // reason): overtime.paid_run_id (which run paid an OT entry),
            // loans.installments_paid (recovery counter — the live loans schema
            // only has emi/tenure_months) and the loan_recoveries ledger (audit
            // trail of every EMI recovered by every run; enables clean reversal).
            try {
                if (Schema::hasTable('overtime') && ! Schema::hasColumn('overtime', 'paid_run_id')) {
                    Schema::table('overtime', function (Blueprint $t) {
                        $t->unsignedBigInteger('paid_run_id')->nullable();
                    });
                }
            } catch (\Throwable $e) {
                // fail-soft: OT still pays; it just won't flip to 'paid'
            }
            try {
                if (Schema::hasTable('loans') && ! Schema::hasColumn('loans', 'installments_paid')) {
                    Schema::table('loans', function (Blueprint $t) {
                        $t->unsignedInteger('installments_paid')->default(0);
                    });
                }
            } catch (\Throwable $e) {
                // fail-soft: EMI still deducts; the counter just won't advance
            }
            try {
                if (! Schema::hasTable('loan_recoveries')) {
                    Schema::create('loan_recoveries', function (Blueprint $t) {
                        $t->id();
                        $t->unsignedBigInteger('tenant_id')->nullable();
                        $t->unsignedBigInteger('run_id')->nullable();
                        $t->unsignedBigInteger('loan_id');
                        $t->unsignedBigInteger('employee_id')->nullable();
                        $t->decimal('amount', 12, 2)->default(0);
                        $t->string('month', 7)->nullable();
                        $t->timestamps();
                    });
                }
            } catch (\Throwable $e) {
                // fail-soft
            }
            // rev181 — clawback run-keyed recovery columns (which run recovered
            // how much, when) — the regenerate reversal is keyed on these.
            try {
                if (Schema::hasTable('clawbacks') && ! Schema::hasColumn('clawbacks', 'recovered_run_id')) {
                    Schema::table('clawbacks', function (Blueprint $t) {
                        $t->unsignedBigInteger('recovered_run_id')->nullable();
                        $t->decimal('recovered_amount', 12, 2)->nullable();
                        $t->timestamp('recovered_at')->nullable();
                    });
                }
            } catch (\Throwable $e) {
                // fail-soft: the clawback still deducts; it just won't be stamped
            }

            // 7 Oct 2026 — run-keyed stamps for expense / bonus / advance items.
            foreach (array_keys(self::ADHOC) as $tbl) {
                try {
                    if (Schema::hasTable($tbl) && ! Schema::hasColumn($tbl, 'paid_run_id')) {
                        Schema::table($tbl, function (Blueprint $t) {
                            $t->unsignedBigInteger('paid_run_id')->nullable();
                            $t->decimal('paid_amount', 12, 2)->nullable();
                        });
                    }
                } catch (\Throwable $e) {
                    // fail-soft: the item still pays; it just won't be stamped
                }
            }

            // rev172 (H2) — write the run header + every payslip ATOMICALLY, so a
            // failure mid-way can never leave a run with a partial set of payslips.
            DB::beginTransaction();
            try {
                $runId = DB::table('payroll_runs')->insertGetId($runRow);

                foreach ($c['rows'] as $r) {
                $earnings = ! empty($r['earnings'])
                    ? $r['earnings']
                    : ['Basic' => $r['basic'], 'HRA' => $r['hra'], 'Special Allowance' => $r['special']];
                if (! empty($r['commissionMap'])) {
                    // Each commission Purpose becomes its own variable earning line
                    // (e.g. Recovery Commission, Collection Incentive).
                    foreach ($r['commissionMap'] as $cpurp => $camt) {
                        if ((float) $camt == 0.0) {
                            continue;
                        }
                        $earnings[$cpurp] = round(($earnings[$cpurp] ?? 0) + (float) $camt, 2);
                    }
                } elseif (! empty($r['commission'])) {
                    $earnings['Commission'] = $r['commission'];
                }
                if (! empty($r['nightAllowance'])) {
                    // rev173 — night shift allowance as its own earning line.
                    $earnings['Night Shift Allowance'] = round(($earnings['Night Shift Allowance'] ?? 0) + (float) $r['nightAllowance'], 2);
                }
                if (! empty($r['otAmount'])) {
                    // rev176 — overtime as its own earning line.
                    $earnings['Overtime'] = round(($earnings['Overtime'] ?? 0) + (float) $r['otAmount'], 2);
                }
                foreach ((array) ($r['adhocEarn'] ?? []) as $al => $aa) {
                    $earnings[$al] = round(($earnings[$al] ?? 0) + (float) $aa, 2);   // 7 Oct 2026 — expense / bonus
                }
                $deductions = ! empty($r['deductionsMap'])
                    ? $r['deductionsMap']
                    : ['PF' => $r['pf'], 'ESI' => $r['esi'], 'Professional Tax' => $r['pt'], 'TDS' => $r['tds']];
                if (empty($r['deductionsMap']) && ! empty($r['conveyance'])) {
                    $deductions['Conveyance'] = $r['conveyance'];
                }
                // rev176/180 — loan EMI, instalment-advance EMI and same-month
                // advance recovery as their own deduction lines.
                if (! empty($r['loanEmi'])) {
                    $deductions['Loan EMI'] = round(($deductions['Loan EMI'] ?? 0) + (float) $r['loanEmi'], 2);
                }
                if (! empty($r['advEmi'])) {
                    $deductions['Advance EMI'] = round(($deductions['Advance EMI'] ?? 0) + (float) $r['advEmi'], 2);
                }
                if (! empty($r['advanceRecovered'])) {
                    $deductions['Salary Advance Recovery'] = round(($deductions['Salary Advance Recovery'] ?? 0) + (float) $r['advanceRecovered'], 2);
                }
                // rev181 — clawback / reversal as its own deduction line.
                if (! empty($r['clawback'])) {
                    $deductions['Clawback / Reversal'] = round(($deductions['Clawback / Reversal'] ?? 0) + (float) $r['clawback'], 2);
                }
                $slip = ApprovalService::safeRow('payslips', [
                    'uuid' => (string) Str::uuid(),
                    'tenant_id' => $tid,
                    'company_id' => $company->id,
                    'employee_id' => $r['employee_id'],
                    'run_id' => $runId,
                    'month' => $month,
                    'period_start' => $periodStart,   // F5 — printed as "Salary Period"
                    'period_end' => $periodEnd,
                    'earnings' => json_encode($earnings),
                    'deductions' => json_encode($deductions),
                    'gross' => $r['gross'],
                    'total_ded' => $r['ded'],
                    'net' => $r['net'],
                    'calc_note' => $r['note'] ?? null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                DB::table('payslips')->insert($slip);
                }
                DB::commit();
            } catch (\Throwable $eTx) {
                DB::rollBack();
                throw $eTx; // surfaced by the method-level catch as a clean JSON error
            }

            // rev 84 (Ejaz): the LOCK — every commission included in this run
            // is frozen forever (no edit, no re-decision). Logged per entry.
            try {
                $lockIds = [];
                foreach ($c['rows'] as $r) {
                    foreach ((array) ($r['commissionIds'] ?? []) as $cid) {
                        $lockIds[] = (int) $cid;
                    }
                }
                if ($lockIds && Schema::hasColumn('commissions', 'locked_at')) {
                    $by = trim((string) ($request->user()->name ?? '')) ?: (string) $request->user()->email;
                    $lockRows = DB::table('commissions')->whereIn('id', $lockIds)->whereNull('locked_at')
                        ->get(['id', 'employee_id', 'amount', 'tenant_id']);
                    $toLock = $lockRows->pluck('id');
                    DB::table('commissions')->whereIn('id', $toLock)->update([
                        'locked_at' => now(), 'locked_by' => $by,
                        'lock_source' => 'payslip '.$c['meta']['monthLabel'], 'updated_at' => now(),
                    ]);
                    foreach ($lockRows as $lr) {
                        ApprovalService::logCommission($tid, (int) $lr->id, 'payslip',
                            'Included in '.$c['meta']['monthLabel'].' payroll (run #'.$runId.') and LOCKED', $by);
                        // rev 85: the ledger records the payslip as the payment
                        // (debit), so each employee's passbook is complete.
                        try {
                            if (Schema::hasTable('commission_payments')) {
                                DB::table('commission_payments')->insert([
                                    'tenant_id' => $lr->tenant_id,
                                    'commission_id' => (int) $lr->id,
                                    'employee_id' => $lr->employee_id,
                                    'paid_on' => now()->toDateString(),
                                    'amount' => round((float) $lr->amount, 2),
                                    'mode' => 'payslip',
                                    'reference' => 'run #'.$runId.' · '.$c['meta']['monthLabel'],
                                    'note' => null,
                                    'by' => $by,
                                    'created_at' => now(),
                                ]);
                            }
                        } catch (\Throwable $e) {
                            \Illuminate\Support\Facades\Log::warning('Commission payslip ledger debit failed (#'.$lr->id.'): '.$e->getMessage());
                        }
                    }
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Commission lock on payroll generate failed: '.$e->getMessage());
            }

            // rev176 — mark this run's OT entries PAID (visibility + no edits;
            // the ot_date month filter already prevents cross-month double-pay).
            try {
                $otIds = [];
                foreach ($c['rows'] as $r) {
                    foreach ((array) ($r['otIds'] ?? []) as $oid) {
                        $otIds[] = (int) $oid;
                    }
                }
                // Only flip when paid_run_id exists — the regenerate reversal is
                // keyed on it; flipping without it would strand entries as 'paid'
                // and silently lose the OT earning on a regenerated draft.
                if ($otIds && Schema::hasTable('overtime') && Schema::hasColumn('overtime', 'paid_run_id')) {
                    $updO = ['status' => 'paid', 'paid_run_id' => $runId];
                    if (Schema::hasColumn('overtime', 'updated_at')) {
                        $updO['updated_at'] = now();
                    }
                    DB::table('overtime')->whereIn('id', $otIds)->where('status', 'approved')->update($updO);
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('OT paid-marking on payroll generate failed: '.$e->getMessage());
            }

            // rev176 — record this run's Loan-EMI recoveries: one installment per
            // recovered loan (+ ledger row), auto-closing a loan when fully repaid
            // (mirrors rev172 H1). Reversed if the draft is regenerated.
            try {
                // Reversal on regenerate is LEDGER-DRIVEN: counters advance only
                // when the loan_recoveries row was written, so a decrement always
                // has a matching increment (never runs ahead of real deductions).
                if (Schema::hasTable('loans') && Schema::hasTable('loan_recoveries')) {
                    $hasPaidCol = Schema::hasColumn('loans', 'installments_paid');
                    foreach ($c['rows'] as $r) {
                        foreach ((array) ($r['loanRecs'] ?? []) as $lrec) {
                            try {
                                $lid = (int) ($lrec['id'] ?? 0);
                                if ($lid <= 0) {
                                    continue;
                                }
                                DB::table('loan_recoveries')->insert([
                                    'tenant_id' => $tid,
                                    'run_id' => $runId,
                                    'loan_id' => $lid,
                                    'employee_id' => $r['employee_id'],
                                    'amount' => round((float) ($lrec['emi'] ?? 0), 2),
                                    'month' => $month,
                                    'created_at' => now(),
                                    'updated_at' => now(),
                                ]);
                                if ($hasPaidCol) {
                                    DB::table('loans')->where('id', $lid)->increment('installments_paid');
                                    $ln = DB::table('loans')->where('id', $lid)->first();
                                    $totL = $ln ? (int) (($ln->installments_total ?? null) ?: ($ln->tenure_months ?? 0)) : 0;
                                    if ($ln && $totL > 0 && (int) ($ln->installments_paid ?? 0) >= $totL) {
                                        DB::table('loans')->where('id', $lid)->update(['status' => 'closed']);
                                    }
                                }
                            } catch (\Throwable $eLr) {
                                \Illuminate\Support\Facades\Log::warning('Loan EMI recovery row failed (loan #'.((int) ($lrec['id'] ?? 0)).'): '.$eLr->getMessage());
                            }
                        }
                    }
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Loan EMI recovery tracking on payroll generate failed: '.$e->getMessage());
            }

            // rev181 — stamp this run's CLAWBACK recoveries (run-keyed; the
            // regenerate reversal clears them so a redraft recovers again).
            // recovered_amount records what THIS slip actually took — a partial
            // take (net ran out) leaves the shortfall visible on the row.
            try {
                if (Schema::hasTable('clawbacks') && Schema::hasColumn('clawbacks', 'recovered_run_id')) {
                    foreach ($c['rows'] as $r) {
                        foreach ((array) ($r['clawbackRecs'] ?? []) as $crec) {
                            $cbId = (int) ($crec['id'] ?? 0);
                            if ($cbId <= 0) {
                                continue;
                            }
                            DB::table('clawbacks')->where('id', $cbId)->whereNull('recovered_run_id')->update([
                                'recovered_run_id' => $runId,
                                'recovered_amount' => round((float) ($crec['amount'] ?? 0), 2),
                                'recovered_at' => now(),
                                'updated_at' => now(),
                            ]);
                        }
                    }
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Clawback recovery stamping on payroll generate failed: '.$e->getMessage());
            }

            // 7 Oct 2026 — stamp expense / bonus / advance items settled by this run.
            try {
                foreach ($c['rows'] as $r) {
                    foreach ((array) ($r['adhocIds'] ?? []) as $tbl => $items) {
                        if (! isset(self::ADHOC[$tbl]) || ! Schema::hasColumn($tbl, 'paid_run_id')) {
                            continue;
                        }
                        foreach ($items as $it) {
                            DB::table($tbl)->where('id', (int) $it['id'])->whereNull('paid_run_id')
                                ->update(['paid_run_id' => $runId, 'paid_amount' => round((float) $it['amount'], 2)]);
                        }
                    }
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Expense / bonus / advance stamping on payroll generate failed: '.$e->getMessage());
            }

            return response()->json([
                'ok' => true,
                'runId' => $runId,
                'count' => $c['totals']['count'],
                'net' => $c['totals']['net'],
                'skipped' => $c['skipped'],
                'message' => 'Draft payroll for '.$c['meta']['monthLabel'].' created — '.$c['totals']['count'].' employee(s), net ₹'.number_format($c['totals']['net'], 2).'. Now in Salary Approval.',
            ]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 200);
        }
    }
}