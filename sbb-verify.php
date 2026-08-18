<?php

/**
 * SBB — read-only schema verification.
 *
 * Answers one question: did the three SBB migrations actually reach THIS
 * database? "Nothing to migrate" can mean either "already applied" or "applied
 * somewhere else", and those need very different responses.
 *
 * READS ONLY. No table is created, altered or written. Safe on production.
 *
 *   php sbb-verify.php
 */

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function line(string $s = ''): void
{
    echo $s.PHP_EOL;
}

function yn(bool $b): string
{
    return $b ? 'YES' : 'no';
}

line('============================================================');
line(' SBB schema verification (read-only)');
line('============================================================');
line();

// ---- which database are we actually looking at? --------------------
$conn = config('database.default');
$cfg = config('database.connections.'.$conn);
line('Connection      : '.$conn);
line('Host / port     : '.($cfg['host'] ?? '-').' : '.($cfg['port'] ?? '-'));
line('Database        : '.($cfg['database'] ?? '-'));
line('App timezone    : '.config('app.timezone'));
line('App URL         : '.config('app.url'));
line();

try {
    DB::connection()->getPdo();
    line('Connected       : YES');
} catch (\Throwable $e) {
    line('Connected       : NO -> '.$e->getMessage());
    line();
    line('Cannot verify anything else without a database connection.');
    exit(1);
}
line();

// ---- are the three migrations recorded? ----------------------------
line('--- migrations table -----------------------------------------');
try {
    $rows = DB::table('migrations')
        ->where('migration', 'like', '2026_08_16_%')
        ->orderBy('migration')
        ->get(['migration', 'batch']);

    if ($rows->isEmpty()) {
        line('NO SBB migrations recorded in this database.');
        line('=> The three files were never applied HERE. Run: php artisan migrate');
    } else {
        foreach ($rows as $r) {
            line('recorded: '.$r->migration.'   (batch '.$r->batch.')');
        }
    }
} catch (\Throwable $e) {
    line('Could not read the migrations table: '.$e->getMessage());
}
line();

// ---- do the objects actually exist? -------------------------------
line('--- tables ---------------------------------------------------');
line('api_keys exists            : '.yn(Schema::hasTable('api_keys')));
line('attendance_pending exists  : '.yn(Schema::hasTable('attendance_pending')));
line('attendance_logs exists     : '.yn(Schema::hasTable('attendance_logs')));
line();

if (Schema::hasTable('attendance_logs')) {
    line('--- attendance_logs new columns ------------------------------');
    foreach (['device_sn', 'device_user_id', 'external_id', 'verify_mode'] as $c) {
        line(str_pad($c, 27).': '.yn(Schema::hasColumn('attendance_logs', $c)));
    }
    line();

    line('--- attendance_logs unique indexes ---------------------------');
    $want = ['attlog_tenant_external_unique', 'attlog_natural_unique'];
    $found = [];
    try {
        foreach (Schema::getIndexes('attendance_logs') as $ix) {
            $found[strtolower((string) ($ix['name'] ?? ''))] = ! empty($ix['unique']);
        }
        foreach ($want as $w) {
            $present = array_key_exists(strtolower($w), $found);
            line(str_pad($w, 33).': '.yn($present).($present && $found[strtolower($w)] ? ' (unique)' : ''));
        }
    } catch (\Throwable $e) {
        line('Could not list indexes: '.$e->getMessage());
    }
    line();

    line('--- direction column width -----------------------------------');
    try {
        $t = collect(Schema::getColumns('attendance_logs'))
            ->firstWhere('name', 'direction');
        line('direction type : '.($t['type'] ?? 'unknown').'   (expected varchar(8) after migration)');
    } catch (\Throwable $e) {
        line('Could not read column type: '.$e->getMessage());
    }
    line();

    line('--- data ------------------------------------------------------');
    line('total punch rows           : '.DB::table('attendance_logs')->count());
    if (Schema::hasColumn('attendance_logs', 'source')) {
        foreach (DB::table('attendance_logs')->select('source')->selectRaw('COUNT(*) as n')->groupBy('source')->get() as $r) {
            line('  source='.str_pad((string) ($r->source ?? 'null'), 16).' rows='.$r->n);
        }
    }
    if (Schema::hasTable('attendance_pending')) {
        line('pending (unresolved)       : '.DB::table('attendance_pending')->whereNull('resolved_at')->count());
    }
    if (Schema::hasTable('api_keys')) {
        line('api keys (active)          : '.DB::table('api_keys')->where('active', true)->count());
    }
    line();

    line('--- duplicates still present on the natural key ---------------');
    try {
        $dupes = DB::table('attendance_logs')
            ->select('tenant_id', 'emp_code', 'punch_at', 'source')
            ->selectRaw('COUNT(*) as n')
            ->groupBy('tenant_id', 'emp_code', 'punch_at', 'source')
            ->havingRaw('COUNT(*) > 1')
            ->limit(20)
            ->get();
        if ($dupes->isEmpty()) {
            line('none — the natural unique index can be created safely.');
        } else {
            line('FOUND '.$dupes->count().'+ duplicate group(s). Run: php artisan attendance:dedupe --dry');
            foreach ($dupes->take(5) as $d) {
                line('  tenant='.($d->tenant_id ?? 'null').' emp='.$d->emp_code.' at='.$d->punch_at.' source='.$d->source.' x'.$d->n);
            }
        }
    } catch (\Throwable $e) {
        line('Could not check duplicates: '.$e->getMessage());
    }
}

line();
line('--- routes ----------------------------------------------------');
foreach (['api/v1/ping', 'api/v1/attendance/punches', 'app/api-keys', 'app/pending-punches'] as $uri) {
    $hit = collect(app('router')->getRoutes()->getRoutes())
        ->first(fn ($r) => $r->uri() === $uri);
    line(str_pad($uri, 30).': '.yn((bool) $hit));
}

line();
line('============================================================');
line(' Done. Nothing was modified.');
line('============================================================');
