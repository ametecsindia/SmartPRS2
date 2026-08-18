@extends('settings.layout')

@section('title', 'API Keys')
@section('nav_keys', 'on')

@section('content')

<h1>API Keys</h1>
<p class="sub">Credentials for Smart Biometric Bridge and any other machine-to-machine caller.</p>

@if (session('key_error'))
    <div class="note err"><p>{{ session('key_error') }}</p></div>
@endif
@if (session('key_notice'))
    <div class="note info"><p>{{ session('key_notice') }}</p></div>
@endif

@if (session('new_key_secret'))
    <div class="card" style="border:2px solid #16a34a;">
        <h2>Key created — copy it now</h2>
        <p class="muted">
            This is the only time <strong>{{ session('new_key_name') }}</strong> will be shown.
            SmartPRS stores only a hash of it, so it cannot be shown again or recovered. If you
            lose it, revoke the key and create another.
        </p>
        <div class="fieldset">
            <label class="fld">API key</label>
            <input type="text" readonly value="{{ session('new_key_secret') }}" onclick="this.select();">
        </div>
        <div class="fieldset">
            <label class="fld">Server address for the bridge</label>
            <input type="text" readonly value="{{ $conn['base'] }}" onclick="this.select();">
        </div>
        <div class="fieldset">
            <label class="fld">Confirm you are pointed at the right customer — paste into a terminal</label>
            <input type="text" readonly onclick="this.select();"
                   value="curl -H &quot;X-Api-Key: {{ session('new_key_secret') }}&quot; {{ $conn['ping'] }}">
        </div>
        <p class="muted small" style="margin-bottom:0;">
            In the bridge, this goes into <em>Server &rarr; API Key</em>. It is sent as the
            <code>X-Api-Key</code> header; <code>Authorization: Bearer &lt;key&gt;</code> also works.
        </p>
    </div>
@endif

{{-- Connection details are useful even before the tables exist. --}}
<div class="card">
    <h2>Connection details for Smart Biometric Bridge</h2>
    <p class="muted">These are the addresses to enter in the bridge. Click a box to select it, then copy.</p>

    @foreach ([
        ['Server / base URL',     $conn['base'],              'Use this if the bridge asks for one address only.'],
        ['Test connection',       'GET  ' . $conn['ping'],    'Open with the key to confirm the right customer.'],
        ['Send punches to',       'POST ' . $conn['punches'], 'Where the bridge posts each batch.'],
        ['Authentication header', $conn['header'],            'Alternative: ' . $conn['headerAlt']],
    ] as [$label, $value, $hint])
        <div class="fieldset">
            <label class="fld">{{ $label }}</label>
            <input type="text" readonly value="{{ $value }}" onclick="this.select();">
            <div class="muted small">{{ $hint }}</div>
        </div>
    @endforeach

    <p class="muted small" style="margin-bottom:6px;">
        <strong>Server timezone:</strong> {{ $conn['timezone'] }} &nbsp;&nbsp;
        <strong>Version:</strong> {{ $conn['version'] ?: '—' }}
    </p>
    <p class="muted small" style="margin-bottom:0;">
        Punch times must be sent as plain local wall clock, <code>YYYY-MM-DD HH:MM:SS</code>, with
        <strong>no timezone offset</strong>. A time carrying <code>+05:30</code> or <code>Z</code> is
        rejected rather than guessed at, so it can never land in payroll at the wrong hour. Check the
        device clock matches the timezone above.
    </p>

    @unless ($conn['secure'])
        <div class="note err" style="margin:14px 0 0;">
            <p><strong>This address is not HTTPS.</strong> An API key sent over plain HTTP can be read
            in transit. Set <code>APP_URL</code> to the https:// address and install a certificate
            before giving this to a site.</p>
        </div>
    @endunless
</div>

@if (! empty($notReady))

    <div class="note warn">
        <p><strong>Not set up yet.</strong> The <code>api_keys</code> table has not been created in this
        database. Run <code>php artisan migrate</code>, then reload this page. Nothing else needs doing.</p>
    </div>

@else

    <div class="card">
        <h2>Create a key</h2>
        <p class="muted">One key per site keeps the audit trail readable and lets you revoke a single
        plant without disturbing the others.</p>

        <form method="POST" action="{{ route('app.apikeys.store') }}">
            @csrf
            <div class="row">
                <div style="flex:2 1 250px;">
                    <label class="fld">Name</label>
                    <input type="text" name="name" required placeholder="SBB — Chennai Plant">
                </div>
                <div style="flex:1 1 170px;">
                    <label class="fld">Scopes</label>
                    @foreach ($scopes as $s)
                        <label style="margin-right:14px; white-space:nowrap; font-weight:500;">
                            <input type="checkbox" name="scopes[]" value="{{ $s }}" @checked($s === 'ingest')>
                            {{ $s }}
                        </label>
                    @endforeach
                </div>
                <div style="flex:1 1 150px;">
                    <label class="fld">Expires <span class="muted small">(optional)</span></label>
                    <input type="date" name="expires_at">
                </div>
                <div><button type="submit" class="btn btn-primary">Create key</button></div>
            </div>
        </form>
    </div>

    <div class="card">
        <h2>Keys</h2>
        @if ($keys->isEmpty())
            <p class="muted" style="margin-bottom:0;">No keys yet. Create one above, then paste it into
            Smart Biometric Bridge.</p>
        @else
            <table>
                <thead>
                    <tr>
                        <th>Name</th><th>Prefix</th><th>Scopes</th>
                        <th>Last used</th><th>Expires</th><th>Status</th><th></th>
                    </tr>
                </thead>
                <tbody>
                @foreach ($keys as $k)
                    <tr>
                        <td>{{ $k['name'] }}</td>
                        <td class="mono">{{ $k['prefix'] }}…</td>
                        <td>{{ $k['scopes'] }}</td>
                        <td class="{{ $k['last_used_at'] === 'never' ? 'muted' : '' }}">{{ $k['last_used_at'] }}</td>
                        <td>{{ $k['expires_at'] }}</td>
                        <td>
                            @if (! $k['active'])
                                <span class="pill red">revoked</span>
                            @elseif ($k['expired'])
                                <span class="pill amber">expired</span>
                            @else
                                <span class="pill green">active</span>
                            @endif
                        </td>
                        <td style="text-align:right;">
                            @if ($k['active'])
                                <form method="POST" action="{{ route('app.apikeys.revoke', $k['id']) }}"
                                      onsubmit="return confirm('Revoke this key? Any bridge using it stops sending immediately.');">
                                    @csrf
                                    <button type="submit" class="btn btn-link">Revoke</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </div>

@endif

@endsection
