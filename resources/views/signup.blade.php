<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>Get started — SmartPRS by Ametecs</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
:root{--navy:#0f1d33;--navy2:#16263f;--accent:#f97316;--ink:#1f2937;--mut:#6b7280;--line:#e5e7eb;--soft:#f8fafc}
*{box-sizing:border-box;margin:0;padding:0;font-family:'Segoe UI',system-ui,-apple-system,sans-serif}
body{background:var(--soft);color:var(--ink)}
.top{background:var(--navy);color:#fff;padding:16px 0}
.wrap{max-width:1080px;margin:0 auto;padding:0 20px}
.brand{display:flex;align-items:center;gap:10px;font-size:20px;font-weight:800}
.brand .bolt{width:34px;height:34px;border-radius:9px;background:linear-gradient(135deg,var(--accent),#ea580c);display:flex;align-items:center;justify-content:center}
.brand small{font-weight:500;font-size:12px;opacity:.7;display:block}
.hero{background:linear-gradient(135deg,var(--navy),var(--navy2));color:#fff;padding:34px 0 86px;text-align:center}
.hero h1{font-size:30px}.hero p{opacity:.85;margin-top:6px;font-size:15px}
.card{background:#fff;border:1px solid var(--line);border-radius:14px;box-shadow:0 10px 30px rgba(15,29,51,.07)}
.shell{margin-top:-56px;padding-bottom:60px}
.grid{display:grid;grid-template-columns:1.25fr .9fr;gap:18px}
@media(max-width:860px){.grid{grid-template-columns:1fr}}
.pad{padding:26px 28px}
h2{font-size:17px;margin-bottom:14px}
.plans{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:18px}
.plan{border:1.5px solid var(--line);border-radius:12px;padding:13px 12px;cursor:pointer;text-align:center;transition:all .15s}
.plan:hover{border-color:#fdba74}
.plan.on{border-color:var(--accent);background:#fff7ed;box-shadow:0 0 0 3px rgba(249,115,22,.12)}
.plan b{display:block;font-size:14px}.plan .pr{font-size:18px;font-weight:800;color:var(--navy);margin:4px 0}
.plan small{color:var(--mut);font-size:11.5px;display:block;line-height:1.45}
label.fl{display:block;font-size:11px;font-weight:700;letter-spacing:.4px;text-transform:uppercase;color:var(--mut);margin:14px 0 5px}
input.fi,select.fi{width:100%;padding:11px 13px;border:1.5px solid var(--line);border-radius:9px;font-size:14.5px;background:#fff}
input.fi:focus,select.fi:focus{outline:none;border-color:var(--accent)}
.cycles{display:grid;grid-template-columns:repeat(3,1fr);gap:8px}
.cyc{border:1.5px solid var(--line);border-radius:10px;padding:10px;cursor:pointer;text-align:center;font-size:12.5px}
.cyc.on{border-color:var(--accent);background:#fff7ed}
.cyc b{display:block;font-size:13px}.cyc .off{color:#16a34a;font-weight:700;font-size:11.5px}
.quote{border-top:1px dashed var(--line);margin-top:18px;padding-top:14px;font-size:13.5px}
.quote .row{display:flex;justify-content:space-between;padding:3.5px 0;color:var(--mut)}
.quote .row b{color:var(--ink)}
.quote .total{display:flex;justify-content:space-between;background:var(--navy);color:#fff;border-radius:10px;padding:12px 14px;margin-top:10px;font-size:15px;font-weight:700}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:9px;width:100%;background:var(--accent);color:#fff;border:none;border-radius:10px;padding:14px;font-size:15.5px;font-weight:700;cursor:pointer;margin-top:16px}
.btn:hover{background:#ea580c}.btn:disabled{opacity:.6;cursor:wait}
.fine{font-size:11.5px;color:var(--mut);text-align:center;margin-top:10px;line-height:1.5}
.err{display:none;background:#fef2f2;border:1px solid #fecaca;color:#b91c1c;border-radius:9px;padding:10px 13px;font-size:13px;margin-top:12px}
.okpane{display:none;text-align:center;padding:46px 30px}
.terms-row{display:flex;gap:9px;align-items:flex-start;margin-top:16px;font-size:13px;line-height:1.5;color:var(--ink)}
.terms-row input{width:17px;height:17px;margin-top:1px;accent-color:var(--accent);flex-shrink:0;cursor:pointer}
.terms-row a{color:var(--accent);font-weight:600;cursor:pointer;text-decoration:underline}
.tov{display:none;position:fixed;inset:0;background:rgba(15,29,51,.6);z-index:9000;align-items:center;justify-content:center;padding:24px}
.tov.open{display:flex}
.tbox{background:#fff;border-radius:14px;max-width:680px;width:100%;max-height:84vh;display:flex;flex-direction:column;box-shadow:0 24px 70px rgba(0,0,0,.35)}
.tbox .thead{padding:16px 22px;border-bottom:1px solid var(--line);font-weight:800;font-size:16px;display:flex;justify-content:space-between;align-items:center}
.tbox .thead i{cursor:pointer;color:var(--mut)}
.tbox .tbody{padding:18px 24px;overflow:auto;font-size:13.5px;line-height:1.65;color:var(--ink)}
.tbox .tbody h4{margin:14px 0 6px;font-size:13.5px;color:var(--navy)}
.tbox .tbody p{margin-bottom:8px;color:#374151}
.tbox .tfoot{padding:13px 22px;border-top:1px solid var(--line);display:flex;justify-content:flex-end;gap:10px}
.tbtn{border:none;border-radius:9px;padding:11px 20px;font-size:14px;font-weight:700;cursor:pointer}
.tbtn.ok{background:var(--accent);color:#fff}.tbtn.gh{background:#fff;border:1.5px solid var(--line);color:var(--mut)}
.okpane .tick{width:64px;height:64px;border-radius:50%;background:#dcfce7;color:#16a34a;font-size:28px;display:flex;align-items:center;justify-content:center;margin:0 auto 16px}
.inc{font-size:12.5px;color:var(--mut);line-height:1.6}
.inc i{color:#16a34a;margin-right:5px}
</style>
</head>
<body>
<div class="top"><div class="wrap"><a href="{{ url('/') }}" style="text-decoration:none;color:#fff"><div class="brand"><img src="{{ asset('images/logo.png') }}" alt="SmartPRS by Ametecs" style="height:38px;width:auto;display:block;"></div></a></div></div>
<div class="hero"><div class="wrap"><h1>Start your SmartPRS workspace</h1><p>All 16 modules in every plan · pay only for headcount · GST invoice emailed instantly</p></div></div>

<div class="wrap shell">
  <div class="card" id="formcard">
    <div class="grid">
      <div class="pad" style="border-right:1px solid var(--line)">
        <h2><i class="fas fa-building" style="color:var(--accent);margin-right:7px"></i>Your organisation</h2>
        <label class="fl">Company / group name</label>
        <input class="fi" id="f_company" placeholder="e.g. Apex Collections Pvt. Ltd.">
        <label class="fl">Your name (admin)</label>
        <input class="fi" id="f_name" placeholder="Full name">
        <label class="fl">Work email (your login)</label>
        <input class="fi" id="f_email" type="email" placeholder="you@company.com">
        <label class="fl">Mobile</label>
        <input class="fi" id="f_mobile" placeholder="10-digit mobile">
        {{-- rev 90: buyer GST profile — state decides CGST+SGST vs IGST on the tax invoice --}}
        <label class="fl">State (for GST on your invoice)</label>
        <select class="fi" id="f_state">
          <option value="">Select your state…</option>
          @foreach ([
            'Andaman & Nicobar Islands (35)','Andhra Pradesh (37)','Arunachal Pradesh (12)','Assam (18)','Bihar (10)',
            'Chandigarh (04)','Chhattisgarh (22)','Dadra & Nagar Haveli and Daman & Diu (26)','Delhi (07)','Goa (30)',
            'Gujarat (24)','Haryana (06)','Himachal Pradesh (02)','Jammu & Kashmir (01)','Jharkhand (20)','Karnataka (29)',
            'Kerala (32)','Ladakh (38)','Lakshadweep (31)','Madhya Pradesh (23)','Maharashtra (27)','Manipur (14)',
            'Meghalaya (17)','Mizoram (15)','Nagaland (13)','Odisha (21)','Puducherry (34)','Punjab (03)','Rajasthan (08)',
            'Sikkim (11)','Tamil Nadu (33)','Telangana (36)','Tripura (16)','Uttar Pradesh (09)','Uttarakhand (05)','West Bengal (19)',
          ] as $st)
            <option value="{{ $st }}">{{ $st }}</option>
          @endforeach
        </select>
        <label class="fl">GSTIN (optional — printed on your tax invoice)</label>
        <input class="fi" id="f_gstin" placeholder="e.g. 36AAHCT0971F1ZB" maxlength="15" style="text-transform:uppercase">
        <div class="inc" style="margin-top:-4px;margin-bottom:10px">Telangana businesses are billed CGST 9% + SGST 9%; other states IGST 18% — same total either way.</div>
        <label class="fl">Choose your SmartPRS web address (optional)</label>
        <div style="display:flex;align-items:center;gap:6px">
          <span style="font-size:13px;color:#64748b;white-space:nowrap">smartprs.com/c/</span>
          <input class="fi" id="f_slug" placeholder="e.g. abcrecover" maxlength="10" style="text-transform:lowercase;margin-bottom:0">
        </div>
        <div class="inc" style="margin-top:6px;margin-bottom:10px">3–10 letters/numbers, no spaces. This becomes your branded login page. Leave blank and we will create one from your company name.</div>
        <label class="fl">Total employees you will manage</label>
        <input class="fi" id="f_seats" type="number" min="1" value="25">
        <label class="fl">Companies in your group</label>
        <input class="fi" id="f_companies" type="number" min="1" max="100" value="1">
        <div class="inc" style="margin-top:-4px;margin-bottom:10px">Every plan includes 1 company. Each additional company is a flat ₹1,000/month — your employee limit stays the same across ALL companies.</div>
        <div class="inc" style="margin-top:16px">
          <i class="fas fa-check"></i>All 16 modules included &nbsp; <i class="fas fa-check"></i>Free onboarding content for collections &amp; recovery<br>
          <i class="fas fa-check"></i>GST tax invoice &nbsp; <i class="fas fa-check"></i>Admin login by email within minutes
        </div>
      </div>
      <div class="pad">
        <h2><i class="fas fa-tags" style="color:var(--accent);margin-right:7px"></i>Plan &amp; billing</h2>
        <div class="plans" id="plans"></div>
        <label class="fl">Advance period</label>
        <div class="cycles" id="cycles"></div>
        {{-- rev 112/113b: discount coupon — hidden unless public coupons are live
             in the backend; an exclusive email match reveals it automatically --}}
        <div id="cpnwrap" style="display:{{ ($couponsEnabled ?? false) ? 'block' : 'none' }}">
          <label class="fl">Have a coupon code?</label>
          <div style="display:flex;gap:8px">
            <input class="fi" id="f_coupon" placeholder="e.g. LAUNCH25" maxlength="40" style="text-transform:uppercase;margin-bottom:0;flex:1">
            <button type="button" id="cpnbtn" onclick="applyCoupon()" style="background:#fff;color:var(--accent);border:1.5px solid var(--accent);border-radius:9px;padding:0 18px;font-weight:700;cursor:pointer;font-family:inherit">Apply</button>
          </div>
          <div id="cpnmsg" style="display:none;margin-top:7px;font-size:13px;border-radius:8px;padding:9px 12px"></div>
        </div>
        <div class="quote" id="quote"></div>
        <div class="terms-row">
          <input type="checkbox" id="f_terms">
          <span>I have read and accept the <a onclick="openTerms()">Terms &amp; Conditions and Refund Policy</a> of SmartPRS by Ametecs India Pvt. Ltd.</span>
        </div>
        <button class="btn" id="paybtn" onclick="startCheckout()"><i class="fas fa-lock"></i> Pay securely &amp; create workspace</button>
        <button class="btn" id="quotebtn" onclick="sendQuote()" style="background:#fff;color:var(--accent);border:1.5px solid var(--accent);margin-top:10px"><i class="fas fa-file-invoice"></i> Send me a Quotation</button>
        <div class="fine" style="margin-top:8px">Need approval from your finance team first? Get a quotation PDF emailed to you with a secure payment link — pay anytime to create the workspace.</div>
        <div class="err" id="err"></div>
        <div class="ok2" id="quoteok" style="display:none;background:#dcfce7;color:#166534;border:1px solid #bbf7d0;border-radius:9px;padding:12px 14px;margin-top:12px;font-size:14px"></div>
        <div class="fine">Payments are processed by Razorpay. Minimum billing period is 3 months.<br>Prices exclude GST (18%), shown above before you pay.</div>
      </div>
    </div>
  </div>

  <div class="card okpane" id="okpane">
    <div class="tick"><i class="fas fa-check"></i></div>
    <h2 style="font-size:22px;margin-bottom:8px">Welcome aboard!</h2>
    <p id="okmsg" style="color:var(--mut);max-width:520px;margin:0 auto;line-height:1.6"></p>
    <p style="margin-top:18px"><a id="oklink" href="{{ url('/login') }}" style="color:var(--accent);font-weight:700">Go to the sign-in page →</a></p>
  </div>
</div>

<div class="tov" id="termsov">
  <div class="tbox">
    <div class="thead">Terms &amp; Conditions and Refund Policy <i class="fas fa-xmark" onclick="closeTerms()"></i></div>
    <div class="tbody">
      <h4>1. The service</h4>
      <p>SmartPRS is a cloud HR, payroll, attendance and compliance platform provided by Ametecs India Pvt. Ltd. ("Ametecs"). Your subscription gives your organisation access to all modules for the number of employees and the advance period you select at checkout.</p>
      <h4>2. Pricing &amp; billing</h4>
      <p>Prices are as displayed at checkout, exclusive of GST (18%), which is added on the invoice. The minimum billing period is 3 months, payable in advance. Half-yearly advance carries a 10% discount and annual advance a 25% discount, applied to the invoice value. Every plan includes one company; each additional company in your group is charged a flat ₹1,000 per month. The subscribed employee count applies to your account as a whole, across all your companies. A GST tax invoice is generated and emailed for every payment.</p>
      <h4>3. Refund policy</h4>
      <p><b>7-day money-back guarantee:</b> if you are not satisfied with SmartPRS, write to support@ametecsindia.com within 7 days of your first payment and we will refund the full amount paid, no questions asked.</p>
      <p>After the first 7 days, fees for the advance period already invoiced are non-refundable; pro-rata refunds are not provided for unused months, seats, or early cancellation. Your workspace remains active until the end of the paid period. Refunds, where due, are processed to the original payment method within 7–10 working days.</p>
      <h4>4. Your data</h4>
      <p>Your data belongs to you. We process it solely to provide the service, in line with applicable Indian law including the DPDP Act, 2023. On termination you may export your data; it is retained for 90 days after the paid period ends (so you can export or reactivate) and then permanently deleted.</p>
      <h4>5. Acceptable use &amp; availability</h4>
      <p>You agree to use SmartPRS lawfully, including compliance with RBI guidelines applicable to collections and recovery operations. We target high availability but the service is provided "as is"; planned maintenance is notified in advance. Ametecs' total liability is limited to the fees paid in the 3 months preceding the claim.</p>
      <h4>6. Renewal, suspension &amp; termination</h4>
      <p>Subscriptions renew at the end of each advance period and a renewal invoice is emailed. Non-payment may lead to suspension after reasonable notice. Either party may terminate with effect from the end of the current paid period.</p>
      <h4>7. General</h4>
      <p>These terms are governed by the laws of India, with courts at Hyderabad, Telangana having jurisdiction. For any question, write to support@ametecsindia.com.</p>
      <p style="margin-top:10px;"><b>Full versions:</b> this is a summary for quick reading. The complete documents govern: <a href="{{ url('/terms-and-conditions') }}" target="_blank">Terms &amp; Conditions</a> · <a href="{{ url('/refund-policy') }}" target="_blank">Refund Policy</a> · <a href="{{ url('/privacy-policy') }}" target="_blank">Privacy Policy</a> · <a href="{{ url('/data-protection') }}" target="_blank">Data Protection</a> · <a href="{{ url('/support-policy') }}" target="_blank">Support Policy</a>.</p>
    </div>
    <div class="tfoot">
      <button class="tbtn gh" onclick="closeTerms()">Close</button>
      <button class="tbtn ok" onclick="acceptTerms()"><i class="fas fa-check"></i> I Accept</button>
    </div>
  </div>
</div>

<script src="https://checkout.razorpay.com/v1/checkout.js"></script>
<script>
var PLANS = @json($plans);
var PICK  = @json($pick);
var GST = 18, DISC = { quarterly: 0, halfyear: 0.10, annual: 0.25 }, MONTHS = { quarterly: 3, halfyear: 6, annual: 12 };
var state = { plan: null, cycle: 'quarterly' };

function inr(n){ return '₹' + Number(n).toLocaleString('en-IN', {maximumFractionDigits: 0}); }
function planById(id){ for (var i=0;i<PLANS.length;i++){ if (PLANS[i].id===id) return PLANS[i]; } return PLANS[0]; }

function renderPlans(){
  var host = document.getElementById('plans'); host.innerHTML = '';
  PLANS.forEach(function(p){
    var d = document.createElement('div');
    d.className = 'plan' + (state.plan===p.id ? ' on' : '');
    d.innerHTML = '<b>'+p.name+'</b><div class="pr">'+inr(p.base_price)+'<span style="font-size:11px;font-weight:500;color:var(--mut)">/mo</span></div>'
      + '<small>Up to '+p.seat_max+' employees<br>+'+inr(p.per_user_price)+'/extra</small>';
    d.onclick = function(){ state.plan = p.id; renderPlans(); renderQuote(); };
    host.appendChild(d);
  });
}
function renderCycles(){
  var defs = [['quarterly','Quarterly','3 months · standard'],['halfyear','Half-yearly','6 months · 10% off'],['annual','Annual','12 months · 25% off']];
  var host = document.getElementById('cycles'); host.innerHTML='';
  defs.forEach(function(c){
    var d = document.createElement('div');
    d.className = 'cyc' + (state.cycle===c[0] ? ' on' : '');
    d.innerHTML = '<b>'+c[1]+'</b><span class="off">'+c[2]+'</span>';
    d.onclick = function(){ state.cycle = c[0]; renderCycles(); renderQuote(); };
    host.appendChild(d);
  });
}
function quoteNow(){
  var p = planById(state.plan);
  var seats = parseInt(document.getElementById('f_seats').value, 10) || 0;
  var companies = Math.max(1, parseInt(document.getElementById('f_companies').value, 10) || 1);
  var extra = Math.max(0, seats - p.seat_max);
  var coFee = 1000 * (companies - 1);
  var perMonth = Number(p.base_price) + Number(p.per_user_price) * extra + coFee;
  var months = MONTHS[state.cycle], disc = DISC[state.cycle];
  var amount = Math.round(perMonth * months * (1 - disc) * 100) / 100;
  var tax = Math.round(amount * GST) / 100;
  return { p:p, seats:seats, companies:companies, coFee:coFee, extra:extra, perMonth:perMonth, months:months, disc:disc, amount:amount, tax:tax, total: Math.round((amount+tax)*100)/100 };
}
function renderQuote(){
  var q = quoteNow();
  var h = '<div class="row"><span>'+q.p.name+' plan (includes '+q.p.seat_max+' employees + 1 company)</span><b>'+inr(q.p.base_price)+'/mo</b></div>';
  if (q.extra > 0) { h += '<div class="row"><span>Extra employees: '+q.extra+' × '+inr(q.p.per_user_price)+'</span><b>'+inr(q.extra*q.p.per_user_price)+'/mo</b></div>'; }
  if (q.companies > 1) { h += '<div class="row"><span>Additional companies: '+(q.companies-1)+' × ₹1,000</span><b>'+inr(q.coFee)+'/mo</b></div>'; }
  h += '<div class="row"><span>Billing period</span><b>'+q.months+' months</b></div>';
  if (q.disc > 0) { h += '<div class="row"><span>Advance discount</span><b style="color:#16a34a">−'+(q.disc*100)+'%</b></div>'; }
  // rev 112: coupon line — display recomputed locally; the order is re-priced server-side.
  if (state.coupon) {
    var cd = state.coupon.ctype === 'flat' ? Math.min(state.coupon.cvalue, q.amount) : Math.round(q.amount * state.coupon.cvalue) / 100;
    cd = Math.round(cd * 100) / 100;
    q.amount = Math.round((q.amount - cd) * 100) / 100;
    q.tax = Math.round(q.amount * GST) / 100;
    q.total = Math.round((q.amount + q.tax) * 100) / 100;
    h += '<div class="row"><span>Coupon ' + state.coupon.code + ' (' + state.coupon.label + ')</span><b style="color:#16a34a">−' + inr(cd) + '</b></div>';
  }
  h += '<div class="row"><span>Subtotal</span><b>'+inr(q.amount)+'</b></div>';
  h += '<div class="row"><span>GST (18%)</span><b>'+inr(q.tax)+'</b></div>';
  h += '<div class="total"><span>Payable now</span><span>'+inr(q.total)+'</span></div>';
  document.getElementById('quote').innerHTML = h;
}
function cpnMsg(ok, text){
  var m = document.getElementById('cpnmsg');
  m.style.display = 'block';
  m.style.background = ok ? '#dcfce7' : '#fee2e2';
  m.style.color = ok ? '#166534' : '#991b1b';
  m.style.border = '1px solid ' + (ok ? '#bbf7d0' : '#fecaca');
  m.innerHTML = text;
}
function applyCoupon(){
  var code = document.getElementById('f_coupon').value.trim().toUpperCase();
  if (!code) { state.coupon = null; document.getElementById('cpnmsg').style.display = 'none'; renderQuote(); return; }
  var b = document.getElementById('cpnbtn'); b.disabled = true;
  post('{{ url('/signup/coupon-check') }}', {
    coupon: code, plan_id: state.plan,
    seats: parseInt(document.getElementById('f_seats').value, 10) || 0,
    companies: Math.max(1, parseInt(document.getElementById('f_companies').value, 10) || 1),
    cycle: state.cycle, email: document.getElementById('f_email').value.trim() || null
  }).then(function(j){
    b.disabled = false;
    if (!j.ok) { state.coupon = null; cpnMsg(false, j.error || 'This coupon code is not valid.'); renderQuote(); return; }
    state.coupon = { code: j.code, label: j.label, ctype: j.ctype, cvalue: j.cvalue };
    cpnMsg(true, '<i class="fas fa-circle-check"></i> Coupon <b>' + j.code + '</b> applied — ' + j.label + '. <a onclick="removeCoupon()" style="color:#166534;font-weight:700;cursor:pointer;text-decoration:underline">Remove</a>');
    renderQuote();
  }).catch(function(){ b.disabled = false; cpnMsg(false, 'Network error — please retry.'); });
}
function removeCoupon(){
  state.coupon = null;
  state.exclDismissed = true;   // user said no — never auto-apply again this visit
  document.getElementById('f_coupon').value = '';
  document.getElementById('cpnmsg').style.display = 'none';
  renderQuote();
}
// rev 113: the cart CATCHES the email — if an exclusive offer was sent to it,
// the discount applies automatically (removable).
function exclusiveCheck(){
  if (state.coupon || state.exclDismissed) { return; }
  var email = document.getElementById('f_email').value.trim();
  if (!email || email.indexOf('@') < 1) { return; }
  post('{{ url('/signup/exclusive-check') }}', {
    email: email, plan_id: state.plan,
    seats: parseInt(document.getElementById('f_seats').value, 10) || 0,
    companies: Math.max(1, parseInt(document.getElementById('f_companies').value, 10) || 1),
    cycle: state.cycle
  }).then(function(j){
    if (!j.ok || state.coupon || state.exclDismissed) { return; }
    state.coupon = { code: j.code, label: j.label, ctype: j.ctype, cvalue: j.cvalue };
    document.getElementById('cpnwrap').style.display = 'block';   // rev 113b: exclusive match reveals the box
    document.getElementById('f_coupon').value = j.code;
    cpnMsg(true, '<i class="fas fa-gift"></i> <b>Your exclusive offer was applied automatically</b> — ' + j.label + ' (code ' + j.code + ', sent to your email). <a onclick="removeCoupon()" style="color:#166534;font-weight:700;cursor:pointer;text-decoration:underline">Remove</a>');
    renderQuote();
  }).catch(function(){});
}
function showErr(msg){ var e = document.getElementById('err'); e.textContent = msg; e.style.display = 'block'; }
function openTerms(){ document.getElementById('termsov').classList.add('open'); }
function closeTerms(){ document.getElementById('termsov').classList.remove('open'); }
function acceptTerms(){ document.getElementById('f_terms').checked = true; closeTerms(); document.getElementById('err').style.display = 'none'; }
function post(url, body){
  return fetch(url, { method:'POST', credentials:'same-origin',
    headers:{ 'Content-Type':'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, 'X-Requested-With':'XMLHttpRequest' },
    body: JSON.stringify(body) }).then(function(r){ return r.json(); });
}
function startCheckout(){
  document.getElementById('err').style.display = 'none';
  var b = document.getElementById('paybtn'); b.disabled = true;
  var body = {
    company: document.getElementById('f_company').value.trim(),
    admin_name: document.getElementById('f_name').value.trim(),
    admin_email: document.getElementById('f_email').value.trim(),
    mobile: document.getElementById('f_mobile').value.trim(),
    state: document.getElementById('f_state').value,
    gstin: document.getElementById('f_gstin').value.trim().toUpperCase(),
    subdomain: (document.getElementById('f_slug') ? document.getElementById('f_slug').value.trim().toLowerCase() : ''),
    plan_id: state.plan, seats: parseInt(document.getElementById('f_seats').value, 10) || 0,
    companies: Math.max(1, parseInt(document.getElementById('f_companies').value, 10) || 1), cycle: state.cycle,
    coupon: state.coupon ? state.coupon.code : ''
  };
  if (!body.company || !body.admin_name || !body.admin_email) { showErr('Please fill the company name, your name and your email.'); b.disabled = false; return; }
  if (!body.state) { showErr('Please select your state — it decides how GST appears on your tax invoice.'); b.disabled = false; return; }
  if (!document.getElementById('f_terms').checked) {
    showErr('Please read and accept the Terms & Conditions and Refund Policy to continue.');
    b.disabled = false; openTerms(); return;
  }
  body.terms_accepted = true;
  post('{{ url('/signup/order') }}', body).then(function(j){
    if (!j.ok) { showErr(j.error || 'Could not start the payment.'); b.disabled = false; return; }
    var rz = new Razorpay({
      key: j.keyId, order_id: j.orderId, amount: j.amountPaise, currency: 'INR',
      name: 'SmartPRS by Ametecs', description: 'Workspace subscription',
      prefill: { name: body.admin_name, email: body.admin_email, contact: body.mobile },
      theme: { color: '#f97316' },
      handler: function (resp) {
        post('{{ url('/signup/complete') }}', {
          uuid: j.uuid,
          razorpay_order_id: resp.razorpay_order_id,
          razorpay_payment_id: resp.razorpay_payment_id,
          razorpay_signature: resp.razorpay_signature
        }).then(function (c) {
          if (c.ok) {
            document.getElementById('formcard').style.display = 'none';
            document.getElementById('okmsg').textContent = c.message;
            var link = document.getElementById('oklink');
            if (c.redirect) {
              link.href = c.redirect;
              link.textContent = c.autoIn ? 'Open my workspace →' : 'Go to the sign-in page →';
            }
            document.getElementById('okpane').style.display = 'block';
            window.scrollTo(0, 0);
            // Signed in automatically? Take them straight into the workspace.
            if (c.autoIn && c.redirect) { setTimeout(function(){ window.location.href = c.redirect; }, 2500); }
          } else { showErr(c.error || 'Payment verification failed — please contact support with your payment id.'); }
          b.disabled = false;
        });
      },
      modal: { ondismiss: function(){ b.disabled = false; } }
    });
    rz.open();
  }).catch(function(){ showErr('Network error — please retry.'); b.disabled = false; });
}
function collectForm(){
  return {
    company: document.getElementById('f_company').value.trim(),
    admin_name: document.getElementById('f_name').value.trim(),
    admin_email: document.getElementById('f_email').value.trim(),
    mobile: document.getElementById('f_mobile').value.trim(),
    state: document.getElementById('f_state').value,
    gstin: document.getElementById('f_gstin').value.trim().toUpperCase(),
    subdomain: (document.getElementById('f_slug') ? document.getElementById('f_slug').value.trim().toLowerCase() : ''),
    plan_id: state.plan, seats: parseInt(document.getElementById('f_seats').value, 10) || 0,
    companies: Math.max(1, parseInt(document.getElementById('f_companies').value, 10) || 1), cycle: state.cycle
  };
}
function sendQuote(){
  document.getElementById('err').style.display = 'none';
  document.getElementById('quoteok').style.display = 'none';
  var body = collectForm();
  body.coupon = state.coupon ? state.coupon.code : '';   // rev 113: coupon locked into the quotation
  if (!body.company || !body.admin_name || !body.admin_email) { showErr('Please fill the company name, your name and your email — the quotation is sent to that email.'); return; }
  if (!body.state) { showErr('Please select your state so the quotation shows the correct GST.'); return; }
  var b = document.getElementById('quotebtn'); b.disabled = true; var old = b.innerHTML; b.innerHTML = 'Sending…';
  post('{{ url('/signup/quote') }}', body).then(function(j){
    b.disabled = false; b.innerHTML = old;
    if (!j.ok) { showErr(j.error || 'Could not send the quotation.'); return; }
    var ok = document.getElementById('quoteok');
    ok.innerHTML = '<i class="fas fa-circle-check"></i> ' + (j.message || 'Quotation sent.') + (j.pdf ? ' &nbsp;<a href="' + j.pdf + '" target="_blank" style="color:#166534;font-weight:700">View the PDF</a>' : '');
    ok.style.display = 'block';
    window.scrollTo(0, document.body.scrollHeight);
  }).catch(function(){ b.disabled = false; b.innerHTML = old; showErr('Network error — please retry.'); });
}
(function init(){
  var def = PLANS.filter(function(p){ return p.name === PICK; });
  state.plan = (def.length ? def[0] : PLANS[0]).id;
  renderPlans(); renderCycles(); renderQuote();
  document.getElementById('f_seats').addEventListener('input', renderQuote);
  document.getElementById('f_companies').addEventListener('input', renderQuote);
  // rev 113: catch the email → auto-apply any exclusive offer sent to it.
  document.getElementById('f_email').addEventListener('blur', exclusiveCheck);
  document.getElementById('f_email').addEventListener('change', exclusiveCheck);
})();
</script>
</body>
</html>
