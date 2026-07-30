<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Set up SmartPRS</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
  * { box-sizing: border-box; margin: 0; }
  body { font-family: 'Segoe UI', system-ui, sans-serif; background: linear-gradient(160deg,#0c1929 0%,#14253c 60%,#0c1929 100%); min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 24px; }
  .card { background:#fff; border-radius:18px; max-width:640px; width:100%; padding:34px 38px; box-shadow:0 24px 70px rgba(0,0,0,.45); }
  .logo { height:40px; margin-bottom:18px; filter: invert(0.85); }
  h1 { font-size:23px; color:#0c1929; margin-bottom:4px; }
  .sub { color:#f97316; font-weight:700; font-size:13px; margin-bottom:16px; }
  p.intro { font-size:13.5px; color:#334155; line-height:1.6; margin-bottom:18px; }
  h2 { font-size:12px; text-transform:uppercase; letter-spacing:.6px; color:#64748b; margin:22px 0 10px; }
  .chk { display:flex; align-items:flex-start; gap:10px; padding:7px 0; border-bottom:1px solid #f1f5f9; font-size:13px; }
  .chk .ic { width:18px; text-align:center; margin-top:1px; }
  .chk .ic.ok { color:#16a34a; } .chk .ic.warn { color:#f59e0b; } .chk .ic.err { color:#dc2626; }
  .chk .l { flex:1; color:#1e293b; font-weight:600; }
  .chk .d { color:#64748b; font-size:11.5px; font-weight:400; display:block; margin-top:1px; }
  .banner { border-radius:10px; padding:11px 14px; font-size:13px; margin:14px 0; }
  .banner.err { background:#fef2f2; border:1px solid #fecaca; color:#b91c1c; }
  .banner.ok { background:#f0fdf4; border:1px solid #bbf7d0; color:#166534; }
  label { display:block; font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:.5px; margin:14px 0 6px; }
  input[type=text],input[type=email],input[type=password] { width:100%; padding:12px 14px; border:1.5px solid #cbd5e1; border-radius:10px; font-size:15px; }
  .row2 { display:flex; gap:12px; } .row2 > div { flex:1; }
  .btn { margin-top:18px; display:inline-flex; align-items:center; gap:9px; border:none; border-radius:10px; padding:13px 26px; font-size:15px; font-weight:700; cursor:pointer; background:#f97316; color:#fff; }
  .btn:hover { background:#ea6308; }
  .btn.ghost { background:#e2e8f0; color:#334155; }
  .note { background:#f8fafc; border:1px solid #e2e8f0; border-radius:11px; padding:12px 15px; font-size:12px; color:#64748b; line-height:1.55; margin-top:20px; }
</style>
</head>
<body>
<div class="card">
  <img class="logo" src="{{ asset('images/logo.png') }}" alt="SmartPRS" onerror="this.style.display='none'">
  <h1>Set up your SmartPRS server</h1>
  <div class="sub">{{ $edition }} &nbsp;·&nbsp; First-run installation</div>
  <p class="intro">This one-time wizard checks your server, then creates your company and administrator login. There are no default passwords — the account you create here is your admin login.</p>

  @if (session('install_err'))
    <div class="banner err"><i class="fas fa-circle-exclamation"></i> {{ session('install_err') }}</div>
  @endif

  <h2>1 · Server requirements</h2>
  @foreach ($checks as $c)
    <div class="chk">
      @php $lvl = $c['ok'] ? 'ok' : ($c['level'] === 'warn' ? 'warn' : 'err'); @endphp
      <div class="ic {{ $lvl }}">
        <i class="fas {{ $c['ok'] ? 'fa-circle-check' : ($c['level']==='warn' ? 'fa-triangle-exclamation' : 'fa-circle-xmark') }}"></i>
      </div>
      <div class="l">{{ $c['label'] }}<span class="d">{{ $c['detail'] }}</span></div>
    </div>
  @endforeach

  <form method="GET" action="{{ url('/install') }}" style="margin-top:12px">
    <button class="btn ghost" type="submit"><i class="fas fa-rotate"></i> Re-check</button>
  </form>

  @if ($ready)
    <div class="banner ok"><i class="fas fa-circle-check"></i> All required checks passed. Create your administrator account below.</div>

    <h2>2 · Create your administrator</h2>
    <form method="POST" action="{{ url('/install') }}">
      @csrf
      <label>Company name</label>
      <input type="text" name="company" value="{{ old('company') }}" placeholder="Your company Pvt Ltd" required>
      <div class="row2">
        <div>
          <label>Administrator name</label>
          <input type="text" name="name" value="{{ old('name') }}" placeholder="Administrator">
        </div>
        <div>
          <label>Login email</label>
          <input type="email" name="email" value="{{ old('email') }}" placeholder="admin@yourcompany.in" required>
        </div>
      </div>
      <div class="row2">
        <div>
          <label>Password (min 8)</label>
          <input type="password" name="password" required>
        </div>
        <div>
          <label>Confirm password</label>
          <input type="password" name="password_confirmation" required>
        </div>
      </div>
      <button class="btn" type="submit"><i class="fas fa-circle-check"></i> Create &amp; finish setup</button>
    </form>
  @else
    <div class="banner err"><i class="fas fa-circle-exclamation"></i> Please resolve the items marked in red above, then press <b>Re-check</b>. The administrator form appears once all required checks pass.</div>
  @endif

  <div class="note"><i class="fas fa-headset" style="color:#f97316"></i> Ametecs India — ejaz@ametecsindia.com · WhatsApp 9000098877. After setup you'll sign in and activate your licence (.lic).</div>
</div>
</body>
</html>
