<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Activate SmartPRS</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
    * { box-sizing: border-box; margin: 0; }
    body { font-family: 'Segoe UI', system-ui, sans-serif; background: linear-gradient(160deg, #0c1929 0%, #14253c 60%, #0c1929 100%); min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 24px; }
    .card { background: #fff; border-radius: 18px; max-width: 540px; width: 100%; padding: 38px 40px; box-shadow: 0 24px 70px rgba(0,0,0,.45); }
    .logo { height: 42px; display: block; margin-bottom: 22px; }
    h1 { font-size: 25px; color: #0c1929; margin-bottom: 6px; }
    .sub { color: #f97316; font-weight: 700; font-size: 14px; margin-bottom: 18px; }
    p { font-size: 14px; color: #334155; line-height: 1.6; margin-bottom: 18px; }
    input[type=text] { width: 100%; padding: 13px 15px; border: 1.5px solid #cbd5e1; border-radius: 11px; font-size: 17px; letter-spacing: 1.5px; text-transform: uppercase; font-family: Consolas, monospace; }
    .btn { margin-top: 16px; display: inline-flex; align-items: center; gap: 9px; border: none; border-radius: 11px; padding: 13px 24px; font-size: 15px; font-weight: 700; cursor: pointer; background: #f97316; color: #fff; }
    .btn:hover { background: #ea6308; }
    .err { background: #fef2f2; border: 1px solid #fecaca; color: #b91c1c; border-radius: 10px; padding: 11px 14px; font-size: 13px; margin-bottom: 16px; }
    .ok { background: #f0fdf4; border: 1px solid #bbf7d0; color: #166534; border-radius: 10px; padding: 11px 14px; font-size: 13px; margin-bottom: 16px; }
    .note { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 11px; padding: 12px 15px; font-size: 12.5px; color: #64748b; line-height: 1.55; margin-top: 22px; }
</style>
</head>
<body>
<div class="card">
    <img class="logo" src="{{ asset('images/logo.png') }}" alt="SmartPRS" onerror="this.style.display='none'" style="filter: invert(0.85)">
    <h1>Activate your SmartPRS licence</h1>
    <div class="sub">{{ $edition }}</div>

    @if (session('lic_err'))
        <div class="err"><i class="fas fa-circle-exclamation"></i> {{ session('lic_err') }}</div>
    @endif
    @if ($activated)
        <div class="ok"><i class="fas fa-circle-check"></i> This installation is activated{{ !empty($state['company']) ? ' for '.$state['company'] : '' }}. You can re-enter a key only if Ametecs has released this licence for a server move.</div>
    @else
        <p>Enter the licence key from your SmartPRS welcome email. One key activates one server — after this, your team simply logs in and works.</p>
    @endif

    <form method="POST" action="{{ url('/app/activate') }}" enctype="multipart/form-data">
        @csrf
        <label style="display:block;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.6px;margin-bottom:7px">Licence key or .lic file</label>
        <input type="text" name="key" placeholder="Paste your licence code (SPRS-… or .lic)" autocomplete="off">
        <div style="margin-top:11px;font-size:12.5px;color:#64748b">…or upload the <strong>.lic</strong> file Ametecs sent you:</div>
        <input type="file" name="licence_file" accept=".lic,text/plain" style="margin-top:6px;font-size:13px;width:100%">
        <button class="btn" type="submit"><i class="fas fa-key"></i> Activate</button>
    </form>

    <div class="note" style="margin-top:18px">
        <div style="font-weight:700;color:#334155;margin-bottom:6px"><i class="fas fa-desktop" style="color:#f97316"></i> This device — share these with Ametecs to receive your licence file</div>
        @if(!empty($fingerprint ?? '')) <div style="word-break:break-all"><strong>Machine fingerprint:</strong> <span style="font-family:Consolas,monospace">{{ $fingerprint }}</span></div> @endif
        <div><strong>Active employees now:</strong> {{ $seatUsed ?? 0 }}@if(!empty($seatLimit)) / {{ $seatLimit }} licensed @endif</div>
        @if(!empty($deviceEmail ?? '')) <div><strong>Account email:</strong> {{ $deviceEmail }}</div> @endif
        @if(!empty($deviceIds ?? [])) <div style="word-break:break-all"><strong>Hardware ID(s):</strong> {{ implode(', ', $deviceIds) }}</div> @endif
    </div>

    <div class="note"><i class="fas fa-headset" style="color:#f97316"></i> Need help? Ametecs India — ejaz@ametecsindia.com · WhatsApp 9000098877. Activation needs internet on this server for one minute; after that SmartPRS runs fully offline.</div>
</div>
</body>
</html>
