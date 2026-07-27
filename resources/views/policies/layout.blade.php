<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title }} — SmartPRS by Ametecs</title>
    <link rel="icon" type="image/png" href="{{ asset('images/logo-icon.png') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root{--navy:#0a1628;--navy2:#102744;--accent:#f97316;--border:#e8edf3;--text:#0f1d33;--text2:#516074;--text3:#9aa7b8;--bg:#f6f8fb;}
        *{margin:0;padding:0;box-sizing:border-box;font-family:'Plus Jakarta Sans',sans-serif;}
        body{color:var(--text);background:var(--bg);line-height:1.65;-webkit-font-smoothing:antialiased;}
        a{color:var(--accent);text-decoration:none;}
        .wrap{max-width:880px;margin:0 auto;padding:0 clamp(16px,4vw,24px);}
        header{background:linear-gradient(135deg,var(--navy),var(--navy2));padding:34px 0 38px;}
        header .logo img{height:38px;width:auto;display:block;}
        header h1{color:#fff;font-size:clamp(1.45rem,3.4vw,2rem);margin-top:20px;font-weight:800;}
        header .sub{color:#b8c4d4;margin-top:6px;font-size:.98rem;}
        header .meta{color:var(--text3);margin-top:12px;font-size:.85rem;}
        header .back{color:#b8c4d4;font-size:.88rem;}
        header .back:hover{color:#fff;}
        main{background:#fff;border-bottom:1px solid var(--border);}
        article{padding:38px 0 56px;}
        article h2{font-size:1.18rem;font-weight:800;color:var(--navy);margin:30px 0 10px;}
        article h3{font-size:1.02rem;font-weight:700;color:var(--navy);margin:20px 0 8px;}
        article p{margin:0 0 12px;color:var(--text2);}
        article ul,article ol{margin:0 0 14px 22px;color:var(--text2);}
        article li{margin-bottom:7px;}
        article strong{color:var(--text);}
        article table{width:100%;border-collapse:collapse;margin:0 0 16px;font-size:.93rem;}
        article th{background:var(--navy);color:#fff;text-align:left;padding:9px 12px;font-weight:700;}
        article td{border:1px solid var(--border);padding:9px 12px;color:var(--text2);vertical-align:top;}
        .note{background:#fff7ed;border:1px solid #fed7aa;border-left:4px solid var(--accent);border-radius:10px;padding:14px 18px;margin:16px 0;color:#7c2d12;font-size:.95rem;}
        footer{padding:30px 0 44px;}
        footer .links{display:flex;flex-wrap:wrap;gap:8px 18px;margin-bottom:14px;}
        footer .links a{color:var(--text2);font-size:.88rem;}
        footer .links a.cur{color:var(--accent);font-weight:700;}
        footer .links a:hover{color:var(--accent);}
        footer .co{color:var(--text3);font-size:.85rem;}
    </style>
</head>
<body>
    <header>
        <div class="wrap">
            <a href="/" class="logo"><img src="{{ asset('images/logo.png') }}" alt="SmartPRS by Ametecs"></a>
            <h1>{{ $title }}</h1>
            <div class="sub">{{ $sub }}</div>
            <div class="meta">Effective date: {{ $effective }} · <a class="back" href="/">&larr; Back to smartprs.com</a></div>
        </div>
    </header>
    <main>
        <div class="wrap">
            <article>
                @yield('content')
            </article>
        </div>
    </main>
    <footer>
        <div class="wrap">
            <div class="links">
                @foreach ($pages as $s => $pg)
                    <a href="{{ url('/'.$s) }}" @class(['cur' => $s === $slug])>{{ $pg[0] }}</a>
                @endforeach
            </div>
            <div class="co">Ametecs India Private Limited · Modern Profound Techpark, Ground Floor, Hive Space, opp. Google, Whitefields, Kondapur, Hyderabad, Telangana, India 500084 · GST 36AAHCT0971F1ZB<br>
            Support: <a href="mailto:support@ametecsindia.com">support@ametecsindia.com</a> · WhatsApp +91 90000 98877 · Mon–Sat 10:00–19:00 IST</div>
        </div>
    </footer>
</body>
</html>
