<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\Company;
use App\Models\Employee;
use App\Models\LeaveApplication;
use App\Models\Loan;
use App\Models\SalaryRun;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * DEPRECATED / LEGACY — not routed. Superseded by PayrollGenController +
 * SalaryApprovalController (the /app payroll flow), which read `attendance_logs`.
 * This legacy run reads the old `attendance` table for absences; do NOT re-wire
 * it or payroll will diverge from the single source of truth.
 */
class SalaryRunController extends Controller
{
    public function index()
    {
        $runs = SalaryRun::withCount('slips')->latest('month')->get();

        return view('salary_runs.index', compact('runs'));
    }

    /**
     * Generate the salary sheet for a month using the harvested formula:
     *   leave_without_pay = (gross / total_days) * total_absence
     *   total_deductions  = lwp + loan_installments + other
     *   net_salary        = gross - total_deductions
     * Idempotent: re-running a month rebuilds its slips.
     */
    public function generate(Request $request)
    {
        $month = $request->input('month', now()->format('Y-m'));
        $request->merge(['month' => $month]);
        $request->validate(['month' => ['required', 'regex:/^\d{4}-\d{2}$/']]);

        $companyId = auth()->user()->company_id ?? Company::value('id');
        $start = Carbon::createFromFormat('Y-m-d', $month.'-01')->startOfMonth();
        $end = (clone $start)->endOfMonth();
        $totalDays = $start->daysInMonth;

        $run = SalaryRun::firstOrCreate(
            ['company_id' => $companyId, 'month' => $month],
            ['status' => 'draft']
        );
        $run->slips()->delete();

        $totalGross = 0;
        $totalNet = 0;

        foreach (Employee::where('status', 'active')->get() as $emp) {
            $gross = (float) $emp->gross_salary;

            $absent = Attendance::where('employee_id', $emp->id)
                ->whereBetween('date', [$start, $end])
                ->where('status', 'absent')->count();

            $unpaidLeaveDays = (int) LeaveApplication::where('employee_id', $emp->id)
                ->where('status', 'approved')->where('leave_type', 'unpaid')
                ->whereBetween('from_date', [$start, $end])->sum('days');

            $absence = $absent + $unpaidLeaveDays;
            $lwp = $totalDays > 0 ? round($gross / $totalDays * $absence, 2) : 0;

            $loanDeduction = Loan::where('employee_id', $emp->id)
                ->get()->sum(fn ($l) => $l->dueInstallment());

            $net = round($gross - $lwp - $loanDeduction, 2);
            $present = max($totalDays - $absence, 0);

            $run->slips()->create([
                'company_id' => $companyId,
                'employee_id' => $emp->id,
                'gross' => $gross,
                'present_days' => $present,
                'absent_days' => $absence,
                'lwp_amount' => $lwp,
                'loan_deduction' => $loanDeduction,
                'other_deduction' => 0,
                'net_salary' => $net,
            ]);

            $totalGross += $gross;
            $totalNet += $net;
        }

        $run->update([
            'total_gross' => $totalGross,
            'total_net' => $totalNet,
            'generated_at' => now(),
        ]);

        return redirect()->route('salary-runs.show', $run)
            ->with('success', "Salary sheet generated for {$month}.");
    }

    public function show(SalaryRun $salaryRun)
    {
        $salaryRun->load(['slips.employee']);

        return view('salary_runs.show', ['run' => $salaryRun]);
    }

    public function finalize(SalaryRun $salaryRun)
    {
        $salaryRun->update(['status' => 'finalized']);

        return back()->with('success', "Salary run {$salaryRun->month} finalized.");
    }
}
