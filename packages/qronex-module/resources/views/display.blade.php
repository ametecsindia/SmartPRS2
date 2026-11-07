<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>{{ $gate->office->name }} · {{ $gate->name }} · {{ $company }}</title>
<script src="{{ route('qronex.asset', ['f' => 'qrcode.min.js']) }}"></script>
<style>
  :root{--navy:#0E2747;--teal:#0A8278}
  *{box-sizing:border-box}html,body{margin:0;height:100%}
  body{background:var(--navy);color:#EAF0F7;font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:2vh;padding:2vh 16px;text-align:center}
  .logo{background:#fff;border-radius:12px;padding:8px 16px;display:inline-flex}.logo{display:inline-flex;align-items:center;gap:.5em}.logo .qx-mark{height:clamp(28px,5vh,56px);width:clamp(28px,5vh,56px)}.logo b{color:var(--navy);font-size:1.6rem}
  h1{margin:0;font-size:clamp(1.2rem,3.4vh,2.4rem)}.sub{color:#A8B9CF;font-size:clamp(.9rem,2.2vh,1.4rem)}
  #qr{background:#fff;padding:clamp(12px,2.5vh,28px);border-radius:20px;width:min(70vh,86vw);aspect-ratio:1;display:flex;align-items:center;justify-content:center;transition:transform .15s}
  #qr img,#qr canvas{width:100%!important;height:100%!important;image-rendering:pixelated}
  #qr.flash{transform:scale(.985)}
  .bar{width:min(70vh,86vw);height:8px;background:#21497A;border-radius:4px;overflow:hidden}.bar i{display:block;height:100%;background:#14B8A6;transform-origin:left}
  .row{display:flex;gap:24px;justify-content:center;flex-wrap:wrap;font-size:clamp(.9rem,2.2vh,1.4rem)}
  .clock{font-variant-numeric:tabular-nums;font-size:clamp(1.4rem,4.5vh,3rem);font-weight:700}
  .off{color:#FCA5A5}.msg{font-size:clamp(1rem,3vh,2rem);max-width:80vw}
</style>
</head>
<body>
  <div class="logo"><svg viewBox="0 0 32 32" class="qx-mark" aria-hidden="true"><rect width="32" height="32" rx="8" fill="#0E2747"/><rect x="6" y="6" width="8" height="8" rx="1.5" fill="none" stroke="#fff" stroke-width="2"/><rect x="18" y="6" width="8" height="8" rx="1.5" fill="none" stroke="#fff" stroke-width="2"/><rect x="6" y="18" width="8" height="8" rx="1.5" fill="none" stroke="#fff" stroke-width="2"/><circle cx="22" cy="22" r="3.2" fill="#14B8A6"/></svg><b style="color:#0E2747;letter-spacing:-.01em">{{ $company }}</b></div>
  <div><h1>{{ $gate->office->name }} · {{ $gate->name }}</h1><div class="sub">Open the QRONEX app and scan to mark attendance</div></div>
  <div id="qr" role="img" aria-label="Attendance QR code"></div>
  <div class="bar"><i id="bar"></i></div>
  <div class="row"><span class="clock" id="clock">--:--:--</span></div>
  <div class="row"><span id="date"></span><span id="net">Connecting…</span></div>
<script>
const TOKEN_URL = @js($tokenUrl), TZ = 'Asia/Kolkata';
const box = document.getElementById('qr'), bar = document.getElementById('bar'), net = document.getElementById('net');
let qr = null, expiresAt = 0, interval = 3, skew = 0, timer = null;

function render(text) {
  box.innerHTML = '';
  if (!text) { box.innerHTML = '<div class="msg" style="color:#0E2747">This gate is not active</div>'; return; }
  new QRCode(box, { text, width: 1024, height: 1024, correctLevel: QRCode.CorrectLevel.M });
  box.classList.add('flash'); setTimeout(() => box.classList.remove('flash'), 150);
}
async function refresh() {
  clearTimeout(timer);
  try {
    const r = await fetch(TOKEN_URL, { headers: { Accept: 'application/json' }, cache: 'no-store' });
    if (r.status === 403 || r.status === 404) { box.innerHTML = '<div class="msg" style="color:#0E2747">Display link was reset. Open the new link from QR Attendance → Offices & gates.</div>'; net.textContent = 'Stopped'; net.className = 'off'; return; }
    if (!r.ok) throw new Error(r.status);
    const d = await r.json();
    skew = Date.parse(d.server_time) - Date.now();
    interval = d.interval; expiresAt = Date.now() + d.expires_in * 1000;
    if (d.qr !== qr) { qr = d.qr; render(qr); }
    net.textContent = '● Online'; net.className = '';
    timer = setTimeout(refresh, Math.max(250, d.expires_in * 1000 + 150));
  } catch (e) {
    // Offline: hide the code so a stale one isn't scanned, retry quickly.
    qr = null; box.innerHTML = '<div class="msg" style="color:#0E2747">Reconnecting…</div>'; net.textContent = 'Offline — codes paused'; net.className = 'off';
    timer = setTimeout(refresh, 2000);
  }
}
setInterval(() => {
  const left = Math.max(0, expiresAt - Date.now());
  bar.style.transform = `scaleX(${Math.min(1, left / (interval * 1000))})`;
  const now = new Date(Date.now() + skew);
  document.getElementById('clock').textContent = now.toLocaleTimeString('en-IN', { timeZone: TZ, hour12: false });
  document.getElementById('date').textContent = now.toLocaleDateString('en-IN', { timeZone: TZ, weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
}, 100);
refresh();
// Keep the screen awake where supported (tablets / Chrome).
navigator.wakeLock?.request('screen').catch(() => {});
document.addEventListener('visibilitychange', () => { if (!document.hidden) { navigator.wakeLock?.request('screen').catch(() => {}); refresh(); } });
</script>
</body>
</html>
