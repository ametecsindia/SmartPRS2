@extends('admin.layout')
@section('title', 'Quotations')
@section('nav_quotes', 'active')

@section('content')
    <h1>Quotations</h1>
    <p class="sub">Quotes sent from the signup page. The client can pay online through the public link — or YOU record an offline payment (bank transfer / UPI / cheque / cash) with status Paid, Partial or Due (credit period), and the workspace is created immediately.</p>

    <table>
        <thead>
            <tr><th>Quote #</th><th>Company / Contact</th><th>Plan</th><th>Employees</th><th>Total</th><th>Sent</th><th>Valid until</th><th>Actions</th></tr>
        </thead>
        <tbody>
            @forelse ($rows as $r)
                @php
                    $valid = $r->quoted_at ? \Carbon\Carbon::parse($r->quoted_at)->addDays($validDays) : null;
                    $expired = $valid && $valid->isPast();
                    $rTotal = round($r->amount + $r->tax, 2);
                @endphp
                <tr>
                    <td><b>{{ $r->quote_no }}</b></td>
                    <td>{{ $r->company }}<br><span style="color:var(--text2);font-size:13px;">{{ $r->admin_name }} · {{ $r->admin_email }}</span></td>
                    <td>{{ $planNames[$r->plan_id] ?? '—' }}</td>
                    <td>{{ $r->seats }}{{ ($r->companies ?? 1) > 1 ? ' · '.$r->companies.' cos' : '' }}</td>
                    <td>₹{{ number_format($rTotal, 2) }}</td>
                    <td style="white-space:nowrap;">{{ $r->quoted_at ? \Carbon\Carbon::parse($r->quoted_at)->format('d M Y') : '—' }}</td>
                    <td style="white-space:nowrap;">
                        @if($expired)<span class="pill" style="background:rgba(239,68,68,.12);color:#dc2626;">Expired {{ $valid->format('d M') }}</span>
                        @else {{ $valid ? $valid->format('d M Y') : '—' }}@endif
                    </td>
                    <td style="white-space:nowrap;">
                        <button class="btn btn-primary" style="padding:7px 12px;font-size:12.5px;"
                            onclick='qpOpen({{ $r->id }}, @json($r->quote_no), @json($r->company), {{ $rTotal }}, false, 0)'>
                            <i class="fas fa-cash-register"></i> Record payment
                        </button><br>
                        <span style="font-size:12.5px;">
                            <a href="{{ url('/quote/'.$r->quote_token) }}" target="_blank" style="color:var(--accent);">Pay page</a> ·
                            <a href="{{ url('/quote/'.$r->quote_token.'/pdf') }}" target="_blank" style="color:var(--text2);">PDF</a>
                        </span>
                    </td>
                </tr>
            @empty
                <tr><td colspan="8" style="text-align:center;color:var(--text3);padding:28px;">No open quotations. They appear here when a visitor clicks “Send a Quotation” on the signup page.</td></tr>
            @endforelse
        </tbody>
    </table>

    {{-- rev 186: credit clients — provisioned workspaces with money still outstanding. --}}
    <h1 style="margin-top:34px;font-size:19px;">Credit clients — balance outstanding</h1>
    <p class="sub">Workspaces already created on a credit period. Overdue balances show in red — follow up personally; nothing locks automatically. The client can also pay the balance online through the same quote link.</p>

    <table>
        <thead>
            <tr><th>Quote #</th><th>Company / Contact</th><th>Invoice</th><th>Total</th><th>Received</th><th>Balance</th><th>Payable by</th><th>Actions</th></tr>
        </thead>
        <tbody>
            @forelse (($credit ?? []) as $c)
                @php
                    $cDue = $c->inv_due_on ? \Carbon\Carbon::parse($c->inv_due_on) : null;
                    $cOver = $cDue && $cDue->isPast();
                @endphp
                <tr>
                    <td><b>{{ $c->quote_no }}</b></td>
                    <td>{{ $c->company }}<br><span style="color:var(--text2);font-size:13px;">{{ $c->admin_name }} · {{ $c->admin_email }}</span></td>
                    <td>{{ $c->inv_number }}</td>
                    <td>₹{{ number_format($c->inv_total, 2) }}</td>
                    <td style="color:#059669;">₹{{ number_format($c->inv_paid, 2) }}</td>
                    <td><b style="color:{{ $cOver ? '#dc2626' : 'var(--accent)' }};">₹{{ number_format($c->inv_balance, 2) }}</b></td>
                    <td style="white-space:nowrap;">
                        @if($cOver)<span class="pill" style="background:rgba(239,68,68,.12);color:#dc2626;"><i class="fas fa-triangle-exclamation"></i> Overdue {{ $cDue->format('d M') }}</span>
                        @else {{ $cDue ? $cDue->format('d M Y') : '—' }}@endif
                    </td>
                    <td style="white-space:nowrap;">
                        <button class="btn btn-primary" style="padding:7px 12px;font-size:12.5px;"
                            onclick='qpOpen({{ $c->id }}, @json($c->quote_no), @json($c->company), {{ $c->inv_total }}, true, {{ $c->inv_balance }})'>
                            <i class="fas fa-hand-holding-dollar"></i> Record balance
                        </button><br>
                        <span style="font-size:12.5px;"><a href="{{ url('/quote/'.$c->quote_token) }}" target="_blank" style="color:var(--accent);">Balance pay link</a></span>
                    </td>
                </tr>
            @empty
                <tr><td colspan="8" style="text-align:center;color:var(--text3);padding:28px;">No outstanding credit. Clients you provision with a Partial or Due payment appear here until fully paid.</td></tr>
            @endforelse
        </tbody>
    </table>

    {{-- rev 186: manual payment entry modal --}}
    <div id="qp-ov" style="display:none;position:fixed;inset:0;z-index:9000;background:rgba(12,25,41,.55);align-items:flex-start;justify-content:center;padding:40px 14px;overflow:auto;">
        <div style="max-width:480px;width:100%;background:#fff;border-radius:14px;margin:auto;overflow:hidden;">
            <div style="background:var(--navy);color:#fff;padding:15px 20px;display:flex;justify-content:space-between;align-items:center;">
                <div>
                    <div style="font-size:11px;color:var(--accent);font-weight:700;text-transform:uppercase;letter-spacing:.6px;" id="qp-kicker">Record payment</div>
                    <div style="font-size:16px;font-weight:700;" id="qp-title">—</div>
                </div>
                <i class="fas fa-xmark" onclick="qpClose()" style="cursor:pointer;font-size:18px;color:#94a3b8;padding:4px;"></i>
            </div>
            <div style="padding:20px;">
                <div id="qp-status-wrap" class="fg">
                    <label>Payment status</label>
                    <select id="qp-status" onchange="qpStatus()">
                        <option value="paid">Paid — full amount received</option>
                        <option value="partial">Partial — part received, balance on credit</option>
                        <option value="due">Due — full amount on credit period</option>
                    </select>
                </div>
                <div class="fg" id="qp-amount-wrap">
                    <label>Amount received (₹)</label>
                    <input type="number" id="qp-amount" min="0" step="0.01">
                </div>
                <div class="fg" id="qp-method-wrap">
                    <label>How was it received?</label>
                    <select id="qp-method">
                        <option>Bank transfer</option>
                        <option>UPI</option>
                        <option>Cheque</option>
                        <option>Cash</option>
                        <option>Razorpay (offline entry)</option>
                        <option>Other</option>
                    </select>
                </div>
                <div class="fg" id="qp-ref-wrap">
                    <label>Reference no. (UTR / cheque no. — optional)</label>
                    <input type="text" id="qp-ref" maxlength="100" placeholder="e.g. UTR 512345678901">
                </div>
                <div class="fg" id="qp-due-wrap">
                    <label>Balance payable by (credit due date)</label>
                    <input type="date" id="qp-due">
                </div>
                <div id="qp-note" style="font-size:12.5px;color:var(--text2);background:#f8fafc;border:1px solid var(--border);border-radius:9px;padding:10px 12px;margin:4px 0 12px;line-height:1.55;"></div>
                <div id="qp-err" style="display:none;background:#fee2e2;color:#991b1b;border-radius:9px;padding:10px 12px;font-size:13px;margin-bottom:12px;"></div>
                <button class="btn btn-primary" id="qp-save" onclick="qpSave()" style="width:100%;justify-content:center;"><i class="fas fa-check"></i> Save &amp; create workspace</button>
            </div>
        </div>
    </div>

    <script>
    var qpCtx = null;
    function qpOpen(id, quoteNo, company, total, isBalance, balance){
        qpCtx = { id:id, total:total, isBalance:isBalance, balance:balance };
        document.getElementById('qp-kicker').textContent = isBalance ? 'Record balance payment' : 'Record payment & create workspace';
        document.getElementById('qp-title').textContent = quoteNo + ' — ' + company;
        document.getElementById('qp-status-wrap').style.display = isBalance ? 'none' : 'flex';
        document.getElementById('qp-status').value = 'paid';
        document.getElementById('qp-amount').value = isBalance ? balance.toFixed(2) : total.toFixed(2);
        document.getElementById('qp-ref').value = '';
        document.getElementById('qp-due').value = '';
        document.getElementById('qp-err').style.display = 'none';
        document.getElementById('qp-save').innerHTML = isBalance
            ? '<i class="fas fa-check"></i> Record balance payment'
            : '<i class="fas fa-check"></i> Save &amp; create workspace';
        qpStatus();
        document.getElementById('qp-ov').style.display = 'flex';
    }
    function qpClose(){ document.getElementById('qp-ov').style.display = 'none'; }
    document.getElementById('qp-ov').addEventListener('click', function(e){ if(e.target === this){ qpClose(); } });
    function qpStatus(){
        if(!qpCtx){ return; }
        if(qpCtx.isBalance){
            document.getElementById('qp-amount-wrap').style.display = 'flex';
            document.getElementById('qp-method-wrap').style.display = 'flex';
            document.getElementById('qp-ref-wrap').style.display = 'flex';
            document.getElementById('qp-due-wrap').style.display = 'none';
            document.getElementById('qp-amount').disabled = false;
            document.getElementById('qp-note').textContent = 'Outstanding balance: ₹' + qpCtx.balance.toFixed(2) + '. Enter what was received now — the invoice flips to PAID automatically when the balance reaches zero.';
            return;
        }
        var st = document.getElementById('qp-status').value;
        var amt = document.getElementById('qp-amount');
        document.getElementById('qp-amount-wrap').style.display = st === 'due' ? 'none' : 'flex';
        document.getElementById('qp-method-wrap').style.display = st === 'due' ? 'none' : 'flex';
        document.getElementById('qp-ref-wrap').style.display = st === 'due' ? 'none' : 'flex';
        document.getElementById('qp-due-wrap').style.display = st === 'paid' ? 'none' : 'flex';
        amt.disabled = st === 'paid';
        if(st === 'paid'){ amt.value = qpCtx.total.toFixed(2); }
        if(st === 'partial' && parseFloat(amt.value || 0) >= qpCtx.total){ amt.value = ''; }
        document.getElementById('qp-note').textContent =
            st === 'paid' ? 'Full ₹' + qpCtx.total.toFixed(2) + ' received offline. The workspace is created now, invoice marked PAID, receipt + welcome email sent.'
            : st === 'partial' ? 'Part received now, the rest on credit. The workspace is created immediately; the balance shows below in Credit clients until cleared.'
            : 'Nothing received yet — full amount on credit period. The workspace is created immediately; the client (or you) pays by the due date.';
    }
    function qpErr(m){ var e = document.getElementById('qp-err'); e.textContent = m; e.style.display = 'block'; }
    function qpSave(){
        if(!qpCtx){ return; }
        var b = document.getElementById('qp-save'); b.disabled = true;
        document.getElementById('qp-err').style.display = 'none';
        var body;
        if(qpCtx.isBalance){
            body = { amount: parseFloat(document.getElementById('qp-amount').value || 0),
                     method: document.getElementById('qp-method').value,
                     reference: document.getElementById('qp-ref').value };
        } else {
            var st = document.getElementById('qp-status').value;
            body = { pay_status: st,
                     amount: st === 'due' ? 0 : parseFloat(document.getElementById('qp-amount').value || 0),
                     method: st === 'due' ? null : document.getElementById('qp-method').value,
                     reference: st === 'due' ? null : document.getElementById('qp-ref').value,
                     due_date: st === 'paid' ? null : document.getElementById('qp-due').value };
        }
        fetch('{{ url('/admin/quotations') }}/' + qpCtx.id + '/payment', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json',
                       'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                       'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify(body)
        }).then(function(r){ return r.json(); }).then(function(j){
            b.disabled = false;
            if(!j.ok){ qpErr(j.error || 'Could not save the payment.'); return; }
            alert(j.message || 'Saved.');
            window.location.reload();
        }).catch(function(){ b.disabled = false; qpErr('Network error — please retry.'); });
    }
    </script>
@endsection
