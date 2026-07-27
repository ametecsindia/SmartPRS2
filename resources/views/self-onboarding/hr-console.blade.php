<!DOCTYPE html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>Self-Onboarding — Verification Console</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&family=DM+Sans:wght@400;500&display=swap" rel="stylesheet">
<style>
  :root{--navy:#0c1929;--accent:#f97316;--accent-soft:rgba(249,115,22,.1);--blue:#3b82f6;--green:#10b981;--amber:#f59e0b;--red:#ef4444;--bg:#f0f4f8;--card:#fff;--border:#e2e8f0;--text1:#0f172a;--text2:#475569;--text3:#94a3b8;}
  *{box-sizing:border-box} body{margin:0;font-family:'Plus Jakarta Sans',system-ui,Segoe UI,sans-serif;background:var(--bg);color:var(--text1)}
  .topbar{background:var(--navy);color:#fff;display:flex;align-items:center;gap:12px;padding:11px 18px;position:sticky;top:0;z-index:10}
  .topbar .t{font-size:13px;color:rgba(255,255,255,.65);font-family:'DM Sans',sans-serif} .topbar .sp{margin-left:auto}
  .topbar a{color:rgba(255,255,255,.8);font-size:12px;text-decoration:none;border:1px solid rgba(255,255,255,.2);padding:6px 12px;border-radius:8px}
  .invite{background:linear-gradient(135deg,var(--accent),#ea580c);color:#fff;border:none;font-weight:700;font-size:12.5px;padding:8px 14px;border-radius:8px;cursor:pointer;font-family:inherit}
  .layout{display:flex;gap:16px;max-width:1180px;margin:16px auto;padding:0 14px;align-items:flex-start}
  .list{width:330px;flex-shrink:0} .detail{flex:1;min-height:200px}
  .card{background:var(--card);border:1px solid var(--border);border-radius:14px;box-shadow:0 8px 26px rgba(12,25,41,.05)} .list .card{padding:8px}
  .row{padding:11px 12px;border-radius:10px;cursor:pointer;border:1px solid transparent} .row:hover{background:#f8fafc} .row.on{background:var(--accent-soft);border-color:#f7d9bd}
  .row .nm{font-weight:700;font-size:14px} .row .meta{font-size:11.5px;color:var(--text2);font-family:'DM Sans',sans-serif;margin-top:3px;display:flex;gap:8px;flex-wrap:wrap}
  .pill{font-size:10.5px;font-weight:800;padding:2px 9px;border-radius:20px}
  .p-sub{background:#fff4e5;color:#b7791f} .p-cor{background:#fdecec;color:#c0392b} .p-ver{background:#eafaf0;color:#0b8a4b} .p-app{background:#e8f0fe;color:#2b62c9}
  .empty{padding:40px 16px;text-align:center;color:var(--text3);font-size:13px}
  .dhead{padding:16px 18px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:12px;flex-wrap:wrap}
  .dhead h2{margin:0;font-size:18px} .dhead .code{font-size:12px;color:var(--text3);font-family:'DM Sans',sans-serif}
  .badges{display:flex;gap:6px;margin-left:auto;flex-wrap:wrap}
  .badge{font-size:11px;font-weight:800;padding:3px 9px;border-radius:20px} .badge.ok{background:#eafaf0;color:#0b8a4b} .badge.no{background:#f1f5f9;color:#94a3b8}
  .dbody{padding:16px 18px;display:flex;gap:18px;flex-wrap:wrap} .col{flex:1;min-width:240px}
  h3{font-size:12px;text-transform:uppercase;letter-spacing:.5px;color:var(--text2);margin:14px 0 8px}
  .kv{display:flex;justify-content:space-between;padding:7px 0;border-bottom:1px solid #f1f5f9;font-size:13px} .kv .k{color:var(--text2)} .kv .v{font-weight:600;text-align:right;max-width:60%}
  .selfie{width:120px;height:150px;border-radius:12px;object-fit:cover;border:1px solid var(--border);background:#f1f5f9}
  .doc{display:flex;align-items:center;gap:8px;border:1px solid var(--border);border-radius:9px;padding:8px 10px;margin-bottom:7px;font-size:13px} .doc a{margin-left:auto;color:var(--accent);font-weight:700;text-decoration:none;font-size:12px}
  .hrf{padding:0 18px 6px} .hrf .rr{display:flex;gap:10px} .hrf .rr>div{flex:1}
  .actions{padding:14px 18px;border-top:1px solid var(--border);display:flex;gap:10px;flex-wrap:wrap}
  .btn{padding:11px 16px;border-radius:10px;border:none;font-weight:700;font-size:13px;font-family:inherit;cursor:pointer}
  .btn.primary{background:linear-gradient(135deg,var(--accent),#ea580c);color:#fff} .btn.green{background:linear-gradient(135deg,#10b981,#059669);color:#fff} .btn.ghost{background:#fff;color:var(--text2);border:1.5px solid var(--border)}
  .corr{padding:14px 18px;border-top:1px solid var(--border);display:none} .corr.on{display:block}
  textarea,input,select{width:100%;padding:10px 12px;border:1.5px solid var(--border);border-radius:10px;font-family:'DM Sans',sans-serif;font-size:13px}
  label{display:block;font-size:11px;font-weight:700;color:var(--text2);margin:10px 0 5px;text-transform:uppercase;letter-spacing:.4px}
  .toast{position:fixed;left:50%;bottom:24px;transform:translateX(-50%);background:var(--navy);color:#fff;padding:11px 18px;border-radius:10px;font-size:13px;opacity:0;transition:opacity .2s;z-index:80} .toast.show{opacity:1}
  .mov{position:fixed;inset:0;background:rgba(12,25,41,.5);display:none;align-items:center;justify-content:center;z-index:70;padding:16px} .mov.on{display:flex}
  .modal{background:#fff;border-radius:16px;width:100%;max-width:430px;padding:22px}
  .modal h2{margin:0 0 4px;font-size:18px} .modal .s{color:var(--text2);font-size:13px;font-family:'DM Sans',sans-serif;margin:0 0 8px} .rr{display:flex;gap:10px} .rr>div{flex:1}
  .okbox{background:#eafaf0;border:1px solid #bfead0;border-radius:10px;padding:10px 12px;font-size:12.5px;margin-top:10px;word-break:break-all}
  .res{border:1px solid var(--border);border-radius:10px;max-height:180px;overflow:auto;margin-top:6px} .res div{padding:8px 11px;font-size:13px;cursor:pointer;border-bottom:1px solid #f1f5f9} .res div:hover{background:var(--accent-soft)}
</style></head>
<body>
<div class="topbar"><img src="{{ $logoUrl ?? url('/images/logo.png') }}" alt="Company logo" style="height:30px;max-width:170px;object-fit:contain"><span class="t">Self-Onboarding · Verification Console</span>
  <span class="sp"></span><button class="invite" id="inviteBtn">+ Invite</button><button class="invite" id="bulkBtn" style="background:#1a3350">⬆ Bulk import</button><a href="{{ url('/app') }}">← Back to app</a></div>
<div class="layout">
  <div class="list"><div class="card" id="listCard"><div class="empty">Loading…</div></div></div>
  <div class="detail"><div class="card" id="detailCard"><div class="empty">Select a submission to review, or invite someone to onboard.</div></div></div>
</div>

<div class="mov" id="inviteMov"><div class="modal">
  <h2>Invite to Self-Onboarding</h2><p class="s">Issue a Temp-EMP ID and send the onboarding link.</p>
  <label>Type</label><select id="ivMode"><option value="new">New candidate</option><option value="existing">Existing employee</option></select>
  <div id="ivNew"><label>Full name</label><input id="ivName" placeholder="Candidate name">
    <div class="rr"><div><label>Email</label><input id="ivEmail" placeholder="name@email.com"></div><div><label>Mobile / WhatsApp</label><input id="ivMobile" placeholder="+91…"></div></div></div>
  <div id="ivExisting" style="display:none"><label>Search employee</label><input id="ivSearch" placeholder="Type code or name…" autocomplete="off"><div class="res" id="ivResults" style="display:none"></div></div>
  <div id="ivResult"></div>
  <div style="display:flex;gap:10px;margin-top:14px"><button class="btn primary" id="ivSend">Send invite</button><button class="btn ghost" id="ivCancel">Close</button></div>
</div></div>

<div class="mov" id="bulkMov"><div class="modal">
  <h2>Bulk import existing staff</h2><p class="s">Upload a CSV. Each row gets a Temp-EMP ID and is auto-matched to an existing employee (old code / PAN / email / mobile).</p>
  <a class="btn ghost" href="{{ route('app.selfonboard.bulk.template') }}" style="display:inline-block;text-decoration:none;margin-bottom:10px">⬇ Download CSV template</a>
  <label>CSV file</label><input type="file" id="bulkFile" accept=".csv,.txt"><div id="bulkResult"></div>
  <div style="display:flex;gap:10px;margin-top:14px"><button class="btn primary" id="bulkUpload">Upload &amp; match</button><button class="btn green" id="bulkCommit" style="display:none">Commit → create/link</button><button class="btn ghost" id="bulkCancel">Close</button></div>
</div></div>

<div class="toast" id="toast"></div>
<script>
(function(){
  var CSRF=document.querySelector('meta[name=csrf-token]').content, curId=null, ivEmpId=null;
  function $(s,r){return (r||document).querySelector(s);}
  function toast(m){var t=$('#toast');t.textContent=m;t.classList.add('show');clearTimeout(t._h);t._h=setTimeout(function(){t.classList.remove('show');},3000);}
  function esc(s){return (s==null?'':String(s)).replace(/[&<>"]/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];});}
  function pill(s){var m={submitted:['p-sub','Pending verify'],correction:['p-cor','Correction sent'],verified:['p-ver','Verified'],approved:['p-app','Approved'],injected:['p-app','Injected'],link_sent:['p-sub','Invited'],opened:['p-sub','Opened'],in_progress:['p-sub','In progress'],verifying:['p-sub','Verifying']};var x=m[s]||['p-sub',s];return '<span class="pill '+x[0]+'">'+x[1]+'</span>';}
  function api(path,opt){opt=opt||{};opt.headers=Object.assign({'X-CSRF-TOKEN':CSRF,'X-Requested-With':'XMLHttpRequest'},opt.headers||{});return fetch(path,opt).then(function(r){return r.json().catch(function(){return{ok:false,error:'Server error'};});});}
  function jpost(p,b){return api(p,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(b||{})});}

  function loadList(){api('{{ route('app.selfonboard.list') }}').then(function(r){var host=$('#listCard');if(!r.ok){host.innerHTML='<div class="empty">'+(r.error||'Could not load')+'</div>';return;}if(!r.rows.length){host.innerHTML='<div class="empty">No submissions yet. Use “+ Invite” or “Bulk import”.</div>';return;}host.innerHTML=r.rows.map(function(x){return '<div class="row" data-id="'+x.id+'"><div class="nm">'+esc(x.name||'—')+' '+pill(x.status)+'</div><div class="meta"><span>'+esc(x.temp_emp_code)+'</span><span>'+esc(x.mode||'')+'</span><span>E '+(x.email_verified?'✔':'—')+' M '+(x.mobile_verified?'✔':'—')+'</span><span>'+x.docs+' docs</span></div></div>';}).join('');Array.prototype.forEach.call(host.querySelectorAll('.row'),function(el){el.onclick=function(){select(el.getAttribute('data-id'));};});if(curId){var c=host.querySelector('.row[data-id="'+curId+'"]');if(c)c.classList.add('on');}});}
  function select(id){curId=id;Array.prototype.forEach.call(document.querySelectorAll('.list .row'),function(el){el.classList.toggle('on',el.getAttribute('data-id')===id);});api('/app/self-onboarding/'+id).then(function(r){if(!r.ok){toast(r.error||'Load failed');return;}renderDetail(r.rec);});}
  function kvB(t,obj,map){if(!obj)obj={};return '<h3>'+t+'</h3>'+map.map(function(m){return '<div class="kv"><span class="k">'+m[1]+'</span><span class="v">'+esc(obj[m[0]]||'—')+'</span></div>';}).join('');}
  function hfInput(id,label,val){return '<div><label>'+label+'</label><input id="'+id+'" value="'+esc(val||'')+'"></div>';}

  function renderDetail(rec){
    var d=rec.data||{},hr=rec.hr||{};
    var badges='<span class="badge '+(rec.email_verified?'ok':'no')+'">Email '+(rec.email_verified?'✔':'—')+'</span><span class="badge '+(rec.mobile_verified?'ok':'no')+'">Mobile '+(rec.mobile_verified?'✔':'—')+'</span><span class="badge '+(rec.wa_verified?'ok':'no')+'">WhatsApp '+(rec.wa_verified?'✔':'—')+'</span>';
    var docs=(rec.docs||[]).length? rec.docs.map(function(x){return '<div class="doc"><span>'+esc(x.kind)+'</span><a href="'+x.url+'" target="_blank">View</a></div>';}).join('') : '<div style="color:#94a3b8;font-size:12px">No documents.</div>';
    var selfie=rec.selfie? '<img class="selfie" src="'+rec.selfie+'">' : '<div class="selfie"></div>';
    var flags=(rec.flags&&rec.flags.length)? '<div style="background:#fdecec;color:#c0392b;border-radius:9px;padding:8px 11px;font-size:12px;margin-bottom:8px">Awaiting correction: '+rec.flags.map(esc).join('; ')+'</div>' : '';
    function sel(id,label,opts,val){return '<div><label>'+label+'</label><select id="'+id+'">'+opts.map(function(o){var v=(typeof o==='object')?o.v:o,t=(typeof o==='object')?o.t:o;return '<option value="'+esc(v)+'"'+(String(val||'')===String(v)?' selected':'')+'>'+esc(t)+'</option>';}).join('')+'</select></div>';}
    var hrForm='<div class="hrf"><h3>HR fields (applied on approve)</h3>'+
      '<div class="rr">'+hfInput('hf_designation','Designation',hr.designation)+hfInput('hf_department','Department',hr.department)+'</div>'+
      '<div class="rr">'+hfInput('hf_branch','Branch',hr.branch)+'<div><label>Date of Joining</label><input type="date" id="hf_doj" value="'+esc(hr.doj||'')+'"></div></div>'+
      '<div class="rr">'+sel('hf_employment_type','Employment type',['',{v:'Permanent',t:'Permanent'},{v:'Contract',t:'Contract'},{v:'Probation',t:'Probation'},{v:'Intern',t:'Intern'}],hr.employment_type)+sel('hf_work_type','Work type',['',{v:'office',t:'Office'},{v:'field',t:'Field'}],hr.work_type)+'</div>'+
      '<div class="rr">'+hfInput('hf_ctc','CTC (annual)',hr.ctc)+hfInput('hf_pf_uan','PF / UAN No.',hr.pf_uan)+'</div>'+
      '<div class="rr">'+sel('hf_pf_applicable','PF applicable',['',{v:'yes',t:'Yes'},{v:'no',t:'No'}],hr.pf_applicable)+hfInput('hf_esic_no','ESIC No.',hr.esic_no)+'</div>'+
      '<div class="rr">'+sel('hf_esi_applicable','ESI applicable',['',{v:'auto',t:'Auto'},{v:'yes',t:'Yes'},{v:'no',t:'No'}],hr.esi_applicable)+hfInput('hf_pt_state','PT State',hr.pt_state)+'</div>'+
      '<button class="btn ghost" id="saveHr" style="margin-top:10px">Save HR fields</button></div>';
    var html='<div class="dhead"><h2>'+esc(rec.name||'—')+'</h2><span class="code">'+esc(rec.temp_emp_code)+' · '+esc(rec.mode||'')+' · '+pill(rec.status)+'</span><div class="badges">'+badges+'</div></div>'+
      '<div class="dbody"><div class="col">'+flags+
        kvB('Personal',d.personal,[['full_name','Full name'],['dob','Date of birth'],['gender','Gender'],['father_name','Father/Guardian'],['nationality','Nationality'],['blood_group','Blood group'],['marital','Marital status']])+
        kvB('Contact',d.contact,[['current_address','Current address'],['permanent_address','Permanent address'],['emergency_name','Emergency name'],['emergency_phone','Emergency phone']])+
        kvB('Statutory',d.statutory,[['pan','PAN'],['uan','UAN'],['aadhaar','Aadhaar/National ID'],['esic','ESIC'],['category','Category'],['dra_status','DRA (Yes/No)'],['pcc_status','PCC (Yes/No)']])+
        kvB('Bank',d.bank,[['acc_name','A/c name'],['acc_no','A/c number'],['ifsc','IFSC'],['bank_name','Bank']])+
      '</div><div class="col" style="max-width:200px"><h3>Selfie</h3>'+selfie+'<h3>Documents</h3>'+docs+'</div></div>'+
      hrForm+
      '<div class="actions"><button class="btn ghost" id="askCorr">Request Correction</button><button class="btn green" id="doVerify">Mark Verified</button><button class="btn primary" id="doApprove">Approve &amp; Inject</button></div>'+
      '<div class="corr" id="corrBox"><h3>Items to correct (one per line)</h3><textarea id="corrItems" rows="3" placeholder="e.g. Education certificate unreadable"></textarea><h3 style="margin-top:10px">Note (optional)</h3><textarea id="corrNote" rows="2"></textarea><div style="margin-top:10px;display:flex;gap:10px"><button class="btn primary" id="sendCorr">Send &amp; notify</button><button class="btn ghost" id="cancelCorr">Cancel</button></div></div>';
    var host=$('#detailCard');host.innerHTML=html;
    function dim(el,txt){if(!el)return;el.disabled=true;el.style.opacity=.55;if(txt)el.textContent=txt;}
    var st=rec.status;
    if(st==='verified'){dim($('#doVerify'),'Verified ✔');} else {dim($('#doApprove'));}
    if(st==='approved'||st==='injected'){dim($('#doVerify'),'Verified ✔');dim($('#doApprove'),'Injected ✔');}
    $('#saveHr').onclick=function(){var b=this;b.disabled=true;var body={};['designation','department','branch','doj','employment_type','work_type','ctc','pf_uan','pf_applicable','esic_no','esi_applicable','pt_state'].forEach(function(k){body[k]=($('#hf_'+k)||{}).value||'';});jpost('/app/self-onboarding/'+rec.id+'/fields',body).then(function(r){b.disabled=false;toast(r.ok?'HR fields saved':(r.error||'Failed'));});};
    $('#askCorr').onclick=function(){$('#corrBox').classList.add('on');};
    $('#cancelCorr').onclick=function(){$('#corrBox').classList.remove('on');};
    $('#sendCorr').onclick=function(){var items=$('#corrItems').value.split('\n').map(function(s){return s.trim();}).filter(Boolean);if(!items.length){toast('Add at least one item');return;}this.disabled=true;jpost('/app/self-onboarding/'+rec.id+'/correction',{items:items,note:$('#corrNote').value}).then(function(r){if(!r.ok){toast(r.error||'Failed');return;}toast('Correction sent');loadList();select(rec.id);});};
    $('#doVerify').onclick=function(){this.disabled=true;jpost('/app/self-onboarding/'+rec.id+'/verify',{}).then(function(r){if(!r.ok){toast(r.error||'Failed');return;}toast('Marked verified');loadList();select(rec.id);});};
    $('#doApprove').onclick=function(){this.disabled=true;this.textContent='Injecting…';jpost('/app/self-onboarding/'+rec.id+'/approve',{}).then(function(r){if(!r.ok){this.disabled=false;this.textContent='Approve & Inject';toast(r.error||'Failed');return;}toast((r.updated?'Updated employee ':'Injected as ')+(r.emp_code||''));loadList();select(rec.id);}.bind(this));};
  }

  // Invite (new + existing search)
  $('#inviteBtn').onclick=function(){$('#ivResult').innerHTML='';ivEmpId=null;$('#inviteMov').classList.add('on');};
  $('#ivCancel').onclick=function(){$('#inviteMov').classList.remove('on');};
  $('#ivMode').onchange=function(){var ex=this.value==='existing';$('#ivNew').style.display=ex?'none':'block';$('#ivExisting').style.display=ex?'block':'none';};
  var sT=null;
  $('#ivSearch').oninput=function(){ivEmpId=null;var q=this.value.trim();clearTimeout(sT);if(q.length<1){$('#ivResults').style.display='none';return;}sT=setTimeout(function(){api('{{ route('app.selfonboard.employees') }}?q='+encodeURIComponent(q)).then(function(r){if(!r.ok)return;var res=$('#ivResults');if(!r.rows.length){res.innerHTML='<div style="color:#94a3b8">No match</div>';res.style.display='block';return;}res.innerHTML=r.rows.map(function(e){return '<div data-id="'+e.id+'" data-nm="'+esc(e.name)+' ('+esc(e.emp_code)+')">'+esc(e.name)+' <b>'+esc(e.emp_code)+'</b></div>';}).join('');res.style.display='block';Array.prototype.forEach.call(res.querySelectorAll('div[data-id]'),function(el){el.onclick=function(){ivEmpId=el.getAttribute('data-id');$('#ivSearch').value=el.getAttribute('data-nm');res.style.display='none';};});});},250);};
  $('#ivSend').onclick=function(){
    var mode=$('#ivMode').value,b=this;
    if(mode==='existing'){
      if(!ivEmpId&&!$('#ivSearch').value.trim()){toast('Search and pick an employee');return;}
      b.disabled=true;b.textContent='Sending…';
      jpost('{{ route('app.selfonboard.invite.existing') }}',{employee_id:ivEmpId,emp:$('#ivSearch').value.trim()}).then(function(r){b.disabled=false;b.textContent='Send invite';if(!r.ok){toast(r.error||'Could not invite');return;}$('#ivResult').innerHTML='<div class="okbox">✔ Invited <b>'+esc(r.name)+'</b> ('+esc(r.emp_code_existing)+') · '+esc(r.temp_emp_code)+'<br>Link: <a href="'+r.link+'" target="_blank">'+r.link+'</a></div>';toast('Invite sent');loadList();});
      return;
    }
    var name=$('#ivName').value.trim(),email=$('#ivEmail').value.trim(),mobile=$('#ivMobile').value.trim();
    if(!name||(!email&&!mobile)){toast('Enter a name and an email or mobile');return;}
    b.disabled=true;b.textContent='Sending…';
    jpost('{{ route('app.selfonboard.invite') }}',{name:name,email:email,mobile:mobile,mode:'new'}).then(function(r){b.disabled=false;b.textContent='Send invite';if(!r.ok){toast(r.error||'Could not invite');return;}$('#ivResult').innerHTML='<div class="okbox">✔ Invited <b>'+esc(name)+'</b> · '+esc(r.temp_emp_code)+'<br>Link: <a href="'+r.link+'" target="_blank">'+r.link+'</a></div>';$('#ivName').value='';$('#ivEmail').value='';$('#ivMobile').value='';toast('Invite sent');loadList();});
  };

  // Bulk
  $('#bulkBtn').onclick=function(){$('#bulkResult').innerHTML='';$('#bulkCommit').style.display='none';$('#bulkMov').classList.add('on');};
  $('#bulkCancel').onclick=function(){$('#bulkMov').classList.remove('on');};
  $('#bulkUpload').onclick=function(){var f=$('#bulkFile').files[0];if(!f){toast('Choose a CSV file');return;}var fd=new FormData();fd.append('file',f);var b=this;b.disabled=true;b.textContent='Uploading…';api('{{ route('app.selfonboard.bulk.upload') }}',{method:'POST',body:fd}).then(function(r){b.disabled=false;b.textContent='Upload & match';if(!r.ok){toast(r.error||'Upload failed');return;}var pv=(r.preview||[]).map(function(x){return '<div class="kv"><span class="k">'+esc(x.name)+' · '+esc(x.temp)+'</span><span class="v">'+(x.match?('matched '+esc(x.match)):'new')+'</span></div>';}).join('');$('#bulkResult').innerHTML='<div class="okbox">Staged <b>'+r.total+'</b> · matched '+r.matched+' · new '+r.new+(r.errors?(' · errors '+r.errors):'')+'</div>'+pv;if(r.total>0)$('#bulkCommit').style.display='inline-block';loadList();});};
  $('#bulkCommit').onclick=function(){var b=this;b.disabled=true;b.textContent='Committing…';jpost('{{ route('app.selfonboard.bulk.commit') }}',{}).then(function(r){b.disabled=false;b.textContent='Commit → create/link';if(!r.ok){toast(r.error||'Commit failed');return;}$('#bulkResult').innerHTML='<div class="okbox">✔ Created '+r.created+' new · updated '+r.updated+' existing.</div>';toast('Bulk committed');loadList();});};

  loadList();
})();
</script>
</body></html>
