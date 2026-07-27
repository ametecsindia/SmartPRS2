<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\Company;
use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * DEPRECATED / LEGACY — not routed. Superseded by AttendanceReportController
 * (in-app punch + reports) which uses `attendance_logs`, the single source of
 * truth. This controller reads/writes the old `attendance` table and must NOT
 * be re-wired: doing so would record attendance that payroll/reports never see.
 */
class AttendanceController extends Controller
{
    public function index(Request $request)
    {
        $date = $request->input('date', now()->toDateString());

        $employees = Employee::where('status', 'active')->orderBy('employee_code')->get();
        $marks = Attendance::whereDate('date', $date)->get()->keyBy('employee_id');

        return view('attendance.index', compact('employees', 'marks', 'date'));
    }

    public function mark(Request $request)
    {
        $data = $request->validate([
            'employee_id' => ['required', 'exists:employees,id'],
            'date' => ['required', 'date'],
            'status' => ['required', Rule::in(['present', 'absent', 'leave', 'holiday', 'weekoff'])],
            'check_in' => ['nullable'],
            'check_out' => ['nullable'],
        ]);

        $companyId = auth()->user()->company_id ?? Company::value('id');

        Attendance::updateOrCreate(
            ['company_id' => $companyId, 'employee_id' => $data['employee_id'], 'date' => $data['date']],
            ['status' => $data['status'], 'check_in' => $data['check_in'] ?? null, 'check_out' => $data['check_out'] ?? null, 'source' => 'manual']
        );

        return back()->with('success', 'Attendance updated.');
    }

    public function markAllPresent(Request $request)
    {
        $date = $request->input('date', now()->toDateString());
        $companyId = auth()->user()->company_id ?? Company::value('id');

        foreach (Employee::where('status', 'active')->get() as $emp) {
            Attendance::updateOrCreate(
                ['company_id' => $companyId, 'employee_id' => $emp->id, 'date' => $date],
                ['status' => 'present', 'source' => 'manual']
            );
        }

        return back()->with('success', "All active employees marked present for {$date}.");
    }
}
