@extends('layouts.app')

@section('title', 'Payslip')
@section('nav_payslips', 'active')

@section('content')
    <div class="page-header">
        <div><h2>Payslip</h2><p>{{ $slip->run ? \Illuminate\Support\Carbon::parse($slip->run->month.'-01')->format('F Y') : '' }} · {{ $slip->employee?->fullName() }}</p></div>
        <div style="display:flex;gap:8px;">
            <a href="{{ route('payslips.index') }}" class="btn btn-outline"><i class="fas fa-arrow-left"></i> Back</a>
            <button onclick="window.print()" class="btn btn-primary"><i class="fas fa-print"></i> Print</button>
        </div>
    </div>

    <div class="payslip">
        <div class="payslip-head">
            <div class="payslip-logo">
                <div class="ico"><i class="fas fa-bolt"></i></div>
                <div><h2>SmartPRS</h2><span>{{ auth()->user()->company?->name ?? 'SmartPRS' }}</span></div>
            </div>
            <div class="payslip-meta">
                <h3>Payslip</h3>
                <span>{{ $slip->run ? \Illuminate\Support\Carbon::parse($slip->run->month.'-01')->format('F Y') : '' }}</span>
            </div>
        </div>

        <div class="payslip-emp">
            <div>
                <div class="payslip-field"><label>Employee</label><span>{{ $slip->employee?->fullName() }}</span></div>
                <div class="payslip-field"><label>Code</label><span>{{ $slip->employee?->employee_code }}</span></div>
                <div class="payslip-field"><label>Department</label><span>{{ $slip->employee?->department?->name ?? '—' }}</span></div>
            </div>
            <div>
                <div class="payslip-field"><label>Type</label><span>{{ ucfirst($slip->employee?->employment_type) }}</span></div>
                <div class="payslip-field"><label>Present / Absent</label><span>{{ $slip->present_days }} / {{ $slip->absent_days }} days</span></div>
                <div class="payslip-field"><label>Status</label><span>{{ ucfirst($slip->run?->status ?? 'draft') }}</span></div>
            </div>
        </div>

        <table class="payslip-table" style="width:100%;border-collapse:collapse;">
            <thead>
                <tr><th style="text-align:left;padding:8px;border-bottom:1px solid var(--border);">Earnings</th><th style="text-align:right;padding:8px;border-bottom:1px solid var(--border);">Amount</th><th style="text-align:left;padding:8px;border-bottom:1px solid var(--border);">Deductions</th><th style="text-align:right;padding:8px;border-bottom:1px solid var(--border);">Amount</th></tr>
            </thead>
            <tbody>
                <tr>
                    <td style="padding:8px;">Gross Salary</td>
                    <td style="padding:8px;text-align:right;font-family:var(--mono);">₹{{ number_format($slip->gross, 2) }}</td>
                    <td style="padding:8px;">Loss of Pay</td>
                    <td style="padding:8px;text-align:right;font-family:var(--mono);">₹{{ number_format($slip->lwp_amount, 2) }}</td>
                </tr>
                <tr>
                    <td style="padding:8px;"></td>
                    <td style="padding:8px;"></td>
                    <td style="padding:8px;">Loan / Advance</td>
                    <td style="padding:8px;text-align:right;font-family:var(--mono);">₹{{ number_format($slip->loan_deduction, 2) }}</td>
                </tr>
                <tr>
                    <td style="padding:8px;"></td>
                    <td style="padding:8px;"></td>
                    <td style="padding:8px;">Other</td>
                    <td style="padding:8px;text-align:right;font-family:var(--mono);">₹{{ number_format($slip->other_deduction, 2) }}</td>
                </tr>
                <tr style="border-top:2px solid var(--navy);font-weight:700;">
                    <td style="padding:8px;">Total Earnings</td>
                    <td style="padding:8px;text-align:right;font-family:var(--mono);">₹{{ number_format($slip->gross, 2) }}</td>
                    <td style="padding:8px;">Total Deductions</td>
                    <td style="padding:8px;text-align:right;font-family:var(--mono);">₹{{ number_format($slip->totalDeductions(), 2) }}</td>
                </tr>
            </tbody>
        </table>

        <div style="margin-top:18px;background:var(--navy);color:#fff;border-radius:10px;padding:16px 20px;display:flex;justify-content:space-between;align-items:center;">
            <span style="font-family:var(--font2);">Net Payable</span>
            <strong style="font-size:22px;">₹{{ number_format($slip->net_salary, 2) }}</strong>
        </div>
        <p style="font-size:11px;color:var(--text3);margin-top:14px;font-family:var(--font2);">Computer-generated payslip · net = gross − (loss-of-pay + loan installments + other). Money stored as decimal.</p>
    </div>
@endsection
