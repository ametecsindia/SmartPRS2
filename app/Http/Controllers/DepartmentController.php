<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Department;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DepartmentController extends Controller
{
    public function index()
    {
        $departments = Department::query()->withCount('employees')->orderBy('name')->get();

        return view('departments.index', compact('departments'));
    }

    public function create()
    {
        return view('departments.form', [
            'department' => new Department(['is_active' => true]),
            'mode' => 'create',
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validateDepartment($request);

        $department = new Department($data);
        if (empty(auth()->user()->company_id)) {
            $department->company_id = Company::value('id');
        }
        $department->save();

        return redirect()->route('departments.index')
            ->with('success', "Department \"{$department->name}\" added.");
    }

    public function edit(Department $department)
    {
        return view('departments.form', [
            'department' => $department,
            'mode' => 'edit',
        ]);
    }

    public function update(Request $request, Department $department)
    {
        $department->update($this->validateDepartment($request, $department));

        return redirect()->route('departments.index')
            ->with('success', "Department \"{$department->name}\" updated.");
    }

    public function destroy(Department $department)
    {
        $name = $department->name;
        $department->delete();

        return redirect()->route('departments.index')
            ->with('success', "Department \"{$name}\" removed.");
    }

    private function validateDepartment(Request $request, ?Department $department = null): array
    {
        $companyId = auth()->user()->company_id ?? Company::value('id');

        return $request->validate([
            'name' => [
                'required', 'string', 'max:120',
                Rule::unique('departments')
                    ->where(fn ($q) => $q->where('company_id', $companyId)->whereNull('deleted_at'))
                    ->ignore($department?->id),
            ],
            'code' => ['nullable', 'string', 'max:30'],
            'description' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }
}
