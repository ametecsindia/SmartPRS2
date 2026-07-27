<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Points auto-award. Turns the (otherwise inert) Points Rules into action by
 * applying ATTENDANCE-based rules for a chosen month into the Points Ledger.
 *
 * Rules are matched by a keyword in the rule's `event` text:
 *   - contains "late"        → points x late-days that month
 *   - contains "absent"      → points x absent-days that month
 *   - contains "perfect" /
 *     "present" / "attendance"→ points once IF the employee had no lates/absences
 *
 * Idempotent per month: re-running replaces the previous auto rows (source_ref
 * prefix "auto:{month}"). Manual ledger entries are never touched.
 */
class PointsController extends Controller
{
    private const SHIFT_START_MIN = 9 * 60 + 30; // 09:30 cutoff for "late" (gamification heuristic)

    public function autoApply(Request $request)
    {
        if ($deny = ApprovalService::denyUnlessRole($request, ['admin', 'hr_manager'])) {
            return $deny;
        }
        try {
            $v = $request->validate(['month' => ['required', 'string']]);
            try {
                $month = Carbon::parse($v['month'].'-01')->format('Y-m');
            } catch (\Throwable $e) {
                return response()->json(['ok' => false, 'error' => 'Pick a valid month.'], 422);
            }
            if (! Schema::hasTable('point_rules') || ! Schema::hasTable('points_ledger')) {
                return response()->json(['ok' => false, 'error' => 'Points Rules / Ledger not set up yet.'], 422);
            }
            $tid = $request->user()->tenant_id;

            $rules = DB::table('point_rules')
                ->when($tid && Schema::hasColumn('point_rules', 'tenant_id'), fn ($q) => $q->where('tenant_id', $tid))
                ->get();
            if ($rules->isEmpty()) {
                return response()->json(['ok' => false, 'error' => 'No points rules defined. Add rules whose Event contains "late", "absent" or "perfect attendance".'], 422);
            }
            // Bucket rules by the attendance keyword in their event text.
            $lateRules = [];
            $absentRules = [];
            $perfectRules = [];
            $awardRules = [];
            $testRules = [];
            foreach ($rules as $r) {
                $ev = strtolower((string) $r->event);
                if (str_contains($ev, 'late')) {
                    $lateRules[] = $r;
                } elseif (str_contains($ev, 'absent')) {
                    $absentRules[] = $r;
                } elseif (str_contains($ev, 'award') || str_contains($ev, 'reward')) {
                    $awardRules[] = $r;
                } elseif (str_contains($ev, 'test') || str_contains($ev, 'pass') || str_contains($ev, 'exam') || str_contains($ev, 'assessment')) {
                    $testRules[] = $r;
                } elseif (str_contains($ev, 'perfect') || str_contains($ev, 'present') || str_contains($ev, 'attendance')) {
                    $perfectRules[] = $r;
                }
            }
            if (! $lateRules && ! $absentRules && ! $perfectRules && ! $awardRules && ! $testRules) {
                return response()->json(['ok' => false, 'error' => 'No matching rules. Name a rule Event with "late", "absent", "perfect attendance", "award" or "test passed" to auto-apply it.'], 422);
            }

            [$start, $end, $working, $holidays] = $this->monthMeta($tid, $month);

            // Clear previous auto rows for this month (idempotent re-run).
            DB::table('points_ledger')
                ->when($tid && Schema::hasColumn('points_ledger', 'tenant_id'), fn ($q) => $q->where('tenant_id', $tid))
                ->where('source_ref', 'like', 'auto:'.$month.'%')
                ->delete();

            $emps = DB::table('employees')
                ->when($tid, fn ($q) => $q->where('tenant_id', $tid))
                ->where('status', 'active')->whereNull('deleted_at')
                ->get(['id', 'emp_code', 'name', 'company_id']);

            $insert = [];
            $today = now()->toDateString();
            foreach ($emps as $e) {
                [$present, $late] = $this->attStats($tid, $e->emp_code, $month, $end);
                $absent = max(0, $working - $present);
                $hadIssues = ($late > 0 || $absent > 0);

                foreach ($lateRules as $r) {
                    if ($late > 0) {
                        $insert[] = $this->ledgerRow($tid, $e, $r, (int) $r->points * $late, $month, 'late', $today);
                    }
                }
                foreach ($absentRules as $r) {
                    if ($absent > 0) {
                        $insert[] = $this->ledgerRow($tid, $e, $r, (int) $r->points * $absent, $month, 'absent', $today);
                    }
                }
                foreach ($perfectRules as $r) {
                    if (! $hadIssues && $present > 0) {
                        $insert[] = $this->ledgerRow($tid, $e, $r, (int) $r->points, $month, 'perfect', $today);
                    }
                }
                if ($awardRules) {
                    $n = $this->awardsThisMonth($tid, $e, $month, $end);
                    foreach ($awardRules as $r) {
                        if ($n > 0) {
                            $insert[] = $this->ledgerRow($tid, $e, $r, (int) $r->points * $n, $month, 'award', $today);
                        }
                    }
                }
                if ($testRules) {
                    $n = $this->testsPassedThisMonth($tid, $e, $month, $end);
                    foreach ($testRules as $r) {
                        if ($n > 0) {
                            $insert[] = $this->ledgerRow($tid, $e, $r, (int) $r->points * $n, $month, 'test', $today);
                        }
                    }
                }
            }
            if ($insert) {
                foreach (array_chunk($insert, 400) as $chunk) {
                    DB::table('points_ledger')->insert($chunk);
                }
            }

            return response()->json(['ok' => true, 'count' => count($insert),
                'message' => count($insert).' points entries auto-applied for '.Carbon::parse($month.'-01')->format('F Y').' (late/absent/perfect-attendance rules).']);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /** [present-days, late-days] for an employee in the month from attendance_logs. */
    private function attStats(?int $tid, string $empCode, string $month, string $end): array
    {
        if (! $empCode || ! Schema::hasTable('attendance_logs')) {
            return [0, 0];
        }
        try {
            $rows = DB::table('attendance_logs')
                ->when($tid && Schema::hasColumn('attendance_logs', 'tenant_id'), fn ($q) => $q->where('tenant_id', $tid))
                ->where('emp_code', $empCode)
                ->whereBetween('log_date', [$month.'-01', $end])
                ->where(function ($q) {
                    $q->where('direction', 'in')->orWhereNull('direction');
                })
                ->orderBy('punch_at')
                ->get(['log_date', 'punch_at']);
            $firstByDay = [];
            foreach ($rows as $r) {
                $d = substr((string) $r->log_date, 0, 10);
                if (! isset($firstByDay[$d]) && $r->punch_at) {
                    $firstByDay[$d] = $r->punch_at;
                }
            }
            $present = count($firstByDay);
            $late = 0;
            foreach ($firstByDay as $pa) {
                $t = Carbon::parse($pa);
                if (($t->hour * 60 + $t->minute) > self::SHIFT_START_MIN) {
                    $late++;
                }
            }

            return [$present, $late];
        } catch (\Throwable $e) {
            return [0, 0];
        }
    }

    /** Awards the employee received in the month. */
    private function awardsThisMonth(?int $tid, object $e, string $month, string $end): int
    {
        if (! Schema::hasTable('awards')) {
            return 0;
        }
        try {
            return (int) DB::table('awards')
                ->when($tid && Schema::hasColumn('awards', 'tenant_id'), fn ($q) => $q->where('tenant_id', $tid))
                ->where(function ($q) use ($e) {
                    if (Schema::hasColumn('awards', 'employee_id')) {
                        $q->where('employee_id', $e->id);
                    } else {
                        $q->where('employee', $e->name);
                    }
                })
                ->when(Schema::hasColumn('awards', 'date'), fn ($q) => $q->whereBetween('date', [$month.'-01', $end]))
                ->count();
        } catch (\Throwable $ex) {
            return 0;
        }
    }

    /** Tests the employee PASSED in the month. */
    private function testsPassedThisMonth(?int $tid, object $e, string $month, string $end): int
    {
        if (! Schema::hasTable('test_attempts')) {
            return 0;
        }
        try {
            $dateCol = Schema::hasColumn('test_attempts', 'attempted_on') ? 'attempted_on' : 'created_at';

            return (int) DB::table('test_attempts')
                ->when($tid && Schema::hasColumn('test_attempts', 'tenant_id'), fn ($q) => $q->where('tenant_id', $tid))
                ->where('employee_id', $e->id)
                ->whereRaw('LOWER(status) = ?', ['passed'])
                ->whereBetween($dateCol, [$month.'-01', $end.' 23:59:59'])
                ->count();
        } catch (\Throwable $ex) {
            return 0;
        }
    }

    private function ledgerRow(?int $tid, object $e, object $rule, int $points, string $month, string $kind, string $date): array
    {
        return [
            'tenant_id' => $tid,
            'company_id' => $e->company_id,
            'employee_id' => $e->id,
            'employee' => $e->name,
            'event' => $rule->event,
            'category' => $rule->category ?: ($points < 0 ? 'negative' : 'positive'),
            'points' => $points,
            'date' => $date,
            'note' => 'Auto: '.$kind.' rule for '.$month,
            'source_ref' => 'auto:'.$month.':'.$kind.':'.$rule->id,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /** [startDate, endDate, workingDays, holidaysMap] for a month (working = days minus Sundays/holidays). */
    private function monthMeta(?int $tid, string $month): array
    {
        $start = Carbon::parse($month.'-01');
        $end = $start->copy()->endOfMonth();
        $endStr = $end->toDateString();
        $holidays = [];
        if (Schema::hasTable('holidays')) {
            try {
                $holidays = DB::table('holidays')
                    ->when($tid && Schema::hasColumn('holidays', 'tenant_id'), fn ($q) => $q->where('tenant_id', $tid))
                    ->whereBetween('date', [$start->toDateString(), $endStr])
                    ->pluck('date')->mapWithKeys(fn ($d) => [substr((string) $d, 0, 10) => true])->all();
            } catch (\Throwable $e) {
                $holidays = [];
            }
        }
        $working = 0;
        for ($d = $start->copy(); $d->lte($end); $d->addDay()) {
            if ($d->dayOfWeek === Carbon::SUNDAY || isset($holidays[$d->toDateString()])) {
                continue;
            }
            $working++;
        }

        return [$start->toDateString(), $endStr, max(1, $working), $holidays];
    }
}
