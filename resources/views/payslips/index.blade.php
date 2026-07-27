@extends('layouts.app')

@section('title', 'Payslips')
@section('nav_payslips', 'active')

@section('content')
    <div class="page-header">
        <div><h2>Payslips</h2><p>All generated payslips across salary runs</p></div>
    </div>

    <div class="card">
        <div class="card-header"><div><h3>Payslip Register</h3><p>{{ $slips->count() }} payslips</p></div></div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Employee</th><th>Month</th><th>Gross</th><th>Deductions</th><th>Net</th><th style="text-align:right;">Actions</th></tr></thead>
                <tbody>
                    @forelse ($slips as $s)
                        <tr>
                            <td>{{ $s->employee?->fullName() ?? '—' }}<div style="font-size:11px;color:var(--text3);">{{ $s->employee?->employee_code }}</div></td>
                            <td>{{ $s->run ? \Illuminate\Support\Carbon::parse($s->run->month.'-01')->format('M Y') : '—' }}</td>
                            <td style="font-family:var(--mono);">₹{{ number_format($s->gross, 2) }}</td>
                            <td style="font-family:var(--mono);color:var(--red);">₹{{ number_format($s->totalDeductions(), 2) }}</td>
                            <td style="font-family:var(--mono);font-weight:700;">₹{{ number_format($s->net_salary, 2) }}</td>
                            <td style="text-align:right;"><a href="{{ route('payslips.show', $s) }}" class="btn btn-outline btn-sm">View <i class="fas fa-arrow-right"></i></a></td>
                        </tr>
                    @empty
                        <tr><td colspan="6" style="text-align:center;padding:40px;color:var(--text3);">No payslips yet. Generate a salary run first.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
