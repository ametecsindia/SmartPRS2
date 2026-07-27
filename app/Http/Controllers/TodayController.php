<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * F3 — the logged-in employee's "today" attendance panel for the dashboard.
 *
 * Additive read-only endpoint: first check-in, last check-out, working hours,
 * a late flag (against the employee's effective Late Policy), and a friendly
 * status — or "No attendance recorded today". Reuses attendance_logs; touches
 * no existing screen or controller.
 */
class TodayController extends Controller
{
    /** GET /app/today */
    public function me(Request $request)
    {
        try {
            $user = $request->user();
            $tid = $user->tenant_id ?? null;
            $emp = $this->currentEmployee($request);
            if (! $emp) {
                return response()->json(['ok' => true, 'linked' => false]);
            }

            $today = Carbon::now()->toDateString();
            $punches = [];
            if (Schema::hasTable('attendance_logs')) {
                $punches = DB::table('attendance_logs')
                    ->when($tid && Schema::hasColumn('attendance_logs', 'tenant_id'), fn ($q) => $q->where('tenant_id', $tid))
                    ->whereRaw('LOWER(emp_code) = ?', [strtolower((string) $emp->emp_code)])
                    ->whereDate('log_date', $today)
                    ->orderBy('punch_at')
                    ->pluck('punch_at')->all();
            }

            if (! $punches) {
                return response()->json([
                    'ok' => true, 'linked' => true, 'date' => $today,
                    'status' => 'no_record', 'message' => 'No attendance recorded today',
                    'first_in' => null, 'last_out' => null, 'working_hours' => 0, 'punches' => 0,
                    'late' => null,
                ]);
            }

            $firstIn = Carbon::parse($punches[0]);
            $lastOut = Carbon::parse($punches[count($punches) - 1]);
            $hours = count($punches) > 1 ? round($firstIn->diffInMinutes($lastOut) / 60, 2) : 0.0;

            [$late, $lateBy] = $this->lateFlag($tid, $emp, $firstIn);

            return response()->json([
                'ok' => true, 'linked' => true, 'date' => $today, 'status' => 'present',
                'first_in' => $firstIn->format('H:i'),
                'last_out' => count($punches) > 1 ? $lastOut->format('H:i') : null,
                'working_hours' => $hours,
                'punches' => count($punches),
                'late' => $late,
                'late_by_min' => $lateBy,
                'message' => $late ? ('Checked in '.$lateBy.' min late') : 'Checked in on time',
            ]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()]);
        }
    }

    /** Same resolution as EssController: linked user → employee row. */
    private function currentEmployee(Request $request)
    {
        $user = $request->user();
        $tid = $user->tenant_id ?? null;
        if (! empty($user->employee_id)) {
            $e = DB::table('employees')->where('id', $user->employee_id)->whereNull('deleted_at')->first();
            if ($e) {
                return $e;
            }
        }

        return DB::table('employees')
            ->when($tid, fn ($q) => $q->where('tenant_id', $tid))
            ->whereNull('deleted_at')
            ->where(fn ($q) => $q->where('email', $user->email)->orWhere('name', $user->name))
            ->first();
    }

    /**
     * Best-effort late flag from late_policy (most specific scope wins:
     * employee > team > company). Returns [bool|null $late, int $lateByMin].
     * Returns [null, 0] when no policy applies (unknown, not "on time").
     */
    private function lateFlag(?int $tid, $emp, Carbon $firstIn): array
    {
        try {
            if (! Schema::hasTable('late_policy')) {
                return [null, 0];
            }
            $rows = DB::table('late_policy')
                ->when($tid && Schema::hasColumn('late_policy', 'tenant_id'), fn ($q) => $q->where('tenant_id', $tid))
                ->get();
            if ($rows->isEmpty()) {
                return [null, 0];
            }
            $companyName = (string) (DB::table('companies')->where('id', $emp->company_id ?? 0)->value('name') ?? '');
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
                return [null, 0];
            }
            $start = (string) ($pick['shift_start'] ?? '') ?: '09:30';
            $parts = array_pad(explode(':', $start), 2, '0');
            $startMin = ((int) $parts[0]) * 60 + (int) $parts[1];
            $grace = (int) ($pick['grace_min'] ?? 0);
            $firstMin = $firstIn->hour * 60 + $firstIn->minute;
            $lateBy = $firstMin - ($startMin + $grace);

            return $lateBy > 0 ? [true, $lateBy] : [false, 0];
        } catch (\Throwable $e) {
            return [null, 0];
        }
    }
}
