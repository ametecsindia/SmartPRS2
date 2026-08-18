@extends('settings.layout')

@section('title', 'Pending Punches')
@section('nav_pending', 'on')

@section('content')

<h1>Pending Punches</h1>
<p class="sub">Punches received from a device whose code is not yet linked to an employee.</p>

@if (session('key_error'))
    <div class="note err"><p>{{ session('key_error') }}</p></div>
@endif
@if (session('key_notice'))
    <div class="note ok"><p>{{ session('key_notice') }}</p></div>
@endif

{{-- Unassigned devices first: until a device is claimed, every punch it sends
     lands in the quarantine below, so this is the thing to fix first. --}}
@if ($unassigned->isNotEmpty())
    <div class="card" style="border-left:4px solid #d97706;">
        <h2>Unassigned devices &mdash; {{ $unassigned->count() }}</h2>
        <p class="muted">
            These devices contacted the server before anyone configured them, so they belong to no
            workspace yet. A device with no workspace can only match employees with no workspace, so
            until you claim it <strong>everything it sends is held below rather than filed</strong>.
            Claim it and its punches match your employees from the next upload.
        </p>
        <table>
            <thead>
                <tr><th>Serial</th><th>Label</th><th>Type</th><th>Last contact</th><th>Assign to your workspace</th></tr>
            </thead>
            <tbody>
            @foreach ($unassigned as $d)
                <tr>
                    <td class="mono" style="font-weight:700;">{{ $d->serial_number ?: '—' }}</td>
                    <td>{{ $d->label ?: '—' }}</td>
                    <td class="muted">{{ $d->provider ?: '—' }}</td>
                    <td class="muted small">{{ $d->last_sync_at ? substr((string) $d->last_sync_at, 0, 16) : 'never' }}</td>
                    <td>
                        <form method="POST" action="{{ route('app.pending.assign') }}"
                              style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;"
                              onsubmit="return confirm('Assign this device to your workspace?');">
                            @csrf
                            <input type="hidden" name="device_row_id" value="{{ $d->id }}">
                            @if ($companies->isNotEmpty())
                                <select name="company_id" style="min-width:170px; width:auto;">
                                    <option value="">Company (optional)…</option>
                                    @foreach ($companies as $c)
                                        <option value="{{ $c->id }}">{{ $c->name }}</option>
                                    @endforeach
                                </select>
                            @endif
                            <button type="submit" class="btn btn-primary">Assign</button>
                        </form>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
@endif

<div class="card">
    <h2>Punches waiting for a mapping</h2>
    <p class="muted" style="margin-bottom:0;">
        These arrived with a device code that matches no employee yet — typically during go-live,
        before the codes have been mapped. They are <strong>held, not discarded</strong>. Map a code
        to an employee and every punch listed against it moves into attendance immediately, backdated
        to when it actually happened.
    </p>
    @if ($totalHeld)
        <p style="margin:12px 0 0; font-weight:600;">
            {{ $totalHeld }} punch(es) held across {{ $rows->count() }} code/device combination(s).
        </p>
    @endif
</div>

<div class="card">
    @if ($rows->isEmpty())
        <p class="muted" style="margin-bottom:0;">
            Nothing pending. Every punch received so far has been matched to an employee.
        </p>
    @else
        <table>
            <thead>
                <tr>
                    <th>Device code</th><th>Raw PIN</th><th>Device</th><th>Held</th>
                    <th>First</th><th>Latest</th><th>Map to employee</th>
                </tr>
            </thead>
            <tbody>
            @foreach ($rows as $r)
                <tr>
                    <td class="mono" style="font-weight:700;">{{ $r->device_code ?: $r->device_user_id }}</td>
                    <td class="mono muted">{{ $r->device_user_id ?: '—' }}</td>
                    <td class="muted">{{ $r->device_sn ?: '—' }}</td>
                    <td>{{ $r->punches }}</td>
                    <td class="muted small">{{ substr((string) $r->first_seen, 0, 16) }}</td>
                    <td class="muted small">{{ substr((string) $r->last_seen, 0, 16) }}</td>
                    <td>
                        <form method="POST" action="{{ route('app.pending.map') }}"
                              style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                            @csrf
                            <input type="hidden" name="device_id" value="{{ $r->device_code ?: $r->device_user_id }}">
                            {{-- Confines the promotion to THIS device: two devices
                                 can both have PIN 5 and they are different people. --}}
                            <input type="hidden" name="device_sn" value="{{ $r->device_sn }}">
                            <select name="emp_code" required style="min-width:190px; width:auto;">
                                <option value="">Choose employee…</option>
                                @foreach ($employees as $e)
                                    <option value="{{ $e->emp_code }}">{{ $e->name }} ({{ $e->emp_code }})</option>
                                @endforeach
                            </select>
                            <label class="muted small" style="white-space:nowrap;"
                                   title="Reassign this code if another employee already holds it">
                                <input type="checkbox" name="force" value="1"> move it
                            </label>
                            <button type="submit" class="btn btn-primary">Map</button>
                        </form>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif
</div>

@endsection
