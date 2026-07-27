<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EmployeeController extends Controller
{
    public function index()
    {
        // Tenant-scoped automatically via BelongsToCompany; super_admin sees all.
        $employees = Employee::query()->with('department')->orderBy('employee_code')->get();

        return view('employees.index', compact('employees'));
    }

    public function create()
    {
        return view('employees.form', [
            'employee' => new Employee(['status' => 'active', 'employment_type' => 'permanent']),
            'departments' => Department::orderBy('name')->get(),
            'mode' => 'create',
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validateEmployee($request);

        $employee = new Employee($data);

        // Company users get company_id auto-filled by the trait; super_admin (no
        // company) defaults to the first company so the record is always valid.
        if (empty(auth()->user()->company_id)) {
            $employee->company_id = Company::value('id');
        }

        $employee->save();

        \App\Services\Audit::record(auth()->user()->tenant_id ? (int) auth()->user()->tenant_id : null, auth()->id(), 'create', 'employees', $employee->id, ['code' => $employee->employee_code], $request->ip());

        return redirect()->route('employees.index')
            ->with('success', "Employee {$employee->employee_code} added.");
    }

    public function edit(Employee $employee)
    {
        return view('employees.form', [
            'employee' => $employee,
            'departments' => Department::orderBy('name')->get(),
            'mode' => 'edit',
        ]);
    }

    public function update(Request $request, Employee $employee)
    {
        $data = $this->validateEmployee($request, $employee);

        $employee->update($data);

        \App\Services\Audit::record(auth()->user()->tenant_id ? (int) auth()->user()->tenant_id : null, auth()->id(), 'update', 'employees', $employee->id, ['code' => $employee->employee_code], $request->ip());

        return redirect()->route('employees.index')
            ->with('success', "Employee {$employee->employee_code} updated.");
    }

    public function destroy(Employee $employee)
    {
        $code = $employee->employee_code;
        $eid = $employee->id;
        $employee->delete();

        \App\Services\Audit::record(auth()->user()->tenant_id ? (int) auth()->user()->tenant_id : null, auth()->id(), 'delete', 'employees', $eid, ['code' => $code], request()->ip());

        return redirect()->route('employees.index')
            ->with('success', "Employee {$code} removed.");
    }

    /**
     * Shared validation. Employee code is unique per company.
     */
    private function validateEmployee(Request $request, ?Employee $employee = null): array
    {
        $companyId = auth()->user()->company_id ?? Company::value('id');

        return $request->validate([
            'employee_code' => [
                'required', 'string', 'max:50',
                Rule::unique('employees')
                    ->where(fn ($q) => $q->where('company_id', $companyId))
                    ->ignore($employee?->id),
            ],
            'department_id' => ['nullable', 'exists:departments,id'],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:150'],
            'phone' => ['nullable', 'string', 'max:20'],
            'position' => ['nullable', 'string', 'max:100'],
            'date_of_joining' => ['nullable', 'date'],
            'employment_type' => ['required', Rule::in(['permanent', 'contract', 'field'])],
            'gross_salary' => ['required', 'numeric', 'min:0'],
            'status' => ['required', Rule::in(['active', 'inactive', 'exited'])],
            'device_user_id' => ['nullable', 'string', 'max:50'],
        ]);
    }
}
