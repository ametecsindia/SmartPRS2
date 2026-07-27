@extends('layouts.app')

@section('title', 'Leave')
@section('nav_leave', 'active')

@php
    $sBadge = ['pending' => 'badge-amber', 'approved' => 'badge-green', 'rejected' => 'badge-red'];
    $tBadge = ['casual' => 'badge-blue', 'sick' => 'badge-purple', 'earned' => 'badge-green', 'unpaid' => 'badge-red'];
@endphp

@section('content')
    @if (session('success'))
        <div style="background:var(--green-soft);color:#059669;font-size:13px;padding:12px 16px;border-radius:10px;margin-bottom:18px;font-family:var(--font2);"><i class="fas fa-circle-check"></i> {{ session('success') }}</div>
    @endif

    <div class="page-header">
        <div>
            <h2>Leave</h2>
            <p>Applications · approved unpaid leave flows into payroll</p>
        </div>
        <a href="{{ route('leave.create') }}" class="btn btn-primary"><i class="fas fa-plus"></i> Apply Leave</a>
    </div>

    <div class="card">
        <div class="card-header"><div><h3>Leave Applications</h3><p>{{ $leaves->count() }} total</p></div></div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Employee</th><th>Type</th><th>From</th><th>To</th><th>Days</th><th>Status</th><th style="text-align:right;">Actions</th></tr></thead>
                <tbody>
                    @forelse ($leaves as $l)
                        <tr>
                            <td>{{ $l->employee?->fullName() ?? '—' }}<div style="font-size:11px;color:var(--text3);">{{ $l->reason }}</div></td>
                            <td><span class="badge {{ $tBadge[$l->leave_type] ?? 'badge-gray' }}">{{ ucfirst($l->leave_type) }}</span></td>
                            <td>{{ $l->from_date->format('d M Y') }}</td>
                            <td>{{ $l->to_date->format('d M Y') }}</td>
                            <td><strong>{{ $l->days }}</strong></td>
                            <td><span class="badge {{ $sBadge[$l->status] ?? 'badge-gray' }}">{{ ucfirst($l->status) }}</span></td>
                            <td style="text-align:right;white-space:nowrap;">
                                @if ($l->status !== 'approved')
                                    <form method="POST" action="{{ route('leave.status', $l) }}" style="display:inline;">@csrf @method('PUT')<input type="hidden" name="status" value="approved"><button class="btn btn-ghost btn-sm" style="color:var(--green);" title="Approve"><i class="fas fa-check"></i></button></form>
                                @endif
                                @if ($l->status !== 'rejected')
                                    <form method="POST" action="{{ route('leave.status', $l) }}" style="display:inline;">@csrf @method('PUT')<input type="hidden" name="status" value="rejected"><button class="btn btn-ghost btn-sm" style="color:var(--amber);" title="Reject"><i class="fas fa-xmark"></i></button></form>
                                @endif
                                <form method="POST" action="{{ route('leave.destroy', $l) }}" style="display:inline;" onsubmit="return confirm('Delete this leave?');">@csrf @method('DELETE')<button class="btn btn-ghost btn-sm" style="color:var(--red);" title="Delete"><i class="fas fa-trash"></i></button></form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" style="text-align:center;padding:40px;color:var(--text3);">No leave applications yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
