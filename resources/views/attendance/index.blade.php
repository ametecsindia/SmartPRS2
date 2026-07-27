@extends('layouts.app')

@section('title', 'Attendance')
@section('nav_attendance', 'active')

@php
    $badge = ['present' => 'badge-green', 'absent' => 'badge-red', 'leave' => 'badge-amber', 'holiday' => 'badge-blue', 'weekoff' => 'badge-gray'];
@endphp

@section('content')
    @if (session('success'))
        <div style="background:var(--green-soft);color:#059669;font-size:13px;padding:12px 16px;border-radius:10px;margin-bottom:18px;font-family:var(--font2);"><i class="fas fa-circle-check"></i> {{ session('success') }}</div>
    @endif

    <div class="page-header">
        <div>
            <h2>Attendance</h2>
            <p>Mark daily attendance · feeds payroll loss-of-pay automatically</p>
        </div>
        <form method="GET" action="{{ route('attendance.index') }}" style="display:flex;gap:8px;align-items:center;">
            <input type="date" name="date" value="{{ $date }}" class="filter-select" onchange="this.form.submit()">
        </form>
    </div>

    <div class="card">
        <div class="card-header">
            <div>
                <h3>{{ \Illuminate\Support\Carbon::parse($date)->format('l, d M Y') }}</h3>
                <p>{{ $employees->count() }} active employees</p>
            </div>
            <div class="actions">
                <form method="POST" action="{{ route('attendance.markAllPresent') }}">
                    @csrf <input type="hidden" name="date" value="{{ $date }}">
                    <button class="btn btn-outline btn-sm"><i class="fas fa-check-double"></i> Mark all present</button>
                </form>
            </div>
        </div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Employee</th><th>Code</th><th>Current</th><th style="text-align:right;">Set status</th></tr></thead>
                <tbody>
                    @forelse ($employees as $e)
                        @php $m = $marks->get($e->id); @endphp
                        <tr>
                            <td>{{ $e->fullName() }}</td>
                            <td><strong>{{ $e->employee_code }}</strong></td>
                            <td>
                                @if ($m)<span class="badge {{ $badge[$m->status] ?? 'badge-gray' }}">{{ ucfirst($m->status) }}</span>
                                @else<span style="color:var(--text3);">Not marked</span>@endif
                            </td>
                            <td style="text-align:right;white-space:nowrap;">
                                @foreach (['present' => 'P', 'absent' => 'A', 'leave' => 'L'] as $st => $lbl)
                                    <form method="POST" action="{{ route('attendance.mark') }}" style="display:inline;">
                                        @csrf
                                        <input type="hidden" name="employee_id" value="{{ $e->id }}">
                                        <input type="hidden" name="date" value="{{ $date }}">
                                        <input type="hidden" name="status" value="{{ $st }}">
                                        <button class="btn btn-sm {{ $m && $m->status === $st ? 'btn-primary' : 'btn-outline' }}" title="{{ ucfirst($st) }}">{{ $lbl }}</button>
                                    </form>
                                @endforeach
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" style="text-align:center;padding:40px;color:var(--text3);">No active employees.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
