<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Pay invoice {{ $c->invoice_no }} — SmartPRS</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
    * { box-sizing: border-box; margin: 0; }
    body { font-family: 'Segoe UI', system-ui, sans-serif; background: linear-gradient(160deg, #0c1929, #14253c); min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 24px; }
    .card { background: #fff; border-radius: 18px; max-width: 520px; width: 100%; padding: 36px 38px; box-shadow: 0 24px 70px rgba(0,0,0,.45); }
    h1 { font-size: 22px; color: #0c1929; margin-bottom: 4px; }
    .sub { color: #f97316; font-weight: 700; font-size: 14px; margin-bottom: 18px; }
    .row { display: flex; justify-content: space-between; font-size: 14px; padding: 8px 0; border-bottom: 1px solid #f1f5f9; }
    .row strong { color: #0c1929; }
    .total { font-size: 18px; font-weight: 800; color: #0c1929; }
    .btn { margin-top: 20px; width: 100%; display: inline-flex; align-items: center; justify-content: center; gap: 9px; border: none; border-radius: 11px; padding: 14px; font-size: 16px; font-weight: 700; cursor: pointer; background: #f97316; color: #fff; }
    .btn:disabled { opacity: .6; cursor: default; }
    .ok { background: #f0fdf4; border: 1px solid #bbf7d0; color: #166534; border-radius: 10px; padding: 12px 14px; font-size: 13.5px; margin-top: 16px; display: none; }
    .err { background: #fef2f2; border: 1px solid #fecaca; color: #b91c1c; border-radius: 10px; padding: 12px 14px; font-size: 13.5px; margin-top: 16px; display: none; }
    .links { margin-top: 18px; font-size: 12.5px; color: #94a3b8; text-align: center; }
    .links a { color: #f97316; text-decoration: none; font-weight: 600; }
</style>
</head>
<body>
<div class="card">
    <h1>{{ $c->company }}</h1>
    <div class="sub">SmartPRS-{{ strtoupper($c->edition) }} perpetual licence · Invoice {{ $c->invoice_no ?: 'draft' }}</div>
    <div class="row"><span>Licence price</span><span>₹{{ number_format($t['price'], 2) }}</span></div>
    <div class="row"><span>GST (18%)</span><span>₹{{ number_format($t['tax'], 2) }}</span></div>
    <div class="row"><span><strong>Total</strong></span><span class="total">₹{{ number_format($t['total'], 2) }}</span></div>
    @if ((float) $c->paid_total > 0)
        <div class="row"><span>Received</span><span>₹{{ number_format((float) $c->paid_total, 2) }}</span></div>
    @endif
    <div class="row" style="border-bottom:none;"><span><strong>Balance due</strong></span><span class="total" style="color:#f97316;">₹{{ number_format($t['balance'], 2) }}</span></div>

    @if ($t['balance'] > 0)
        <button class="btn" id="payBtn"><i class="fas fa-lock"></i> Pay ₹{{ number_format($t['balance'], 2) }} securely</button>
    @else
        <div class="ok" style="display:block;"><i class="fas fa-circle-check"></i> Fully paid — thank you! Your licence key has been emailed.</div>
    @endif
    <div class="ok" id="okBox"></div>
    <div class="err" id="errBox"></div>

    <div class="links">
        @if ($c->invoice_no)<a href="{{ url('/licence/'.$token.'/pdf') }}" target="_blank">Download invoice PDF</a> · @endif
        Razorpay secured · Ametecs India · WhatsApp 9000098877
    </div>
</div>
<script src="https://checkout.razorpay.com/v1/checkout.js"></script>
<script>
(function () {
    var btn = document.getElementById('payBtn');
    if (!btn) { return; }
    function show(id, msg) { var d = document.getElementById(id); d.textContent = msg; d.style.display = 'block'; }
    btn.onclick = function () {
        btn.disabled = true;
        fetch('{{ url('/licence/'.$token.'/order') }}', { method: 'POST', headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (!j.ok) { show('errBox', j.error || 'Could not start payment.'); btn.disabled = false; return; }
                var rz = new Razorpay({
                    key: j.keyId, amount: j.amountPaise, currency: 'INR',
                    name: 'SmartPRS by Ametecs', description: 'Licence invoice {{ $c->invoice_no }}',
                    order_id: j.orderId,
                    prefill: { name: '{{ addslashes($c->contact_name ?: $c->company) }}', email: '{{ $c->email }}', contact: '{{ $c->mobile }}' },
                    theme: { color: '#f97316' },
                    modal: { ondismiss: function () { btn.disabled = false; } },
                    handler: function (resp) {
                        fetch('{{ url('/licence/'.$token.'/complete') }}', {
                            method: 'POST',
                            headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Content-Type': 'application/json', 'Accept': 'application/json' },
                            body: JSON.stringify(resp)
                        }).then(function (r) { return r.json(); })
                          .then(function (k) {
                              if (k.ok) { show('okBox', k.message); btn.style.display = 'none'; setTimeout(function () { location.reload(); }, 4000); }
                              else { show('errBox', k.error || 'Verification failed — if money was deducted, contact Ametecs.'); btn.disabled = false; }
                          });
                    }
                });
                rz.open();
            }).catch(function () { show('errBox', 'Network problem — please try again.'); btn.disabled = false; });
    };
})();
</script>
</body>
</html>
