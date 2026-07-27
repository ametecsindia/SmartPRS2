@extends('layouts.app')

@section('title', 'Departments')
@section('nav_departments', 'active')

@section('content')
    @if (session('success'))
        <div style="background:var(--green-soft);color:#059669;font-size:13px;padding:12px 16px;border-radius:10px;margin-bottom:18px;font-family:var(--font2);display:flex;align-items:center;gap:8px;">
            <i class="fas fa-circle-check"></i> {{ session('success') }}
        </div>
    @endif

    <div class="page-header">
        <div>
            <h2>Departments</h2>
            <p>{{ auth()->user()->company?->name ?? 'All companies — cross-tenant view' }}</p>
        </div>
        <a href="{{ route('departments.create') }}" class="btn btn-primary"><i class="fas fa-plus"></i> Add Department</a>
    </div>

    <div class="card">
        <div class="card-header">
            <div>
                <h3>Department Directory</h3>
                <p>{{ $departments->count() }} departments · {{ $departments->sum('employees_count') }} people assigned</p>
            </div>
        </div>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr><th>Department</th><th>Code</th><th>Employees</th><th>Status</th><th style="text-align:right;">Actions</th></tr>
                </thead>
                <tbody>
                    @forelse ($departments as $d)
                        <tr>
                            <td>
                                <strong>{{ $d->name }}</strong>
                                @if ($d->description)<div style="font-size:11px;color:var(--text3);">{{ $d->description }}</div>@endif
                            </td>
                            <td>{{ $d->code ?? '—' }}</td>
                            <td><span class="badge badge-blue">{{ $d->employees_count }}</span></td>
                            <td><span class="badge {{ $d->is_active ? 'badge-green' : 'badge-gray' }}">{{ $d->is_active ? 'Active' : 'Inactive' }}</span></td>
                            <td style="text-align:right;white-space:nowrap;">
                                <a href="{{ route('departments.edit', $d) }}" class="btn btn-ghost btn-sm" title="Edit"><i class="fas fa-pen"></i></a>
                                <form method="POST" action="{{ route('departments.destroy', $d) }}" style="display:inline;" onsubmit="return confirm('Remove {{ $d->name }}?');">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="btn btn-ghost btn-sm" title="Remove" style="color:var(--red);"><i class="fas fa-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" style="text-align:center;padding:40px;color:var(--text3);">No departments yet. <a href="{{ route('departments.create') }}" style="color:var(--accent);">Add your first →</a></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
