<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>{{ $title }} — Live Demonstration · SmartPRS</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
    * { box-sizing: border-box; margin: 0; }
    body { font-family: 'Segoe UI', system-ui, -apple-system, sans-serif; background: linear-gradient(160deg, #0c1929 0%, #14253c 60%, #0c1929 100%); min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 24px; color: #e2e8f0; }
    .card { background: #fff; color: #0f172a; border-radius: 18px; max-width: 560px; width: 100%; padding: 38px 40px; box-shadow: 0 24px 70px rgba(0,0,0,.45); }
    .logo { height: 42px; display: block; margin-bottom: 22px; }
    .chip { display: inline-block; background: #f9731618; color: #c2570c; font-weight: 700; font-size: 12px; letter-spacing: .8px; text-transform: uppercase; border-radius: 999px; padding: 5px 13px; margin-bottom: 12px; }
    h1 { font-size: 27px; color: #0c1929; margin-bottom: 4px; }
    .sub { color: #f97316; font-weight: 700; font-size: 15px; margin-bottom: 16px; }
    p.blurb { font-size: 14.5px; line-height: 1.65; color: #334155; margin-bottom: 22px; }
    .note { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 11px; padding: 13px 16px; font-size: 12.5px; color: #64748b; line-height: 1.55; margin-bottom: 24px; }
    .note i { color: #f97316; margin-right: 6px; }
    .btn { display: inline-flex; align-items: center; gap: 9px; border: none; border-radius: 11px; padding: 13px 22px; font-size: 15px; font-weight: 700; cursor: pointer; text-decoration: none; }
    .btn-primary { background: #f97316; color: #fff; }
    .btn-primary:hover { background: #ea6308; }
    .btn-outline { background: #fff; color: #0c1929; border: 1.5px solid #cbd5e1; }
    .btn-outline:hover { border-color: #f97316; color: #c2570c; }
    .row { display: flex; gap: 12px; flex-wrap: wrap; }
    .err { background: #fef2f2; border: 1px solid #fecaca; color: #b91c1c; border-radius: 10px; padding: 11px 14px; font-size: 13px; margin-bottom: 18px; }
    .links { margin-top: 22px; font-size: 12.5px; color: #94a3b8; }
    .links a { color: #f97316; text-decoration: none; font-weight: 600; }
</style>
</head>
<body>
<div class="card">
    <img class="logo" src="{{ asset('images/logo.png') }}" alt="SmartPRS" onerror="this.style.display='none'">
    <span class="chip">Edition demonstration</span>
    <h1>{{ $title }}</h1>
    <div class="sub">{{ $subtitle }} Edition</div>
    <p class="blurb">{{ $blurb }}</p>

    @if (session('demo_err'))
        <div class="err"><i class="fas fa-circle-exclamation"></i> {{ session('demo_err') }}</div>
    @endif

    <div class="note"><i class="fas fa-flask"></i> A fully loaded sample workspace — every screen is live and clickable, nothing restricted. {{ $edition ? 'Only the modules licensed in '.$title.' are shown.' : 'All sixteen modules are shown.' }} Your passkey is valid for {{ $hours }} hour{{ $hours == 1 ? '' : 's' }}; the workspace then resets itself, so demonstrate boldly.</div>

    @if (request('expired'))
        <div class="err"><i class="fas fa-hourglass-end"></i> Your passkey window has ended and the workspace was reset. Request a fresh passkey below.</div>
    @endif

    @if ($ready)
        {{-- rev185: Team PIN removed — request a passkey (sent to email + WhatsApp), then enter it below. --}}
        <div style="margin-bottom:20px;">
            <div style="font-size:13px; font-weight:700; color:#0c1929; margin-bottom:8px;"><i class="fas fa-paper-plane" style="color:#f97316;"></i> Step 1 — request your passkey</div>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
                <input type="text" id="rq_name" maxlength="120" placeholder="Name*" style="padding:11px 14px; border:1.5px solid #cbd5e1; border-radius:10px; font-size:14px;">
                <input type="text" id="rq_mobile" maxlength="20" inputmode="tel" placeholder="Phone number (WhatsApp)*" style="padding:11px 14px; border:1.5px solid #cbd5e1; border-radius:10px; font-size:14px;">
                <input type="email" id="rq_email" maxlength="160" placeholder="Email ID*" style="grid-column:1 / -1; padding:11px 14px; border:1.5px solid #cbd5e1; border-radius:10px; font-size:14px;">
            </div>
            <button class="btn btn-primary" type="button" id="rq_btn" onclick="requestPin()" style="margin-top:12px;"><i class="fas fa-paper-plane"></i> Submit request</button>
            <div id="rq_msg" style="display:none; background:#f0fdf4; border:1px solid #bbf7d0; color:#166534; border-radius:10px; padding:10px 13px; font-size:13px; margin-top:12px;"></div>
            <div id="rq_err" style="display:none; background:#fef2f2; border:1px solid #fecaca; color:#b91c1c; border-radius:10px; padding:10px 13px; font-size:13px; margin-top:12px;"></div>
        </div>
        <form method="POST" action="{{ $action }}" style="border-top:1.5px dashed #e2e8f0; padding-top:18px;">
            @csrf
            <div style="font-size:13px; font-weight:700; color:#0c1929; margin-bottom:8px;"><i class="fas fa-key" style="color:#f97316;"></i> Step 2 — enter the passkey you received</div>
            <div style="margin-bottom: 16px;">
                <input type="text" name="pin" autocomplete="off" inputmode="numeric" maxlength="10" placeholder="Passkey" style="width:220px; padding:11px 14px; border:1.5px solid #cbd5e1; border-radius:10px; font-size:16px; letter-spacing:4px; font-weight:700;" required>
            </div>
            <div class="row">
                <button class="btn btn-primary" type="submit"><i class="fas fa-rocket"></i> Start the {{ $title }} demo</button>
                <button class="btn btn-outline" type="submit" name="tour" value="1"><i class="fas fa-wand-magic-sparkles"></i> Start with guided tour</button>
            </div>
        </form>
        <script>
        function requestPin(){
            var b=document.getElementById('rq_btn'); b.disabled=true;
            var m=document.getElementById('rq_msg'), e=document.getElementById('rq_err');
            m.style.display='none'; e.style.display='none';
            fetch('{{ $requestAction }}', { method:'POST', credentials:'same-origin',
                headers:{ 'Content-Type':'application/json', 'X-CSRF-TOKEN':'{{ csrf_token() }}', 'X-Requested-With':'XMLHttpRequest', 'Accept':'application/json' },
                body: JSON.stringify({ name:document.getElementById('rq_name').value.trim(), mobile:document.getElementById('rq_mobile').value.trim(), email:document.getElementById('rq_email').value.trim(), entry:'{{ $n }}' })
            }).then(function(r){ return r.json(); }).then(function(j){
                b.disabled=false;
                if(!j.ok){ e.textContent=j.error || 'Could not send the passkey — please retry.'; e.style.display='block'; return; }
                m.innerHTML='<i class="fas fa-circle-check"></i> ' + (j.message || 'Passkey sent — enter it in Step 2.');
                m.style.display='block';
            }).catch(function(){ b.disabled=false; e.textContent='Network error — please retry.'; e.style.display='block'; });
        }
        </script>
    @else
        <div class="err"><i class="fas fa-clock"></i> The demonstration workspace is being prepared — please try again in a couple of minutes.</div>
    @endif

    <div class="links">
        Team demos: <a href="{{ url('/teamdemo') }}">Complete platform</a> · <a href="{{ url('/app1') }}">L1 Core</a> · <a href="{{ url('/app2') }}">L2 Advanced</a> · <a href="{{ url('/app3') }}">L3 Collections DNA</a> &nbsp;|&nbsp; Visitors: <a href="{{ url('/demo') }}">Live Demo</a>
    </div>
</div>
</body>
</html>
