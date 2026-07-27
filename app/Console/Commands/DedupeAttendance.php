<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * rev173g — repair duplicated attendance punches.
 *
 * Cause: until rev173g, ETimeOfficeService::import() included DIRECTION in the
 * dedup match keys, so after correcting the In/Out Machine IDs a re-sync wrote
 * every punch AGAIN with the fixed direction, leaving the old wrong-direction
 * row behind. Duplicates break both the Attendance Report and payroll (worked
 * time doubles / collapses, late flags misfire).
 *
 * This removes duplicates sharing (tenant_id, emp_code, punch_at, source),
 * keeping the NEWEST row (highest id — the one a re-sync corrected).
 *
 *   php artisan attendance:dedupe --dry     preview only
 *   php artisan attendance:dedupe           delete duplicates
 *   php artisan attendance:dedupe --tenant=3
 */
class DedupeAttendance extends Command
{
    protected $signature = 'attendance:dedupe {--dry : Preview without deleting} {--tenant= : Limit to one tenant id}';

    protected $description = 'Remove duplicate attendance punches (same tenant/employee/moment/source), keeping the newest row';

    public function handle(): int
    {
        if (! Schema::hasTable('attendance_logs')) {
            $this->error('attendance_logs table not found.');

            return self::FAILURE;
        }
        $tenant = $this->option('tenant');

        // Group duplicates in PHP (portable across MySQL/SQLite): identity is
        // tenant|emp_code|punch_at|source; keep the max id per group.
        $q = DB::table('attendance_logs')
            ->when($tenant !== null && $tenant !== '', fn ($x) => $x->where('tenant_id', (int) $tenant))
            ->orderBy('id')
            ->get(['id', 'tenant_id', 'emp_code', 'punch_at', 'source', 'direction']);

        $keep = [];      // key => id to keep (max id wins)
        foreach ($q as $r) {
            $key = ($r->tenant_id ?? 'null').'|'.$r->emp_code.'|'.$r->punch_at.'|'.($r->source ?? '');
            if (! isset($keep[$key]) || $r->id > $keep[$key]) {
                $keep[$key] = $r->id;
            }
        }
        $doomed = [];
        foreach ($q as $r) {
            $key = ($r->tenant_id ?? 'null').'|'.$r->emp_code.'|'.$r->punch_at.'|'.($r->source ?? '');
            if ($keep[$key] !== $r->id) {
                $doomed[] = $r->id;
            }
        }

        $this->info('Punch rows scanned: '.count($q));
        $this->info('Duplicate rows found: '.count($doomed));
        if (! $doomed) {
            $this->info('Nothing to do — no duplicates.');

            return self::SUCCESS;
        }
        if ($this->option('dry')) {
            $sample = DB::table('attendance_logs')->whereIn('id', array_slice($doomed, 0, 10))
                ->get(['id', 'emp_code', 'punch_at', 'direction', 'source']);
            foreach ($sample as $s) {
                $this->line('  would delete #'.$s->id.'  '.$s->emp_code.'  '.$s->punch_at.'  '.$s->direction.'  '.$s->source);
            }
            $this->warn('Dry run — nothing deleted. Re-run without --dry to clean up.');

            return self::SUCCESS;
        }

        $deleted = 0;
        foreach (array_chunk($doomed, 500) as $chunk) {
            $deleted += DB::table('attendance_logs')->whereIn('id', $chunk)->delete();
        }
        $this->info('Deleted '.$deleted.' duplicate punch row(s). Kept the newest copy of each punch.');
        $this->info('Tip: run a Sync now (last few days) on Biometric Device Setup so remaining rows pick up machine-corrected directions.');

        return self::SUCCESS;
    }
}
