<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Confidentiality Undertaking — {{ $company }}</title>
<style>
  :root{--navy:#0c1929;--accent:#f97316;--accent2:#ea580c;--line:#e2e8f0;--slate:#475569;}
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:'Plus Jakarta Sans','Segoe UI',system-ui,sans-serif;background:#f0f4f8;color:var(--navy);padding:28px 16px;line-height:1.6}
  .card{max-width:760px;margin:0 auto;background:#fff;border:1px solid var(--line);border-radius:16px;overflow:hidden}
  .head{display:flex;align-items:center;gap:14px;background:linear-gradient(135deg,var(--navy),#0e1c30);color:#fff;padding:22px 26px}
  .logo{width:42px;height:42px;border-radius:11px;background:linear-gradient(135deg,var(--accent),var(--accent2));display:flex;align-items:center;justify-content:center;font-size:20px;color:#fff;font-weight:800}
  .brand{font-weight:800;font-size:20px}
  .brand span{color:var(--accent)}
  .sub{font-size:13px;color:#cbd5e1}
  .inner{padding:24px 26px}
  .ok{background:#dcfce7;color:#166534;border:1px solid #bbf7d0;border-radius:10px;padding:12px 14px;font-weight:600;margin-bottom:18px}
  .body{white-space:normal;background:#f8fafc;border:1px solid var(--line);border-radius:12px;padding:18px 20px;font-size:15px;color:#1f2937;max-height:none}
  form{margin-top:20px}
  label{display:block;font-weight:600;font-size:14px;margin:14px 0 6px}
  input[type=text],input:not([type]){width:100%;padding:12px 14px;border:1px solid #cbd5e1;border-radius:10px;font-size:15px;font-family:inherit}
  input:focus{outline:none;border-color:var(--accent);box-shadow:0 0 0 3px rgba(249,115,22,.12)}
  .chk{display:flex;align-items:flex-start;gap:9px;font-weight:500;color:var(--slate);font-size:14px;margin-top:14px}
  .chk input{margin-top:3px}
  button{margin-top:20px;width:100%;padding:14px;border:none;border-radius:11px;background:linear-gradient(135deg,var(--accent),var(--accent2));color:#fff;font-size:16px;font-weight:700;cursor:pointer}
  button:hover{opacity:.94}
  .meta{margin-top:16px;font-size:12.5px;color:#94a3b8}
  .foot{margin-top:18px;text-align:center;font-size:12px;color:#94a3b8}
</style>
</head>
<body>
  <div class="card">
    <div class="head">
      <div class="logo">&#10003;</div>
      <div><div class="brand">Smart<span>PRS</span></div><div class="sub">Confidentiality &amp; Non-Disclosure Undertaking · {{ $company }}</div></div>
    </div>
    <div class="inner">
      @if ($signed || session('justSigned'))
        <div class="ok">&#10003; Signed{{ $letter->signed_name ? ', '.$letter->signed_name : '' }} — thank you. This undertaking has been recorded.</div>
      @endif

      <div class="body">{!! nl2br(e($body)) !!}</div>

      @if (! ($signed || session('justSigned')))
        <form method="post" action="{{ url('/nda/'.$token.'/sign') }}">
          <label for="nm">Type your full name to e-sign</label>
          <input type="text" id="nm" name="name" required placeholder="Your full name" autocomplete="name">
          <label class="chk"><input type="checkbox" required> I confirm I have read and agree to this confidentiality undertaking, and that typing my name above is my electronic signature.</label>
          <button type="submit">I agree &amp; e-sign</button>
        </form>
      @else
        <div class="meta">Recorded on {{ $letter->accepted_at }} (IP {{ $letter->accepted_ip }}). This page is your confirmation; the signed record is stored in SmartPRS.</div>
      @endif

      <div class="foot">Secured by a private link · SmartPRS by Ametecs</div>
    </div>
  </div>
</body>
</html>
