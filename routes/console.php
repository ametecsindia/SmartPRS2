<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| rev 187 (Ejaz): STANDING invoice-number format
|--------------------------------------------------------------------------
| PRS-<financial year>-<month>-<count>  e.g. PRS-2026-27-07-0001
| Count is CONSECUTIVE through the financial year (Apr–Mar) — one shared
| series for SaaS invoices + on-prem licence invoices. Quotations use
| PRS-Q-<FY>-<MM>-<count> (own series). New documents already follow this
| (BillingController::nextInvoiceNumber); this ONE-TIME command renumbers
| documents created before rev187. Idempotent — PRS-format rows are skipped.
|
|   php artisan invoices:renumber-prs --dry     preview old → new mapping
|   php artisan invoices:renumber-prs           apply
*/
Artisan::command('invoices:renumber-prs {--dry : Preview without writing}', function () {
    $dry = (bool) $this->option('dry');
    $db = fn ($t) => \Illuminate\Support\Facades\DB::table($t);
    $fyOf = function ($date) {
        $d = \Illuminate\Support\Carbon::parse($date ?: now());
        $y = $d->month >= 4 ? $d->year : $d->year - 1;

        return $y.'-'.substr((string) ($y + 1), -2);
    };
    $pad = fn (int $n) => str_pad((string) $n, 4, '0', STR_PAD_LEFT);

    // ---------- Invoices: SaaS `invoices` + on-prem licence — ONE shared series per FY.
    $docs = [];
    foreach ($db('invoices')->orderBy('id')->get() as $i) {
        $r = (array) $i;
        if (str_starts_with((string) $r['number'], 'PRS-')) {
            continue;
        }
        $docs[] = ['table' => 'invoices', 'col' => 'number', 'id' => $r['id'],
            'old' => $r['number'], 'date' => $r['issued_on'] ?? $r['created_at'] ?? now()];
    }
    if (\Illuminate\Support\Facades\Schema::hasTable('onprem_clients')) {
        foreach ($db('onprem_clients')->whereNotNull('invoice_no')->orderBy('id')->get() as $c) {
            $r = (array) $c;
            if (str_starts_with((string) $r['invoice_no'], 'PRS-')) {
                continue;
            }
            $docs[] = ['table' => 'onprem_clients', 'col' => 'invoice_no', 'id' => $r['id'],
                'old' => $r['invoice_no'], 'date' => $r['invoiced_at'] ?? $r['updated_at'] ?? $r['created_at'] ?? now()];
        }
    }

    // Group by FY, order chronologically, continue after any existing PRS max.
    $byFy = [];
    foreach ($docs as $d) {
        $byFy[$fyOf($d['date'])][] = $d;
    }
    $changes = [];
    foreach ($byFy as $fy => $list) {
        usort($list, fn ($a, $b) => [strtotime((string) $a['date']), $a['id']] <=> [strtotime((string) $b['date']), $b['id']]);
        $seq = 0;   // start after the highest already-assigned PRS number of this FY
        foreach ([['invoices', 'number'], ['onprem_clients', 'invoice_no']] as [$t, $col]) {
            try {
                foreach ($db($t)->where($col, 'like', 'PRS-'.$fy.'-%')->pluck($col) as $n) {
                    if (preg_match('/-(\d+)$/', (string) $n, $m)) {
                        $seq = max($seq, (int) $m[1]);
                    }
                }
            } catch (\Throwable $e) {
            }
        }
        foreach ($list as $d) {
            $seq++;
            $mm = \Illuminate\Support\Carbon::parse($d['date'])->format('m');
            $d['new'] = 'PRS-'.$fy.'-'.$mm.'-'.$pad($seq);
            $changes[] = $d;
        }
    }

    // ---------- Quotations: PRS-Q-<FY>-<MM>-<count>, own series per FY.
    if (\Illuminate\Support\Facades\Schema::hasTable('signups')) {
        $qByFy = [];
        foreach ($db('signups')->whereNotNull('quote_no')->orderBy('id')->get() as $s) {
            $r = (array) $s;
            if (str_starts_with((string) $r['quote_no'], 'PRS-Q-')) {
                continue;
            }
            $date = $r['quoted_at'] ?? $r['created_at'] ?? now();
            $qByFy[$fyOf($date)][] = ['table' => 'signups', 'col' => 'quote_no', 'id' => $r['id'],
                'old' => $r['quote_no'], 'date' => $date];
        }
        foreach ($qByFy as $fy => $list) {
            usort($list, fn ($a, $b) => [strtotime((string) $a['date']), $a['id']] <=> [strtotime((string) $b['date']), $b['id']]);
            $seq = 0;
            foreach ($db('signups')->where('quote_no', 'like', 'PRS-Q-'.$fy.'-%')->pluck('quote_no') as $n) {
                if (preg_match('/-(\d+)$/', (string) $n, $m)) {
                    $seq = max($seq, (int) $m[1]);
                }
            }
            foreach ($list as $d) {
                $seq++;
                $mm = \Illuminate\Support\Carbon::parse($d['date'])->format('m');
                $d['new'] = 'PRS-Q-'.$fy.'-'.$mm.'-'.$pad($seq);
                $changes[] = $d;
            }
        }
    }

    if (! $changes) {
        $this->info('Nothing to renumber — every document already uses the PRS format.');

        return;
    }
    $this->table(['Table', 'ID', 'Old number', 'New number', 'Dated'],
        array_map(fn ($c) => [$c['table'], $c['id'], $c['old'], $c['new'],
            \Illuminate\Support\Carbon::parse($c['date'])->format('d M Y')], $changes));
    if ($dry) {
        $this->warn(count($changes).' document(s) would be renumbered. Run WITHOUT --dry to apply.');

        return;
    }
    \Illuminate\Support\Facades\DB::transaction(function () use ($changes, $db) {
        foreach ($changes as $c) {
            $db($c['table'])->where('id', $c['id'])->update([$c['col'] => $c['new'], 'updated_at' => now()]);
        }
    });
    $this->info(count($changes).' document(s) renumbered to the PRS format.');
})->purpose('One-time: renumber old invoices/quotes to PRS-<FY>-<MM>-<count>');

/*
|--------------------------------------------------------------------------
| Scheduled tasks
|--------------------------------------------------------------------------
| Field-force compliance: scan DRA/PCC expiry every morning and email a
| digest to each tenant's HR/Admin. Requires the scheduler to be running on
| the server (one cron entry, or `php artisan schedule:work` during dev).
| withoutOverlapping guards against a slow run colliding with the next tick.
*/
Schedule::command('compliance:scan')
    ->dailyAt('07:30')
    ->withoutOverlapping()
    ->onOneServer();

/*
| Production hardening (rev 35):
|  - Nightly database + files backup (spatie/laravel-backup), preceded by a
|    cleanup that prunes old archives per config/backup.php retention.
|  - Daily error digest: emails ops a summary of error-level log entries.
| All require the scheduler to be running on the server (cron: schedule:run
| every minute — see the Cloud VPS deploy guide).
*/
Schedule::command('backup:clean')
    ->dailyAt('01:30')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('backup:run')
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('errors:digest')
    ->dailyAt('07:45')
    ->withoutOverlapping()
    ->onOneServer();

/*
| Subscription expiry alerts (rev 75) — REPLACES the rev-51 auto-invoice job
| (billing:renew) now that tenants renew themselves via Administration → My
| Subscription. Sends email + WhatsApp reminders 15/7/3/1 days before expiry,
| on the day, and daily through the 7-day grace; marks tenants 'suspended'
| after grace (live lock-out is the EnsureSubscriptionActive middleware).
| billing:renew still exists for manual/legacy use: `php artisan billing:renew`.
*/
Schedule::command('billing:alerts')
    ->dailyAt('08:00')
    ->withoutOverlapping()
    ->onOneServer();

/*
| Employee transfers (rev 77): apply approved, future-dated transfers on their
| effective date (branch moves + master↔subsidiary company moves). Runs early
| so the employee is in the right company/branch before the workday's
| attendance and payroll activity.
*/
Schedule::command('transfers:apply')
    ->dailyAt('06:30')
    ->withoutOverlapping()
    ->onOneServer();

/*
| rev 97: PUBLIC LIVE DEMO — wipe + reseed the shared demo workspace every
| 3 hours so website visitors always land in a clean, populated demo
| (Ejaz: "it helps reduce stress on our staff"). Runs at 00:00/03:00/06:00…
*/
// rev185: the demo is passkey-gated — reset WHEN a visitor's window has ended
// (and nobody with a live passkey is inside), checked every 15 minutes, so the
// customer's test data is erased right after their hours finish. A daily 03:30
// full reset backstops installs where no passkey activity happens.
Schedule::command('demo:reset --if-due')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->onOneServer();
Schedule::command('demo:reset')
    ->dailyAt('03:30')
    ->withoutOverlapping()
    ->onOneServer();

/*
| eTimeOffice cloud biometric attendance (rev156): pull punches every hour into
| attendance_logs so the Attendance Report and payroll stay current. Re-pulls the
| last day each run (writes are idempotent via updateOrInsert, so overlap just
| absorbs any late device→cloud sync). INERT until ETIMEOFFICE_ENABLED=true and
| the credentials are set in .env — the ->when() guard keeps it off otherwise.
*/
// Fires when the .env cloud fallback is on OR any device is enabled on the
// "Biometric Device Setup" screen — covers eTimeOffice (cloud) and eSSL
// eTimeTrackLite (local WebAPI) installs where .env is left untouched.
// Ticks every 5 min; the command gates each device by its own sync_interval_min
// (default hourly), so the owner sets 5/10/15/30/60 per device.
Schedule::command('attendance:sync-etimeoffice --days=1')
    ->everyFiveMinutes()
    ->when(function () {
        if ((bool) config('smartprs.etimeoffice.enabled')) {
            return true;
        }
        try {
            return \Illuminate\Support\Facades\Schema::hasTable('biometric_configs')
                && \Illuminate\Support\Facades\DB::table('biometric_configs')->where('enabled', true)->exists();
        } catch (\Throwable $e) {
            return false;
        }
    })
    ->withoutOverlapping()
    ->onOneServer();

/*
| F8 greetings — hourly; the command self-gates on each tenant's send_hour and
| dedupes, so hourly is safe and lets every company fire at its own local time.
*/
Schedule::command('greetings:send')
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer();

/*
| F6 probation completion reminders — once each morning.
*/
Schedule::command('probation:notify')
    ->dailyAt('07:15')
    ->withoutOverlapping()
    ->onOneServer();

/*
| F10 previous-day absence — hourly; each tenant fires at its configured
| send_hour (default 11:00, giving imports time to land) and dedupes.
*/
Schedule::command('absence:notify')
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer();
