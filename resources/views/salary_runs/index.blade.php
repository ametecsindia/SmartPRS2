@extends('layouts.app')

@section('title', 'Salary Runs')
@section('nav_salary', 'active')

@section('content')
    @if (session('success'))
        <div style="background:var(--green-soft);color:#059669;font-size:13px;padding:12px 16px;border-radius:10px;margin-bottom:18px;font-family:var(--font2);"><i class="fas fa-circle-check"></i> {{ session('success') }}</div>
    @endif

    <div class="page-header">
        <div><h2>Salary Runs</h2><p>Generate monthly payroll · net = gross − (loss-of-pay + loan installments)</p></div>
        <form method="POST" action="{{ route('salary-runs.generate') }}" style="display:flex;gap:8px;align-items:center;">
            @csrf
            <input type="month" name="month" value="{{ now()->format('Y-m') }}" class="filter-select" required>
            <button class="btn btn-primary"><i class="fas fa-gears"></i> Generate</button>
        </form>
    </div>

    <div class="card">
        <div class="card-header"><div><h3>Payroll History</h3><p>{{ $runs->count() }} runs</p></div></div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Month</th><th>Employees</th><th>Total Gross</th><th>Total Net</th><th>Status</th><th>Generated</th><th style="text-align:right;">Actions</th></tr></thead>
                <tbody>
                    @forelse ($runs as $r)
                        <tr>
                            <td><strong>{{ \Illuminate\Support\Carbon::parse($r->month.'-01')->format('F Y') }}</strong></td>
                            <td>{{ $r->slips_count }}</td>
                            <td style="font-family:var(--mono);">₹{{ number_format($r->total_gross, 2) }}</td>
                            <td style="font-family:var(--mono);font-weight:700;">₹{{ number_format($r->total_net, 2) }}</td>
                            <td><span class="badge {{ $r->status === 'finalized' ? 'badge-green' : 'badge-amber' }}">{{ ucfirst($r->status) }}</span></td>
                            <td style="font-size:12px;color:var(--text3);">{{ $r->generated_at?->format('d M Y H:i') ?? '—' }}</td>
                            <td style="text-align:right;"><a href="{{ route('salary-runs.show', $r) }}" class="btn btn-outline btn-sm">Open <i class="fas fa-arrow-right"></i></a></td>
                        </tr>
                    @empty
                        <tr><td colspan="7" style="text-align:center;padding:40px;color:var(--text3);">No salary runs yet. Pick a month and click Generate.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
