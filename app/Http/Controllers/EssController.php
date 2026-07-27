<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Employee Self-Service (ESS): one endpoint that returns the logged-in
 * employee's own snapshot — profile, recent payslips (with PDF links), this
 * month's attendance, recent leave, and active notices. Rendered on the
 * Account screen so every employee has a personal "My Space".
 */
class EssController extends Controller
{
    public function me(Request $request)
    {
        try {
            $user = $request->user();
            $tid = $user->tenant_id;
            $emp = $this->currentEmployee($request);
            if (! $emp) {
                return response()->json(['ok' => true, 'linked' => false]);
            }

            return response()->json([
                'ok' => true,
                'linked' => true,
                'profile' => $this->profile($emp),
                'payslips' => $this->payslips($emp),
                'attendance' => $this->attendance($emp, $tid),
                'leaves' => $this->leaves($emp, $tid),
                'balances' => $this->leaveBalances($emp, $tid),
                'notices' => $this->notices($tid),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()]);
        }
    }

    private function currentEmployee(Request $request)
    {
        $user = $request->user();
        $tid = $user->tenant_id;
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

    private function nameFrom(string $table, $id): string
    {
        if (! $id || ! Schema::hasTable($table)) {
            return '';
        }

        return (string) (DB::table($table)->where('id', $id)->value('name') ?: '');
    }

    private function profile($e): array
    {
        $a = (array) $e;
        $designation = $a['designation'] ?? '';
        if (! $designation && ! empty($a['designation_id'])) {
            $designation = $this->nameFrom('designations', $a['designation_id']);
        }
        $department = $a['department'] ?? '';
        if (! $department && ! empty($a['department_id'])) {
            $department = $this->nameFrom('departments', $a['department_id']);
        }

        return [
            'name' => $a['name'] ?? '',
            'emp_code' => $a['emp_code'] ?? '',
            'designation' => $designation,
            'department' => $department,
            'type' => $a['type'] ?? '',
            'email' => $a['email'] ?? '',
            'mobile' => $a['mobile'] ?? '',
            'whatsapp' => $a['whatsapp'] ?? '',
            'address' => $a['address'] ?? '',
            'father' => $a['father'] ?? '',
            'spouse' => $a['spouse'] ?? '',
            'blood_group' => $a['blood_group'] ?? '',
            'id_marks' => $a['id_marks'] ?? '',
            'gender' => $a['gender'] ?? '',
            'dob' => $a['dob'] ?? '',
            'joined' => $a['doj'] ?? ($a['joined_on'] ?? null),
            'photo' => ! empty($a['photo_path']) ? url('/app/emp-photo/'.($a['emp_code'] ?? '')) : '',
        ];
    }

    private function payslips($e): array
    {
        if (! Schema::hasTable('payslips')) {
            return [];
        }
        $code = $e->emp_code ?? '';
        // rev172 — company payslip-download policy: when the employee is blocked,
        // no PDF link is offered (HR can still download/email their slip).
        $canDl = SettingsController::payslipSelfAllowed($e);

        return DB::table('payslips')->where('employee_id', $e->id)
            ->orderByDesc('month')->orderByDesc('id')->limit(6)
            ->get(['month', 'gross', 'net'])
            ->map(fn ($p) => [
                'month' => $p->month,
                'label' => $p->month ? Carbon::parse($p->month.'-01')->format('M Y') : '',
                'gross' => (float) $p->gross,
                'net' => (float) $p->net,
                'pdf' => ($code && $canDl) ? url('/app/payslip/'.$code.'/pdf?month='.$p->month) : null,
            ])->all();
    }

    private function attendance($e, $tid): array
    {
        $out = ['present' => 0, 'last_punch' => null, 'month' => now()->format('M Y')];
        if (! Schema::hasTable('attendance_logs')) {
            return $out;
        }
        $month = now()->format('Y-m');
        $end = now()->endOfMonth()->toDateString();
        $code = $e->emp_code ?? '';
        $out['present'] = (int) DB::table('attendance_logs')->when($tid, fn ($q) => $q->where('tenant_id', $tid))->where('emp_code', $code)
            ->whereBetween('log_date', [$month.'-01', $end])->distinct()->count('log_date'); // rev172 — tenant-scoped (emp codes repeat across tenants)
        $last = DB::table('attendance_logs')->when($tid, fn ($q) => $q->where('tenant_id', $tid))->where('emp_code', $code)->orderByDesc('punch_at')->value('punch_at');
        $out['last_punch'] = $last ? Carbon::parse($last)->format('d M, H:i') : null;

        return $out;
    }

    private function leaves($e, $tid): array
    {
        if (! Schema::hasTable('leaves')) {
            return [];
        }
        $types = Schema::hasTable('leave_types') ? DB::table('leave_types')->pluck('name', 'id')->all() : [];

        return DB::table('leaves')->where('employee_id', $e->id)
            ->orderByDesc('id')->limit(6)->get()
            ->map(function ($l) use ($types) {
                $a = (array) $l;

                return [
                    'type' => $types[$a['type_id'] ?? null] ?? ($a['type'] ?? 'Leave'),
                    'from' => isset($a['from_date']) ? substr((string) $a['from_date'], 0, 10) : '',
                    'to' => isset($a['to_date']) ? substr((string) $a['to_date'], 0, 10) : '',
                    'status' => $a['status'] ?? 'pending',
                ];
            })->all();
    }

    private function notices($tid): array
    {
        if (! Schema::hasTable('notices')) {
            return [];
        }
        try {
            return DB::table('notices')
                ->when($tid && Schema::hasColumn('notices', 'tenant_id'), fn ($q) => $q->where('tenant_id', $tid))
                ->when(Schema::hasColumn('notices', 'deleted_at'), fn ($q) => $q->whereNull('deleted_at'))
                ->where(function ($q) {
                    $q->whereNull('status')->orWhereRaw("LOWER(status) NOT IN ('inactive', 'archived', 'draft', 'expired')");
                })
                ->orderByDesc('posted_on')->orderByDesc('id')->limit(5)
                ->get(['title', 'body', 'posted_on'])
                ->map(fn ($n) => ['title' => $n->title, 'body' => $n->body, 'date' => $n->posted_on])->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * rev162 — the employee's OWN leave balances for the current year, per type:
     * allocated (entitlement) / used (approved) / pending / remaining. Mirrors
     * LeaveController::computeBalances so the ESS figures match the leave module.
     */
    private function leaveBalances($emp, $tid): array
    {
        $year = now()->year;
        $types = Schema::hasTable('leave_types')
            ? DB::table('leave_types')->when($tid, fn ($q) => $q->where('tenant_id', $tid))->get()
            : collect();
        if ($types->isEmpty()) {
            $types = collect([
                (object) ['name' => 'Casual Leave', 'days_per_year' => 12, 'paid' => 1],
                (object) ['name' => 'Sick Leave', 'days_per_year' => 12, 'paid' => 1],
                (object) ['name' => 'Earned Leave', 'days_per_year' => 15, 'paid' => 1],
                (object) ['name' => 'Loss of Pay', 'days_per_year' => 0, 'paid' => 0],
            ]);
        }
        $taken = [];
        if ($emp && Schema::hasTable('leaves')) {
            try {
                $rows = DB::table('leaves')->where('employee_id', $emp->id)
                    ->whereYear('from_date', $year)->get(['type_name', 'days', 'status']);
                foreach ($rows as $r) {
                    $t = $r->type_name ?: 'Leave';
                    $taken[$t] = $taken[$t] ?? ['used' => 0.0, 'pending' => 0.0];
                    if ($r->status === 'approved') {
                        $taken[$t]['used'] += (float) $r->days;
                    } elseif ($r->status === 'pending') {
                        $taken[$t]['pending'] += (float) $r->days;
                    }
                }
            } catch (\Throwable $e) {
            }
        }
        $out = [];
        foreach ($types as $t) {
            $paid = (int) ($t->paid ?? 1) === 1;
            $ent = (float) ($t->days_per_year ?? 0);
            $u = $taken[$t->name]['used'] ?? 0.0;
            $p = $taken[$t->name]['pending'] ?? 0.0;
            $out[] = [
                'type' => $t->name,
                'allocated' => $ent,
                'used' => $u,
                'pending' => $p,
                'remaining' => $paid ? round($ent - $u, 1) : null,
                'paid' => $paid,
            ];
        }

        return $out;
    }

    /** Make sure the self-editable personal columns exist (self-creating convention). */
    private function ensureProfileCols(): void
    {
        if (! Schema::hasTable('employees')) {
            return;
        }
        foreach (['whatsapp', 'address', 'father', 'spouse', 'blood_group', 'id_marks', 'gender', 'dob'] as $c) {
            if (! Schema::hasColumn('employees', $c)) {
                try {
                    Schema::table('employees', fn ($t) => $t->string($c)->nullable());
                } catch (\Throwable $e) {
                }
            }
        }
    }

    /**
     * rev162 — POST /app/ess/update — employee self-edit of their OWN personal
     * details. Only contact + personal fields are editable; org-controlled fields
     * (emp_code, salary/CTC, designation, department, company) are never touched.
     */
    public function updateProfile(Request $request)
    {
        try {
            $emp = $this->currentEmployee($request);
            if (! $emp) {
                return response()->json(['ok' => false, 'error' => 'Your login is not linked to an employee record. Ask HR to link it.'], 422);
            }
            $v = $request->validate([
                'mobile' => ['nullable', 'string', 'max:20'],
                'whatsapp' => ['nullable', 'string', 'max:20'],
                'email' => ['nullable', 'email', 'max:160'],
                'address' => ['nullable', 'string', 'max:400'],
                'father' => ['nullable', 'string', 'max:120'],
                'spouse' => ['nullable', 'string', 'max:120'],
                'blood_group' => ['nullable', 'string', 'max:8'],
                'id_marks' => ['nullable', 'string', 'max:200'],
                'gender' => ['nullable', 'string', 'max:20'],
                'dob' => ['nullable', 'string', 'max:20'],
            ]);
            $this->ensureProfileCols();
            $upd = [];
            foreach ($v as $f => $val) {
                if ($request->has($f) && Schema::hasColumn('employees', $f)) {
                    $upd[$f] = $val;
                }
            }
            if ($upd) {
                $upd['updated_at'] = now();
                DB::table('employees')->where('id', $emp->id)->update($upd);
            }

            return response()->json(['ok' => true]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }
}
