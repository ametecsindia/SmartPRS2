<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reports (rev 39) — a simple, real export builder over existing data. Replaces
 * the prototype Reports screen. The user picks a dataset (+ optional month and
 * company), previews the first rows + a total count, and downloads a CSV.
 *
 * Read-only, tenant-scoped, admin/HR guarded, fail-soft JSON. Each dataset is a
 * [columns, rows] builder reused by BOTH preview() and export() so the CSV
 * always matches what was previewed.
 */
class ReportController extends Controller
{
    /** Datasets the report builder can produce. */
    public static function datasets(): array
    {
        return [
            'employees' => ['label' => 'Employees', 'month' => false],
            'payslips' => ['label' => 'Payslips', 'month' => true],
            'leaves' => ['label' => 'Leave records', 'month' => true],
            'attendance' => ['label' => 'Attendance punches', 'month' => true],
            'min-wage-check' => ['label' => 'Minimum-wage check (D1)', 'month' => false],
            'reg-wage' => ['label' => 'Wage register — Form B (D7)', 'month' => true],
            'reg-muster' => ['label' => 'Muster roll — Form D (D7)', 'month' => true],
            'reg-deductions' => ['label' => 'Deduction register — Form E (D7)', 'month' => true],
            'bank-advice' => ['label' => 'Bank advice / NEFT (D8)', 'month' => true],
            'fnf' => ['label' => 'Full & final settlement (D9)', 'month' => false],
            'reg-overtime' => ['label' => 'Overtime register (E3)', 'month' => true],
            'compliance-scorecard' => ['label' => 'Compliance scorecard (F1)', 'month' => false],
            'exemp-access' => ['label' => 'Ex-employee access audit (G4)', 'month' => false],
            'audit-trail' => ['label' => 'Audit trail — signed (J2)', 'month' => false],
        ];
    }

    private function normMonth(?string $month): ?string
    {
        if (! $month) {
            return null;
        }
        try {
            return Carbon::createFromFormat('Y-m', substr($month, 0, 7))->format('Y-m');
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Build [columns[], rows[]] for a dataset. $limit caps rows (null = all).
     * Returns ['columns'=>[], 'rows'=>[], 'count'=>int].
     */
    private function build(?int $tid, string $dataset, ?string $month, ?int $companyId, ?int $limit): array
    {
        $end = $month ? Carbon::createFromFormat('Y-m-d', $month.'-01')->endOfMonth()->toDateString() : null;

        if ($dataset === 'employees') {
            $cols = ['Code', 'Name', 'Company', 'Type', 'CTC', 'Status', 'Mobile', 'Email'];
            $q = DB::table('employees as e')
                ->leftJoin('companies as c', 'c.id', '=', 'e.company_id')
                ->when($tid, fn ($x) => $x->where('e.tenant_id', $tid))
                ->when($companyId, fn ($x) => $x->where('e.company_id', $companyId))
                ->whereNull('e.deleted_at')
                ->orderBy('e.emp_code');
            $count = (clone $q)->count();
            $rows = $q->when($limit, fn ($x) => $x->limit($limit))
                ->get(['e.emp_code', 'e.name', 'c.name as company', 'e.type', 'e.ctc', 'e.status', 'e.mobile', 'e.email'])
                ->map(fn ($r) => ['Code' => $r->emp_code, 'Name' => $r->name, 'Company' => $r->company, 'Type' => $r->type,
                    'CTC' => (float) $r->ctc, 'Status' => $r->status, 'Mobile' => $r->mobile, 'Email' => $r->email])->all();

            return ['columns' => $cols, 'rows' => $rows, 'count' => $count];
        }

        if ($dataset === 'payslips') {
            $cols = ['Month', 'Code', 'Name', 'Company', 'Gross', 'Deductions', 'Net'];
            $q = DB::table('payslips as p')
                ->join('employees as e', 'e.id', '=', 'p.employee_id')
                ->leftJoin('companies as c', 'c.id', '=', 'p.company_id')
                ->when($tid, fn ($x) => $x->where('p.tenant_id', $tid))
                ->when($companyId, fn ($x) => $x->where('p.company_id', $companyId))
                ->when($month, fn ($x) => $x->where('p.month', $month))
                ->orderByDesc('p.month')->orderBy('e.emp_code');
            $count = (clone $q)->count();
            $rows = $q->when($limit, fn ($x) => $x->limit($limit))
                ->get(['p.month', 'e.emp_code', 'e.name', 'c.name as company', 'p.gross', 'p.total_ded', 'p.net'])
                ->map(fn ($r) => ['Month' => $r->month, 'Code' => $r->emp_code, 'Name' => $r->name, 'Company' => $r->company,
                    'Gross' => (float) $r->gross, 'Deductions' => (float) $r->total_ded, 'Net' => (float) $r->net])->all();

            return ['columns' => $cols, 'rows' => $rows, 'count' => $count];
        }

        if ($dataset === 'leaves') {
            $cols = ['Code', 'Name', 'Type', 'From', 'To', 'Days', 'Status'];
            $q = DB::table('leaves as l')
                ->join('employees as e', 'e.id', '=', 'l.employee_id')
                ->leftJoin('leave_types as t', 't.id', '=', 'l.type_id')
                ->when($tid, fn ($x) => $x->where('l.tenant_id', $tid))
                ->when($companyId, fn ($x) => $x->where('l.company_id', $companyId))
                ->when($month, fn ($x) => $x->where('l.from_date', '<=', $end)->where('l.to_date', '>=', $month.'-01'))
                ->orderByDesc('l.from_date');
            $count = (clone $q)->count();
            $rows = $q->when($limit, fn ($x) => $x->limit($limit))
                ->get(['e.emp_code', 'e.name', 't.name as type', 'l.from_date', 'l.to_date', 'l.days', 'l.status'])
                ->map(fn ($r) => ['Code' => $r->emp_code, 'Name' => $r->name, 'Type' => $r->type ?: '—',
                    'From' => $r->from_date, 'To' => $r->to_date, 'Days' => (float) $r->days, 'Status' => $r->status])->all();

            return ['columns' => $cols, 'rows' => $rows, 'count' => $count];
        }

        if ($dataset === 'attendance') {
            $cols = ['Code', 'Name', 'Date', 'Time', 'Direction'];
            if (! Schema::hasTable('attendance_logs')) {
                return ['columns' => $cols, 'rows' => [], 'count' => 0];
            }
            $q = DB::table('attendance_logs')
                ->when($tid, fn ($x) => $x->where('tenant_id', $tid))
                ->when($companyId, fn ($x) => $x->where('company_id', $companyId))
                ->when($month, fn ($x) => $x->whereBetween('log_date', [$month.'-01', $end]))
                ->orderByDesc('log_date')->orderByDesc('punch_at');
            $count = (clone $q)->count();
            $rows = $q->when($limit, fn ($x) => $x->limit($limit))
                ->get(['emp_code', 'emp_name', 'log_date', 'punch_at', 'direction'])
                ->map(fn ($r) => ['Code' => $r->emp_code, 'Name' => $r->emp_name, 'Date' => $r->log_date,
                    'Time' => $r->punch_at ? Carbon::parse($r->punch_at)->format('H:i:s') : '', 'Direction' => $r->direction])->all();

            return ['columns' => $cols, 'rows' => $rows, 'count' => $count];
        }

        // D1 — minimum-wage check: monthly gross (CTC/12) vs the configured minimum.
        if ($dataset === 'min-wage-check') {
            $cols = ['Code', 'Name', 'Company', 'Monthly Gross', 'Min Wage', 'Shortfall', 'Status'];
            $min = 0.0;
            if (Schema::hasTable('min_wages')) {
                $min = (float) DB::table('min_wages')->when($tid, fn ($x) => $x->where('tenant_id', $tid))->max('monthly_min');
            }
            $q = DB::table('employees as e')->leftJoin('companies as c', 'c.id', '=', 'e.company_id')
                ->when($tid, fn ($x) => $x->where('e.tenant_id', $tid))
                ->when($companyId, fn ($x) => $x->where('e.company_id', $companyId))
                ->whereNull('e.deleted_at')->orderBy('e.emp_code');
            $count = (clone $q)->count();
            $rows = $q->when($limit, fn ($x) => $x->limit($limit))->get(['e.emp_code', 'e.name', 'c.name as company', 'e.ctc'])
                ->map(function ($r) use ($min) {
                    $g = round(((float) $r->ctc) / 12, 2);
                    $short = $min > 0 ? round(max(0, $min - $g), 2) : 0;

                    return ['Code' => $r->emp_code, 'Name' => $r->name, 'Company' => $r->company, 'Monthly Gross' => $g,
                        'Min Wage' => $min, 'Shortfall' => $short, 'Status' => ($min <= 0 ? 'No min set' : ($g >= $min ? 'OK' : 'BELOW MIN'))];
                })->all();

            return ['columns' => $cols, 'rows' => $rows, 'count' => $count];
        }

        // D7 — Wage register (Form B): per-payslip earnings/deductions for the month.
        if ($dataset === 'reg-wage') {
            $cols = ['Month', 'Code', 'Name', 'Gross', 'PF', 'ESI', 'PT', 'TDS', 'Total Deductions', 'Net'];
            $hasDed = Schema::hasColumn('payslips', 'deductions');
            $q = DB::table('payslips as p')->join('employees as e', 'e.id', '=', 'p.employee_id')
                ->when($tid, fn ($x) => $x->where('p.tenant_id', $tid))
                ->when($companyId, fn ($x) => $x->where('p.company_id', $companyId))
                ->when($month, fn ($x) => $x->where('p.month', $month))->orderBy('e.emp_code');
            $count = (clone $q)->count();
            $sel = ['p.month', 'e.emp_code', 'e.name', 'p.gross', 'p.total_ded', 'p.net'];
            if ($hasDed) {
                $sel[] = 'p.deductions';
            }
            $rows = $q->when($limit, fn ($x) => $x->limit($limit))->get($sel)->map(function ($r) use ($hasDed) {
                $d = ['PF' => '', 'ESI' => '', 'PT' => '', 'TDS' => ''];
                if ($hasDed && ! empty($r->deductions)) {
                    $j = json_decode($r->deductions, true);
                    if (is_array($j)) {
                        foreach ($j as $k => $v) {
                            $kl = strtolower((string) $k);
                            if (str_contains($kl, 'provident') || str_contains($kl, 'pf')) {
                                $d['PF'] = $v;
                            } elseif (str_contains($kl, 'esi')) {
                                $d['ESI'] = $v;
                            } elseif (str_contains($kl, 'professional') || $kl === 'pt') {
                                $d['PT'] = $v;
                            } elseif (str_contains($kl, 'tds')) {
                                $d['TDS'] = $v;
                            }
                        }
                    }
                }

                return ['Month' => $r->month, 'Code' => $r->emp_code, 'Name' => $r->name, 'Gross' => (float) $r->gross,
                    'PF' => $d['PF'], 'ESI' => $d['ESI'], 'PT' => $d['PT'], 'TDS' => $d['TDS'],
                    'Total Deductions' => (float) $r->total_ded, 'Net' => (float) $r->net];
            })->all();

            return ['columns' => $cols, 'rows' => $rows, 'count' => $count];
        }

        // D7 — Muster roll (Form D): present-day count per agent for the month.
        if ($dataset === 'reg-muster') {
            $cols = ['Code', 'Name', 'Present Days'];
            if (! Schema::hasTable('attendance_logs')) {
                return ['columns' => $cols, 'rows' => [], 'count' => 0];
            }
            $q = DB::table('attendance_logs as a')
                ->when($tid, fn ($x) => $x->where('a.tenant_id', $tid))
                ->when($companyId, fn ($x) => $x->where('a.company_id', $companyId))
                ->when($month, fn ($x) => $x->whereBetween('a.log_date', [$month.'-01', $end]));
            $rows = (clone $q)->select('a.emp_code', 'a.emp_name', DB::raw('COUNT(DISTINCT a.log_date) as present_days'))
                ->groupBy('a.emp_code', 'a.emp_name')->orderBy('a.emp_code')->get()
                ->map(fn ($r) => ['Code' => $r->emp_code, 'Name' => $r->emp_name, 'Present Days' => (int) $r->present_days])->all();

            return ['columns' => $cols, 'rows' => $rows, 'count' => count($rows)];
        }

        // D7 — Deduction register (Form E): fines / recoveries / statutory deductions.
        if ($dataset === 'reg-deductions') {
            $cols = ['Code', 'Name', 'Type', 'Amount', 'Status'];
            if (! Schema::hasTable('deductions')) {
                return ['columns' => $cols, 'rows' => [], 'count' => 0];
            }
            $q = DB::table('deductions as d')->leftJoin('employees as e', 'e.id', '=', 'd.employee_id')
                ->when($tid, fn ($x) => $x->where('d.tenant_id', $tid))
                ->when($companyId, fn ($x) => $x->where('d.company_id', $companyId));
            if ($month && Schema::hasColumn('deductions', 'cycle_month')) {
                $q->where('d.cycle_month', $month);
            } elseif ($month && Schema::hasColumn('deductions', 'created_at')) {
                $q->whereBetween('d.created_at', [$month.'-01 00:00:00', $end.' 23:59:59']);
            }
            $count = (clone $q)->count();
            $rows = $q->when($limit, fn ($x) => $x->limit($limit))->orderByDesc('d.id')
                ->get(['e.emp_code', 'e.name', 'd.type', 'd.amount', 'd.status'])
                ->map(fn ($r) => ['Code' => $r->emp_code, 'Name' => $r->name, 'Type' => $r->type, 'Amount' => (float) $r->amount, 'Status' => $r->status])->all();

            return ['columns' => $cols, 'rows' => $rows, 'count' => $count];
        }

        // D8 — Bank advice / NEFT: net payable per agent with bank account + IFSC.
        if ($dataset === 'bank-advice') {
            $cols = ['Code', 'Name', 'Bank A/C', 'IFSC', 'Net Amount', 'Mode'];
            $q = DB::table('payslips as p')->join('employees as e', 'e.id', '=', 'p.employee_id')
                ->when($tid, fn ($x) => $x->where('p.tenant_id', $tid))
                ->when($companyId, fn ($x) => $x->where('p.company_id', $companyId))
                ->when($month, fn ($x) => $x->where('p.month', $month))->orderBy('e.emp_code');
            $count = (clone $q)->count();
            $rows = $q->when($limit, fn ($x) => $x->limit($limit))->get(['e.emp_code', 'e.name', 'e.bank_acc', 'e.ifsc', 'p.net'])
                ->map(fn ($r) => ['Code' => $r->emp_code, 'Name' => $r->name, 'Bank A/C' => $r->bank_acc, 'IFSC' => $r->ifsc, 'Net Amount' => (float) $r->net, 'Mode' => 'NEFT'])->all();

            return ['columns' => $cols, 'rows' => $rows, 'count' => $count];
        }

        // D9 — Full & final settlement: prorated final salary + gratuity − advances − dues.
        if ($dataset === 'fnf') {
            $cols = ['Code', 'Name', 'Last Day', 'Final Month Salary', 'Gratuity', 'Advances Recovered', 'Dues', 'FnF Payable'];
            if (! Schema::hasTable('exits')) {
                return ['columns' => $cols, 'rows' => [], 'count' => 0];
            }
            $q = DB::table('exits as x')->join('employees as e', 'e.id', '=', 'x.employee_id')
                ->when($tid, fn ($z) => $z->where('x.tenant_id', $tid))
                ->when($companyId, fn ($z) => $z->where('x.company_id', $companyId))->orderByDesc('x.id');
            $count = (clone $q)->count();
            $rows = $q->when($limit, fn ($z) => $z->limit($limit))
                ->get(['e.emp_code', 'e.name', 'e.ctc', 'e.doj', 'x.last_day', 'x.advances_recovered', 'x.dues'])
                ->map(function ($r) {
                    $monthly = round(((float) $r->ctc) / 12, 2);
                    $basic = round($monthly * 0.5, 2);
                    $grat = 0.0;
                    if ($r->doj && $r->last_day) {
                        try {
                            $yrs = Carbon::parse($r->doj)->diffInYears(Carbon::parse($r->last_day));
                            if ($yrs >= 5) {
                                $grat = round(15 / 26 * $basic * $yrs, 2);
                            }
                        } catch (\Throwable $e) {
                        }
                    }
                    $adv = (float) ($r->advances_recovered ?? 0);
                    $dues = (float) ($r->dues ?? 0);

                    return ['Code' => $r->emp_code, 'Name' => $r->name, 'Last Day' => $r->last_day,
                        'Final Month Salary' => $monthly, 'Gratuity' => $grat, 'Advances Recovered' => $adv,
                        'Dues' => $dues, 'FnF Payable' => round($monthly + $grat - $adv - $dues, 2)];
                })->all();

            return ['columns' => $cols, 'rows' => $rows, 'count' => $count];
        }


        // E3 — Overtime register: OT entries logged for the month.
        if ($dataset === 'reg-overtime') {
            $cols = ['Code', 'Name', 'OT Date', 'Hours', 'Multiplier', 'Amount', 'Status'];
            if (! Schema::hasTable('overtime')) {
                return ['columns' => $cols, 'rows' => [], 'count' => 0];
            }
            $q = DB::table('overtime as o')->leftJoin('employees as e', 'e.id', '=', 'o.employee_id')
                ->when($tid, fn ($x) => $x->where('o.tenant_id', $tid))
                ->when($companyId, fn ($x) => $x->where('o.company_id', $companyId))
                ->when($month, fn ($x) => $x->whereBetween('o.ot_date', [$month.'-01', $end]))
                ->orderByDesc('o.ot_date');
            $count = (clone $q)->count();
            $rows = $q->when($limit, fn ($x) => $x->limit($limit))
                ->get(['e.emp_code', 'e.name', 'o.ot_date', 'o.hours', 'o.multiplier', 'o.amount', 'o.status'])
                ->map(fn ($r) => ['Code' => $r->emp_code, 'Name' => $r->name, 'OT Date' => $r->ot_date,
                    'Hours' => (float) $r->hours, 'Multiplier' => $r->multiplier, 'Amount' => (float) $r->amount, 'Status' => $r->status])->all();

            return ['columns' => $cols, 'rows' => $rows, 'count' => $count];
        }

        // F1 — compliance scorecard: per-agent compliance score + incentive eligibility.
        if ($dataset === 'compliance-scorecard') {
            $cols = ['Code', 'Name', 'Company', 'Compliance Score', 'Incentive Eligible'];
            $min = 60;
            try {
                $min = (int) (\App\Http\Controllers\SettingsController::rates($tid)['incentive_min_compliance'] ?? 60);
            } catch (\Throwable $e) {
            }
            $q = DB::table('employees as e')->leftJoin('companies as c', 'c.id', '=', 'e.company_id')
                ->when($tid, fn ($x) => $x->where('e.tenant_id', $tid))
                ->when($companyId, fn ($x) => $x->where('e.company_id', $companyId))
                ->whereNull('e.deleted_at')->orderBy('e.emp_code');
            $count = (clone $q)->count();
            $rows = $q->when($limit, fn ($x) => $x->limit($limit))->get(['e.id', 'e.emp_code', 'e.name', 'c.name as company'])
                ->map(function ($r) use ($min) {
                    $sc = \App\Http\Controllers\ComplianceController::scoreFor((int) $r->id, null);

                    return ['Code' => $r->emp_code, 'Name' => $r->name, 'Company' => $r->company,
                        'Compliance Score' => $sc, 'Incentive Eligible' => ($sc >= $min ? 'Yes' : 'No')];
                })->all();

            return ['columns' => $cols, 'rows' => $rows, 'count' => $count];
        }

        // G4 — ex-employee access audit: exited agents whose email still matches a login.
        if ($dataset === 'exemp-access') {
            $cols = ['Code', 'Name', 'Email', 'Employee Status', 'Login Status', 'Risk'];
            if (! Schema::hasTable('users')) {
                return ['columns' => $cols, 'rows' => [], 'count' => 0];
            }
            $hasUserStatus = Schema::hasColumn('users', 'status');
            $q = DB::table('employees as e')->leftJoin('users as u', 'u.email', '=', 'e.email')
                ->when($tid, fn ($x) => $x->where('e.tenant_id', $tid))
                ->when($companyId, fn ($x) => $x->where('e.company_id', $companyId))
                ->where(fn ($x) => $x->where('e.status', 'exited')->orWhereNotNull('e.deleted_at'))
                ->orderBy('e.emp_code');
            $count = (clone $q)->count();
            $sel = ['e.emp_code', 'e.name', 'e.email', 'e.status as emp_status', 'u.id as uid'];
            if ($hasUserStatus) {
                $sel[] = 'u.status as user_status';
            }
            $rows = $q->when($limit, fn ($x) => $x->limit($limit))->get($sel)
                ->map(function ($r) use ($hasUserStatus) {
                    $login = $r->uid ? ($hasUserStatus ? ($r->user_status ?? 'active') : 'active') : 'no login';
                    $active = $r->uid && ! in_array($login, ['inactive', 'disabled', 'suspended'], true);

                    return ['Code' => $r->emp_code, 'Name' => $r->name, 'Email' => $r->email,
                        'Employee Status' => $r->emp_status, 'Login Status' => $login,
                        'Risk' => $active ? 'ACTIVE LOGIN — revoke' : 'OK'];
                })->all();

            return ['columns' => $cols, 'rows' => $rows, 'count' => $count];
        }

        if ($dataset === 'audit-trail') {
            // J2 — the immutable, hash-chained activity log as a verifiable export.
            // Each row carries its SHA-256 row hash; Integrity = result of replaying
            // the chain. Ordered chronologically so the chain reads top-to-bottom.
            $cols = ['#', 'When', 'Action', 'Subject', 'Detail', 'By', 'IP', 'Integrity', 'RowHash'];
            if (! Schema::hasTable('activity_logs')) {
                return ['columns' => $cols, 'rows' => [], 'count' => 0];
            }
            $hasChain = Schema::hasColumn('activity_logs', 'row_hash');
            $verdict = $hasChain ? \App\Services\Audit::verify() : ['ok' => false, 'broken_at' => 0];
            $userMap = DB::table('users')->pluck('name', 'id');
            $q = DB::table('activity_logs')
                ->when($tid, fn ($x) => $x->where('tenant_id', $tid))
                ->orderBy('id');
            $count = (clone $q)->count();
            $rows = $q->when($limit, fn ($x) => $x->limit($limit))->get()
                ->map(function ($r) use ($userMap, $hasChain, $verdict) {
                    $a = (array) $r;
                    $detail = '';
                    if (! empty($a['detail'])) {
                        $d = json_decode($a['detail'], true);
                        if (is_array($d)) {
                            if (isset($d['note'])) {
                                $detail = $d['note'];
                            } elseif (isset($d['fields']) && is_array($d['fields'])) {
                                $detail = 'fields: '.implode(', ', $d['fields']);
                            } else {
                                $detail = json_encode($d);
                            }
                        } else {
                            $detail = (string) $a['detail'];
                        }
                    }
                    $uid = $a['user_id'] ?? null;
                    $rid = (int) ($a['id'] ?? 0);
                    $intact = $hasChain && (empty($verdict['broken_at']) || $rid < $verdict['broken_at']);

                    return [
                        '#' => $rid,
                        'When' => ! empty($a['created_at']) ? Carbon::parse($a['created_at'])->format('d M Y H:i:s') : '',
                        'Action' => ucfirst(str_replace('_', ' ', (string) ($a['action'] ?? ''))),
                        'Subject' => trim(str_replace('_', ' ', (string) ($a['entity'] ?? '')).(! empty($a['entity_id']) ? ' #'.$a['entity_id'] : '')),
                        'Detail' => $detail,
                        'By' => $uid ? ($userMap[$uid] ?? ('User #'.$uid)) : 'System',
                        'IP' => $a['ip'] ?? '',
                        'Integrity' => ! $hasChain ? 'unsigned' : ($intact ? 'verified' : 'BROKEN'),
                        'RowHash' => $hasChain ? substr((string) ($a['row_hash'] ?? ''), 0, 16) : '',
                    ];
                })->all();

            return ['columns' => $cols, 'rows' => $rows, 'count' => $count];
        }

        return ['columns' => [], 'rows' => [], 'count' => 0];
    }

    /** PREVIEW — dataset list + first rows + total count. */
    public function preview(Request $request)
    {
        if ($deny = ApprovalService::denyUnlessRole($request, ['admin', 'hr_manager'])) {
            return $deny;
        }
        try {
            $tid = $request->user()->tenant_id;
            $defs = self::datasets();
            $dataset = (string) $request->query('dataset', 'employees');
            if (! isset($defs[$dataset])) {
                $dataset = 'employees';
            }
            $month = $defs[$dataset]['month'] ? $this->normMonth($request->query('month')) : null;
            $companyId = $request->query('company_id') ? (int) $request->query('company_id') : null;

            $built = $this->build($tid, $dataset, $month, $companyId, 100);

            return response()->json([
                'ok' => true,
                'datasets' => collect($defs)->map(fn ($d, $k) => ['key' => $k, 'label' => $d['label'], 'month' => $d['month']])->values(),
                'dataset' => $dataset,
                'label' => $defs[$dataset]['label'],
                'usesMonth' => $defs[$dataset]['month'],
                'month' => $month,
                'columns' => $built['columns'],
                'rows' => $built['rows'],
                'count' => $built['count'],
                'shown' => count($built['rows']),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 200);
        }
    }

    /** EXPORT — stream the full dataset as a CSV download. */
    public function export(Request $request)
    {
        if ($deny = ApprovalService::denyUnlessRole($request, ['admin', 'hr_manager'])) {
            return $deny;
        }
        try {
            $tid = $request->user()->tenant_id;
            $defs = self::datasets();
            $dataset = (string) $request->query('dataset', 'employees');
            if (! isset($defs[$dataset])) {
                $dataset = 'employees';
            }
            $month = $defs[$dataset]['month'] ? $this->normMonth($request->query('month')) : null;
            $companyId = $request->query('company_id') ? (int) $request->query('company_id') : null;

            $built = $this->build($tid, $dataset, $month, $companyId, null);

            $out = $this->csvLine($built['columns']);
            foreach ($built['rows'] as $row) {
                $line = [];
                foreach ($built['columns'] as $c) {
                    $line[] = $row[$c] ?? '';
                }
                $out .= $this->csvLine($line);
            }

            // G3 — access/export logging: record who exported which dataset.
            try {
                if (Schema::hasTable('activity_logs')) {
                    DB::table('activity_logs')->insert([
                        'tenant_id' => $tid, 'user_id' => optional($request->user())->id,
                        'action' => 'report_export', 'entity' => 'reports', 'entity_id' => 0,
                        'detail' => json_encode(['dataset' => $dataset, 'month' => $month, 'rows' => count($built['rows'])]),
                        'ip' => $request->ip(), 'created_at' => now(),
                    ]);
                }
            } catch (\Throwable $e) {
                // export logging is best-effort
            }

            $fname = 'smartprs-'.$dataset.($month ? '-'.$month : '').'-'.now()->format('Ymd').'.csv';

            return response($out, 200, [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="'.$fname.'"',
            ]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 200);
        }
    }

    /** One RFC-4180-ish CSV line from a list of values. */
    private function csvLine(array $vals): string
    {
        $cells = array_map(function ($v) {
            $v = (string) $v;
            if (preg_match('/[",\r\n]/', $v)) {
                $v = '"'.str_replace('"', '""', $v).'"';
            }

            return $v;
        }, $vals);

        return implode(',', $cells)."\r\n";
    }
}
