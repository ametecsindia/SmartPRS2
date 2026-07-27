<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>SmartPRS — Live Demo</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="icon" type="image/png" href="{{ asset('images/logo-icon.png') }}">
    <style>
        :root{--navy:#0c1929;--accent:#f97316;--bg:#f1f5f9;--mut:#64748b;--line:#e2e8f0;}
        *{margin:0;padding:0;box-sizing:border-box;font-family:'Segoe UI',system-ui,sans-serif;}
        body{background:var(--navy);min-height:100vh;}
        .top{padding:18px 24px;}
        .top img{height:36px;}
        .wrap{max-width:480px;margin:4vh auto;padding:0 16px;}
        .card{background:#fff;border-radius:18px;padding:30px;box-shadow:0 24px 60px rgba(0,0,0,.35);}
        h1{font-size:23px;color:var(--navy);margin-bottom:6px;}
        .sub{color:var(--mut);font-size:14px;line-height:1.55;margin-bottom:18px;}
        label{display:block;font-size:11px;font-weight:700;color:#334155;text-transform:uppercase;letter-spacing:.4px;margin:12px 0 5px;}
        input{width:100%;padding:11px 13px;border:1.5px solid #cbd5e1;border-radius:10px;font-size:15px;background:#f8fafc;}
        input:focus{outline:none;border-color:var(--accent);background:#fff;}
        .btn{display:block;width:100%;background:var(--accent);color:#fff;border:none;border-radius:10px;padding:14px;font-size:16px;font-weight:700;cursor:pointer;margin-top:20px;}
        .btn:disabled{opacity:.5;}
        .btn2{display:block;width:100%;background:var(--navy);color:#fff;border:none;border-radius:10px;padding:13px;font-size:15px;font-weight:700;cursor:pointer;margin-top:14px;}
        .btn2:disabled{opacity:.5;}
        .err{background:#fee2e2;color:#991b1b;border:1px solid #fecaca;border-radius:9px;padding:11px 13px;margin-top:14px;font-size:14px;display:none;}
        .ok{background:#f0fdf4;border:1px solid #bbf7d0;color:#166534;border-radius:10px;padding:11px 13px;margin-top:14px;font-size:14px;display:none;}
        .fine{font-size:12px;color:var(--mut);margin-top:14px;line-height:1.5;text-align:center;}
        .points{margin:14px 0 4px;font-size:13.5px;color:#334155;line-height:2;}
        .points i{color:#16a34a;width:18px;}
        .notready{background:#fef3c7;border:1px solid #fde68a;color:#92400e;border-radius:10px;padding:12px 14px;font-size:14px;margin-top:6px;}
        .expired{background:#fef3c7;border:1px solid #fde68a;color:#92400e;border-radius:10px;padding:12px 14px;font-size:14px;margin-bottom:14px;}
        .keybox{border-top:1.5px dashed var(--line);margin-top:24px;padding-top:8px;}
    </style>
</head>
<body>
    <div class="top"><a href="{{ url('/') }}"><img src="{{ asset('images/logo.png') }}" alt="SmartPRS by Ametecs"></a></div>
    <div class="wrap">
        <div class="card">
            @if(request('expired'))
                <div class="expired"><i class="fas fa-hourglass-end"></i> Your demo passkey window has ended and the workspace was reset. Please submit a new request below for a fresh passkey.</div>
            @endif
            <h1>Try SmartPRS live</h1>
            <div class="sub">A real, fully-loaded workspace with sample employees, attendance, payroll and incentives. Submit the request — your personal passkey arrives on email &amp; WhatsApp within moments.</div>
            <div class="points">
                <div><i class="fas fa-check"></i> No card. No signup. Just a passkey.</div>
                <div><i class="fas fa-check"></i> All 16 modules, live and clickable.</div>
                <div><i class="fas fa-check"></i> Your passkey is valid for {{ $hours }} hour{{ $hours == 1 ? '' : 's' }}; the demo then resets itself.</div>
            </div>
            @if(!$ready)
                <div class="notready"><i class="fas fa-rotate"></i> The demo is being refreshed right now — please try again in a couple of minutes.</div>
            @else
            <form id="reqForm" onsubmit="return requestPin(event)">
                <input type="text" name="website" value="" style="display:none" tabindex="-1" autocomplete="off">
                <label>Your name*</label>
                <input id="d_name" required maxlength="120" placeholder="Full name">
                <label>Phone number (WhatsApp)*</label>
                <input id="d_mobile" required maxlength="20" inputmode="tel" placeholder="10-digit mobile — passkey comes here">
                <label>Email ID*</label>
                <input id="d_email" type="email" required maxlength="160" placeholder="you@company.in — passkey also comes here">
                <label>Company (optional)</label>
                <input id="d_company" maxlength="160" placeholder="Your company / agency">
                <button class="btn" id="d_btn" type="submit"><i class="fas fa-paper-plane"></i> Submit request — get my passkey</button>
                <div class="ok" id="d_msg"></div>
                <div class="err" id="d_err"></div>
            </form>
            <div class="keybox">
                <form id="keyForm" onsubmit="return enterDemo(event)">
                    <label>Have your passkey? Enter it here*</label>
                    <input id="d_pin" required maxlength="10" inputmode="numeric" placeholder="······" style="letter-spacing:8px;font-size:20px;text-align:center;font-weight:800;">
                    <button class="btn2" id="d_btn2" type="submit"><i class="fas fa-play"></i> Enter the live demo</button>
                    <div class="err" id="d_err2"></div>
                </form>
            </div>
            @endif
            <div class="fine">By requesting the demo you agree to be contacted by Ametecs India about SmartPRS.<br>Shared demo environment — please don't enter real personal data. Everything you try is erased when your passkey window ends.</div>
        </div>
    </div>
    <script>
    function post(url, body){
        return fetch(url, { method:'POST', credentials:'same-origin',
            headers:{ 'Content-Type':'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, 'X-Requested-With':'XMLHttpRequest', 'Accept':'application/json' },
            body: JSON.stringify(body) }).then(function(r){ return r.json(); });
    }
    function requestPin(ev){
        if(ev){ ev.preventDefault(); }
        var b=document.getElementById('d_btn'); b.disabled=true;
        var e=document.getElementById('d_err'), m=document.getElementById('d_msg');
        e.style.display='none'; m.style.display='none';
        post('{{ route('demo.request') }}', {
            name:document.getElementById('d_name').value.trim(),
            mobile:document.getElementById('d_mobile').value.trim(),
            email:document.getElementById('d_email').value.trim(),
            company:document.getElementById('d_company').value.trim(),
            entry:'demo',
            website:document.querySelector('[name=website]').value
        }).then(function(j){
            b.disabled=false;
            if(!j.ok){ e.textContent=j.error || 'Could not send the passkey — please retry.'; e.style.display='block'; return; }
            m.innerHTML='<i class="fas fa-circle-check"></i> ' + (j.message || 'Passkey sent — enter it below.');
            m.style.display='block';
            document.getElementById('d_pin').focus();
        }).catch(function(){ b.disabled=false; e.textContent='Network error — please retry.'; e.style.display='block'; });
        return false;
    }
    function enterDemo(ev){
        ev.preventDefault();
        var b=document.getElementById('d_btn2'); b.disabled=true;
        var e=document.getElementById('d_err2'); e.style.display='none';
        post('{{ route('demo.start') }}', { pin:document.getElementById('d_pin').value.trim() }).then(function(j){
            if(j.ok && j.redirect){ window.location.href=j.redirect; return; }
            e.textContent=(j && j.error) || 'Could not start the demo — please retry.'; e.style.display='block'; b.disabled=false;
        }).catch(function(){ e.textContent='Network error — please retry.'; e.style.display='block'; b.disabled=false; });
        return false;
    }
    </script>
</body>
</html>
