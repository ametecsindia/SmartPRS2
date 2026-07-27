<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Employee;
use App\Models\Loan;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LoanController extends Controller
{
    public function index()
    {
        $loans = Loan::with('employee')->latest()->get();

        return view('loans.index', compact('loans'));
    }

    public function create()
    {
        return view('loans.form', [
            'loan' => new Loan(['type' => 'loan', 'status' => 'active', 'installments_paid' => 0, 'start_month' => now()->format('Y-m')]),
            'employees' => Employee::where('status', 'active')->orderBy('employee_code')->get(),
            'mode' => 'create',
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validateLoan($request);
        $data['company_id'] = auth()->user()->company_id ?? Company::value('id');

        Loan::create($data);

        return redirect()->route('loans.index')->with('success', 'Loan / advance recorded.');
    }

    public function edit(Loan $loan)
    {
        return view('loans.form', [
            'loan' => $loan,
            'employees' => Employee::where('status', 'active')->orderBy('employee_code')->get(),
            'mode' => 'edit',
        ]);
    }

    public function update(Request $request, Loan $loan)
    {
        $loan->update($this->validateLoan($request));

        return redirect()->route('loans.index')->with('success', 'Loan / advance updated.');
    }

    public function destroy(Loan $loan)
    {
        $loan->delete();

        return back()->with('success', 'Loan / advance removed.');
    }

    private function validateLoan(Request $request): array
    {
        $data = $request->validate([
            'employee_id' => ['required', 'exists:employees,id'],
            'type' => ['required', Rule::in(['loan', 'advance'])],
            'principal' => ['required', 'numeric', 'min:0'],
            'installment_amount' => ['required', 'numeric', 'min:0'],
            'installments_total' => ['required', 'integer', 'min:1'],
            'installments_paid' => ['nullable', 'integer', 'min:0'],
            'start_month' => ['nullable', 'string', 'max:7'],
            'status' => ['required', Rule::in(['active', 'closed'])],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        // rev172 (H1) — auto-close a loan once all installments are paid, so its
        // EMI stops being deducted in payroll.
        if ((int) ($data['installments_paid'] ?? 0) >= (int) $data['installments_total']) {
            $data['status'] = 'closed';
        }

        return $data;
    }
}
