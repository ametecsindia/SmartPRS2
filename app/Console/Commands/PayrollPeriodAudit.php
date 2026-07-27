<?php

namespace App\Console\Commands;

use App\Services\PeriodResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * F5e helper — READ-ONLY audit of payroll periods.
 *
 * Writes nothing, changes nothing. It answers the questions you actually need
 * answered before trusting the F5 work:
 *
 *   1. Does the period stored on each run match what the engine would resolve
 *      today? (A mismatch means someone changed the pay-cycle cut-off after the
 *      run was generated — exactly the drift the stored window now prevents.)
 *   2. Are a company's periods continuous — no unpaid gap, no double-paid
 *      overlap? This is the check the new transition guard enforces going
 *      forward; here it is applied to history.
 *   3. Which runs pre-date the change and carry no period at all.
 *
 *   php artisan payroll:period-audit
 *   php artisan payroll:period-audit --tenant=1 --company=2
 */
class PayrollPeriodAudit extends Command
{
    protected $signature = 'payroll:period-audit
        {--tenant= : Limit to one tenant id}
        {--company= : Limit to one company id}';

    protected $description = 'READ-ONLY: check stored payroll periods for drift, gaps and overlaps (F5).';

    public function handle(): int
    {
        if (! Schema::hasTable('payroll_runs')) {
            $this->error('No payroll_runs table.');

            return self::FAILURE;
        }
        $hasPeriod = Schema::hasColumn('payroll_runs', 'period_start');
        if (! $hasPeriod) {
            $this->warn('payroll_runs has no period_start column yet — run `php artisan migrate` first.');
        }

        $runs = DB::table('payroll_runs')
            ->when($this->option('tenant'), fn ($q) => $q->where('tenant_id', (int) $this->option('tenant')))
            ->when($this->option('company'), fn ($q) => $q->where('company_id', (int) $this->option('company')))
            ->orderBy('company_id')->orderBy('cycle_label')->get();

        if ($runs->isEmpty()) {
            $this->info('No payroll runs found.');

            return self::SUCCESS;
        }

        $byCompany = [];
        foreach ($runs as $r) {
            $byCompany[(int) $r->company_id][] = $r;
        }

        $problems = 0;
        $missing = 0;
        $checked = 0;

        foreach ($byCompany as $companyId => $list) {
            $name = $this->companyName($companyId);
            $cutoff = $this->cutoffFor($list[0]->tenant_id ?? null, $name);
            $endDay = ($cutoff >= 2 && $cutoff <= 28) ? $cutoff : null;

            $this->newLine();
            $this->line('<options=bold>Company '.$companyId.' — '.($name ?: '(unnamed)').'</>');
            $this->line('  Pay-cycle cut-off today: '.($endDay === null ? 'calendar month' : 'day '.$endDay.' (window)'));

            $rows = [];
            $prevEnd = null;
            foreach ($list as $r) {
                $checked++;
                $month = (string) $r->cycle_label;
                $stored = ($hasPeriod && $r->period_start && $r->period_end)
                    ? $r->period_start.' → '.$r->period_end
                    : null;

                $expect = PeriodResolver::resolve($month, $endDay);
                $expectStr = $expect['start'].' → '.$expect['end'];

                $flag = '';
                if ($stored === null) {
                    $missing++;
                    $flag = 'no period stored (pre-F5 run)';
                } else {
                    if ($stored !== $expectStr) {
                        $problems++;
                        $flag = 'DRIFT — stored window differs from what the current cut-off would produce';
                    }
                    // continuity against the previous stored period
                    if ($prevEnd !== null) {
                        $wanted = Carbon::parse($prevEnd)->addDay()->toDateString();
                        if ($r->period_start !== $wanted) {
                            $problems++;
                            $overlap = Carbon::parse($r->period_start)->lt(Carbon::parse($wanted));
                            $flag = trim($flag.' | '.($overlap
                                ? 'OVERLAP with the previous run (days paid twice)'
                                : 'GAP after '.$prevEnd.' (days unpaid)'));
                        }
                    }
                    $prevEnd = $r->period_end;
                }

                $rows[] = [
                    $month,
                    $r->status,
                    $stored ?: '—',
                    $expectStr,
                    $flag ?: 'ok',
                ];
            }

            $this->table(['Month', 'Status', 'Stored period', 'Expected today', 'Result'], $rows);
        }

        $this->newLine();
        $this->line('Runs checked: '.$checked);
        $this->line('Without a stored period: '.$missing.' (expected for runs generated before this release)');
        if ($problems) {
            $this->error('Problems found: '.$problems.' — review the rows flagged DRIFT / GAP / OVERLAP above.');

            return self::FAILURE;
        }
        $this->info('No drift, gaps or overlaps detected.');

        return self::SUCCESS;
    }

    private function companyName(int $id): string
    {
        try {
            return (string) (DB::table('companies')->where('id', $id)->value('name') ?: '');
        } catch (\Throwable $e) {
            return '';
        }
    }

    /** Mirrors PayrollGenController::resolveCutoffDay (company row beats all-company). */
    private function cutoffFor($tid, string $companyName): int
    {
        try {
            if (! Schema::hasTable('pay_cycles') || ! Schema::hasColumn('pay_cycles', 'cutoff_day')) {
                return 0;
            }
            $rows = DB::table('pay_cycles')
                ->when($tid && Schema::hasColumn('pay_cycles', 'tenant_id'), fn ($q) => $q->where('tenant_id', $tid))
                ->when(Schema::hasColumn('pay_cycles', 'status'),
                    fn ($q) => $q->where(fn ($x) => $x->where('status', 'active')->orWhereNull('status')))
                ->orderByDesc('id')->limit(50)->get();
            $best = 0;
            foreach ($rows as $row) {
                $cn = strtolower(trim((string) ($row->company_name ?? '')));
                if ($cn !== '' && $cn !== 'all' && $cn !== strtolower(trim($companyName))) {
                    continue;
                }
                $cd = (int) ($row->cutoff_day ?? 0);
                if ($cd >= 2 && $cd <= 28) {
                    if ($cn !== '' && $cn !== 'all') {
                        return $cd;
                    }
                    $best = $best ?: $cd;
                }
            }

            return $best;
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
