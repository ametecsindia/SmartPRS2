<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Self-Onboarding — SmartPRS</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&family=DM+Sans:wght@400;500&display=swap" rel="stylesheet">
<style>
  :root{--navy:#0c1929;--navy3:#1a3350;--accent:#f97316;--accent-soft:rgba(249,115,22,.1);
    --blue:#3b82f6;--green:#10b981;--amber:#f59e0b;--red:#ef4444;
    --bg:#f0f4f8;--card:#fff;--border:#e2e8f0;--text1:#0f172a;--text2:#475569;--text3:#94a3b8;}
  *{box-sizing:border-box}
  body{margin:0;font-family:'Plus Jakarta Sans',system-ui,Segoe UI,sans-serif;background:var(--bg);color:var(--text1)}
  .topbar{background:var(--navy);color:#fff;display:flex;align-items:center;gap:12px;padding:12px 18px;position:sticky;top:0;z-index:10}
  .mark{width:32px;height:32px;border-radius:9px;background:linear-gradient(135deg,var(--accent),#ea580c);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:800;font-size:15px}
  .topbar b{font-weight:800;font-size:15px} .topbar b span{color:var(--accent)}
  .topbar .ref{margin-left:auto;font-size:11px;color:rgba(255,255,255,.6);font-family:'DM Sans',sans-serif}
  .wrap{max-width:600px;margin:20px auto;padding:0 14px}
  .card{background:var(--card);border:1px solid var(--border);border-radius:16px;box-shadow:0 12px 40px rgba(12,25,41,.06);overflow:hidden}
  .prog{height:6px;background:#e8edf4} .prog i{display:block;height:100%;background:linear-gradient(90deg,var(--accent),#ea580c);width:0;transition:width .3s}
  .chips{display:flex;flex-wrap:wrap;gap:5px;padding:12px 16px 4px}
  .chip{font-size:11px;font-weight:700;padding:4px 11px;border-radius:20px;background:#eef2f7;color:var(--text2);border:1px solid transparent;cursor:pointer}
  .chip.on{background:var(--accent);color:#fff}
  .chip.done{background:#eafaf0;color:#0b8a4b;border-color:#bfead0} .chip.done::before{content:"✔ "}
  .panel{display:none;padding:18px 20px 22px} .panel.on{display:block}
  h2{font-size:18px;margin:0 0 2px} .sub{color:var(--text2);font-size:13px;font-family:'DM Sans',sans-serif;margin:0 0 12px}
  .info{display:flex;gap:9px;align-items:flex-start;background:var(--accent-soft);border:1px solid #f7d9bd;border-left:4px solid var(--accent);border-radius:9px;padding:9px 12px;margin-bottom:14px;font-size:12.5px;color:#7c3a06;font-family:'DM Sans',sans-serif}
  .info .i{flex-shrink:0;width:18px;height:18px;border-radius:50%;background:var(--accent);color:#fff;text-align:center;line-height:18px;font-size:11px;font-weight:800;font-style:italic}
  label{display:block;font-size:11px;font-weight:700;color:var(--text2);margin:12px 0 5px;text-transform:uppercase;letter-spacing:.4px}
  input,select,textarea{width:100%;padding:11px 13px;border:1.5px solid var(--border);border-radius:10px;font-size:14px;color:var(--text1);background:#f8fafc;font-family:'DM Sans',sans-serif}
  input:focus,select:focus,textarea:focus{outline:none;border-color:var(--accent);background:#fff;box-shadow:0 0 0 3px var(--accent-soft)}
  .two{display:flex;gap:10px} .two>div{flex:1}
  .nav{display:flex;gap:10px;margin-top:20px}
  .btn{flex:1;text-align:center;padding:13px;border-radius:11px;border:none;font-size:14px;font-weight:700;font-family:inherit;cursor:pointer}
  .btn.primary{background:linear-gradient(135deg,var(--accent),#ea580c);color:#fff;box-shadow:0 6px 16px rgba(249,115,22,.28)}
  .btn.ghost{background:#fff;color:var(--accent);border:1.5px solid var(--accent)}
  .btn.sm{flex:none;padding:9px 14px;font-size:13px}
  .btn:disabled{opacity:.5;cursor:not-allowed;box-shadow:none}
  .vrow{display:flex;align-items:center;gap:10px;border:1px solid var(--border);border-radius:11px;padding:11px 12px;margin-bottom:10px;flex-wrap:wrap}
  .vrow .lbl{font-weight:700;font-size:13px;min-width:78px}
  .vrow .dest{color:var(--text2);font-size:12.5px;font-family:'DM Sans',sans-serif;flex:1;min-width:120px}
  .badge{font-size:11px;font-weight:800;padding:3px 9px;border-radius:20px}
  .badge.pend{background:#fff4e5;color:#b7791f} .badge.ok{background:#eafaf0;color:#0b8a4b}
  .codebox{display:flex;gap:8px;width:100%;margin-top:8px}
  .codebox input{letter-spacing:5px;text-align:center;font-weight:700}
  .hint{font-size:11.5px;color:var(--text3);margin-top:6px;font-family:'DM Sans',sans-serif}
  .cam{width:210px;height:250px;margin:6px auto;border-radius:14px;background:#0c1929;position:relative;overflow:hidden;display:flex;align-items:center;justify-content:center}
  .cam video,.cam img{width:100%;height:100%;object-fit:cover}
  .cam .guide{position:absolute;top:32px;left:50%;transform:translateX(-50%);width:120px;height:150px;border:2px dashed rgba(249,115,22,.8);border-radius:50%}
  .center{text-align:center}
  .doc{display:flex;align-items:center;gap:10px;border:1px solid var(--border);border-radius:10px;padding:10px 12px;margin-bottom:8px;font-size:13px}
  .doc .st{margin-left:auto;font-size:11px;font-weight:800}
  .doc .st.pend{color:var(--amber)} .doc .st.ok{color:var(--green)}
  .doc input[type=file]{display:none}
  .doc .pick{font-size:12px;font-weight:700;color:var(--accent);cursor:pointer;border:1px solid var(--accent);border-radius:8px;padding:5px 10px;background:#fff}
  .srow{display:flex;justify-content:space-between;padding:9px 0;border-bottom:1px solid #f1f5f9;font-size:13.5px}
  .srow .k{color:var(--text2)} .srow .v{font-weight:600}
  .toast{position:fixed;left:50%;bottom:24px;transform:translateX(-50%);background:var(--navy);color:#fff;padding:11px 18px;border-radius:10px;font-size:13px;opacity:0;transition:opacity .2s;z-index:50}
  .toast.show{opacity:1}
  .done-wrap{text-align:center;padding:36px 24px}
  .done-wrap .big{width:64px;height:64px;border-radius:50%;background:#eafaf0;color:var(--green);display:flex;align-items:center;justify-content:center;font-size:30px;margin:0 auto 16px}
  .foot{text-align:center;margin:16px 0;font-size:12px;color:var(--text3)} .foot b span{color:var(--accent)}
  .consent{display:flex;gap:9px;align-items:flex-start;font-size:12.5px;color:var(--text2);font-family:'DM Sans',sans-serif;margin-top:8px}
  .consent input{width:auto;margin-top:2px}
</style>
</head>
<body>
<div class="topbar"><img src="{{ $logoUrl ?? url('/images/logo.png') }}" alt="Company logo" style="height:30px;max-width:170px;object-fit:contain"><span class="ref" id="refCode"></span></div>
<div class="wrap">
  <div class="card">
    <div class="prog"><i id="bar"></i></div>
    <div class="chips" id="chips"></div>

    <!-- VERIFY -->
    <section class="panel" data-step="verify">
      <h2>Verify your contact details</h2>
      <p class="sub">We’ll send a one-time code to each so we know we can reach you.</p>
      <div class="info"><span class="i">i</span><span>Verify your <b>email</b> and <b>WhatsApp/mobile</b>. Tap “Send code”, then enter the 6-digit code.</span></div>
      <div class="vrow" data-ch="email">
        <span class="lbl">Email</span><span class="dest" data-dest="email"></span><span class="badge pend" data-badge>Pending</span>
        <button class="btn ghost sm" data-send>Send code</button>
        <div class="codebox" style="display:none" data-cbox><input maxlength="6" inputmode="numeric" placeholder="——————" data-code><button class="btn primary sm" data-verify>Verify</button></div>
        <div class="hint" data-hint></div>
      </div>
      <div class="vrow" data-ch="whatsapp">
        <span class="lbl">WhatsApp</span><span class="dest" data-dest="whatsapp"></span><span class="badge pend" data-badge>Pending</span>
        <button class="btn ghost sm" data-send>Send code</button>
        <div class="codebox" style="display:none" data-cbox><input maxlength="6" inputmode="numeric" placeholder="——————" data-code><button class="btn primary sm" data-verify>Verify</button></div>
        <div class="hint" data-hint></div>
      </div>
      <div class="nav"><button class="btn primary" data-next disabled id="verifyNext">Continue</button></div>
    </section>

    <!-- PERSONAL -->
    <section class="panel" data-step="personal">
      <h2>Personal details</h2><p class="sub">Step 2 of 8</p>
      <div class="info"><span class="i">i</span><span>Enter your name exactly as on your ID proof.</span></div>
      <label>Full name</label><input data-field="full_name">
      <div class="two"><div><label>Date of birth</label><input type="date" data-field="dob"></div>
      <div><label>Gender</label><select data-field="gender"><option value="">Select</option><option>Male</option><option>Female</option><option>Other</option></select></div></div>
      <label>Father / Guardian name</label><input data-field="father_name">
      <label>Nationality</label><input data-field="nationality" value="Indian">
      <div class="two"><div><label>Blood group</label><select data-field="blood_group"><option value="">Select</option><option>A+</option><option>A-</option><option>B+</option><option>B-</option><option>O+</option><option>O-</option><option>AB+</option><option>AB-</option></select></div>
      <div><label>Marital status</label><select data-field="marital"><option value="">Select</option><option>Single</option><option>Married</option><option>Widowed</option><option>Divorced</option></select></div></div>
      <div class="nav"><button class="btn ghost" data-back>Back</button><button class="btn primary" data-save data-section="personal">Save &amp; Next</button></div>
    </section>

    <!-- CONTACT -->
    <section class="panel" data-step="contact">
      <h2>Contact &amp; address</h2><p class="sub">Step 3 of 8</p>
      <div class="info"><span class="i">i</span><span>Give an address where you can receive documents. Add an emergency contact.</span></div>
      <label>Current address</label><textarea data-field="current_address" rows="2"></textarea>
      <label>Permanent address</label><textarea data-field="permanent_address" rows="2"></textarea>
      <div class="two"><div><label>Emergency contact name</label><input data-field="emergency_name"></div>
      <div><label>Emergency contact no.</label><input data-field="emergency_phone"></div></div>
      <div class="nav"><button class="btn ghost" data-back>Back</button><button class="btn primary" data-save data-section="contact">Save &amp; Next</button></div>
    </section>

    <!-- STATUTORY -->
    <section class="panel" data-step="statutory">
      <h2>Statutory IDs</h2><p class="sub">Step 4 of 8</p>
      <div class="info"><span class="i">i</span><span>Enter carefully — these are used for payroll &amp; tax.</span></div>
      <label>PAN</label><input data-field="pan" style="text-transform:uppercase" maxlength="10">
      <div class="two"><div><label>UAN / PF no. (if any)</label><input data-field="uan"></div>
      <div><label>Aadhaar / National ID</label><input data-field="aadhaar"></div></div>
      <div class="two"><div><label>ESIC no. (if any)</label><input data-field="esic"></div>
      <div><label>Category</label><select data-field="category"><option value="">Select</option><option>General</option><option>OBC</option><option>SC</option><option>ST</option><option>EWS</option></select></div></div>
      <div class="two"><div><label>DRA Status — valid DRA certificate? <span style="color:#c0392b">*</span></label><select data-field="dra_status"><option value="">Select</option><option>Yes</option><option>No</option></select></div>
      <div><label>PCC Status — Police Clearance obtained? <span style="color:#c0392b">*</span></label><select data-field="pcc_status"><option value="">Select</option><option>Yes</option><option>No</option></select></div></div>
      <div class="nav"><button class="btn ghost" data-back>Back</button><button class="btn primary" data-save data-section="statutory">Save &amp; Next</button></div>
    </section>

    <!-- BANK -->
    <section class="panel" data-step="bank">
      <h2>Bank details</h2><p class="sub">Step 5 of 8</p>
      <div class="info"><span class="i">i</span><span>Enter the account where your salary will be paid. Double-check the account number and IFSC.</span></div>
      <label>Account holder name</label><input data-field="acc_name">
      <label>Account number</label><input data-field="acc_no">
      <div class="two"><div><label>IFSC</label><input data-field="ifsc" style="text-transform:uppercase"></div>
      <div><label>Bank name</label><input data-field="bank_name"></div></div>
      <div class="nav"><button class="btn ghost" data-back>Back</button><button class="btn primary" data-save data-section="bank">Save &amp; Next</button></div>
    </section>

    <!-- SELFIE -->
    <section class="panel" data-step="selfie">
      <h2>Take your photo</h2><p class="sub">Step 6 of 8</p>
      <div class="info"><span class="i">i</span><span>Plain background, good lighting, face inside the outline, no cap/sunglasses. You can retake.</span></div>
      <div class="cam" id="cam"><span class="guide" id="guide"></span><video id="video" autoplay playsinline muted></video><img id="shot" style="display:none"></div>
      <div class="center" style="margin-top:10px">
        <button class="btn primary sm" id="capBtn" style="display:inline-block">Capture</button>
        <button class="btn ghost sm" id="retakeBtn" style="display:none">Retake</button>
        <label class="pick" style="display:inline-block;margin-left:8px" for="selfieUp">Upload instead</label>
        <input type="file" id="selfieUp" accept="image/*" style="display:none">
      </div>
      <div class="nav"><button class="btn ghost" data-back>Back</button><button class="btn primary" data-next id="selfieNext" disabled>Continue</button></div>
    </section>

    <!-- DOCUMENTS -->
    <section class="panel" data-step="documents">
      <h2>Upload documents</h2><p class="sub">Step 7 of 8</p>
      <div class="info"><span class="i">i</span><span>Clear, readable copies (PDF/JPG/PNG, max 5 MB). Blurred files will be sent back.</span></div>
      <div id="docList"></div>
      <div class="nav"><button class="btn ghost" data-back>Back</button><button class="btn primary" data-next>Continue</button></div>
    </section>

    <!-- REVIEW -->
    <section class="panel" data-step="review">
      <h2>Review &amp; submit</h2><p class="sub">Step 8 of 8</p>
      <div id="summary"></div>
      <label class="consent"><input type="checkbox" id="consent"> I confirm the information is true and consent to its processing for my onboarding.</label>
      <div class="nav"><button class="btn ghost" data-back>Back</button><button class="btn primary" id="submitBtn" disabled>Submit for verification</button></div>
    </section>

    <!-- DONE -->
    <section class="panel" data-step="done">
      <div class="done-wrap"><div class="big">✓</div><h2>Onboarding submitted</h2>
        <p class="sub">Thank you! Your details are with the HR team for verification. If anything is missing, we’ll email you. You can close this page.</p></div>
    </section>
  </div>
  <div class="foot">Powered by <b>Smart<span>PRS</span></b></div>
</div>
<div class="toast" id="toast"></div>
<canvas id="canvas" style="display:none"></canvas>

<script>
window.SO = {
  base: "{{ url('/self-onboard/'.$rec->token) }}",
  csrf: "{{ csrf_token() }}",
  tempCode: @json($rec->temp_emp_code),
  name: @json($rec->name),
  email: @json($rec->email),
  mobile: @json($rec->mobile),
  whatsapp: @json($rec->whatsapp),
  data: @json($data),
  flags: {email_verified: {{ $rec->email_verified ? 'true':'false' }}, mobile_verified: {{ $rec->mobile_verified ? 'true':'false' }}, wa_verified: {{ $rec->wa_verified ? 'true':'false' }}},
  hasSelfie: {{ $hasSelfie ? 'true':'false' }},
  docKinds: @json($docKinds),
};
</script>
@verbatim
<script>
(function(){
  var SO=window.SO;
  var STEPS=['verify','personal','contact','statutory','bank','selfie','documents','review'];
  var DOCS=[{k:'id',l:'ID proof (Aadhaar / Passport)'},{k:'address',l:'Address proof'},{k:'education',l:'Education certificate'},{k:'experience',l:'Experience / relieving letter'},{k:'bank',l:'Bank proof (cheque / passbook)'}];
  var done={}, cur='verify';
  document.getElementById('refCode').textContent='Ref: '+(SO.tempCode||'');

  function $(s,r){return (r||document).querySelector(s);}
  function $all(s,r){return Array.prototype.slice.call((r||document).querySelectorAll(s));}
  function toast(m){var t=$('#toast');t.textContent=m;t.classList.add('show');clearTimeout(t._h);t._h=setTimeout(function(){t.classList.remove('show');},2600);}
  function mask(v){if(!v)return '—';v=String(v);if(v.indexOf('@')>0){var p=v.split('@');return p[0].slice(0,2)+'•••@'+p[1];}return v.length>4?('•••••'+v.slice(-4)):v;}

  function post(path,body,isForm){
    var opt={method:'POST',headers:{'X-CSRF-TOKEN':SO.csrf,'X-Requested-With':'XMLHttpRequest'}};
    if(isForm){opt.body=body;}else{opt.headers['Content-Type']='application/json';opt.body=JSON.stringify(body||{});}
    return fetch(SO.base+path,opt).then(function(r){return r.json().catch(function(){return {ok:false,error:'Server error'};});});
  }

  // initial done state
  if(SO.flags.email_verified && (SO.flags.mobile_verified||SO.flags.wa_verified)) done.verify=true;
  ['personal','contact','statutory','bank'].forEach(function(s){if(SO.data&&SO.data[s]&&Object.keys(SO.data[s]).length)done[s]=true;});
  if(SO.hasSelfie)done.selfie=true;
  if(SO.docKinds&&SO.docKinds.length)done.documents=true;

  // prefill fields
  ['personal','contact','statutory','bank'].forEach(function(s){
    var d=(SO.data||{})[s]||{};
    $all('[data-step="'+s+'"] [data-field]').forEach(function(el){if(d[el.getAttribute('data-field')]!=null)el.value=d[el.getAttribute('data-field')];});
  });

  // chips
  function renderChips(){
    var c=$('#chips');c.innerHTML='';
    STEPS.forEach(function(s,i){
      var ch=document.createElement('span');ch.className='chip'+(s===cur?' on':'')+(done[s]?' done':'');
      ch.textContent=s.charAt(0).toUpperCase()+s.slice(1);
      ch.onclick=function(){ show(s); };
      c.appendChild(ch);
    });
  }
  function canEnter(s){var i=STEPS.indexOf(s);for(var j=0;j<i;j++){if(!done[STEPS[j]]&&STEPS[j]!=='documents')return false;}return true;}
  function progressPct(){var n=0;STEPS.forEach(function(s){if(done[s])n++;});return Math.round(n/8*100);}
  function bar(){$('#bar').style.width=progressPct()+'%';}

  function show(step){
    cur=step;
    $all('.panel').forEach(function(p){p.classList.toggle('on',p.getAttribute('data-step')===step);});
    renderChips();bar();
    if(step==='selfie')startCam();else stopCam();
    if(step==='review')buildSummary();
    window.scrollTo(0,0);
  }

  // navigation buttons
  $all('[data-back]').forEach(function(b){b.onclick=function(){var i=STEPS.indexOf(cur);if(i>0)show(STEPS[i-1]);};});
  $all('[data-next]').forEach(function(b){b.onclick=function(){var i=STEPS.indexOf(cur);if(i<STEPS.length-1)show(STEPS[i+1]);};});
  $all('[data-save]').forEach(function(b){b.onclick=function(){
    var sec=b.getAttribute('data-section');
    var d={};$all('[data-step="'+sec+'"] [data-field]').forEach(function(el){d[el.getAttribute('data-field')]=el.value.trim();});
    if(sec==='statutory'&&(!d.dra_status||!d.pcc_status)){toast('Please select DRA Status and PCC Status (Yes / No) — both are required.');return;}
    b.disabled=true;
    post('/save',{section:sec,data:d}).then(function(r){b.disabled=false;
      if(!r.ok){toast(r.error||'Could not save');return;}
      done[sec]=true;var i=STEPS.indexOf(cur);show(STEPS[i+1]);
    });
  };});

  // ----- OTP -----
  $all('.vrow').forEach(function(row){
    var ch=row.getAttribute('data-ch');
    var dest=ch==='email'?SO.email:(SO.whatsapp||SO.mobile);
    $('[data-dest]',row).textContent=mask(dest);
    var verified=(ch==='email')?SO.flags.email_verified:(SO.flags.wa_verified||SO.flags.mobile_verified);
    if(verified) markVerified(row);
    $('[data-send]',row).onclick=function(){
      var b=this;b.disabled=true;b.textContent='Sending…';
      post('/otp/send',{channel:ch}).then(function(r){b.disabled=false;b.textContent='Resend';
        if(!r.ok){toast(r.error||'Could not send');return;}
        $('[data-cbox]',row).style.display='flex';
        if(r.dev_code){$('[data-code]',row).value=r.dev_code;$('[data-hint]',row).textContent='(dev) code: '+r.dev_code;}
        else toast('Code sent');
      });
    };
    $('[data-verify]',row).onclick=function(){
      var code=$('[data-code]',row).value.trim();if(!code){toast('Enter the code');return;}
      var b=this;b.disabled=true;
      post('/otp/verify',{channel:ch,code:code}).then(function(r){b.disabled=false;
        if(!r.ok){toast(r.error||'Wrong code');return;}
        SO.flags=Object.assign(SO.flags,{email_verified:r.verified.email,mobile_verified:r.verified.mobile,wa_verified:r.verified.whatsapp});
        markVerified(row);checkVerifyDone();
      });
    };
  });
  function markVerified(row){$('[data-badge]',row).textContent='Verified';$('[data-badge]',row).className='badge ok';var s=$('[data-send]',row);if(s)s.style.display='none';var cb=$('[data-cbox]',row);if(cb)cb.style.display='none';}
  function checkVerifyDone(){var ok=SO.flags.email_verified&&(SO.flags.mobile_verified||SO.flags.wa_verified);$('#verifyNext').disabled=!ok;if(ok)done.verify=true;renderChips();bar();}
  checkVerifyDone();

  // ----- SELFIE -----
  var stream=null;
  function startCam(){
    var v=$('#video'),shot=$('#shot');
    if(SO.hasSelfie){v.style.display='none';shot.style.display='block';shot.src=SO.base+'/selfie?ts='+Date.now();$('#capBtn').style.display='none';$('#retakeBtn').style.display='inline-block';$('#selfieNext').disabled=false;$('#guide').style.display='none';return;}
    if(!navigator.mediaDevices||!navigator.mediaDevices.getUserMedia){return;}
    navigator.mediaDevices.getUserMedia({video:{facingMode:'user'}}).then(function(s){stream=s;v.srcObject=s;v.style.display='block';shot.style.display='none';}).catch(function(){toast('Camera unavailable — use “Upload instead”.');});
  }
  function stopCam(){if(stream){stream.getTracks().forEach(function(t){t.stop();});stream=null;}}
  $('#capBtn').onclick=function(){
    var v=$('#video'),c=$('#canvas');c.width=v.videoWidth||480;c.height=v.videoHeight||600;c.getContext('2d').drawImage(v,0,0,c.width,c.height);
    var img=c.toDataURL('image/jpeg',0.85);uploadSelfie(img);
  };
  $('#selfieUp').onchange=function(){var f=this.files[0];if(!f)return;var rd=new FileReader();rd.onload=function(){uploadSelfie(rd.result);};rd.readAsDataURL(f);};
  $('#retakeBtn').onclick=function(){SO.hasSelfie=false;$('#shot').style.display='none';$('#retakeBtn').style.display='none';$('#capBtn').style.display='inline-block';$('#guide').style.display='block';$('#selfieNext').disabled=true;startCam();};
  function uploadSelfie(img){
    post('/selfie',{image:img}).then(function(r){
      if(!r.ok){toast(r.error||'Could not save photo');return;}
      stopCam();var shot=$('#shot');shot.src=img;shot.style.display='block';$('#video').style.display='none';$('#guide').style.display='none';
      $('#capBtn').style.display='none';$('#retakeBtn').style.display='inline-block';$('#selfieNext').disabled=false;
      SO.hasSelfie=true;done.selfie=true;renderChips();bar();toast('Photo saved');
    });
  }

  // ----- DOCUMENTS -----
  function renderDocs(){
    var host=$('#docList');host.innerHTML='';
    DOCS.forEach(function(d){
      var up=(SO.docKinds||[]).indexOf(d.k)>=0;
      var row=document.createElement('div');row.className='doc';
      row.innerHTML='<span>'+d.l+'</span><span class="st '+(up?'ok':'pend')+'">'+(up?'Uploaded ✔':'Required')+'</span>'+
        '<label class="pick" for="f_'+d.k+'">'+(up?'Replace':'Upload')+'</label><input type="file" id="f_'+d.k+'" accept=".pdf,.jpg,.jpeg,.png">';
      host.appendChild(row);
      row.querySelector('input').onchange=function(){
        var f=this.files[0];if(!f)return;var fd=new FormData();fd.append('kind',d.k);fd.append('file',f);
        var st=row.querySelector('.st');st.textContent='Uploading…';
        post('/document',fd,true).then(function(r){
          if(!r.ok){st.textContent='Failed';st.className='st pend';toast(r.error||'Upload failed');return;}
          SO.docKinds=r.kinds||SO.docKinds;st.textContent='Uploaded ✔';st.className='st ok';
          done.documents=true;renderChips();bar();
        });
      };
    });
  }
  renderDocs();

  // ----- REVIEW -----
  function buildSummary(){
    var d=SO.data||{};var p=d.personal||{},b=d.bank||{};
    var rows=[['Name',p.full_name||SO.name||'—'],['Date of birth',p.dob||'—'],
      ['Email',(SO.flags.email_verified?'Verified ✔ ':'')+mask(SO.email)],
      ['Mobile/WhatsApp',((SO.flags.mobile_verified||SO.flags.wa_verified)?'Verified ✔ ':'')+mask(SO.whatsapp||SO.mobile)],
      ['Bank a/c',b.acc_no?('•••'+String(b.acc_no).slice(-4)):'—'],
      ['Selfie',SO.hasSelfie?'Captured ✔':'—'],
      ['Documents',((SO.docKinds||[]).length)+' uploaded']];
    $('#summary').innerHTML=rows.map(function(r){return '<div class="srow"><span class="k">'+r[0]+'</span><span class="v">'+r[1]+'</span></div>';}).join('');
  }
  $('#consent').onchange=function(){$('#submitBtn').disabled=!this.checked;};
  $('#submitBtn').onclick=function(){
    var b=this;b.disabled=true;b.textContent='Submitting…';
    post('/submit',{}).then(function(r){b.textContent='Submit for verification';
      if(!r.ok){b.disabled=false;toast(r.error||'Could not submit');return;}
      done.review=true;show('done');
    });
  };

  // resume at first incomplete step
  var startAt='verify';for(var i=0;i<STEPS.length;i++){if(!done[STEPS[i]]){startAt=STEPS[i];break;}if(i===STEPS.length-1)startAt='review';}
  show(startAt);
})();
</script>
@endverbatim
</body>
</html>
