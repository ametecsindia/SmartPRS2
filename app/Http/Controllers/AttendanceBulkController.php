<?php

namespace App\Http\Controllers;

use App\Services\LateArrivalService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * BULK attendance upload with an APPROVAL gate (rev 82, Ejaz 5 Jun 2026).
 *
 * Flow: HR downloads the template → fills one row per EMPLOYEE-DAY
 * (code/name, date, in time, out time) → uploads (parsed in the browser,
 * .xlsx via SheetJS or .csv) → rows land in `attendance_pending` with
 * employee resolution + validation (bad rows are flagged, never imported)
 * → an ADMIN approves the batch → punches are written to attendance_logs
 * (source 'bulk'). Single manual entries (Manual Entry screen) stay instant.
 */
class AttendanceBulkController extends Controller
{
    private function uploadGuard(Request $request)
    {
        return ApprovalService::denyUnlessRole($request, ['admin', 'hr_manager']);
    }

    private function approveGuard(Request $request)
    {
        return ApprovalService::denyUnlessRole($request, ['admin']);
    }

    private function ensure(): void
    {
        if (Schema::hasTable('attendance_pending')) {
            return;
        }
        Schema::create('attendance_pending', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->nullable()->index();
            $t->uuid('batch')->index();
            $t->string('emp_code')->nullable();
            $t->string('emp_name')->nullable();
            $t->date('log_date')->nullable();
            $t->string('in_at', 8)->nullable();    // HH:MM
            $t->string('out_at', 8)->nullable();
            $t->string('status', 20)->default('pending');   // pending|approved|rejected|error
            $t->string('error', 300)->nullable();
            $t->string('uploaded_by')->nullable();
            $t->string('decided_by')->nullable();
            $t->timestamp('decided_at')->nullable();
            $t->timestamps();
        });
    }

    /**
     * Excel-friendly CSV templates. Two formats (auto-detected on upload):
     *   day   — one row per employee-DAY: in_time + out_time
     *   punch — one row per PUNCH: time + direction (matches biometric-device
     *           log exports when the device is not connected to the app)
     */
    public function template(Request $request)
    {
        if ($request->query('type') === 'punch') {
            $csv = "emp_code,employee_name,date,punch_time,direction\n"
                ."EMP100,Asha Rao,2026-06-05,09:15,in\n"
                ."EMP100,Asha Rao,2026-06-05,13:05,out\n"
                ."EMP100,Asha Rao,2026-06-05,13:35,in\n"
                ."EMP100,Asha Rao,2026-06-05,18:35,out\n"
                .",Vikram Reddy,2026-06-05,09:30,in\n";
            $name = 'smartprs-attendance-punch-log-template.csv';
        } else {
            $csv = "emp_code,employee_name,date,in_time,out_time\n"
                ."EMP100,Asha Rao,2026-06-05,09:15,18:35\n"
                .",Vikram Reddy,2026-06-05,09:30,18:10\n";
            $name = 'smartprs-attendance-import-template.csv';
        }

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
        ]);
    }

    /** Normalise a time cell ("9:5", "09:05", "9.05 AM", Excel fraction) → HH:MM or null. */
    private function normTime($v): ?string
    {
        $s = trim((string) $v);
        if ($s === '') {
            return null;
        }
        // Excel numeric time (fraction of a day) arrives via SheetJS as e.g. 0.3854
        if (is_numeric($s) && (float) $s > 0 && (float) $s < 1) {
            $mins = (int) round(((float) $s) * 24 * 60);

            return sprintf('%02d:%02d', intdiv($mins, 60) % 24, $mins % 60);
        }
        $s = strtoupper(str_replace(['.', ' '], [':', ''], $s));
        $ampm = null;
        if (str_contains($s, 'AM')) {
            $ampm = 'AM';
            $s = str_replace('AM', '', $s);
        } elseif (str_contains($s, 'PM')) {
            $ampm = 'PM';
            $s = str_replace('PM', '', $s);
        }
        $parts = explode(':', $s);
        if (! is_numeric($parts[0] ?? null)) {
            return null;
        }
        $h = (int) $parts[0];
        $m = (int) ($parts[1] ?? 0);
        if ($ampm === 'PM' && $h < 12) {
            $h += 12;
        }
        if ($ampm === 'AM' && $h === 12) {
            $h = 0;
        }
        if ($h > 23 || $m > 59) {
            return null;
        }

        return sprintf('%02d:%02d', $h, $m);
    }

    /** Normalise a date cell (Y-m-d, d-m-Y, d/m/Y, Excel serial) → Y-m-d or null. */
    private function normDate($v): ?string
    {
        $s = trim((string) $v);
        if ($s === '') {
            return null;
        }
        // Excel serial date (days since 1899-12-30), via SheetJS raw cells.
        if (is_numeric($s) && (float) $s > 25000 && (float) $s < 60000) {
            return Carbon::create(1899, 12, 30)->addDays((int) $s)->toDateString();
        }
        foreach (['Y-m-d', 'd-m-Y', 'd/m/Y', 'm/d/Y', 'd.m.Y'] as $fmt) {
            try {
                $d = Carbon::createFromFormat($fmt, $s);
                if ($d && (int) $d->year > 2000) {
                    return $d->toDateString();
                }
            } catch (\Throwable $e) {
            }
        }
        try {
            return Carbon::parse($s)->toDateString();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Receive parsed rows (browser-parsed xlsx/csv) into a pending batch. */
    public function upload(Request $request)
    {
        if ($deny = $this->uploadGuard($request)) {
            return $deny;
        }
        try {
            $this->ensure();
            $tid = $request->user()->tenant_id;
            $rows = (array) $request->input('rows', []);
            if (! $rows) {
                return response()->json(['ok' => false, 'error' => 'No rows found in the file.'], 422);
            }
            if (count($rows) > 3000) {
                return response()->json(['ok' => false, 'error' => 'Too many rows in one file (max 3,000). Split the file.'], 422);
            }

            // Tenant employee map: by lowercase code AND lowercase name.
            $byCode = [];
            $byName = [];
            foreach (DB::table('employees')->when($tid, fn ($q) => $q->where('tenant_id', $tid))
                ->whereNull('deleted_at')->get(['emp_code', 'name']) as $e) {
                $byCode[strtolower(trim((string) $e->emp_code))] = $e;
                $byName[strtolower(trim((string) $e->name))] = $e;
            }

            $batch = (string) Str::uuid();
            $ok = 0;
            $bad = 0;
            $ins = [];
            foreach ($rows as $r) {
                $r = (array) $r;
                $code = strtolower(trim((string) ($r['code'] ?? '')));
                $name = strtolower(trim((string) ($r['name'] ?? '')));
                $emp = ($code !== '' ? ($byCode[$code] ?? null) : null) ?: ($name !== '' ? ($byName[$name] ?? null) : null);
                $date = $this->normDate($r['date'] ?? '');
                $in = $this->normTime($r['in'] ?? '');
                $out = $this->normTime($r['out'] ?? '');
                $err = null;

                // rev 82b: PUNCH-LOG format (one punch per row, like a biometric
                // device export): time + direction → folded into in/out.
                $ptime = $this->normTime($r['time'] ?? '');
                $dir = strtolower(trim((string) ($r['dir'] ?? '')));
                if ($dir !== '' || ($ptime && ! $in && ! $out)) {
                    $isIn = in_array($dir, ['in', 'i', '1', 'entry', 'checkin', 'check-in', 'c/in', 'check in'], true);
                    $isOut = in_array($dir, ['out', 'o', '0', 'exit', 'checkout', 'check-out', 'c/out', 'check out'], true);
                    if (! $ptime) {
                        $err = 'Missing punch time';
                    } elseif (! $isIn && ! $isOut) {
                        $err = 'Unknown direction "'.$dir.'" (use in / out)';
                    } else {
                        $in = $isIn ? $ptime : null;
                        $out = $isOut ? $ptime : null;
                    }
                }

                if (! $err) {
                    if (! $emp) {
                        $err = 'Employee not found (code/name)';
                    } elseif (! $date) {
                        $err = 'Invalid / missing date';
                    } elseif ($date > now()->toDateString()) {
                        $err = 'Future date not allowed';
                    } elseif (! $in && ! $out) {
                        $err = 'No In or Out time';
                    } elseif ($in && $out && $out <= $in) {
                        $err = 'Out time must be after In time';
                    }
                }
                $ins[] = [
                    'tenant_id' => $tid, 'batch' => $batch,
                    'emp_code' => $emp->emp_code ?? ($r['code'] ?? null),
                    'emp_name' => $emp->name ?? ($r['name'] ?? null),
                    'log_date' => $date, 'in_at' => $in, 'out_at' => $out,
                    'status' => $err ? 'error' : 'pending', 'error' => $err,
                    'uploaded_by' => $request->user()->name,
                    'created_at' => now(), 'updated_at' => now(),
                ];
                $err ? $bad++ : $ok++;
            }
            foreach (array_chunk($ins, 500) as $chunk) {
                DB::table('attendance_pending')->insert($chunk);
            }

            return response()->json(['ok' => true, 'batch' => $batch, 'total' => count($ins), 'valid' => $ok, 'errors' => $bad,
                'message' => $ok.' row(s) ready for approval'.($bad ? ', '.$bad.' row(s) flagged with errors (they will be skipped)' : '').'.']);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /** Pending batches + their rows (latest first). */
    public function index(Request $request)
    {
        if ($deny = $this->uploadGuard($request)) {
            return $deny;
        }
        try {
            $this->ensure();
            $tid = $request->user()->tenant_id;
            $isAdmin = $request->user()->hasAnyRole(['admin', 'super_admin']);

            $batches = DB::table('attendance_pending')
                ->when($tid, fn ($q) => $q->where('tenant_id', $tid))
                ->selectRaw("batch, MIN(created_at) as up_at, MAX(uploaded_by) as uploaded_by,
                    SUM(status = 'pending') as pending, SUM(status = 'error') as errors,
                    SUM(status = 'approved') as approved, SUM(status = 'rejected') as rejected, COUNT(*) as total")
                ->groupBy('batch')->orderByDesc('up_at')->limit(12)->get();

            $out = [];
            foreach ($batches as $b) {
                $rows = DB::table('attendance_pending')->where('batch', $b->batch)
                    ->orderBy('status')->orderBy('emp_code')->limit(400)
                    ->get(['id', 'emp_code', 'emp_name', 'log_date', 'in_at', 'out_at', 'status', 'error']);
                $out[] = [
                    'batch' => $b->batch,
                    'uploadedBy' => $b->uploaded_by,
                    'at' => Carbon::parse($b->up_at)->format('d M Y H:i'),
                    'pending' => (int) $b->pending, 'errors' => (int) $b->errors,
                    'approved' => (int) $b->approved, 'rejected' => (int) $b->rejected, 'total' => (int) $b->total,
                    'rows' => $rows->map(fn ($r) => [
                        'id' => $r->id, 'code' => $r->emp_code, 'name' => $r->emp_name,
                        'date' => $r->log_date, 'in' => $r->in_at, 'out' => $r->out_at,
                        'status' => $r->status, 'error' => $r->error,
                    ])->values(),
                ];
            }

            return response()->json(['ok' => true, 'batches' => $out, 'canApprove' => $isAdmin]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /** ADMIN approves (→ punches written) or rejects a batch's pending rows. */
    public function decide(Request $request, string $batch)
    {
        if ($deny = $this->approveGuard($request)) {
            return $deny;
        }
        try {
            $this->ensure();
            $tid = $request->user()->tenant_id;
            $v = $request->validate(['action' => ['required', 'in:approve,reject']]);
            $rows = DB::table('attendance_pending')->where('batch', $batch)
                ->when($tid, fn ($q) => $q->where('tenant_id', $tid))
                ->where('status', 'pending')->get();
            if ($rows->isEmpty()) {
                return response()->json(['ok' => false, 'error' => 'No pending rows in this batch.'], 422);
            }

            $written = 0;
            if ($v['action'] === 'approve') {
                $logs = [];
                foreach ($rows as $r) {
                    if ($r->in_at) {
                        $logs[] = ['tenant_id' => $r->tenant_id, 'emp_code' => $r->emp_code, 'emp_name' => $r->emp_name,
                            'log_date' => $r->log_date, 'punch_at' => $r->log_date.' '.$r->in_at.':00',
                            'direction' => 'in', 'source' => 'bulk', 'created_at' => now(), 'updated_at' => now()];
                    }
                    if ($r->out_at) {
                        $logs[] = ['tenant_id' => $r->tenant_id, 'emp_code' => $r->emp_code, 'emp_name' => $r->emp_name,
                            'log_date' => $r->log_date, 'punch_at' => $r->log_date.' '.$r->out_at.':00',
                            'direction' => 'out', 'source' => 'bulk', 'created_at' => now(), 'updated_at' => now()];
                    }
                }
                $logs = array_map(fn ($l) => ApprovalService::safeRow('attendance_logs', $l), $logs);
                foreach (array_chunk($logs, 500) as $chunk) {
                    DB::table('attendance_logs')->insert($chunk);
                }
                $written = count($logs);
                // F4 — immediate late-arrival email for the approved IN punches
                // (fail-soft, OFF unless enabled; only today's/yesterday's dates).
                $touched = [];
                foreach ($rows as $r) {
                    if ($r->in_at && $r->emp_code && $r->log_date) {
                        $touched[$r->emp_code][$r->log_date] = true;
                    }
                }
                LateArrivalService::notifyTouched($tid, $touched);
            }

            DB::table('attendance_pending')->where('batch', $batch)->where('status', 'pending')
                ->when($tid, fn ($q) => $q->where('tenant_id', $tid)) // rev172 (M4) — defence-in-depth: never touch another tenant's batch
                ->update([
                    'status' => $v['action'] === 'approve' ? 'approved' : 'rejected',
                    'decided_by' => $request->user()->name, 'decided_at' => now(), 'updated_at' => now(),
                ]);

            return response()->json(['ok' => true,
                'message' => $v['action'] === 'approve'
                    ? $rows->count().' row(s) approved — '.$written.' punch(es) written to attendance.'
                    : $rows->count().' row(s) rejected.']);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /** Remove one bad/unwanted row before approval. */
    public function deleteRow(Request $request, int $id)
    {
        if ($deny = $this->uploadGuard($request)) {
            return $deny;
        }
        try {
            $this->ensure();
            $tid = $request->user()->tenant_id;
            DB::table('attendance_pending')->where('id', $id)
                ->when($tid, fn ($q) => $q->where('tenant_id', $tid))
                ->whereIn('status', ['pending', 'error'])->delete();

            return response()->json(['ok' => true]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }
}
