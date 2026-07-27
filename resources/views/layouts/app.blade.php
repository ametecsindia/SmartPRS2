<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SmartPRS — @yield('title', 'Dashboard')</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=DM+Sans:wght@400;500;600;700&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="/css/smartprs.css">
</head>
<body>
@php
    $u = auth()->user();
    $roleLabel = ucwords(str_replace('_', ' ', $u->getRoleNames()->first() ?? 'User'));
    $initials = collect(explode(' ', $u->name))->map(fn ($p) => mb_substr($p, 0, 1))->take(2)->implode('');
@endphp

<aside class="sidebar">
    <div class="sidebar-header">
        <div class="brand">
            <div class="brand-icon"><i class="fas fa-bolt"></i></div>
            <div class="brand-text"><h1>SmartPRS</h1><span>by Ametecs</span></div>
        </div>
    </div>
    <nav class="sidebar-nav">
        @if ($u->isSuperAdmin())
        <div class="nav-section">
            <div class="nav-label">SaaS Platform</div>
            <a href="{{ route('tenants.index') }}" class="nav-item @yield('nav_tenants')"><i class="fas fa-building-user"></i> Tenants</a>
        </div>
        @endif
        <div class="nav-section">
            <div class="nav-label">Main</div>
            <a href="{{ route('dashboard') }}" class="nav-item @yield('nav_dashboard')"><i class="fas fa-gauge-high"></i> Dashboard</a>
        </div>
        <div class="nav-section">
            <div class="nav-label">HR Core</div>
            <a href="{{ route('employees.index') }}" class="nav-item @yield('nav_employees')"><i class="fas fa-users"></i> Employees</a>
            <a href="{{ route('departments.index') }}" class="nav-item @yield('nav_departments')"><i class="fas fa-sitemap"></i> Departments</a>
            <a href="{{ route('attendance.index') }}" class="nav-item @yield('nav_attendance')"><i class="fas fa-fingerprint"></i> Attendance</a>
            <a href="{{ route('leave.index') }}" class="nav-item @yield('nav_leave')"><i class="fas fa-plane-departure"></i> Leave</a>
        </div>
        <div class="nav-section">
            <div class="nav-label">Payroll</div>
            <a href="{{ route('salary-runs.index') }}" class="nav-item @yield('nav_salary')"><i class="fas fa-money-check-dollar"></i> Salary Runs</a>
            <a href="{{ route('payslips.index') }}" class="nav-item @yield('nav_payslips')"><i class="fas fa-file-invoice-dollar"></i> Payslips</a>
            <a href="{{ route('loans.index') }}" class="nav-item @yield('nav_loans')"><i class="fas fa-hand-holding-dollar"></i> Loans &amp; Advances</a>
        </div>
        <div class="nav-section">
            <div class="nav-label">Settings</div>
            <a href="{{ route('devices.index') }}" class="nav-item @yield('nav_devices_settings')"><i class="fas fa-fingerprint"></i> Biometric Devices</a>
        </div>
    </nav>
    <div class="sidebar-footer">
        <div class="user-card">
            <div class="user-avatar">{{ $initials }}</div>
            <div class="user-info">
                <h4>{{ $u->name }}</h4>
                <span>{{ $roleLabel }}</span>
            </div>
            <form method="POST" action="{{ route('logout') }}" style="margin-left:auto;">@csrf
                <button type="submit" class="btn-ghost" title="Sign out" style="color:rgba(255,255,255,.4);">
                    <i class="fas fa-right-from-bracket"></i>
                </button>
            </form>
        </div>
    </div>
</aside>

<div class="main-wrap">
    <div class="topbar">
        <div>
            <div class="page-title">@yield('title', 'Dashboard')</div>
            <div class="breadcrumb">SmartPRS <i class="fas fa-chevron-right" style="font-size:9px;"></i> <span>@yield('title', 'Dashboard')</span></div>
        </div>
        <div class="topbar-actions">
            <div class="topbar-search">
                <i class="fas fa-magnifying-glass"></i>
                <input type="text" placeholder="Search…">
            </div>
            <div class="topbar-btn"><i class="fas fa-bell"></i><span class="dot"></span></div>
            <div class="topbar-avatar" title="{{ $u->name }}">{{ $initials }}</div>
        </div>
    </div>
    <div class="content">
        @yield('content')
    </div>
</div>
</body>
</html>
