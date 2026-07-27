<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $title }} — SmartPRS</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
<style>
  :root{--navy:#0c1929;--accent:#f97316;--bg:#f0f4f8;--card:#fff;--border:#e2e8f0;--text2:#475569}
  *{box-sizing:border-box}
  body{margin:0;font-family:'Plus Jakarta Sans',system-ui,Segoe UI,sans-serif;background:var(--bg);color:var(--navy);
       min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px}
  .card{background:var(--card);border:1px solid var(--border);border-radius:16px;max-width:440px;width:100%;
        padding:34px 30px;text-align:center;box-shadow:0 12px 40px rgba(12,25,41,.08)}
  .mark{width:56px;height:56px;border-radius:15px;background:linear-gradient(135deg,var(--accent),#ea580c);
        display:flex;align-items:center;justify-content:center;margin:0 auto 18px;color:#fff;font-size:24px;font-weight:800;
        box-shadow:0 12px 26px rgba(249,115,22,.32)}
  h1{font-size:20px;margin:0 0 8px}
  p{color:var(--text2);font-size:14px;line-height:1.6;margin:0}
  .brand{margin-top:22px;font-size:12px;color:#94a3b8}
  .brand b{color:var(--navy)} .brand b span{color:var(--accent)}
</style>
</head>
<body>
  <div class="card">
    <div class="mark">i</div>
    <h1>{{ $title }}</h1>
    <p>{{ $msg }}</p>
    <div class="brand">Powered by <b>Smart<span>PRS</span></b></div>
  </div>
</body>
</html>
