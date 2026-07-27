@extends('layouts.app')

@section('title', 'Tenants')
@section('nav_tenants', 'active')

@section('content')
    @if (session('success'))
        <div style="background:var(--green-soft);color:#059669;font-size:13px;padding:12px 16px;border-radius:10px;margin-bottom:18px;font-family:var(--font2);"><i class="fas fa-circle-check"></i> {{ session('success') }}</div>
    @endif

    <div class="page-header">
        <div><h2>Tenants</h2><p>SaaS Super Admin · all client companies on the platform</p></div>
        <a href="{{ route('tenants.create') }}" class="btn btn-primary"><i class="fas fa-plus"></i> Add Tenant</a>
    </div>

    <div class="stats-grid">
        <div class="stat-card"><div class="icon" style="background:var(--accent-soft);color:var(--accent);"><i class="fas fa-building-user"></i></div><h3>{{ $companies->count() }}</h3><p>Tenants</p></div>
        <div class="stat-card"><div class="icon" style="background:var(--green-soft);color:var(--green);"><i class="fas fa-circle-check"></i></div><h3>{{ $companies->where('is_active', true)->count() }}</h3><p>Active</p></div>
        <div class="stat-card"><div class="icon" style="background:var(--blue-soft);color:var(--blue);"><i class="fas fa-users"></i></div><h3>{{ $companies->sum('employees_count') }}</h3><p>Total Employees</p></div>
        <div class="stat-card"><div class="icon" style="background:var(--purple-soft);color:var(--purple);"><i class="fas fa-server"></i></div><h3>{{ $companies->where('deployment', 'onprem')->count() }}</h3><p>On-Premise</p></div>
    </div>

    <div class="card">
        <div class="card-header"><div><h3>Tenant Directory</h3><p>{{ $companies->count() }} companies</p></div></div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Company</th><th>Code</th><th>Deployment</th><th>Employees</th><th>Users</th><th>Status</th><th style="text-align:right;">Actions</th></tr></thead>
                <tbody>
                    @forelse ($companies as $c)
                        <tr>
                            <td><strong>{{ $c->name }}</strong><div style="font-size:11px;color:var(--text3);">{{ $c->state }}</div></td>
                            <td><strong>{{ $c->code }}</strong></td>
                            <td><span class="badge {{ $c->deployment === 'saas' ? 'badge-blue' : 'badge-purple' }}">{{ $c->deployment === 'saas' ? 'SaaS' : 'On-Premise' }}</span></td>
                            <td>{{ $c->employees_count }}</td>
                            <td>{{ $c->users_count }}</td>
                            <td><span class="badge {{ $c->is_active ? 'badge-green' : 'badge-red' }}">{{ $c->is_active ? 'Active' : 'Suspended' }}</span></td>
                            <td style="text-align:right;white-space:nowrap;">
                                <a href="{{ route('tenants.edit', $c) }}" class="btn btn-ghost btn-sm" title="Edit"><i class="fas fa-pen"></i></a>
                                <form method="POST" action="{{ route('tenants.destroy', $c) }}" style="display:inline;" onsubmit="return confirm('Toggle status for {{ $c->name }}?');">@csrf @method('DELETE')<button class="btn btn-ghost btn-sm" title="Toggle active" style="color:var(--amber);"><i class="fas fa-power-off"></i></button></form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" style="text-align:center;padding:40px;color:var(--text3);">No tenants yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
