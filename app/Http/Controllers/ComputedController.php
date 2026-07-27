<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Computed read-only screens (rev 47): Live Salary, Points leaderboard, Test
 * Reports, Attrition, Activity Logs. Derived live from existing data; returns
 * {label, columns, rows, note} the SPA renders as a table (statRptScreen).
 * Money is pre-formatted to strings here. Tenant-scoped, fail-soft.
 */
class ComputedController extends Controller
{
    public function report(Request $request, string $type)
    {
        if ($deny = ApprovalService::denyUnlessRole($request, ['admin', 'hr_manager'])) {
            return $deny;
        }
        try {
            $tid = $request->user()->tenant_id;
            $inr = fn ($n) => '₹'.number_format((float) $n);

            if ($type === 'live-salary') {
                $run = DB::table('payroll_runs')->when($tid, fn ($q) => $q->where('tenant_id', $tid))->orderByDesc('id')->first();
                $rows = [];
                if ($run) {
                    // line_status / calc_note are runtime-added by the salary
                    // APPROVAL flow — a fresh DB doesn't have them until the
                    // first line decision. Select only what exists (Ejaz hit
                    // SQLSTATE 42S22 here on the rebuilt demo DB, 4 Jun 2026).
                    $hasNote = Schema::hasColumn('payslips', 'calc_note');
                    $hasLineStatus = Schema::hasColumn('payslips', 'line_status');
                    $sel = ['e.emp_code', 'e.name', 'p.gross', 'p.total_ded', 'p.net'];
                    if ($hasLineStatus) {
                        $sel[] = 'p.line_status';
                    }
                    if ($hasNote) {
                        $sel[] = 'p.calc_note';
                    }
                    $rows = DB::table('payslips as p')->join('employees as e', 'e.id', '=', 'p.employee_id')
                        ->where('p.run_id', $run->id)->orderBy('e.emp_code')
                        ->get($sel)
                        ->map(fn ($r) => ['Code' => $r->emp_code, 'Name' => $r->name, 'Gross' => $inr($r->gross),
                            'Deductions' => $inr($r->total_ded), 'Net' => $inr($r->net),
                            'Status' => ucfirst(($hasLineStatus ? ($r->line_status ?? '') : '') ?: 'pending'),
                            '_note' => $hasNote ? ($r->calc_note ?? null) : null])->all();
                }

                return response()->json(['ok' => true, 'label' => 'Live salary — latest run '.($run->cycle_label ?? '—'),
                    'columns' => ['Code', 'Name', 'Gross', 'Deductions', 'Net', 'Status'], 'rows' => $rows,
                    'note' => $run ? 'Showing the most recent payroll run. Generate a new run in Payroll → Generate Payroll.' : 'No payroll run yet.']);
            }

            if ($type === 'points-scores') {
                $rows = [];
                if (Schema::hasTable('points_ledger')) {
                    $rows = DB::table('points_ledger as pl')->join('employees as e', 'e.id', '=', 'pl.employee_id')
                        ->when($tid, fn ($q) => $q->where('pl.tenant_id', $tid))
                        ->groupBy('e.id', 'e.emp_code', 'e.name')
                        ->selectRaw('e.emp_code, e.name, SUM(CASE WHEN pl.category = ? THEN pl.points ELSE 0 END) as pos, SUM(CASE WHEN pl.category = ? THEN pl.points ELSE 0 END) as neg, SUM(pl.points) as net', ['positive', 'negative'])
                        ->orderByDesc('net')->get()
                        ->map(fn ($r, $i) => ['Rank' => $i + 1, 'Code' => $r->emp_code, 'Name' => $r->name,
                            'Positive' => (int) $r->pos, 'Negative' => (int) $r->neg, 'Net Points' => (int) $r->net])->all();
                }

                return response()->json(['ok' => true, 'label' => 'Points leaderboard',
                    'columns' => ['Rank', 'Code', 'Name', 'Positive', 'Negative', 'Net Points'], 'rows' => $rows,
                    'note' => 'Ranked by net points from the Points Ledger.']);
            }

            if ($type === 'test-reports') {
                $rows = [];
                if (Schema::hasTable('test_attempts') && Schema::hasTable('tests')) {
                    $rows = DB::table('tests as t')->leftJoin('test_attempts as a', 'a.test_id', '=', 't.id')
                        ->when($tid, fn ($q) => $q->where('t.tenant_id', $tid))
                        ->groupBy('t.id', 't.name')
                        ->selectRaw('t.name, COUNT(a.id) as attempts, SUM(CASE WHEN a.status = ? THEN 1 ELSE 0 END) as passed, SUM(CASE WHEN a.status = ? THEN 1 ELSE 0 END) as failed', ['passed', 'failed'])
                        ->orderBy('t.name')->get()
                        ->map(function ($r) {
                            $att = (int) $r->attempts;
                            $rate = $att > 0 ? round(((int) $r->passed) * 100 / $att).'%' : '—';

                            return ['Test' => $r->name, 'Attempts' => $att, 'Passed' => (int) $r->passed, 'Failed' => (int) $r->failed, 'Pass Rate' => $rate];
                        })->all();
                }

                return response()->json(['ok' => true, 'label' => 'Test reports',
                    'columns' => ['Test', 'Attempts', 'Passed', 'Failed', 'Pass Rate'], 'rows' => $rows,
                    'note' => 'Attempts + pass-rate per test (from Test Results).']);
            }

            if ($type === 'attrition') {
                $rows = DB::table('employees')->when($tid, fn ($q) => $q->where('tenant_id', $tid))
                    ->whereNull('deleted_at')->whereIn('status', ['notice', 'exited'])
                    ->orderByDesc('updated_at')->limit(500)
                    ->get(['emp_code', 'name', 'status', 'doj'])
                    ->map(fn ($r) => ['Code' => $r->emp_code, 'Name' => $r->name, 'Status' => ucfirst($r->status),
                        'Joined' => $r->doj ? Carbon::parse($r->doj)->format('d M Y') : '—'])->all();
                $active = (int) DB::table('employees')->when($tid, fn ($q) => $q->where('tenant_id', $tid))->where('status', 'active')->whereNull('deleted_at')->count();

                return response()->json(['ok' => true, 'label' => 'Attrition — employees on notice / exited',
                    'columns' => ['Code', 'Name', 'Status', 'Joined'], 'rows' => $rows,
                    'note' => count($rows).' on notice/exited · '.$active.' active.']);
            }

            if ($type === 'activity-logs') {
                $rows = [];
                $note = 'Audit logging populates this as users create, edit, approve and export records.';
                if (Schema::hasTable('activity_logs')) {
                    $userMap = DB::table('users')->pluck('name', 'id');
                    $logs = DB::table('activity_logs')
                        ->when($tid, fn ($x) => $x->where('tenant_id', $tid))
                        ->orderByDesc('id')->limit(300)->get();
                    $rows = $logs->map(function ($r) use ($userMap) {
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

                        return [
                            'When' => ! empty($a['created_at']) ? Carbon::parse($a['created_at'])->format('d M Y H:i') : '',
                            'Action' => ucfirst(str_replace('_', ' ', (string) ($a['action'] ?? ''))),
                            'Subject' => trim(str_replace('_', ' ', (string) ($a['entity'] ?? '')).(! empty($a['entity_id']) ? ' #'.$a['entity_id'] : '')),
                            'Detail' => $detail,
                            'By' => $uid ? ($userMap[$uid] ?? ('User #'.$uid)) : 'System',
                            'IP' => $a['ip'] ?? '',
                        ];
                    })->all();
                    $v = \App\Services\Audit::verify();
                    if (! empty($v['ok'])) {
                        $note = 'Showing the most recent '.count($rows).' entries. Integrity: chain VERIFIED across '.$v['checked'].' signed records — no tampering detected. Export the full signed trail from Reports → Audit trail (signed).';
                    } elseif (! empty($v['broken_at'])) {
                        $note = 'WARNING: the audit chain is broken at record #'.$v['broken_at'].' — a historic row was altered or deleted. Investigate immediately.';
                    } else {
                        $note = 'Showing the most recent '.count($rows).' entries. Export the full trail from Reports → Audit trail (signed).';
                    }
                }

                return response()->json(['ok' => true, 'label' => 'Activity & audit logs',
                    'columns' => ['When', 'Action', 'Subject', 'Detail', 'By', 'IP'], 'rows' => $rows,
                    'note' => $note]);
            }

            return response()->json(['ok' => false, 'error' => 'Unknown report'], 422);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 200);
        }
    }
}
