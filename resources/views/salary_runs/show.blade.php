@extends('layouts.app')

@section('title', 'Salary Run')
@section('nav_salary', 'active')

@section('content')
    @if (session('success'))
        <div style="background:var(--green-soft);color:#059669;font-size:13px;padding:12px 16px;border-radius:10px;margin-bottom:18px;font-family:var(--font2);"><i class="fas fa-circle-check"></i> {{ session('success') }}</div>
    @endif

    <div class="page-header">
        <div>
            <h2>Payroll — {{ \Illuminate\Support\Carbon::parse($run->month.'-01')->format('F Y') }}</h2>
            <p><span class="badge {{ $run->status === 'finalized' ? 'badge-green' : 'badge-amber' }}">{{ ucfirst($run->status) }}</span> · {{ $run->slips->count() }} payslips</p>
        </div>
        <div style="display:flex;gap:8px;">
            <a href="{{ route('salary-runs.index') }}" class="btn btn-outline"><i class="fas fa-arrow-left"></i> Back</a>
            @if ($run->status !== 'finalized')
                <form method="POST" action="{{ route('salary-runs.finalize', $run) }}">@csrf @method('PUT')<button class="btn btn-primary"><i class="fas fa-lock"></i> Finalize</button></form>
            @endif
        </div>
    </div>

    <div class="stats-grid">
        <div class="stat-card"><div class="icon" style="background:var(--blue-soft);color:var(--blue);"><i class="fas fa-indian-rupee-sign"></i></div><h3>₹{{ number_format($run->total_gross, 0) }}</h3><p>Total Gross</p></div>
        <div class="stat-card"><div class="icon" style="background:var(--red-soft);color:var(--red);"><i class="fas fa-arrow-trend-down"></i></div><h3>₹{{ number_format($run->total_gross - $run->total_net, 0) }}</h3><p>Total Deductions</p></div>
        <div class="stat-card"><div class="icon" style="background:var(--green-soft);color:var(--green);"><i class="fas fa-money-bill-wave"></i></div><h3>₹{{ number_format($run->total_net, 0) }}</h3><p>Net Payable</p></div>
        <div class="stat-card"><div class="icon" style="background:var(--accent-soft);color:var(--accent);"><i class="fas fa-users"></i></div><h3>{{ $run->slips->count() }}</h3><p>Payslips</p></div>
    </div>

    <div class="card">
        <div class="card-header"><div><h3>Salary Sheet</h3><p>Per-employee breakdown</p></div></div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Employee</th><th>Gross</th><th>Present</th><th>Absent</th><th>Loss of Pay</th><th>Loan</th><th>Net</th><th style="text-align:right;">Payslip</th></tr></thead>
                <tbody>
                    @foreach ($run->slips as $s)
                        <tr>
                            <td>{{ $s->employee?->fullName() ?? '—' }}<div style="font-size:11px;color:var(--text3);">{{ $s->employee?->employee_code }}</div></td>
                            <td style="font-family:var(--mono);">₹{{ number_format($s->gross, 2) }}</td>
                            <td>{{ $s->present_days }}</td>
                            <td>{{ $s->absent_days }}</td>
                            <td style="font-family:var(--mono);color:var(--red);">₹{{ number_format($s->lwp_amount, 2) }}</td>
                            <td style="font-family:var(--mono);color:var(--red);">₹{{ number_format($s->loan_deduction, 2) }}</td>
                            <td style="font-family:var(--mono);font-weight:700;">₹{{ number_format($s->net_salary, 2) }}</td>
                            <td style="text-align:right;"><a href="{{ route('payslips.show', $s) }}" class="btn btn-ghost btn-sm" title="View payslip"><i class="fas fa-file-invoice-dollar"></i></a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endsection
