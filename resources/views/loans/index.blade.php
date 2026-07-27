@extends('layouts.app')

@section('title', 'Loans & Advances')
@section('nav_loans', 'active')

@section('content')
    @if (session('success'))
        <div style="background:var(--green-soft);color:#059669;font-size:13px;padding:12px 16px;border-radius:10px;margin-bottom:18px;font-family:var(--font2);"><i class="fas fa-circle-check"></i> {{ session('success') }}</div>
    @endif

    <div class="page-header">
        <div><h2>Loans &amp; Advances</h2><p>Active installments are deducted automatically in payroll</p></div>
        <a href="{{ route('loans.create') }}" class="btn btn-primary"><i class="fas fa-plus"></i> Add Loan / Advance</a>
    </div>

    <div class="card">
        <div class="card-header"><div><h3>Records</h3><p>{{ $loans->count() }} total</p></div></div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Employee</th><th>Type</th><th>Principal</th><th>Installment</th><th>Progress</th><th>Status</th><th style="text-align:right;">Actions</th></tr></thead>
                <tbody>
                    @forelse ($loans as $l)
                        <tr>
                            <td>{{ $l->employee?->fullName() ?? '—' }}<div style="font-size:11px;color:var(--text3);">{{ $l->note }}</div></td>
                            <td><span class="badge {{ $l->type === 'loan' ? 'badge-blue' : 'badge-purple' }}">{{ ucfirst($l->type) }}</span></td>
                            <td style="font-family:var(--mono);">₹{{ number_format($l->principal, 2) }}</td>
                            <td style="font-family:var(--mono);">₹{{ number_format($l->installment_amount, 2) }}</td>
                            <td>{{ $l->installments_paid }}/{{ $l->installments_total }}</td>
                            <td><span class="badge {{ $l->status === 'active' ? 'badge-green' : 'badge-gray' }}">{{ ucfirst($l->status) }}</span></td>
                            <td style="text-align:right;white-space:nowrap;">
                                <a href="{{ route('loans.edit', $l) }}" class="btn btn-ghost btn-sm" title="Edit"><i class="fas fa-pen"></i></a>
                                <form method="POST" action="{{ route('loans.destroy', $l) }}" style="display:inline;" onsubmit="return confirm('Remove this record?');">@csrf @method('DELETE')<button class="btn btn-ghost btn-sm" style="color:var(--red);" title="Remove"><i class="fas fa-trash"></i></button></form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" style="text-align:center;padding:40px;color:var(--text3);">No loans or advances yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
