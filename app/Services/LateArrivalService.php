<?php

namespace App\Services;

use App\Http\Controllers\SettingsController;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

/**
 * F4 — immediate late-arrival email notification.
 *
 * When a punch is recorded (cloud sync, LAN device sync or an approved bulk
 * upload), we evaluate the employee's FIRST-IN for that day against the
 * organisation's Late Policy (shift start + grace). If they were late — and we
 * have not already emailed them for that day — the employee (cc: reporting
 * manager) gets an automatic email.
 *
 * Everything here is FAIL-SOFT: any error is swallowed and logged so a
 * notification problem can NEVER break attendance import or payroll. It is
 * OFF by default (statutory setting late_email_enabled) so no client is
 * surprised by automatic mail; HR turns it on in Statutory Rate Settings.
 */
class LateArrivalService
{
    /** A default shift start when a policy row has none. */
    private const DEFAULT_START = '09:30';

    private static function ensureTable(): void
    {
        if (Schema::hasTable('late_notifications')) {
            return;
        }
        Schema::create('late_notifications', function ($t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->nullable()->index();
            $t->unsignedBigInteger('employee_id')->nullable()->index();
            $t->string('emp_code')->nullable();
            $t->date('log_date')->nullable();
            $t->integer('late_min')->default(0);
            $t->string('channel', 20)->default('email');
            $t->timestamp('sent_at')->nullable();
            $t->timestamps();
            $t->unique(['tenant_id', 'employee_id', 'log_date'], 'late_notif_unique');
        });
    }

    /**
     * Evaluate & notify a batch of touched (emp_code => dates) pairs. Each entry
     * is de-duplicated per employee-day. Call this right after punches are written.
     *
     * @param array<string,array<string,bool>> $touched  emp_code => [ 'Y-m-d' => true, ... ]
     */
    public static function notifyTouched(?int $tid, array $touched): void
    {
        try {
            if (! $touched) {
                return;
            }
            $rates = SettingsController::rates($tid);
            if ((int) ($rates['late_email_enabled'] ?? 0) !== 1) {
                return;   // feature OFF (default) — nothing to do
            }
            foreach ($touched as $empCode => $dates) {
                foreach (array_keys($dates) as $date) {
                    self::evaluate($tid, (string) $empCode, (string) $date, $rates);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('LateArrivalService.notifyTouched: '.$e->getMessage());
        }
    }

    /** Evaluate one employee-day and email if late (once/day). Fail-soft. */
    public static function evaluate(?int $tid, string $empCode, string $date, ?array $rates = null): void
    {
        try {
            $empCode = trim($empCode);
            if ($empCode === '' || $date === '') {
                return;
            }
            $rates = $rates ?: SettingsController::rates($tid);
            if ((int) ($rates['late_email_enabled'] ?? 0) !== 1) {
                return;
            }
            // Only for RECENT punches — a historical backfill/sync must not spray
            // emails about punches from weeks ago. Today or yesterday only.
            try {
                $days = Carbon::parse($date)->startOfDay()->diffInDays(Carbon::now()->startOfDay(), false);
                if ($days < 0 || $days > 1) {
                    return;
                }
            } catch (\Throwable $e) {
                return;
            }

            if (! Schema::hasTable('employees') || ! Schema::hasTable('attendance_logs')) {
                return;
            }
            $emp = DB::table('employees')
                ->when($tid, fn ($q) => $q->where('tenant_id', $tid))
                ->whereRaw('LOWER(emp_code) = ?', [strtolower($empCode)])
                ->whereNull('deleted_at')
                ->first();
            if (! $emp || empty($emp->email)) {
                return;   // nobody to email
            }

            // First punch of the day (earliest punch_at) — treat as the arrival.
            $first = DB::table('attendance_logs')
                ->when($tid && Schema::hasColumn('attendance_logs', 'tenant_id'), fn ($q) => $q->where('tenant_id', $tid))
                ->whereRaw('LOWER(emp_code) = ?', [strtolower($emp->emp_code)])
                ->whereDate('log_date', $date)
                ->orderBy('punch_at')
                ->value('punch_at');
            if (! $first) {
                return;
            }
            $firstIn = Carbon::parse($first);
            $firstMin = $firstIn->hour * 60 + $firstIn->minute;

            [$startMin, $grace, $ok] = self::effectivePolicy($tid, $emp);
            if (! $ok) {
                return;   // no late policy for this employee → nothing to enforce
            }
            $lateMin = $firstMin - ($startMin + $grace);
            if ($lateMin <= 0) {
                return;   // on time
            }

            // De-dup: one email per employee-day.
            self::ensureTable();
            $already = DB::table('late_notifications')
                ->where('employee_id', $emp->id)->whereDate('log_date', $date)->exists();
            if ($already) {
                return;
            }

            self::sendEmail($emp, $date, $firstIn, $lateMin, $startMin, $grace, $tid);

            DB::table('late_notifications')->insert([
                'tenant_id' => $emp->tenant_id ?? $tid,
                'employee_id' => $emp->id,
                'emp_code' => $emp->emp_code,
                'log_date' => $date,
                'late_min' => $lateMin,
                'channel' => 'email',
                'sent_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('LateArrivalService.evaluate('.$empCode.','.$date.'): '.$e->getMessage());
        }
    }

    /**
     * Resolve the employee's effective Late Policy → [shiftStartMinutes, graceMinutes, found].
     * Most specific wins: employee (scope_target = emp_code) > team > company.
     */
    private static function effectivePolicy(?int $tid, $emp): array
    {
        if (! Schema::hasTable('late_policy')) {
            return [0, 0, false];
        }
        $rows = DB::table('late_policy')
            ->when($tid && Schema::hasColumn('late_policy', 'tenant_id'), fn ($q) => $q->where('tenant_id', $tid))
            ->get();
        if ($rows->isEmpty()) {
            return [0, 0, false];
        }
        $companyName = '';
        try {
            $companyName = (string) DB::table('companies')->where('id', $emp->company_id ?? 0)->value('name');
        } catch (\Throwable $e) {
        }
        $teamName = (string) ($emp->team ?? '');
        $empCode = (string) ($emp->emp_code ?? '');

        $pick = null;
        $rank = -1;
        foreach ($rows as $r) {
            $a = (array) $r;
            $cn = (string) ($a['company_name'] ?? '');
            if ($cn !== '' && strcasecmp($cn, $companyName) !== 0) {
                continue;
            }
            $scope = $a['scope'] ?? 'company';
            $target = (string) ($a['scope_target'] ?? '');
            $tr = -1;
            if ($scope === 'employee' && $empCode !== '' && strcasecmp($target, $empCode) === 0) {
                $tr = 2;
            } elseif ($scope === 'team' && $teamName !== '' && $target !== '' && strcasecmp($target, $teamName) === 0) {
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
            return [0, 0, false];
        }
        $start = (string) ($pick['shift_start'] ?? '') ?: self::DEFAULT_START;
        $parts = array_pad(explode(':', $start), 2, '0');
        $startMin = ((int) $parts[0]) * 60 + (int) $parts[1];
        $grace = (int) ($pick['grace_min'] ?? 0);

        return [$startMin, $grace, true];
    }

    /** Send the late-arrival email (employee, cc reporting manager). Fail-soft. */
    private static function sendEmail($emp, string $date, Carbon $firstIn, int $lateMin, int $startMin, int $grace, ?int $tid): void
    {
        try {
            $company = '';
            try {
                $company = (string) DB::table('companies')->where('id', $emp->company_id ?? 0)->value('name');
            } catch (\Throwable $e) {
            }
            $startHhmm = sprintf('%02d:%02d', intdiv($startMin, 60), $startMin % 60);
            $h = intdiv($lateMin, 60);
            $m = $lateMin % 60;
            $lateStr = ($h > 0 ? $h.' hr ' : '').$m.' min';
            $dateNice = Carbon::parse($date)->format('d M Y');

            $body = "Dear ".($emp->name ?? 'Employee').",\n\n"
                ."This is an automated notification that your attendance for ".$dateNice." was recorded as a LATE ARRIVAL.\n\n"
                ."  First punch (arrival) : ".$firstIn->format('h:i A')."\n"
                ."  Shift start + grace   : ".$startHhmm." (+".$grace." min grace)\n"
                ."  Late by               : ".$lateStr."\n\n"
                ."Please ensure timely arrival as per the company's attendance policy. "
                ."If this is due to approved duty, travel or an exception, kindly inform your reporting manager / HR.\n\n"
                .($company !== '' ? $company."\n" : '')
                ."(This is a system-generated email from SmartPRS. Please do not reply.)";

            // CC the reporting manager when we can resolve their email.
            $ccEmail = null;
            try {
                $mgrName = trim((string) ($emp->reporting_manager ?? ''));
                if ($mgrName !== '') {
                    $ccEmail = DB::table('employees')
                        ->when($tid, fn ($q) => $q->where('tenant_id', $tid))
                        ->whereRaw('LOWER(name) = ?', [strtolower($mgrName)])
                        ->whereNotNull('email')->value('email');
                }
            } catch (\Throwable $e) {
            }

            Mail::raw($body, function ($msg) use ($emp, $ccEmail, $dateNice) {
                $msg->to($emp->email)->subject('Late arrival recorded — '.$dateNice);
                if ($ccEmail && strcasecmp($ccEmail, (string) $emp->email) !== 0) {
                    $msg->cc($ccEmail);
                }
            });
        } catch (\Throwable $e) {
            Log::warning('LateArrivalService.sendEmail: '.$e->getMessage());
        }
    }
}
