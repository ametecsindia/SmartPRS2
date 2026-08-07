@extends('admin.layout')
@section('title', 'On-Prem Clients')
@section('nav_onprem', 'active')

@section('content')
    <h1>On-Prem Clients &amp; Licences</h1>
    <p class="sub">The perpetual-licence sales desk: record the client → record payments → generate the key (full payment, or partial with your tick) → the key is emailed and shown here for the installing engineer. AMC renewals and server moves are managed here too.</p>

    @if (session('success'))
        <div class="flash">{{ session('success') }}</div>
    @endif

    <details style="margin-bottom:22px;" {{ request('edit') ? 'open' : '' }}>
        <summary style="cursor:pointer;font-weight:700;color:var(--accent);margin-bottom:10px;">+ New client</summary>
        <form method="POST" action="{{ route('admin.onprem.save') }}" style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:18px;display:grid;grid-template-columns:repeat(3,1fr);gap:12px;">
            @csrf
            <label>Company *<input name="company" required></label>
            <label>Contact person<input name="contact_name"></label>
            <label>Email (key &amp; updates go here)<input type="email" name="email"></label>
            <label>Mobile<input name="mobile"></label>
            <label>GSTIN<input name="gstin"></label>
            <label>State<input name="state"></label>
            <label>Edition *
                <select name="edition"><option value="l1">SmartPRS-L1 (Core)</option><option value="l2">SmartPRS-L2 (Advanced)</option><option value="l3">SmartPRS-L3 (Collections DNA)</option></select>
            </label>
            <label>Employee band<input name="employee_band" placeholder="up to 250"></label>
            <label>Licence price (₹)<input type="number" step="0.01" name="price"></label>
            <label>AMC %<input type="number" step="0.01" name="amc_percent" value="18"></label>
            {{-- rev140 — Super Admin sets HOW LONG the client may use the app.
                 A duration, OR an exact expiry date (the date wins if both set).
                 This becomes the licence's expiry, enforced at the client login. --}}
            <label>Access validity
                <select name="licence_term_months">
                    <option value="12">1 year</option>
                    <option value="24">2 years</option>
                    <option value="36">3 years</option>
                    <option value="60">5 years</option>
                    <option value="6">6 months</option>
                    <option value="3">3 months</option>
                    <option value="1">1 month</option>
                    <option value="">Use exact date →</option>
                </select>
            </label>
            <label>Or exact expiry date<input type="date" name="licence_expires_on"></label>
            <label>On expiry (client login)
                <select name="expiry_mode">
                    <option value="renew">Show License Code field (client can renew)</option>
                    <option value="notify">Show "LC Expired" notice only</option>
                </select>
            </label>
            <label style="grid-column:span 3;">&nbsp;<span style="display:block;font-weight:400;color:#94a3b8;font-size:11px;margin-top:4px;line-height:1.4;">Validity is applied when the key is generated — leave the date blank to use the duration. "On expiry" controls what the client sees at login once the licence lapses: a renewal field, or just a notice to contact you.</span></label>
            <label style="grid-column:span 2;">Address<input name="address"></label>
            <label style="grid-column:span 3;">Notes<input name="notes"></label>
            <div><button class="btn btn-primary" type="submit">Save client</button></div>
        </form>
    </details>

    <form method="GET" action="{{ route('admin.onprem') }}" style="display:flex;gap:8px;align-items:center;margin-bottom:18px;flex-wrap:wrap;">
        <input type="text" name="q" value="{{ $q ?? '' }}" placeholder="Search company, contact, email, mobile or GSTIN…" style="flex:1;min-width:240px;padding:10px 14px;border:1.5px solid #cbd5e1;border-radius:10px;font-size:14px;">
        <button class="btn btn-primary" type="submit"><i class="fas fa-magnifying-glass"></i> Search</button>
        @if (!empty($q ?? ''))
            <a class="btn btn-outline" href="{{ route('admin.onprem') }}">Clear</a>
            <span style="font-size:12px;color:#64748b;">{{ $clients->count() }} match{{ $clients->count() === 1 ? '' : 'es' }} for “{{ $q }}”</span>
        @endif
    </form>

    @forelse ($clients as $c)
        @php
            $lics = $licences->get($c->id, collect());
            $live = $lics->whereIn('status', ['pending', 'active'])->first();
            $pays = $payments->get($c->id, collect());
            $tot = \App\Http\Controllers\OnpremClientController::totals($c);
            $fullyPaid = \App\Http\Controllers\OnpremClientController::fullyPaid($c);
        @endphp
        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:16px 18px;margin-bottom:14px;">
            <div style="display:flex;justify-content:space-between;flex-wrap:wrap;gap:8px;align-items:center;">
                <div>
                    <strong style="font-size:15px;">{{ $c->company }}</strong>
                    <span style="background:#0c1929;color:#fff;border-radius:6px;font-size:11px;font-weight:700;padding:2px 8px;margin-left:8px;">SmartPRS-{{ strtoupper($c->edition) }}</span>
                    @if ($live)
                        <span style="background:{{ $live->status === 'active' ? '#16a34a' : '#f59e0b' }};color:#fff;border-radius:6px;font-size:11px;font-weight:700;padding:2px 8px;margin-left:4px;">{{ strtoupper($live->status) }}{{ $live->key_last4 ? ' ·…'.$live->key_last4 : '' }}</span>
                        <span style="font-size:11px;color:#64748b;margin-left:6px;">Valid till {{ $live->amc_expires_on ?: '—' }}</span>
                    @endif
                </div>
                <div style="font-size:12px;color:#64748b;">{{ $c->contact_name }} · {{ $c->email }} · {{ $c->mobile }}</div>
            </div>
            <div style="display:flex;gap:18px;flex-wrap:wrap;margin-top:10px;font-size:13px;align-items:center;">
                <span>Price: <strong>₹{{ number_format((float) $c->price) }}</strong> <span style="color:#64748b;">(+GST = ₹{{ number_format($tot['total']) }})</span></span>
                <span>Paid: <strong style="color:{{ $fullyPaid ? '#16a34a' : '#f59e0b' }};">₹{{ number_format((float) $c->paid_total) }}</strong> ({{ $pays->count() }} payment{{ $pays->count() === 1 ? '' : 's' }})</span>
                <span>Balance: <strong>₹{{ number_format($tot['balance']) }}</strong></span>
                <span>AMC: {{ $c->amc_percent }}%</span>
                @if ($c->invoice_no ?? false)
                    <span style="color:#64748b;">{{ $c->invoice_no }} · <a href="{{ url('/licence/'.$c->invoice_token) }}" target="_blank" style="color:var(--accent);">pay link</a></span>
                @endif
                @if (! $fullyPaid)
                    <form method="POST" action="{{ route('admin.onprem.partial', $c->id) }}" style="display:inline;">@csrf
                        <button class="btn btn-outline" style="font-size:11px;">{{ $c->activate_on_partial ? '✓ Partial-activation ON — click to remove' : 'Allow activation on partial payment' }}</button>
                    </form>
                @endif
            </div>
            <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px;align-items:flex-end;">
                <form method="POST" action="{{ route('admin.onprem.payment', $c->id) }}" style="display:flex;gap:6px;align-items:flex-end;flex-wrap:wrap;">@csrf
                    <label style="font-size:11px;">Amount<br><input type="number" step="0.01" name="amount" style="width:110px;" required></label>
                    <label style="font-size:11px;">Mode<br><select name="mode" style="width:100px;"><option>neft</option><option>cheque</option><option>upi</option><option>gateway</option><option>cash</option></select></label>
                    <label style="font-size:11px;">Reference<br><input name="reference" style="width:140px;"></label>
                    <button class="btn btn-outline" type="submit">Record payment</button>
                </form>
                <form method="POST" action="{{ route('admin.onprem.invoice', $c->id) }}" style="display:inline;">@csrf
                    <button class="btn btn-outline" type="submit">{{ ($c->invoice_no ?? false) ? 'Re-send invoice + pay link' : 'Email invoice + pay link' }}</button>
                </form>
                {{-- AS-DL cutover: the legacy SPRSX1 "Generate offline LC" control is retired.
                     Offline licensing is RSA .lic only (below). --}}
                {{-- AS-DL: RSA-signed, node-locked .lic — bound to the client's machine
                     fingerprint (shown on their Activation screen). Downloads <KEY>.lic. --}}
                <form method="POST" action="{{ route('admin.onprem.licfile', $c->id) }}" style="display:flex;gap:6px;align-items:flex-end;flex-wrap:wrap;">@csrf
                    <label style="font-size:11px;">Machine fingerprint<br><input name="fingerprint" placeholder="Paste from client's Activation screen" style="width:250px;" title="On the client server open SmartPRS → Activation screen → copy the Machine fingerprint. Blank = works on any PC (not recommended)."></label>
                    <label style="font-size:11px;">Seats<br><input type="number" name="seats" min="0" value="0" placeholder="0" style="width:90px;" title="Employee seat cap (device_limit). 0 = unlimited."></label>
                    <button class="btn btn-outline" type="submit" title="Download a node-locked .lic bound to that fingerprint; email/hand it to the client to import on their Activation screen"><i class="fas fa-file-arrow-down"></i> Generate .lic file</button>
                </form>
                @if ($live && \App\Http\Controllers\ClientUpdateController::looksLikeLicenceFile(\App\Services\LicenseService::reveal($live) ?? ''))
                    <a class="btn btn-outline" href="{{ route('admin.onprem.licdownload', $c->id) }}" title="Re-download the last generated .lic exactly as it was issued — every generated licence file is stored on this server"><i class="fas fa-download"></i> Download .lic</a>
                @endif
                @if (! $live)
                    <form method="POST" action="{{ route('admin.onprem.key', $c->id) }}" style="display:inline;">@csrf
                        <button class="btn btn-primary" type="submit" {{ ($fullyPaid || $c->activate_on_partial) ? '' : 'disabled title=Payment-pending' }}>Generate licence key</button>
                    </form>
                @else
                    <form method="POST" action="{{ route('admin.onprem.renew', $c->id) }}" style="display:flex;gap:6px;align-items:flex-end;flex-wrap:wrap;">@csrf
                        <label style="font-size:11px;">Extend until (optional)<br><input type="date" name="renew_until" style="width:150px;"></label>
                        <button class="btn btn-outline" type="submit">Renew licence</button>
                    </form>
                    <form method="POST" action="{{ route('admin.onprem.deactivate', $c->id) }}" style="display:inline;" onsubmit="return confirm('Release the server binding so the client can activate on a NEW server?');">@csrf<button class="btn btn-outline">Release server binding</button></form>
                    <form method="POST" action="{{ route('admin.onprem.shift', $c->id) }}" style="display:inline;" onsubmit="return confirm('Shift this licence to a NEW server? The current binding is released and the client re-binds on next check-in.');">@csrf<button class="btn btn-outline" title="Client genuinely replaced their server — release the old binding so the new one re-binds automatically">Shift machine</button></form>
                    <form method="POST" action="{{ route('admin.onprem.revoke', $c->id) }}" style="display:inline;" onsubmit="return confirm('REVOKE this licence? Activation and updates will be blocked for it.');">@csrf<button class="btn btn-outline" style="color:#dc2626;border-color:#fca5a5;">Revoke</button></form>
                @endif
                <form method="POST" action="{{ route('admin.onprem.delete', $c->id) }}" style="display:inline;margin-left:auto;" onsubmit="return confirm('DELETE {{ addslashes($c->company) }} and all its licence records here? This cannot be undone. (Tip: Revoke first if the client should be blocked.)');">@csrf<button class="btn btn-outline" style="color:#b91c1c;border-color:#fca5a5;background:#fef2f2;"><i class="fas fa-trash"></i> Delete</button></form>
            </div>
            @if ($live)
                @php
                    \$lhEv = \Illuminate\Support\Facades\DB::table('licence_events')->where('licence_id', \$live->id)->orderByDesc('id')->limit(6)->get();
                    \$lhDev = \Illuminate\Support\Facades\Schema::hasTable('licence_devices') ? \Illuminate\Support\Facades\DB::table('licence_devices')->where('licence_id', \$live->id)->where('status','active')->get() : collect();
                @endphp
                @if (!empty(\$live->last_mismatch_at))
                    <div style="margin-top:10px;background:#fef2f2;border:1px solid #fecaca;border-radius:10px;padding:10px 12px;font-size:12px;color:#b91c1c;"><i class="fas fa-triangle-exclamation"></i> <strong>Fraud alert:</strong> a DIFFERENT machine tried this licence on {{ \Illuminate\Support\Carbon::parse(\$live->last_mismatch_at)->format('d M Y H:i') }}. If the client replaced their PC, use <strong>Shift machine</strong>; otherwise investigate a possible cloned/old install.</div>
                @endif
                <details style="margin-top:10px;">
                    <summary style="cursor:pointer;font-size:12px;color:#334155;font-weight:600;">Licence History &amp; bound servers ({{ \$lhDev->count() }}/{{ (int)(\$live->server_limit ?? 1) }} server seat(s) used)</summary>
                    <div style="margin-top:6px;font-size:12px;color:#475569;">
                        <div style="margin-bottom:6px;"><strong>Bound servers:</strong> @forelse(\$lhDev as \$d){{ \$d->hostname ?: 'server' }} <span style="font-family:Consolas,monospace;color:#94a3b8;">({{ \Illuminate\Support\Str::limit(\$d->device_uid,16,'…') }})</span>@if(!\$loop->last), @endif @empty <span style="color:#94a3b8;">none bound yet</span>@endforelse</div>
                        <table style="width:100%;border-collapse:collapse;"><tbody>
                        @forelse(\$lhEv as \$e)
                            <tr><td style="padding:2px 6px;white-space:nowrap;color:#94a3b8;">{{ \Illuminate\Support\Carbon::parse(\$e->created_at)->format('d M H:i') }}</td><td style="padding:2px 6px;font-weight:600;color:{{ in_array(\$e->type,['rejected','denied'])?'#dc2626':(in_array(\$e->type,['verified','activated','machine_bound'])?'#059669':'#334155') }};">{{ \$e->type }}</td><td style="padding:2px 6px;color:#64748b;">{{ \Illuminate\Support\Str::limit(\$e->detail,80) }}</td></tr>
                        @empty
                            <tr><td colspan="3" style="padding:4px 6px;color:#94a3b8;">No history yet.</td></tr>
                        @endforelse
                        </tbody></table>
                    </div>
                </details>
            @endif
            @if ($live && $revealId === (int) $c->id)
                <div style="margin-top:12px;background:#fff7ed;border:1px solid #fed7aa;border-radius:10px;padding:12px 14px;font-family:Consolas,monospace;font-size:16px;letter-spacing:1px;">
                    {{ \App\Services\LicenseService::reveal($live) ?? 'Could not decrypt the key on this server.' }}
                    <div style="font-size:11px;color:#9a3412;font-family:inherit;margin-top:4px;">Shown because you just generated it — note it in the installation record. It was also emailed{{ $c->email ? ' to '.$c->email : '' }}.</div>
                </div>
            @endif
            @if (session('offline_key') && (int) session('offline_key_id') === (int) $c->id)
                <div style="margin-top:12px;background:#ecfdf5;border:1px solid #a7f3d0;border-radius:10px;padding:12px 14px;">
                    <div style="font-size:12px;font-weight:700;color:#047857;margin-bottom:6px;"><i class="fas fa-key"></i> Offline License Code — give this to the client</div>
                    <textarea readonly onclick="this.select()" style="width:100%;border:1px solid #6ee7b7;border-radius:8px;padding:8px 10px;font-family:Consolas,monospace;font-size:13px;word-break:break-all;resize:vertical;min-height:54px;">{{ session('offline_key') }}</textarea>
                    <div style="font-size:11px;color:#065f46;margin-top:4px;">Works on the client PC with no internet. Click to select, then copy. To extend later, change the validity above and generate again.{{ $c->email ? ' Also emailed to '.$c->email.'.' : '' }}</div>
                </div>
            @endif
        </div>
    @empty
        <p style="color:#64748b;">No on-prem clients yet — add the first one above.</p>
    @endforelse

    <style>
        label { font-size: 12px; color: #475569; font-weight: 600; display: block; }
        label input, label select { width: 100%; padding: 8px 10px; border: 1.5px solid #cbd5e1; border-radius: 8px; font-size: 13px; margin-top: 4px; }
    </style>
@endsection
