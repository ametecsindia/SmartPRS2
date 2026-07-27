@extends('layouts.app')

@section('title', 'Dashboard')
@section('nav_dashboard', 'active')

@php
    $tints = ['#f97316', '#3b82f6', '#10b981', '#8b5cf6', '#f59e0b', '#ef4444'];
    $total = max($stats['total'], 1); // avoid divide-by-zero for the bars
    $pct = fn ($n) => round($n / $total * 100);
@endphp

@section('content')
    <div class="page-header">
        <div>
            <h2>Welcome back, {{ explode(' ', auth()->user()->name)[0] }}</h2>
            <p>{{ auth()->user()->company?->name ?? 'All companies — cross-tenant view' }} · {{ now()->format('l, d M Y') }}</p>
        </div>
        <a href="{{ route('employees.create') }}" class="btn btn-primary"><i class="fas fa-plus"></i> Add Employee</a>
    </div>

    <div class="kpi-strip">
        <div class="kpi orange">
            <h3>{{ $stats['total'] }}</h3>
            <p>Total Employees</p>
            <div class="sub" style="color:var(--accent);"><i class="fas fa-users"></i></div>
        </div>
        <div class="kpi green">
            <h3>{{ $stats['active'] }}</h3>
            <p>Active</p>
            <div class="sub" style="color:var(--green);">{{ $pct($stats['active']) }}% of workforce</div>
        </div>
        <div class="kpi blue">
            <h3>₹{{ number_format($stats['gross'], 0) }}</h3>
            <p>Monthly Gross</p>
            <div class="sub" style="color:var(--blue);">payroll commitment</div>
        </div>
        <div class="kpi purple">
            <h3>{{ $stats['field'] }}</h3>
            <p>Field Agents</p>
            <div class="sub" style="color:var(--purple);">on-ground recovery</div>
        </div>
        <div class="kpi red">
            <h3>₹{{ $stats['total'] ? number_format($stats['gross'] / $stats['total'], 0) : 0 }}</h3>
            <p>Avg. Salary</p>
            <div class="sub" style="color:var(--text2);">per employee</div>
        </div>
    </div>

    <div class="dash-grid">
        <div class="card">
            <div class="card-header">
                <div>
                    <h3>Workforce by Type</h3>
                    <p>Distribution across employment types</p>
                </div>
            </div>
            <div style="padding:20px 22px;">
                @foreach (['permanent' => ['Permanent', 'var(--blue)'], 'contract' => ['Contract', 'var(--amber)'], 'field' => ['Field', 'var(--purple)']] as $key => [$label, $color])
                    <div style="margin-bottom:16px;">
                        <div style="display:flex;justify-content:space-between;font-size:13px;font-family:var(--font2);margin-bottom:4px;">
                            <span style="font-weight:600;">{{ $label }}</span>
                            <span style="color:var(--text3);">{{ $stats[$key] }} ({{ $pct($stats[$key]) }}%)</span>
                        </div>
                        <div class="progress-bar"><div class="progress-fill" style="width:{{ $pct($stats[$key]) }}%;background:{{ $color }};"></div></div>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <div>
                    <h3>Recently Added</h3>
                    <p>Latest joiners</p>
                </div>
                <div class="actions">
                    <a href="{{ route('employees.index') }}" class="btn btn-ghost btn-sm">View all <i class="fas fa-arrow-right"></i></a>
                </div>
            </div>
            <div class="mini-list" style="padding:8px 22px 16px;">
                @forelse ($recent as $i => $e)
                    <div class="mini-item">
                        <div class="avatar-sm" style="background:{{ $tints[$i % count($tints)] }};">
                            {{ mb_substr($e->first_name, 0, 1) }}{{ mb_substr($e->last_name ?? '', 0, 1) }}
                        </div>
                        <div class="mini-item-text">
                            <h4>{{ $e->fullName() }}</h4>
                            <p>{{ $e->employee_code }} · {{ ucfirst($e->employment_type) }}</p>
                        </div>
                        <div class="mini-item-val">₹{{ number_format($e->gross_salary, 0) }}</div>
                    </div>
                @empty
                    <p style="color:var(--text3);font-size:13px;padding:20px 0;">No employees yet. <a href="{{ route('employees.create') }}" style="color:var(--accent);">Add your first →</a></p>
                @endforelse
            </div>
        </div>
    </div>
@endsection
