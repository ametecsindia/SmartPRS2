<?php

namespace App\Http\Controllers;

use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * SaaS Super Admin — manage all tenant companies (cross-tenant).
 * Restricted to the super_admin role.
 */
class TenantController extends Controller
{
    public function index()
    {
        $this->guard();

        $companies = Company::withCount(['employees', 'users'])->orderBy('name')->get();

        return view('tenants.index', compact('companies'));
    }

    public function create()
    {
        $this->guard();

        return view('tenants.form', [
            'company' => new Company(['deployment' => 'saas', 'is_active' => true]),
            'mode' => 'create',
        ]);
    }

    public function store(Request $request)
    {
        $this->guard();

        Company::create($this->validateCompany($request));

        return redirect()->route('tenants.index')->with('success', 'Tenant created.');
    }

    public function edit(Company $tenant)
    {
        $this->guard();

        return view('tenants.form', ['company' => $tenant, 'mode' => 'edit']);
    }

    public function update(Request $request, Company $tenant)
    {
        $this->guard();

        $tenant->update($this->validateCompany($request, $tenant));

        return redirect()->route('tenants.index')->with('success', 'Tenant updated.');
    }

    public function destroy(Company $tenant)
    {
        $this->guard();

        $tenant->update(['is_active' => ! $tenant->is_active]);

        return back()->with('success', $tenant->is_active ? 'Tenant activated.' : 'Tenant suspended.');
    }

    private function guard(): void
    {
        abort_unless(auth()->user()?->isSuperAdmin(), 403, 'Super Admin only.');
    }

    private function validateCompany(Request $request, ?Company $company = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'code' => ['required', 'string', 'max:30', Rule::unique('companies')->ignore($company?->id)],
            'legal_name' => ['nullable', 'string', 'max:200'],
            'gstin' => ['nullable', 'string', 'max:15'],
            'pan' => ['nullable', 'string', 'max:10'],
            'state' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:150'],
            'deployment' => ['required', Rule::in(['saas', 'onprem'])],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }
}
