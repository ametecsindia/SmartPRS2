@extends('admin.layout')
@section('title', 'Platform Staff')
@section('nav_staff', 'active')

@section('content')
    <h1>Platform Staff</h1>
    <p class="sub">People who manage the SmartPRS platform itself (not tied to any client company).</p>

    <div class="card">
        <h3><i class="fas fa-user-plus" style="color:var(--accent)"></i> {{ $editing ? 'Edit staff member' : 'Add staff member' }}</h3>

        @if ($errors->any())
            <div style="background:rgba(239,68,68,.1);color:#dc2626;padding:10px 14px;border-radius:9px;margin-bottom:14px;font-size:13px;">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ $editing ? route('admin.staff.update', $editing) : route('admin.staff.store') }}">
            @csrf
            @if ($editing) @method('PUT') @endif
            <div class="grid c3">
                <div class="fg"><label>Name</label><input name="name" value="{{ old('name', $editing->name ?? '') }}" required></div>
                <div class="fg"><label>Email</label><input type="email" name="email" value="{{ old('email', $editing->email ?? '') }}" required></div>
                <div class="fg">
                    <label>Role</label>
                    <select name="role">
                        @foreach ($roles as $val => $lbl)
                            <option value="{{ $val }}" @selected(($editing && $editing->hasRole($val)) || old('role') === $val)>{{ $lbl }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="fg"><label>Password {{ $editing ? '(leave blank to keep)' : '' }}</label><input type="password" name="password" {{ $editing ? '' : 'required' }}></div>
            </div>
            <div style="display:flex;gap:10px;margin-top:8px;">
                @if ($editing)<a href="{{ route('admin.staff') }}" class="btn btn-outline">Cancel</a>@endif
                <button class="btn btn-primary"><i class="fas fa-check"></i> {{ $editing ? 'Save changes' : 'Add staff' }}</button>
            </div>
        </form>
    </div>

    <table>
        <thead><tr><th>Name</th><th>Email</th><th>Role</th><th style="text-align:right;">Actions</th></tr></thead>
        <tbody>
            @forelse ($staff as $u)
                <tr>
                    <td><strong>{{ $u->name }}</strong></td>
                    <td>{{ $u->email }}</td>
                    <td><span class="pill">{{ ucwords(str_replace('_',' ', $u->getRoleNames()->first() ?? '—')) }}</span></td>
                    <td style="text-align:right;white-space:nowrap;">
                        <a href="{{ route('admin.staff', ['edit' => $u->id]) }}" class="btn btn-outline" style="padding:6px 12px;">Edit</a>
                        <form method="POST" action="{{ route('admin.staff.destroy', $u) }}" style="display:inline;" onsubmit="return confirm('Remove {{ $u->name }}?');">
                            @csrf @method('DELETE')
                            <button class="btn btn-outline" style="padding:6px 12px;color:var(--red);border-color:var(--red);">Remove</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="4" style="text-align:center;padding:30px;color:var(--text3);">No platform staff yet.</td></tr>
            @endforelse
        </tbody>
    </table>
@endsection
