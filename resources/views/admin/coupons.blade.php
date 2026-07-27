@extends('admin.layout')

@section('title', 'Coupons')
@section('nav_coupons', 'active')

@section('content')
<h1>Discount Coupons</h1>
<p class="sub">Create codes for campaigns — signup and renewal discounts, tracked per redemption. Share a code on WhatsApp, in an ad, or with a partner, and see exactly which clients it brought.</p>

@if ($errors->any())
    <div class="flash" style="background:rgba(239,68,68,.1);color:#b91c1c;"><i class="fas fa-triangle-exclamation"></i> {{ $errors->first() }}</div>
@endif

<div class="card">
    <h3><i class="fas fa-ticket" style="color:var(--accent);margin-right:6px;"></i>New coupon</h3>
    <form method="POST" action="{{ route('admin.coupons.save') }}">
        @csrf
        <div class="grid c3">
            <div class="fg"><label>Code (what the client types)</label><input name="code" placeholder="e.g. LAUNCH25" maxlength="40" required style="text-transform:uppercase"></div>
            <div class="fg"><label>Discount type</label><select name="type"><option value="percent">Percentage (%)</option><option value="flat">Flat amount (₹)</option></select></div>
            <div class="fg"><label>Value (25 = 25% or ₹25)</label><input name="value" type="number" step="0.01" min="0.01" required></div>
        </div>
        <div class="grid c3">
            <div class="fg"><label>Valid till (blank = no expiry)</label><input name="valid_till" type="date"></div>
            <div class="fg"><label>Max total uses (blank = unlimited)</label><input name="max_uses" type="number" min="1" placeholder="e.g. 50"></div>
            <div class="fg"><label>Minimum advance period</label><select name="min_cycle"><option value="">Any</option><option value="quarterly">Quarterly</option><option value="halfyear">Half-yearly</option><option value="annual">Annual only</option></select></div>
        </div>
        <div class="grid c3">
            <div class="fg"><label>Applies to</label><select name="applies_to"><option value="both">Signup + renewals</option><option value="signup">New signups only</option><option value="renewal">Renewals only</option></select></div>
            <div class="fg"><label>Plans (none ticked = all plans)</label>
                <div style="display:flex;gap:14px;flex-wrap:wrap;padding:10px 2px;">
                    @foreach ($plans as $p)
                        <label style="display:flex;align-items:center;gap:6px;text-transform:none;font-size:13px;font-weight:500;cursor:pointer;"><input type="checkbox" name="plan_ids[]" value="{{ $p->id }}" style="width:15px;height:15px;"> {{ $p->name }}</label>
                    @endforeach
                </div>
            </div>
            <div class="fg"><label>One use per customer</label>
                <label style="display:flex;align-items:center;gap:8px;text-transform:none;font-size:13px;font-weight:500;padding:10px 2px;cursor:pointer;"><input type="checkbox" name="once_per_customer" value="1" checked style="width:15px;height:15px;"> Same email/workspace cannot reuse</label>
            </div>
        </div>
        <div class="fg"><label>Campaign note (internal — e.g. "June LinkedIn ads")</label><input name="notes" maxlength="255"></div>
        <button class="btn btn-primary" style="margin-top:8px;"><i class="fas fa-plus"></i> Create coupon</button>
    </form>
</div>

{{-- rev 113: exclusive offer sent to ONE email — auto-applies on the cart --}}
<div class="card">
    <h3><i class="fas fa-envelope-circle-check" style="color:var(--accent);margin-right:6px;"></i>Send an exclusive offer by email</h3>
    <p class="sub" style="margin-bottom:14px;">Creates a single-use coupon locked to one email and sends it. When that person types this email at signup, renewal or quotation, the discount applies <b>automatically</b> — no code typing needed.</p>
    <form method="POST" action="{{ route('admin.coupons.exclusive') }}">
        @csrf
        <div class="grid c3">
            <div class="fg"><label>Send to (any email)</label><input name="email" type="email" required placeholder="client@company.com"></div>
            <div class="fg"><label>Name (for the email greeting)</label><input name="name" maxlength="120" placeholder="e.g. Rajesh"></div>
            <div class="fg"><label>Applies to</label><select name="applies_to"><option value="both">Signup + renewal</option><option value="signup">New signup only</option><option value="renewal">Renewal only</option></select></div>
        </div>
        <div class="grid c3">
            <div class="fg"><label>Discount type</label><select name="type"><option value="percent">Percentage (%)</option><option value="flat">Flat amount (₹)</option></select></div>
            <div class="fg"><label>Value</label><input name="value" type="number" step="0.01" min="0.01" required></div>
            <div class="fg"><label>Valid for (days)</label><input name="valid_days" type="number" min="1" max="365" value="10" required></div>
        </div>
        <div class="fg"><label>Note (internal)</label><input name="notes" maxlength="200" placeholder="e.g. negotiated on call 8 Jun"></div>
        <button class="btn btn-primary" style="margin-top:8px;"><i class="fas fa-paper-plane"></i> Create &amp; send offer</button>
    </form>
</div>

<div class="card" style="padding:0;overflow-x:auto;">
    <table>
        <tr><th>Code</th><th>Discount</th><th>Scope</th><th>Validity</th><th>Used</th><th>Status</th><th>Note</th><th></th></tr>
        @forelse ($coupons as $c)
            <tr>
                <td><b>{{ $c->code }}</b></td>
                <td>{{ $c->type === 'flat' ? '₹'.number_format((float) $c->value) : rtrim(rtrim(number_format((float) $c->value, 2), '0'), '.').'%' }} off</td>
                <td style="font-size:12.5px;color:var(--text2);">
                    @if (!empty($c->exclusive_email))<span class="pill" style="background:rgba(139,92,246,.12);color:#7c3aed;">EXCLUSIVE</span> {{ $c->exclusive_email }}<br>@endif
                    {{ ['both' => 'Signup + renewal', 'signup' => 'Signup only', 'renewal' => 'Renewal only'][$c->applies_to] ?? $c->applies_to }}
                    @if ($c->min_cycle) · min {{ ['quarterly' => 'quarterly', 'halfyear' => 'half-yearly', 'annual' => 'annual'][$c->min_cycle] }} @endif
                    @if ($c->plan_ids) · plans {{ $c->plan_ids }} @endif
                    @if ($c->once_per_customer) · once/customer @endif
                </td>
                <td>{{ $c->valid_till ? \Carbon\Carbon::parse($c->valid_till)->format('d M Y') : 'No expiry' }}</td>
                <td>{{ $c->used_count }}{{ $c->max_uses ? ' / '.$c->max_uses : '' }}</td>
                <td><span class="pill" style="{{ $c->status === 'active' ? 'background:rgba(16,185,129,.12);color:#059669;' : 'background:rgba(148,163,184,.18);color:#64748b;' }}">{{ strtoupper($c->status) }}</span></td>
                <td style="font-size:12.5px;color:var(--text3);">{{ $c->notes }}</td>
                <td style="white-space:nowrap;">
                    <form method="POST" action="{{ route('admin.coupons.toggle', $c->id) }}" style="display:inline;">@csrf<button class="btn btn-outline" style="padding:6px 10px;font-size:12px;">{{ $c->status === 'active' ? 'Disable' : 'Enable' }}</button></form>
                    <form method="POST" action="{{ route('admin.coupons.delete', $c->id) }}" style="display:inline;" onsubmit="return confirm('Delete coupon {{ $c->code }}?');">@csrf<button class="btn btn-outline" style="padding:6px 10px;font-size:12px;color:var(--red);">Delete</button></form>
                </td>
            </tr>
        @empty
            <tr><td colspan="8" style="text-align:center;color:var(--text3);padding:26px;">No coupons yet — create your first campaign code above.</td></tr>
        @endforelse
    </table>
</div>

<div class="card" style="padding:0;overflow-x:auto;">
    <table>
        <tr><th colspan="6" style="font-size:12px;">Redemptions (latest 100)</th></tr>
        <tr><th>When</th><th>Code</th><th>Context</th><th>Company / Email</th><th>Workspace</th><th>Discount given</th></tr>
        @forelse ($redemptions as $r)
            <tr>
                <td>{{ \Carbon\Carbon::parse($r->created_at)->format('d M Y H:i') }}</td>
                <td><b>{{ $r->code }}</b></td>
                <td>{{ ucfirst($r->context) }}</td>
                <td style="font-size:12.5px;">{{ $r->company }}<br><span style="color:var(--text3);">{{ $r->email }}</span></td>
                <td>{{ $r->tenant_id ? '#'.$r->tenant_id : '—' }}</td>
                <td>₹{{ number_format((float) $r->amount_discounted, 2) }}</td>
            </tr>
        @empty
            <tr><td colspan="6" style="text-align:center;color:var(--text3);padding:26px;">No redemptions yet.</td></tr>
        @endforelse
    </table>
</div>
@endsection
