<?php

namespace App\Console\Commands;

use App\Http\Controllers\PayrollGenController;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * rev191 — wipe payroll runs so a clean set can be generated (testing / a bad
 * cycle configuration that produced wrong slips across several months).
 *
 * It does NOT just delete rows. Every run is put through
 * PayrollGenController::reverseRun(), the same reversal a regenerate uses, so
 * commissions are unlocked, overtime goes back to 'approved', loan instalment
 * counters are wound back and clawbacks are re-opened. A raw
 * "DELETE FROM payroll_runs" leaves all of that stamped against runs that no
 * longer exist, and the next payroll silently skips those commissions.
 *
 *   php artisan payroll:purge                         # everything, after confirming
 *   php artisan payroll:purge --month=2026-08
 *   php artisan payroll:purge --company=3 --force
 *   php artisan payroll:purge --dry-run               # show what would go
 */
class PayrollPurge extends Command
{
    protected $signature = 'payroll:purge
        {--tenant= : Limit to one tenant_id}
        {--company= : Limit to one company_id}
        {--month= : Limit to one cycle_label, YYYY-MM}
        {--dry-run : List the runs and change nothing}
        {--force : Skip the confirmation prompt}
        {--reseed : Re-allow the demo 12-month history seeder and exit}';

    protected $description = 'Delete payroll runs and their payslips, reversing every ledger stamp they made';

    public function handle(): int
    {
        if ($this->option('reseed')) {
            DB::table('settings')->where('key', 'payroll_history_seed')->delete();
            $this->info('Demo 12-month payroll history seeding re-enabled for all tenants.');

            return self::SUCCESS;
        }

        if (! Schema::hasTable('payroll_runs')) {
            $this->error('No payroll_runs table.');

            return self::FAILURE;
        }

        $q = DB::table('payroll_runs')
            ->when($this->option('tenant') && Schema::hasColumn('payroll_runs', 'tenant_id'),
                fn ($x) => $x->where('tenant_id', (int) $this->option('tenant')))
            ->when($this->option('company'), fn ($x) => $x->where('company_id', (int) $this->option('company')))
            ->when($this->option('month'), fn ($x) => $x->where('cycle_label', (string) $this->option('month')))
            ->orderBy('id');

        $runs = $q->get(['id', 'tenant_id', 'company_id', 'cycle_label', 'status', 'employees_count', 'net_total']);
        if ($runs->isEmpty()) {
            $this->info('No payroll runs match — nothing to purge.');
            // Still park the marker: an empty table is exactly the state the demo
            // seeder refills on the next page load, so this is the moment it
            // matters most. (Nothing is deleted here, so --dry-run is unaffected.)
            $this->parkSeedMarker();

            return self::SUCCESS;
        }

        $companies = Schema::hasTable('companies')
            ? DB::table('companies')->pluck('name', 'id')->all()
            : [];

        $this->table(
            ['Run', 'Company', 'Cycle', 'Status', 'Employees', 'Net'],
            $runs->map(fn ($r) => [
                $r->id,
                $companies[$r->company_id] ?? $r->company_id,
                $r->cycle_label,
                $r->status,
                $r->employees_count,
                number_format((float) $r->net_total, 2),
            ])->all()
        );

        if ($this->option('dry-run')) {
            $this->comment($runs->count().' run(s) would be purged. Nothing was changed.');

            return self::SUCCESS;
        }

        // A paid run is real money that left the building — say so out loud.
        $paid = $runs->filter(fn ($r) => in_array($r->status, ['paid', 'locked'], true))->count();
        if ($paid) {
            $this->warn($paid.' of these are already PAID/LOCKED. Purging them erases the record of salaries that were actually disbursed.');
        }

        if (! $this->option('force') && ! $this->confirm('Purge '.$runs->count().' run(s) and every payslip under them?', false)) {
            $this->comment('Cancelled — nothing was changed.');

            return self::SUCCESS;
        }

        $gen = app(PayrollGenController::class);
        $done = 0;
        foreach ($runs as $r) {
            $gen->reverseRun((int) $r->id);
            $done++;
        }

        // Payslips whose parent run was deleted by some earlier raw delete. On an
        // unfiltered purge also take the ones carrying no run_id at all — the
        // demo seeder and older code paths wrote those, and nothing else will
        // ever clean them up.
        $unfiltered = ! $this->option('tenant') && ! $this->option('company') && ! $this->option('month');
        $orphans = 0;
        if (Schema::hasTable('payslips')) {
            $ids = DB::table('payroll_runs')->pluck('id')->all();
            $orphans = DB::table('payslips')
                ->where(function ($x) use ($ids, $unfiltered) {
                    $x->whereNotNull('run_id')->when($ids, fn ($y) => $y->whereNotIn('run_id', $ids));
                    if ($unfiltered) {
                        $x->orWhereNull('run_id');
                    }
                })
                ->delete();
        }

        // rev191 — stop AppDataController::ensurePayrollHistory() re-creating
        // what was just deleted. It seeds 12 months (11 of them 'paid') on every
        // /app/data call and its only guard is "does a run for this month
        // exist" — so without this marker a purged month came straight back on
        // the next page load and blocked regeneration. `--reseed` lifts it.
        $marker = $this->parkSeedMarker();

        $this->info('Purged '.$done.' run(s)'.($orphans ? ' plus '.$orphans.' orphaned payslip(s) from earlier raw deletes' : '').'.');
        $this->comment('Commissions, overtime, loan EMIs and clawbacks were reversed — generate fresh runs when ready.');
        if ($marker) {
            $this->comment('Demo history seeding switched off for '.$marker.' tenant(s) so these months stay deleted (php artisan payroll:purge --reseed to undo).');
        }

        return self::SUCCESS;
    }

    /**
     * Switch off AppDataController::ensurePayrollHistory() for every tenant.
     *
     * That seeder writes 12 months of runs (11 stamped 'paid') on every
     * /app/data call and its only guard is "does a run for this month exist" —
     * so anything purged reappears on the next page load. Covers every tenant
     * with a company, not just the ones that happened to have runs: a tenant
     * whose runs are already gone is precisely the one about to be refilled.
     *
     * settings.value is a JSON column (see AppStateController::save), so the
     * value must be JSON-encoded — a bare string fails with "Invalid JSON text".
     *
     * @return int tenants marked
     */
    private function parkSeedMarker(): int
    {
        $n = 0;
        try {
            if (! Schema::hasTable('settings')) {
                return 0;
            }
            $tenants = Schema::hasTable('companies')
                ? DB::table('companies')->distinct()->pluck('tenant_id')->filter()->all()
                : [];
            foreach (array_unique(array_merge($tenants, [0])) as $tt) {
                DB::table('settings')->updateOrInsert(
                    ['tenant_id' => (int) $tt, 'key' => 'payroll_history_seed'],
                    ['value' => json_encode('off'), 'updated_at' => now(), 'created_at' => now()]
                );
                $n++;
            }
        } catch (\Throwable $e) {
            $this->warn('Could not park the demo-seed marker ('.$e->getMessage().') — if runs reappear, that seeder is why.');

            return 0;
        }

        return $n;
    }
}
