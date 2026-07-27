@extends('layouts.app')

@section('title', 'Employees')
@section('nav_employees', 'active')

@php
    // Deterministic avatar tint per employee (matches prototype palette).
    $tints = ['#f97316', '#3b82f6', '#10b981', '#8b5cf6', '#f59e0b', '#ef4444'];
    $typeBadge = ['permanent' => 'badge-blue', 'contract' => 'badge-amber', 'field' => 'badge-purple'];
@endphp

@section('content')
    @if (session('success'))
        <div style="background:var(--green-soft);color:#059669;font-size:13px;padding:12px 16px;border-radius:10px;margin-bottom:18px;font-family:var(--font2);display:flex;align-items:center;gap:8px;">
            <i class="fas fa-circle-check"></i> {{ session('success') }}
        </div>
    @endif

    <div class="page-header">
        <div>
            <h2>Employees</h2>
            <p>{{ auth()->user()->company?->name ?? 'All companies — cross-tenant view' }}</p>
        </div>
        <a href="{{ route('employees.create') }}" class="btn btn-primary"><i class="fas fa-plus"></i> Add Employee</a>
    </div>

    <div class="stats-grid">
        <div class="stat-card">
            <div class="icon" style="background:var(--accent-soft);color:var(--accent);"><i class="fas fa-users"></i></div>
            <h3>{{ $employees->count() }}</h3>
            <p>Total Employees</p>
        </div>
        <div class="stat-card">
            <div class="icon" style="background:var(--green-soft);color:var(--green);"><i class="fas fa-circle-check"></i></div>
            <h3>{{ $employees->where('status', 'active')->count() }}</h3>
            <p>Active</p>
        </div>
        <div class="stat-card">
            <div class="icon" style="background:var(--blue-soft);color:var(--blue);"><i class="fas fa-indian-rupee-sign"></i></div>
            <h3>₹{{ number_format($employees->sum('gross_salary'), 0) }}</h3>
            <p>Monthly Gross</p>
        </div>
        <div class="stat-card">
            <div class="icon" style="background:var(--purple-soft);color:var(--purple);"><i class="fas fa-user-tie"></i></div>
            <h3>{{ $employees->where('employment_type', 'field')->count() }}</h3>
            <p>Field Agents</p>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <div>
                <h3>Employee Directory</h3>
                <p>Tenant-scoped — you only see your company's people</p>
            </div>
            <div class="actions">
                <button class="btn btn-outline btn-sm"><i class="fas fa-download"></i> Export</button>
            </div>
        </div>
        <div class="filter-bar">
            <div class="search-box">
                <i class="fas fa-magnifying-glass"></i>
                <input type="text" placeholder="Search employees…">
            </div>
            <select class="filter-select"><option>All Types</option><option>Permanent</option><option>Field</option></select>
            <span class="filter-count">{{ $employees->count() }} records</span>
        </div>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr><th>Employee</th><th>Code</th><th>Department</th><th>Type</th><th>Gross Salary</th><th>Status</th><th style="text-align:right;">Actions</th></tr>
                </thead>
                <tbody>
                    @forelse ($employees as $i => $e)
                        <tr>
                            <td>
                                <div class="emp-cell">
                                    <div class="avatar-sm" style="background:{{ $tints[$i % count($tints)] }};">
                                        {{ mb_substr($e->first_name, 0, 1) }}{{ mb_substr($e->last_name ?? '', 0, 1) }}
                                    </div>
                                    <div class="emp-info">
                                        {{ $e->fullName() }}
                                        <span>{{ $e->email ?? $e->position ?? '—' }}</span>
                                    </div>
                                </div>
                            </td>
                            <td><strong>{{ $e->employee_code }}</strong></td>
                            <td>
                                @if ($e->department)
                                    <span class="badge badge-gray">{{ $e->department->name }}</span>
                                @else
                                    <span style="color:var(--text3);">—</span>
                                @endif
                            </td>
                            <td><span class="badge {{ $typeBadge[$e->employment_type] ?? 'badge-gray' }}">{{ ucfirst($e->employment_type) }}</span></td>
                            <td style="font-family:var(--mono);font-weight:600;">₹{{ number_format($e->gross_salary, 2) }}</td>
                            <td><span class="badge badge-green">{{ ucfirst($e->status) }}</span></td>
                            <td style="text-align:right;white-space:nowrap;">
                                <a href="{{ route('employees.edit', $e) }}" class="btn btn-ghost btn-sm" title="Edit"><i class="fas fa-pen"></i></a>
                                <form method="POST" action="{{ route('employees.destroy', $e) }}" style="display:inline;" onsubmit="return confirm('Remove {{ $e->fullName() }}?');">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="btn btn-ghost btn-sm" title="Remove" style="color:var(--red);"><i class="fas fa-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" style="text-align:center;padding:40px;color:var(--text3);">No employees yet for this company.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
