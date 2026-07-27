<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DashboardController extends Controller
{
    public function index()
    {
        // Tenant-scoped automatically via the Employee global scope.
        $employees = Employee::query()->get();

        $stats = [
            'total' => $employees->count(),
            'active' => $employees->where('status', 'active')->count(),
            'gross' => $employees->sum('gross_salary'),
            'field' => $employees->where('employment_type', 'field')->count(),
            'permanent' => $employees->where('employment_type', 'permanent')->count(),
            'contract' => $employees->where('employment_type', 'contract')->count(),
        ];

        $recent = Employee::query()->latest()->take(5)->get();

        return view('dashboard', compact('stats', 'recent'));
    }

    /**
     * Live dashboard widgets (rev 43). Super admin (tenant_id NULL) → platform
     * figures; company user → HR/payroll figures. Tenant-scoped, fail-soft.
     */
    public function stats(Request $request)
    {
        try {
            $u = $request->user();
            $tid = $u->tenant_id;
            if ($tid === null && $u->hasRole('super_admin')) {
                // Platform owner sees notices too (all tenants + platform-wide).
                return response()->json(['scope' => 'platform', 'cards' => $this->platformCards(), 'notices' => $this->activeNotices(null)]);
            }

            return response()->json(['scope' => 'company', 'cards' => $this->companyCards($tid, \App\Http\Controllers\AppDataController::selfScope($request)), 'notices' => $this->activeNotices($tid)]);
        } catch (\Throwable $e) {
            return response()->json(['scope' => 'company', 'cards' => [], 'error' => $e->getMessage()]);
        }
    }

    /** Recent active notices to show on the dashboard (employee-facing). */
    private function activeNotices(?int $tid): array
    {
        if (! Schema::hasTable('notices')) {
            return [];
        }
        try {
            return DB::table('notices')
                // Company users see their tenant's notices PLUS platform-wide ones
                // (tenant_id NULL — e.g. posted by the super admin).
                ->when($tid && Schema::hasColumn('notices', 'tenant_id'), fn ($q) => $q->where(function ($w) use ($tid) {
                    $w->where('tenant_id', $tid)->orWhereNull('tenant_id');
                }))
                ->when(Schema::hasColumn('notices', 'deleted_at'), fn ($q) => $q->whereNull('deleted_at'))
                ->where(function ($q) {
                    $q->whereNull('status')->orWhereRaw("LOWER(status) NOT IN ('inactive', 'archived', 'draft', 'expired')");
                })
                ->orderByDesc('posted_on')->orderByDesc('id')->limit(6)
                ->get(['title', 'body', 'posted_on'])
                ->map(fn ($r) => ['title' => $r->title, 'body' => $r->body, 'date' => $r->posted_on])->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function companyCards(?int $tid, ?array $self = null): array
    {
        $headcount = (int) DB::table('employees')->when($tid, fn ($q) => $q->where('tenant_id', $tid))
            ->when($self, fn ($q) => $q->where('id', $self['id']))
            ->where('status', 'active')->whereNull('deleted_at')->count();

        $presentToday = 0;
        if (Schema::hasTable('attendance_logs')) {
            $presentToday = (int) DB::table('attendance_logs')->when($tid, fn ($q) => $q->where('tenant_id', $tid))
                ->when($self, fn ($q) => $q->where('emp_code', $self['code']))
                ->whereDate('log_date', now()->toDateString())->distinct()->count('emp_code');
        }

        $pending = Schema::hasTable('leaves')
            ? (int) DB::table('leaves')->when($tid, fn ($q) => $q->where('tenant_id', $tid))->when($self, fn ($q) => $q->where('employee_id', $self['id']))->where('status', 'pending')->count()
            : 0;
        foreach (ApprovalService::modules() as $m) {
            $t = $m['table'];
            if (Schema::hasTable($t) && Schema::hasColumn($t, 'status')) {
                $pending += (int) DB::table($t)->when($tid, fn ($q) => $q->where('tenant_id', $tid))->when($self && Schema::hasColumn($t, 'employee_id'), fn ($q) => $q->where('employee_id', $self['id']))->where('status', 'pending')->count();
            }
        }

        $compliance = 0;
        if (Schema::hasColumn('employees', 'dra_expiry')) {
            $soon = now()->addDays(30)->toDateString();
            $compliance = (int) DB::table('employees')->when($tid, fn ($q) => $q->where('tenant_id', $tid))->when($self, fn ($q) => $q->where('id', $self['id']))->whereNull('deleted_at')
                ->where(fn ($q) => $q->where('dra_expiry', '<=', $soon)->orWhere('pcc_expiry', '<=', $soon))->count();
        }

        $tickets = Schema::hasTable('helpdesk')
            ? (int) DB::table('helpdesk')->when($tid, fn ($q) => $q->where('tenant_id', $tid))->when($self && Schema::hasColumn('helpdesk', 'employee_id'), fn ($q) => $q->where('employee_id', $self['id']))->where('status', 'open')->count()
            : 0;

        $payroll = ['cycle' => '—', 'net' => 0, 'status' => '—'];
        if ($self && Schema::hasTable('payslips')) {
            // rev175 — employee dashboard shows THEIR own latest payslip, never the company payroll total.
            $slip = (array) (DB::table('payslips')->where('employee_id', $self['id'])->orderByDesc('month')->orderByDesc('id')->first() ?: []);
            if ($slip) {
                $payroll = ['cycle' => $slip['month'] ?? '—', 'net' => (float) ($slip['net'] ?? $slip['net_pay'] ?? $slip['net_total'] ?? 0), 'status' => $slip['status'] ?? 'paid'];
            }
        } elseif (Schema::hasTable('payroll_runs')) {
            $run = DB::table('payroll_runs')->when($tid, fn ($q) => $q->where('tenant_id', $tid))->orderByDesc('id')->first();
            if ($run) {
                $payroll = ['cycle' => $run->cycle_label, 'net' => (float) $run->net_total, 'status' => $run->status];
            }
        }

        return [
            ['label' => 'Active Employees', 'value' => number_format($headcount), 'color' => '#3b82f6', 'icon' => 'fa-users'],
            ['label' => 'Present Today', 'value' => number_format($presentToday), 'color' => '#10b981', 'icon' => 'fa-user-check'],
            ['label' => 'Pending Approvals', 'value' => number_format($pending), 'color' => '#f97316', 'icon' => 'fa-hourglass-half'],
            ['label' => 'Compliance Flags (30d)', 'value' => number_format($compliance), 'color' => '#dc2626', 'icon' => 'fa-triangle-exclamation'],
            ['label' => 'Open Helpdesk', 'value' => number_format($tickets), 'color' => '#8b5cf6', 'icon' => 'fa-headset'],
            ['label' => 'Latest Payroll · '.$payroll['cycle'].' ('.ucfirst((string) $payroll['status']).')', 'value' => '₹'.number_format($payroll['net']), 'color' => '#6366f1', 'icon' => 'fa-money-check-dollar'],
        ];
    }

    private function platformCards(): array
    {
        $tenantTotal = (int) DB::table('tenants')->whereNull('deleted_at')->count();
        $tenantActive = (int) DB::table('tenants')->whereNull('deleted_at')->where('status', 'active')->count();
        $mrr = (float) DB::table('tenants')->whereNull('deleted_at')->sum('mrr');

        $invDue = 0;
        $invDueAmt = 0.0;
        if (Schema::hasTable('invoices')) {
            $q = DB::table('invoices')->whereIn('status', ['due', 'overdue']);
            $invDue = (int) (clone $q)->count();
            $invDueAmt = (float) (clone $q)->sum(DB::raw('amount + tax'));
        }
        $paidThisMonth = Schema::hasTable('payments')
            ? (float) DB::table('payments')->where('status', 'success')->whereYear('paid_at', now()->year)->whereMonth('paid_at', now()->month)->sum('amount')
            : 0.0;
        $plans = Schema::hasTable('plans') ? (int) DB::table('plans')->count() : 0;

        return [
            ['label' => 'Tenants (active)', 'value' => number_format($tenantActive).' / '.number_format($tenantTotal), 'color' => '#3b82f6', 'icon' => 'fa-building-user'],
            ['label' => 'MRR', 'value' => '₹'.number_format($mrr), 'color' => '#10b981', 'icon' => 'fa-arrow-trend-up'],
            ['label' => 'Invoices Due', 'value' => number_format($invDue), 'color' => '#f97316', 'icon' => 'fa-file-invoice'],
            ['label' => 'Outstanding', 'value' => '₹'.number_format($invDueAmt), 'color' => '#dc2626', 'icon' => 'fa-circle-exclamation'],
            ['label' => 'Collected (this month)', 'value' => '₹'.number_format($paidThisMonth), 'color' => '#6366f1', 'icon' => 'fa-sack-dollar'],
            ['label' => 'Plans', 'value' => number_format($plans), 'color' => '#8b5cf6', 'icon' => 'fa-tags'],
        ];
    }

    /** Global search across employees (top-bar). */
    public function search(Request $request)
    {
        try {
            $q = trim((string) $request->query('q', ''));
            if (mb_strlen($q) < 2) {
                return response()->json(['employees' => []]);
            }
            $tid = $request->user()->tenant_id;
            $emps = DB::table('employees as e')
                ->leftJoin('companies as c', 'c.id', '=', 'e.company_id')
                ->when($tid, fn ($x) => $x->where('e.tenant_id', $tid))
                ->whereNull('e.deleted_at')
                ->where(function ($x) use ($q) {
                    $x->where('e.name', 'like', '%'.$q.'%')
                        ->orWhere('e.emp_code', 'like', '%'.$q.'%')
                        ->orWhere('e.email', 'like', '%'.$q.'%')
                        ->orWhere('e.mobile', 'like', '%'.$q.'%');
                })
                ->orderBy('e.name')->limit(8)
                ->get(['e.emp_code', 'e.name', 'c.name as company'])
                ->map(fn ($r) => ['code' => $r->emp_code, 'name' => $r->name, 'company' => $r->company ?: ''])
                ->all();

            return response()->json(['employees' => $emps]);
        } catch (\Throwable $e) {
            return response()->json(['employees' => [], 'error' => $e->getMessage()]);
        }
    }
}
