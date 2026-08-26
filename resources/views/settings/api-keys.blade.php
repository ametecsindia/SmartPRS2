@extends('settings.layout')

@section('title', 'API Keys')
@section('nav_keys', 'on')

@section('content')

<h1>API Keys</h1>
<p class="sub">Credentials for SmartEPT and any other machine-to-machine caller that pushes attendance into SmartPRS.</p>

@if (session('key_error'))
    <div class="note err"><p>{{ session('key_error') }}</p></div>
@endif
@if (session('key_notice'))
    <div class="note info"><p>{{ session('key_notice') }}</p></div>
@endif

{{-- Shown once, at creation and at rotation. The secret is encrypted at rest
     rather than hashed — it has to be, to verify a signature — but it is still
     never redisplayed, so this screen never leaks a live credential. --}}
@if (session('new_webhook_secret'))
    <div class="card" style="border:2px solid #16a34a;">
        <h2>Receiver ready — copy both values now</h2>
        <p class="muted">
            This is the only time the secret for <strong>{{ session('new_webhook_name') }}</strong> will be
            shown. In SmartEPT, both go into <em>Integrations &rarr; Add target</em>.
        </p>
        <div class="fieldset">
            <label class="fld">Target URL &nbsp;<span class="muted small">(SmartEPT: "URL")</span></label>
            <input type="text" readonly value="{{ session('new_webhook_url') }}" onclick="this.select();">
        </div>
        <div class="fieldset">
            <label class="fld">Shared secret &nbsp;<span class="muted small">(SmartEPT: "Secret")</span></label>
            <input type="text" readonly value="{{ session('new_webhook_secret') }}" onclick="this.select();">
        </div>
        <p class="muted small" style="margin-bottom:0;">
            SmartEPT signs each push with this secret and sends the result as
            <code>{{ $conn['sigHeader'] }}</code>. If the two sides disagree by even one character,
            SmartPRS answers <code>401</code> and stores nothing — which is the point.
        </p>
    </div>
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
            <label class="fld">Server address</label>
            <input type="text" readonly value="{{ $conn['base'] }}" onclick="this.select();">
        </div>
        <div class="fieldset">
            <label class="fld">Confirm you are pointed at the right customer — paste into a terminal</label>
            <input type="text" readonly onclick="this.select();"
                   value="curl -H &quot;X-Api-Key: {{ session('new_key_secret') }}&quot; {{ $conn['ping'] }}">
        </div>
        <p class="muted small" style="margin-bottom:0;">
            Sent as the <code>X-Api-Key</code> header; <code>Authorization: Bearer &lt;key&gt;</code> also works.
        </p>
    </div>
@endif

{{-- ------------------------------------------------------------------------
     SmartEPT webhook receiver. This card replaced the "Connection details for
     Smart Biometric Bridge" card on 26 Aug 2026. The bridge endpoints still
     work exactly as before — /api/v1/ping and /api/v1/attendance/punches are
     untouched — this screen simply no longer advertises them.
     ------------------------------------------------------------------------ --}}
<div class="card">
    <h2>SmartEPT webhook receiver</h2>
    <p class="muted">
        SmartEPT pushes attendance <em>to</em> SmartPRS: every login and logout as it happens, and a
        summary of each finished day. Create a receiver below, then paste its URL and secret into
        SmartEPT under <em>Integrations</em>. Nothing needs to be opened or polled on this side.
    </p>

    <div class="fieldset">
        <label class="fld">Receiver address</label>
        <input type="text" readonly value="{{ $conn['receiverBase'] }}/&lt;receiver id&gt;" onclick="this.select();">
        <div class="muted small">Each receiver gets its own address. Create one below to see it.</div>
    </div>

    <p class="muted small" style="margin-bottom:6px;">
        <strong>Signature header:</strong> <code>{{ $conn['sigHeader'] }}</code> &nbsp;&nbsp;
        <strong>Event header:</strong> <code>{{ $conn['eventHeader'] }}</code> &nbsp;&nbsp;
        <strong>Server timezone:</strong> {{ $conn['timezone'] }} &nbsp;&nbsp;
        <strong>Version:</strong> {{ $conn['version'] ?: '—' }}
    </p>
    <p class="muted small" style="margin-bottom:0;">
        SmartEPT sends times with a timezone offset, e.g. <code>2026-08-26T09:41:00+05:30</code>.
        SmartPRS converts each one into <strong>{{ $conn['timezone'] }}</strong> wall clock before storing it,
        so a punch cannot land in payroll at the wrong hour. A punch that arrives twice is recognised and
        ignored rather than stored again, so it is safe for SmartEPT to re-send a day.
    </p>

    @unless ($conn['secure'])
        <div class="note err" style="margin:14px 0 0;">
            <p><strong>This address is not HTTPS.</strong> A webhook secret sent over plain HTTP can be
            read in transit. Set <code>APP_URL</code> to the https:// address and install a certificate
            before giving this to a site.</p>
        </div>
    @endunless
</div>

@if (empty($webhooksReady))

    <div class="note warn">
        <p><strong>Not set up yet.</strong> The <code>smartept_webhook_endpoints</code> table has not been
        created in this database. Run <code>php artisan migrate</code>, then reload this page.</p>
    </div>

@else

    <div class="card">
        <h2>Create a receiver</h2>
        <p class="muted">One receiver per SmartEPT installation, so you can see which one is sending and
        revoke a single site without disturbing the others.</p>

        <form method="POST" action="{{ route('app.smartept.store') }}">
            @csrf
            <div class="row">
                <div style="flex:2 1 250px;">
                    <label class="fld">Name</label>
                    <input type="text" name="name" required placeholder="SmartEPT — Head Office">
                </div>
                <div style="flex:2 1 280px;">
                    <label class="fld">Accept events</label>
                    <label style="display:block; font-weight:500;">
                        <input type="checkbox" name="events[]" value="attendance.punch" checked>
                        attendance.punch <span class="muted small">— live IN/OUT as it happens</span>
                    </label>
                    <label style="display:block; font-weight:500;">
                        <input type="checkbox" name="events[]" value="attendance.daily" checked>
                        attendance.daily <span class="muted small">— end-of-day summary</span>
                    </label>
                </div>
                <div><button type="submit" class="btn btn-primary">Create receiver</button></div>
            </div>
        </form>
    </div>

    <div class="card">
        <h2>Receivers</h2>
        @if ($webhooks->isEmpty())
            <p class="muted" style="margin-bottom:0;">No receivers yet. Create one above, then paste its
            URL and secret into SmartEPT.</p>
        @else
            <table>
                <thead>
                    <tr>
                        <th>Name</th><th>Events</th><th>Last received</th>
                        <th>Last result</th><th>Accepted</th><th>Rejected</th><th>Status</th><th></th>
                    </tr>
                </thead>
                <tbody>
                @foreach ($webhooks as $w)
                    <tr>
                        <td>
                            {{ $w['name'] }}
                            <div class="mono small muted" style="word-break:break-all;">{{ $w['url'] }}</div>
                            @if ($w['unreadable'])
                                <div class="small" style="color:#b91c1c;">
                                    Secret cannot be read on this server — <code>APP_KEY</code> has changed
                                    since it was created. Rotate it and re-paste into SmartEPT.
                                </div>
                            @endif
                        </td>
                        <td class="small">{{ $w['events'] }}</td>
                        <td class="{{ $w['last_received_at'] === 'never' ? 'muted' : '' }}">{{ $w['last_received_at'] }}</td>
                        <td class="small">{{ $w['last_event'] }}<br><span class="muted">{{ $w['last_status'] }}</span></td>
                        <td>{{ $w['accepted_count'] }}</td>
                        <td class="{{ $w['rejected_count'] > 0 ? '' : 'muted' }}">{{ $w['rejected_count'] }}</td>
                        <td>
                            @if (! $w['active'])
                                <span class="pill red">revoked</span>
                            @elseif ($w['last_received_at'] === 'never')
                                <span class="pill amber">waiting</span>
                            @else
                                <span class="pill green">active</span>
                            @endif
                        </td>
                        <td style="text-align:right; white-space:nowrap;">
                            <form method="POST" action="{{ route('app.smartept.rotate', $w['id']) }}"
                                  style="display:inline;"
                                  onsubmit="return confirm('Rotate the secret? SmartEPT is refused until the new secret is pasted into it.');">
                                @csrf
                                <button type="submit" class="btn btn-link">Rotate secret</button>
                            </form>
                            @if ($w['active'])
                                <form method="POST" action="{{ route('app.smartept.revoke', $w['id']) }}"
                                      style="display:inline;"
                                      onsubmit="return confirm('Revoke this receiver? SmartEPT stops delivering immediately.');">
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

{{-- ------------------------------------------------------------------------
     API keys — unchanged. Still the credential for Smart Biometric Bridge and
     any other caller on /api/v1/*.
     ------------------------------------------------------------------------ --}}
@if (! empty($notReady))

    <div class="note warn">
        <p><strong>Not set up yet.</strong> The <code>api_keys</code> table has not been created in this
        database. Run <code>php artisan migrate</code>, then reload this page. Nothing else needs doing.</p>
    </div>

@else

    <div class="card">
        <h2>Create a key</h2>
        <p class="muted">For a caller that authenticates with a header rather than a signature. One key
        per site keeps the audit trail readable and lets you revoke a single plant without disturbing the
        others. Base address: <code>{{ $conn['base'] }}</code></p>

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
            <p class="muted" style="margin-bottom:0;">No keys yet.</p>
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
