<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>SmartPRS — Quotation {{ $s->quote_no }}</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="icon" type="image/png" href="{{ asset('images/logo-icon.png') }}">
    <style>
        :root{--navy:#0c1929;--accent:#f97316;--bg:#f1f5f9;--mut:#64748b;--line:#e2e8f0;}
        *{margin:0;padding:0;box-sizing:border-box;font-family:'Segoe UI',system-ui,sans-serif;}
        body{background:var(--bg);color:var(--navy);}
        .top{background:var(--navy);padding:16px 24px;}
        .top img{height:34px;}
        .wrap{max-width:640px;margin:28px auto;padding:0 16px;}
        .card{background:#fff;border:1px solid var(--line);border-radius:16px;padding:28px;box-shadow:0 10px 30px rgba(15,29,51,.07);}
        h1{font-size:22px;margin-bottom:4px;}
        .sub{color:var(--mut);font-size:14px;margin-bottom:20px;}
        .row{display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid #f1f5f9;font-size:14px;}
        .row b{font-weight:700;}
        .total{display:flex;justify-content:space-between;padding:14px 0 4px;font-size:19px;font-weight:800;}
        .total span:last-child{color:var(--accent);}
        .btn{display:block;width:100%;text-align:center;background:var(--accent);color:#fff;border:none;border-radius:10px;padding:14px;font-size:16px;font-weight:700;cursor:pointer;margin-top:18px;text-decoration:none;}
        .btn:disabled{opacity:.5;}
        .btn2{display:inline-flex;align-items:center;gap:7px;color:var(--accent);font-weight:600;font-size:14px;text-decoration:none;margin-top:14px;}
        .fine{font-size:12px;color:var(--mut);margin-top:14px;line-height:1.5;text-align:center;}
        .err{background:#fee2e2;color:#991b1b;border:1px solid #fecaca;border-radius:9px;padding:11px 13px;margin-top:14px;font-size:14px;display:none;}
        .badge{display:inline-block;background:#fff7ed;color:#c2410c;border:1px solid #fed7aa;border-radius:20px;padding:3px 12px;font-size:12px;font-weight:700;margin-bottom:14px;}
        .okpane{display:none;text-align:center;}
        .tick{width:64px;height:64px;border-radius:50%;background:#16a34a;color:#fff;display:flex;align-items:center;justify-content:center;font-size:28px;margin:0 auto 14px;}
        .feat{display:grid;grid-template-columns:1fr 1fr;gap:4px 14px;margin:14px 0;font-size:13px;color:#334155;}
    </style>
</head>
<body>
    <div class="top"><img src="{{ asset('images/logo.png') }}" alt="SmartPRS by Ametecs"></div>
    <div class="wrap">
        <div class="card" id="quotecard">
            <div class="badge">Quotation {{ $s->quote_no }}</div>
            <h1>Your SmartPRS workspace</h1>
            <div class="sub">Prepared for <b>{{ $s->company }}</b> · all 16 modules included</div>

            @if($paid && ($balance ?? null))
                {{-- rev 186: workspace created ON CREDIT — this page now collects the BALANCE. --}}
                <div class="err" style="display:block;background:#fff7ed;color:#9a3412;border-color:#fed7aa;">
                    <i class="fas fa-circle-info"></i> Your workspace is already <b>live</b>. A balance of <b>₹{{ number_format($balance['balance'], 2) }}</b> remains on this subscription{{ $balance['dueOn'] ? ' — payable by '.$balance['dueOn'] : '' }}.
                </div>
                <div class="row"><span>Total (incl. GST)</span><b>₹{{ number_format($balance['total'], 2) }}</b></div>
                <div class="row" style="color:#16a34a;"><span>Received so far</span><b>₹{{ number_format($balance['paid'], 2) }}</b></div>
                <div class="total"><span>Balance payable</span><span>₹{{ number_format($balance['balance'], 2) }}</span></div>
                <button class="btn" id="paybtn" onclick="payNow()"><i class="fas fa-lock"></i> Pay balance ₹{{ number_format($balance['balance'], 2) }}</button>
                <div class="err" id="err"></div>
                <div class="fine">Payment by Razorpay · The receipt with your GST tax invoice is emailed when fully paid · <a href="{{ url('/login') }}" style="color:var(--accent);">Sign in to your workspace</a></div>
            @elseif($paid)
                <div class="err" style="display:block;background:#dcfce7;color:#166534;border-color:#bbf7d0;">
                    <i class="fas fa-circle-check"></i> This quotation has already been paid and the workspace is live. Please <a href="{{ url('/login') }}" style="color:#166534;font-weight:700;">sign in</a>.
                </div>
            @elseif($expired)
                <div class="err" style="display:block;">
                    <i class="fas fa-clock"></i> This quotation expired on {{ $validUntil }}. Please request a fresh quotation at sales@ametecsindia.com or start again on the <a href="{{ url('/signup') }}" style="color:#991b1b;font-weight:700;">signup page</a>.
                </div>
            @else
                <div class="row"><span>{{ $plan->name ?? 'Plan' }} plan — {{ (int) $s->seats }} employees</span><b>₹{{ number_format($plan->base_price ?? 0, 0) }}/mo base</b></div>
                @if(($s->companies ?? 1) > 1)<div class="row"><span>{{ $s->companies }} companies (1 included + {{ $s->companies - 1 }} extra)</span><b>₹{{ number_format(1000 * (($s->companies) - 1), 0) }}/mo</b></div>@endif
                <div class="row"><span>Billing period</span><b>{{ ['quarterly'=>'Quarterly (3 mo)','halfyear'=>'Half-yearly (6 mo, 10% off)','annual'=>'Annual (12 mo, 25% off)'][$s->cycle] ?? $s->cycle }}</b></div>
                {{-- rev 113: locked coupon discount shown clearly --}}
                @if(($price['coupon_discount'] ?? 0) > 0)
                <div class="row"><span>Amount before discount</span><b>₹{{ number_format($price['amount_before_coupon'] ?? 0, 2) }}</b></div>
                <div class="row" style="color:#16a34a;"><span><i class="fas fa-gift"></i> Discount — coupon {{ $price['coupon_code'] }}</span><b>− ₹{{ number_format($price['coupon_discount'], 2) }}</b></div>
                @endif
                <div class="row"><span>Subscription amount{{ ($price['coupon_discount'] ?? 0) > 0 ? ' (after discount)' : '' }}</span><b>₹{{ number_format($price['amount'], 2) }}</b></div>
                <div class="row"><span>GST (18%)</span><b>₹{{ number_format($price['tax'], 2) }}</b></div>
                <div class="total"><span>Total payable</span><span>₹{{ number_format($price['total'], 2) }}</span></div>

                <div class="feat">
                    <div><i class="fas fa-check" style="color:#16a34a"></i> All 16 modules</div>
                    <div><i class="fas fa-check" style="color:#16a34a"></i> GST tax invoice</div>
                    <div><i class="fas fa-check" style="color:#16a34a"></i> Collections-ready content</div>
                    <div><i class="fas fa-check" style="color:#16a34a"></i> Admin login in minutes</div>
                </div>

                <button class="btn" id="paybtn" onclick="payNow()"><i class="fas fa-lock"></i> Pay securely &amp; create workspace</button>
                <a class="btn2" href="{{ url('/quote/'.$token.'/pdf') }}" target="_blank"><i class="fas fa-file-pdf"></i> Download quotation PDF</a>
                <div class="err" id="err"></div>
                <div class="fine">Valid until {{ $validUntil }} · Payment by Razorpay · GST tax invoice emailed on payment · Minimum 3 months advance.</div>
            @endif
        </div>

        <div class="card okpane" id="okpane">
            <div class="tick"><i class="fas fa-check"></i></div>
            <h1 style="text-align:center;">Welcome aboard!</h1>
            <p class="fine" id="okmsg"></p>
            <p style="text-align:center;margin-top:16px;"><a id="oklink" href="{{ url('/login') }}" style="color:var(--accent);font-weight:700;">Go to sign-in →</a></p>
        </div>
    </div>

    @if((!$paid && !$expired) || ($balance ?? null))
    <script src="https://checkout.razorpay.com/v1/checkout.js"></script>
    <script>
    function showErr(m){ var e=document.getElementById('err'); e.textContent=m; e.style.display='block'; }
    function post(url, body){
        return fetch(url, { method:'POST', credentials:'same-origin',
            headers:{ 'Content-Type':'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, 'X-Requested-With':'XMLHttpRequest' },
            body: JSON.stringify(body||{}) }).then(function(r){ return r.json(); });
    }
    function payNow(){
        var b=document.getElementById('paybtn'); b.disabled=true;
        document.getElementById('err').style.display='none';
        post('{{ url('/quote/'.$token.'/order') }}', {}).then(function(j){
            if(!j.ok){ showErr(j.error || 'Could not start the payment.'); b.disabled=false; return; }
            var rz=new Razorpay({
                key:j.keyId, order_id:j.orderId, amount:j.amountPaise, currency:'INR',
                name:'SmartPRS by Ametecs', description:'Workspace subscription · {{ $s->quote_no }}',
                prefill:{ name:'{{ $s->admin_name }}', email:'{{ $s->admin_email }}', contact:'{{ $s->mobile }}' },
                theme:{ color:'#f97316' },
                handler:function(resp){
                    post('{{ url('/quote/'.$token.'/complete') }}', {
                        razorpay_order_id:resp.razorpay_order_id, razorpay_payment_id:resp.razorpay_payment_id, razorpay_signature:resp.razorpay_signature
                    }).then(function(c){
                        if(c.ok){
                            if(j.balance){ document.querySelector('#okpane h1').textContent='Payment received — thank you!'; }
                            document.getElementById('quotecard').style.display='none';
                            document.getElementById('okmsg').textContent=c.message||'Payment received. Your workspace is being set up.';
                            var lk=document.getElementById('oklink'); if(c.redirect){ lk.href=c.redirect; lk.textContent=c.autoIn?'Open my workspace →':'Go to sign-in →'; }
                            document.getElementById('okpane').style.display='block'; window.scrollTo(0,0);
                            if(c.autoIn && c.redirect){ setTimeout(function(){ window.location.href=c.redirect; }, 2500); }
                        } else { showErr(c.error || 'Payment verification failed — contact sales@ametecsindia.com with your payment id.'); b.disabled=false; }
                    });
                },
                modal:{ ondismiss:function(){ b.disabled=false; } }
            });
            rz.open();
        }).catch(function(){ showErr('Network error — please retry.'); b.disabled=false; });
    }
    </script>
    @endif
</body>
</html>
