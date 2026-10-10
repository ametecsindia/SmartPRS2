<?php

namespace App\Services;

use App\Support\SchemaHelper;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * F5 / rev182 — the payroll PERIOD resolver.
 *
 * Today the engine hard-assumes a calendar month: the attendance window, the
 * LOP denominator, DOJ proration and the payslip label are all derived from
 * "the 1st to the month end". Clients running a retention window (21st of the
 * previous month to the 20th of this one) cannot be served by that.
 *
 * This class is the single place that answers "what dates does payroll month
 * YYYY-MM actually cover, and when does it get paid" — mirroring the
 * ShiftResolver precedent (a small, pure, well-tested service the big engine
 * calls into).
 *
 * DESIGN RULE FROM THE BLUEPRINT — "hardcode the menu, NOT the engine":
 * the UI offers exactly two choices (Calendar, and 21st→20th), but nothing in
 * here knows the number 20. The cutoff is a parameter (`period_end_day`,
 * NULL = calendar). A future client on 25→24 is one dropdown entry and zero
 * engine changes.
 *
 * rev191 — WIRED. This is now the single source of truth for "what dates does
 * payroll month YYYY-MM cover for this company". Before rev191 four separate
 * copies of that question existed (PayrollGenController::resolveCutoffDay,
 * AppDataController::payDateFor, PayrollPeriodAudit and this class) reading two
 * different columns, and every other module — ESS, Reports, Live Salary, the
 * Simulator — simply assumed a calendar month. A 21st–20th company therefore
 * saw one window on the payslip and a different one everywhere else. They all
 * call window() now; nothing keeps a private opinion about the period.
 */
class PeriodResolver
{
    /** The two shapes the UI offers. Values are the stored period_end_day. */
    public const MODE_CALENDAR = null;   // 1st → month end
    public const MODE_21_20 = 20;        // 21st of prev month → 20th of this

    /** Dropdown definition for the Pay Cycle master. */
    public static function modes(): array
    {
        return [
            ['value' => '', 'end_day' => null, 'label' => 'Calendar month (1st to month end)'],
            ['value' => '20', 'end_day' => 20, 'label' => '21st to 20th (retention window)'],
        ];
    }

    // ---- the core calculation ---------------------------------------------

    /**
     * The date range a payroll month covers.
     *
     * @param  string    $payrollMonth  'YYYY-MM' — the month the payslip is labelled with
     * @param  int|null  $endDay        NULL = calendar month; else the cutoff day
     * @return array  ['start','end','days','label','mode']
     */
    public static function resolve(string $payrollMonth, ?int $endDay = null): array
    {
        [$y, $m] = self::splitMonth($payrollMonth);

        if ($endDay === null) {
            $start = Carbon::create($y, $m, 1)->startOfDay();
            $end = $start->copy()->endOfMonth()->startOfDay();
        } else {
            // Period ENDS on $endDay of the payroll month (clamped for short
            // months — decision #3: a day that does not exist falls back to the
            // last calendar day, so Feb "30" becomes 28 or 29).
            $end = self::clamp($y, $m, $endDay);
            // …and STARTS the day after the previous month's cutoff.
            $prev = Carbon::create($y, $m, 1)->subMonthNoOverflow();
            $start = self::clamp($prev->year, $prev->month, $endDay)->addDay();
        }

        return [
            'start' => $start->toDateString(),
            'end' => $end->toDateString(),
            'days' => (int) $start->diffInDays($end) + 1,
            'label' => self::label($start, $end),
            'mode' => $endDay === null ? 'calendar' : ('window-' . $endDay),
        ];
    }

    /**
     * Which payroll month a given calendar date belongs to.
     * With a 20th cutoff, 25 Jun 2026 falls inside 21 Jun → 20 Jul, i.e. the
     * JULY payroll month. Needed so attendance, leave and statutory lookups
     * agree with the run they will be paid in.
     */
    public static function payrollMonthFor($date, ?int $endDay = null): string
    {
        $d = self::toDate($date);
        if ($endDay === null) {
            return $d->format('Y-m');
        }
        // On or before the cutoff → this month; after it → next month.
        $cut = self::clamp($d->year, $d->month, $endDay);

        return $d->lte($cut)
            ? $d->format('Y-m')
            : $d->copy()->addMonthNoOverflow()->format('Y-m');
    }

    /**
     * The pay date for a period: the first occurrence of `payDay` on or after
     * the period end. A 21→20 window with pay day 30 therefore pays on the
     * 30th of the SAME month — not "the Nth of next month", which was the old
     * assumption and would have pushed it a month late.
     *
     * Returns null when payDay is not set.
     */
    public static function payDate(string $periodEnd, ?int $payDay): ?string
    {
        if (! $payDay) {
            return null;
        }
        $end = self::toDate($periodEnd);
        $cand = self::clamp($end->year, $end->month, $payDay);
        // 7 Oct 2026 (Ejaz) — the pay day is NEVER inside the attendance period:
        // a pay day on or before the period end moves to the next month.
        if ($cand->lte($end)) {
            $next = $end->copy()->addMonthNoOverflow();
            $cand = self::clamp($next->year, $next->month, $payDay);
        }

        return $cand->toDateString();
    }

    /**
     * Statutory comfort check: wages are expected within 7 days of the period
     * ending. Returns ['ok'=>bool,'gap'=>int,'note'=>string] — advisory only,
     * surfaced as a soft note on the Pay Cycle screen, never a hard block.
     */
    public static function payGapCheck(string $periodEnd, ?string $payDate): array
    {
        if (! $payDate) {
            return ['ok' => true, 'gap' => 0, 'note' => ''];
        }
        $gap = (int) self::toDate($periodEnd)->diffInDays(self::toDate($payDate), false);
        if ($gap > 7) {
            return [
                'ok' => false, 'gap' => $gap,
                'note' => 'Wages are paid ' . $gap . ' days after the period ends. Most state Shops & Establishments rules expect payment within 7 days.',
            ];
        }

        return ['ok' => true, 'gap' => $gap, 'note' => ''];
    }

    // ---- transition guard --------------------------------------------------

    /**
     * THE BIGGEST RISK in rev182: the month a company switches from calendar to
     * a window. Moving from "1–31 Jul" to "21 Jul–20 Aug" would silently skip
     * 1–20 Jul, or double-pay it if the switch goes the other way.
     *
     * Given the last period already paid and the new mode, this returns the
     * stub period that must be run once to bridge the gap, or null when the
     * periods already meet.
     *
     * @return array|null ['start','end','days','label','reason']
     */
    public static function transitionStub(?string $lastPaidPeriodEnd, string $firstNewMonth, ?int $newEndDay): ?array
    {
        if (! $lastPaidPeriodEnd) {
            return null;   // nothing paid yet — no gap to bridge
        }
        $next = self::resolve($firstNewMonth, $newEndDay);
        $gapStart = self::toDate($lastPaidPeriodEnd)->addDay();
        $newStart = self::toDate($next['start']);

        if ($gapStart->gte($newStart)) {
            return null;   // contiguous (or overlapping — caught by validate())
        }
        $gapEnd = $newStart->copy()->subDay();

        return [
            'start' => $gapStart->toDateString(),
            'end' => $gapEnd->toDateString(),
            'days' => (int) $gapStart->diffInDays($gapEnd) + 1,
            'label' => self::label($gapStart, $gapEnd),
            'reason' => 'One-off bridging period created by the pay-cycle change. Run this before the first ' . $next['label'] . ' payroll.',
        ];
    }

    /**
     * Decision #4 — cycles must be continuous: no gaps AND no overlaps.
     * Compares consecutive months under one mode and reports the first break.
     *
     * @return array ['ok'=>bool,'error'=>string]
     */
    public static function validateContinuity(string $fromMonth, int $months, ?int $endDay): array
    {
        $cur = self::splitMonthCarbon($fromMonth);
        $prevEnd = null;
        for ($i = 0; $i < max(1, $months); $i++) {
            $p = self::resolve($cur->format('Y-m'), $endDay);
            if ($prevEnd !== null) {
                $expected = self::toDate($prevEnd)->addDay()->toDateString();
                if ($p['start'] !== $expected) {
                    $overlap = self::toDate($p['start'])->lt(self::toDate($expected));

                    return [
                        'ok' => false,
                        'error' => ($overlap ? 'Periods overlap' : 'There is a gap') . ' between ' . $prevEnd . ' and ' . $p['start'] . '.',
                    ];
                }
            }
            $prevEnd = $p['end'];
            $cur = $cur->addMonthNoOverflow();
        }

        return ['ok' => true, 'error' => ''];
    }

    // ---- storage -----------------------------------------------------------

    /** Additive columns this feature needs. Idempotent; safe to re-run. */
    public static function ensureSchema(): void
    {
        SchemaHelper::ensureColumns('pay_cycles', [
            'period_end_day' => fn ($t) => $t->unsignedTinyInteger('period_end_day')->nullable(),
            'effective_from' => fn ($t) => $t->date('effective_from')->nullable(),
        ]);
        foreach (['payroll_runs', 'payslips'] as $tbl) {
            SchemaHelper::ensureColumns($tbl, [
                'period_start' => fn ($t) => $t->date('period_start')->nullable(),
                'period_end' => fn ($t) => $t->date('period_end')->nullable(),
            ]);
        }
    }

    /**
     * rev191 — scan the Salary Schedules / Pay Cycle masters for one configured
     * day value (cutoff_day or pay_day), by company NAME, which is how those
     * tables actually key their rows. Lifted verbatim from
     * PayrollGenController::resolveCutoffDay so the precedence is unchanged:
     * salary_schedules before pay_cycles, a company-specific row before an
     * all-company / blank one, newest id first. 0 = not configured.
     */
    public static function masterDay(?int $tenantId, ?string $companyName, string $column, int $min = 2, int $max = 28): int
    {
        try {
            $cn = strtolower(trim((string) $companyName));
            $bestGeneric = 0;
            foreach (['salary_schedules', 'pay_cycles'] as $table) {
                if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                    continue;
                }
                $rows = DB::table($table)
                    ->when($tenantId && Schema::hasColumn($table, 'tenant_id'), fn ($q) => $q->where('tenant_id', $tenantId))
                    ->when(Schema::hasColumn($table, 'status'), fn ($q) => $q->where(fn ($x) => $x->where('status', 'active')->orWhereNull('status')))
                    ->orderByDesc('id')->limit(50)->get();
                foreach ($rows as $row) {
                    $rcn = strtolower(trim((string) ($row->company_name ?? '')));
                    if ($rcn !== '' && $rcn !== 'all' && $rcn !== $cn) {
                        continue;
                    }
                    $v = (int) ($row->{$column} ?? 0);
                    if ($v < $min || $v > $max) {
                        continue;
                    }
                    if ($rcn !== '' && $rcn !== 'all') {
                        return $v;   // company-specific row wins
                    }
                    if ($bestGeneric === 0) {
                        $bestGeneric = $v;
                    }
                }
            }

            return $bestGeneric;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * rev191 — THE call every module makes. One window, one label, one answer.
     *
     * @param  bool  $capToday  cap the end at today for an in-progress period,
     *                          so "this month so far" means the same thing in
     *                          Live Salary, ESS and the payroll engine.
     * @return array ['start','end','days','label','end_day','pay_date',
     *                'open' => period has not finished, 'attendance_end']
     */
    public static function window(?int $tenantId, ?string $companyName, string $payrollMonth, bool $capToday = false): array
    {
        $endDay = self::masterDay($tenantId, $companyName, 'cutoff_day');
        $p = self::resolve($payrollMonth, $endDay >= 2 && $endDay <= 28 ? $endDay : null);
        $today = Carbon::now()->startOfDay()->toDateString();
        $p['open'] = $p['end'] > $today;
        $p['attendance_end'] = ($capToday && $p['open']) ? $today : $p['end'];
        $p['end_day'] = $endDay ?: null;
        $payDay = self::masterDay($tenantId, $companyName, 'pay_day', 1, 28);
        // 7 Oct 2026 (Ejaz) — pay on the first pay day AFTER the period ends.
        // (The old "cut-off cycle pays inside the run month" rule put a pay day
        // of 1–cutoff BEFORE the period closed, e.g. 26 Aug–25 Sep paid 01-09.)
        $p['pay_date'] = self::payDate($p['end'], $payDay ?: null);

        return $p;
    }

    /**
     * rev191 — which payroll month TODAY belongs to for this company. With a
     * 21st–20th cycle, 23 September sits inside 21 Sep → 20 Oct, i.e. the
     * OCTOBER run. Live Salary and ESS used now()->format('Y-m') and were a
     * month out for every window client.
     */
    public static function currentMonthFor(?int $tenantId, ?string $companyName, $on = null): string
    {
        $endDay = self::masterDay($tenantId, $companyName, 'cutoff_day');

        return self::payrollMonthFor($on ?: Carbon::now(), $endDay >= 2 && $endDay <= 28 ? $endDay : null);
    }

    /**
     * The cutoff configured for a company, or null (calendar).
     * Effective-dated: reads the row in force on $on via {@see EffectiveDated},
     * so a mid-year switch never retro-changes a closed month.
     */
    public static function endDayForCompany(?int $tenantId, ?int $companyId, $on = null): ?int
    {
        try {
            if (! Schema::hasTable('pay_cycles') || ! Schema::hasColumn('pay_cycles', 'period_end_day')) {
                return null;
            }
            $row = EffectiveDated::resolve('pay_cycles', [
                'tenant_id' => $tenantId,
                'company_id' => $companyId,
            ], $on);

            // Fall back to any active cycle for the tenant when the table has no
            // company scoping (older installs keyed cycles by company_name).
            if (! $row) {
                $row = DB::table('pay_cycles')
                    ->when($tenantId && Schema::hasColumn('pay_cycles', 'tenant_id'),
                        fn ($q) => $q->where('tenant_id', $tenantId))
                    ->orderByDesc('id')->first();
            }
            $v = $row->period_end_day ?? null;

            return ($v === null || $v === '') ? null : (int) $v;
        } catch (\Throwable $e) {
            return null;   // fail safe: calendar month, i.e. today's behaviour
        }
    }

    /**
     * Convenience for the engine: everything about a company's payroll month in
     * one call. With no configuration this returns exactly the calendar month,
     * which is the invariant the P1 regression gate checks.
     */
    public static function forCompany(?int $tenantId, ?int $companyId, string $payrollMonth, ?int $payDay = null): array
    {
        $endDay = self::endDayForCompany($tenantId, $companyId, $payrollMonth . '-01');
        $p = self::resolve($payrollMonth, $endDay);
        $p['pay_date'] = self::payDate($p['end'], $payDay);
        $p['end_day'] = $endDay;

        return $p;
    }

    // ---- helpers -----------------------------------------------------------

    /** Day-of-month clamped to the real length of that month. */
    public static function clamp(int $year, int $month, int $day): Carbon
    {
        $last = Carbon::create($year, $month, 1)->daysInMonth;

        return Carbon::create($year, $month, min(max(1, $day), $last))->startOfDay();
    }

    /** "21-06-2026 – 20-07-2026 (30 days)" style label for payslips and reports. */
    public static function label(Carbon $start, Carbon $end): string
    {
        $days = (int) $start->diffInDays($end) + 1;

        return $start->format('d-m-Y')
            . ' – ' . $end->format('d-m-Y')
            . ' (' . $days . ' days)';
    }

    private static function splitMonth(string $ym): array
    {
        if (preg_match('/^(\d{4})-(\d{1,2})/', trim($ym), $m)) {
            return [(int) $m[1], (int) $m[2]];
        }
        $n = Carbon::now();

        return [$n->year, $n->month];
    }

    private static function splitMonthCarbon(string $ym): Carbon
    {
        [$y, $m] = self::splitMonth($ym);

        return Carbon::create($y, $m, 1)->startOfDay();
    }

    private static function toDate($v): Carbon
    {
        try {
            return Carbon::parse($v)->startOfDay();
        } catch (\Throwable $e) {
            return Carbon::now()->startOfDay();
        }
    }
}
