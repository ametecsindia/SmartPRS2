<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>SmartPRS Admin — @yield('title')</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        :root{--navy:#0c1929;--accent:#f97316;--bg:#f0f4f8;--card:#fff;--border:#e2e8f0;--text2:#475569;--text3:#94a3b8;--green:#10b981;--red:#ef4444;}
        *{margin:0;padding:0;box-sizing:border-box;font-family:'Plus Jakarta Sans',sans-serif;}
        body{background:var(--bg);color:var(--navy);}
        header{height:60px;background:var(--navy);color:#fff;display:flex;align-items:center;justify-content:space-between;padding:0 24px;}
        .logo{display:flex;align-items:center;gap:10px;font-weight:800;}
        .logo .mark{width:30px;height:30px;border-radius:8px;background:var(--accent);display:flex;align-items:center;justify-content:center;}
        .logo span{color:var(--accent);}
        header nav{display:flex;gap:8px;align-items:center;}
        header a,.lo{color:rgba(255,255,255,.8);font-size:13px;padding:8px 14px;border-radius:8px;border:1px solid rgba(255,255,255,.18);background:transparent;cursor:pointer;}
        header a:hover{background:rgba(255,255,255,.1);color:#fff;}
        header a.active{background:var(--accent);color:#fff;border-color:var(--accent);}
        main{max-width:1000px;margin:28px auto;padding:0 20px;}
        h1{font-size:22px;margin-bottom:4px;} .sub{color:var(--text2);font-size:13px;margin-bottom:22px;font-family:'DM Sans',sans-serif;}
        .card{background:var(--card);border:1px solid var(--border);border-radius:14px;padding:22px;margin-bottom:18px;}
        .card h3{font-size:14px;margin-bottom:16px;padding-bottom:12px;border-bottom:1px solid var(--border);}
        .grid{display:grid;grid-template-columns:repeat(2,1fr);gap:14px;}
        .grid.c3{grid-template-columns:repeat(3,1fr);}
        .fg{display:flex;flex-direction:column;gap:5px;margin-bottom:6px;}
        .fg.span2{grid-column:span 2;}
        label{font-size:11px;font-weight:600;color:var(--text2);text-transform:uppercase;letter-spacing:.5px;}
        input,select,textarea{padding:10px 12px;border:1.5px solid var(--border);border-radius:9px;font-size:14px;background:#f8fafc;outline:none;font-family:'DM Sans',sans-serif;}
        input:focus,select:focus,textarea:focus{border-color:var(--accent);background:#fff;}
        .btn{display:inline-flex;align-items:center;gap:7px;padding:10px 18px;border-radius:9px;font-weight:700;font-size:14px;border:none;cursor:pointer;}
        .btn-primary{background:var(--accent);color:#fff;} .btn-primary:hover{background:#ea6c0f;}
        .btn-outline{background:transparent;border:1px solid var(--border);color:var(--text2);}
        .flash{background:rgba(16,185,129,.12);color:#059669;padding:12px 16px;border-radius:10px;margin-bottom:18px;font-size:14px;font-family:'DM Sans',sans-serif;}
        table{width:100%;border-collapse:collapse;background:var(--card);border-radius:12px;overflow:hidden;}
        th{background:#f8fafc;text-align:left;padding:12px 16px;font-size:11px;text-transform:uppercase;color:var(--text3);border-bottom:1px solid var(--border);}
        td{padding:12px 16px;border-bottom:1px solid #f1f5f9;font-size:14px;}
        .pill{padding:3px 10px;border-radius:20px;font-size:12px;font-weight:600;background:rgba(249,115,22,.12);color:var(--accent);}
        .rowsec{border:1px dashed var(--border);border-radius:10px;padding:14px;margin-bottom:12px;}
        @media(max-width:760px){
            .grid,.grid.c3{grid-template-columns:1fr;}
            header{flex-direction:column;height:auto;padding:12px;gap:10px;}
            header nav{flex-wrap:wrap;justify-content:center;}
            main{margin:18px auto;}
        }
    </style>
</head>
<body>
    <header>
        <div class="logo"><img src="{{ asset('images/logo.png') }}" alt="SmartPRS" style="height:34px;width:auto;display:block;"> <span style="color:rgba(255,255,255,.75);font-weight:600;font-size:13px;margin-left:8px;">Admin</span></div>
        <nav>
            <a href="{{ route('landing.editor') }}" class="@yield('nav_landing')">Landing CMS</a>
            <a href="{{ route('admin.leads') }}" class="@yield('nav_leads')">Leads</a>
            <a href="{{ route('admin.quotations') }}" class="@yield('nav_quotes')">Quotations</a>
            <a href="{{ route('admin.coupons') }}" class="@yield('nav_coupons')">Coupons</a>
            <a href="{{ route('admin.onprem') }}" class="@yield('nav_onprem')">On-Prem Clients</a>
            <a href="{{ route('admin.releases') }}" class="@yield('nav_releases')">Releases</a>
            <a href="{{ route('admin.staff') }}" class="@yield('nav_staff')">Platform Staff</a>
            <a href="{{ route('app') }}">← Back to app</a>
            <form method="POST" action="{{ route('logout') }}" style="display:inline;">@csrf<button class="lo">Sign out</button></form>
        </nav>
    </header>
    <main>
        @if (session('success'))<div class="flash"><i class="fas fa-circle-check"></i> {{ session('success') }}</div>@endif
        @yield('content')
    </main>

    {{-- rev 100b: the same ⓘ screen-help system as the app, for the admin
         panel pages — so platform staff are self-service too. The key is
         derived from the URL: /admin/landing → admin-landing, etc. --}}
    <script>
    (function () {
        var key = 'admin-' + (window.location.pathname.split('/')[2] || 'panel');
        var h1 = document.querySelector('main h1');
        if (!h1) { return; }
        var ic = document.createElement('i');
        ic.className = 'fas fa-circle-info';
        ic.title = 'How this page works';
        ic.style.cssText = 'margin-left:8px;color:#94a3b8;cursor:pointer;font-size:15px;vertical-align:middle;';
        ic.onmouseenter = function () { ic.style.color = 'var(--accent)'; };
        ic.onmouseleave = function () { ic.style.color = '#94a3b8'; };
        ic.onclick = openHelp;
        h1.appendChild(ic);
        function esc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }
        function closeHelp() { var o = document.getElementById('ad-help'); if (o) { o.remove(); } }
        window.adHelpClose = closeHelp;
        window.adHelpTab = function (which) {
            var on = 'flex:1;border:none;background:none;padding:11px;font-size:13px;font-weight:700;cursor:pointer;color:#0c1929;border-bottom:2.5px solid var(--accent);';
            var off = 'flex:1;border:none;background:none;padding:11px;font-size:13px;font-weight:700;cursor:pointer;color:#64748b;border-bottom:2.5px solid transparent;';
            ['how', 'why', 'mk'].forEach(function (k) {
                var d = document.getElementById('adh-' + k);
                if (d) { d.style.display = k === which ? 'block' : 'none'; }
                var b = document.getElementById('adh-tb-' + k);
                if (b) { b.style.cssText = k === which ? on : off; }
            });
        };
        function openHelp() {
            var ov = document.createElement('div');
            ov.id = 'ad-help';
            ov.style.cssText = 'position:fixed;inset:0;z-index:9500;background:rgba(12,25,41,.55);display:flex;align-items:flex-start;justify-content:center;padding:36px 14px;overflow:auto;';
            ov.onclick = function (e) { if (e.target === ov) { closeHelp(); } };
            ov.innerHTML = '<div style="max-width:560px;width:100%;background:#fff;border-radius:14px;margin:auto;padding:40px;text-align:center;color:#94a3b8;">Loading the guide…</div>';
            document.body.appendChild(ov);
            fetch('{{ url('/app/help') }}/' + encodeURIComponent(key), { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (j) { render((j && j.help) || null); })
                .catch(function () { render(null); });
        }
        function render(h) {
            var ov = document.getElementById('ad-help'); if (!ov) { return; }
            if (!h) { ov.innerHTML = '<div style="max-width:560px;width:100%;background:#fff;border-radius:14px;margin:auto;padding:40px;text-align:center;color:#94a3b8;">Could not load the guide right now.</div>'; return; }
            var chip = function (col, bg, icon, label) { return '<div style="margin:0 0 8px;"><span style="font-size:12px;font-weight:700;color:' + col + ';background:' + bg + ';display:inline-block;padding:3px 11px;border-radius:99px;"><i class="fas ' + icon + '" style="font-size:12px;"></i> ' + label + '</span></div>'; };
            var feats = (h.f || []).map(function (x) { return '<div style="border:1px solid var(--border);border-radius:9px;padding:8px 11px;font-size:12.5px;color:#334155;"><i class="fas ' + esc(x[0]) + '" style="color:var(--accent);font-size:13px;"></i> ' + esc(x[1]) + '</div>'; }).join('');
            var steps = (h.s || []).map(function (tx, i) { return '<div style="display:flex;gap:11px;margin-bottom:9px;"><span style="flex:0 0 22px;height:22px;border-radius:50%;background:var(--accent);color:#fff;font-size:12px;font-weight:700;display:flex;align-items:center;justify-content:center;">' + (i + 1) + '</span><div style="font-size:13.5px;line-height:1.55;color:#334155;">' + esc(tx) + '</div></div>'; }).join('');
            var roles = (h.r || []).map(function (x) { return '<span style="font-size:11.5px;background:#e6f1fb;color:#0c447c;padding:2px 9px;border-radius:99px;">' + esc(x) + '</span>'; }).join(' ');
            ov.innerHTML = '<div style="max-width:560px;width:100%;background:#fff;border-radius:14px;overflow:hidden;margin:auto;box-shadow:0 24px 60px rgba(0,0,0,.35);">'
                + '<div style="background:var(--navy);padding:17px 22px;display:flex;align-items:flex-start;gap:13px;">'
                + '<div style="width:38px;height:38px;border-radius:10px;background:var(--accent);display:flex;align-items:center;justify-content:center;flex:0 0 auto;"><i class="fas fa-circle-info" style="font-size:19px;color:#fff;"></i></div>'
                + '<div style="flex:1;"><div style="font-size:11px;color:var(--accent);letter-spacing:.6px;font-weight:700;text-transform:uppercase;">Screen guide · ' + esc(h.m) + '</div>'
                + '<div style="font-size:18px;font-weight:700;color:#fff;margin-top:2px;">' + esc(h.t) + '</div>'
                + '<div style="font-size:12.5px;color:#94a3b8;margin-top:3px;">' + esc(h.g) + '</div></div>'
                + '<i class="fas fa-xmark" onclick="adHelpClose()" title="Close" style="font-size:18px;color:#94a3b8;cursor:pointer;padding:4px;"></i></div>'
                + '<div style="display:flex;border-bottom:1px solid var(--border);background:#f8fafc;">'
                + '<button id="adh-tb-how" onclick="adHelpTab(\'how\')" style="flex:1;border:none;background:none;padding:11px;font-size:13px;font-weight:700;cursor:pointer;color:#0c1929;border-bottom:2.5px solid var(--accent);"><i class="fas fa-list-check"></i> How to use it</button>'
                + '<button id="adh-tb-why" onclick="adHelpTab(\'why\')" style="flex:1;border:none;background:none;padding:11px;font-size:13px;font-weight:700;cursor:pointer;color:#64748b;border-bottom:2.5px solid transparent;"><i class="fas fa-handshake" style="color:var(--accent);"></i> Why it matters</button>'
                + (((h.mk || []).length) ? '<button id="adh-tb-mk" onclick="adHelpTab(\'mk\')" style="flex:1;border:none;background:none;padding:11px;font-size:13px;font-weight:700;cursor:pointer;color:#64748b;border-bottom:2.5px solid transparent;"><i class="fas fa-triangle-exclamation" style="color:#f59e0b;"></i> Do it right</button>' : '')
                + '</div>'
                + '<div id="adh-how" style="padding:18px 22px 8px;">'
                + chip('#993c1d', '#faece7', 'fa-circle-question', 'What is this page for?')
                + '<div style="font-size:14px;line-height:1.65;color:#334155;margin:0 0 14px;">' + esc(h.w) + '</div>'
                + (feats ? chip('#085041', '#e1f5ee', 'fa-wand-magic-sparkles', 'What you can do here') + '<div style="display:grid;grid-template-columns:1fr 1fr;gap:7px;margin-bottom:16px;">' + feats + '</div>' : '')
                + (steps ? chip('#26215c', '#eeedfe', 'fa-list-ol', 'How to use it — step by step') + '<div style="margin-bottom:14px;">' + steps + '</div>' : '')
                + (h.tip ? '<div style="background:#faeeda;border-radius:10px;padding:11px 14px;margin-bottom:14px;"><div style="font-size:12px;font-weight:700;color:#633806;margin-bottom:3px;"><i class="fas fa-lightbulb"></i> Good to know</div><div style="font-size:12.5px;line-height:1.6;color:#854f0b;">' + esc(h.tip) + '</div></div>' : '')
                + (roles ? '<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:8px;"><span style="font-size:11.5px;color:#475569;font-weight:700;">Who can use it:</span> ' + roles + '</div>' : '')
                + '</div>'
                + '<div id="adh-why" style="display:none;padding:18px 22px 8px;">'
                + chip('#993c1d', '#faece7', 'fa-bullseye', 'Why this feature exists')
                + '<div style="font-size:14px;line-height:1.7;color:#334155;margin:0 0 16px;">' + esc(h.why || '') + '</div>'
                + (h.uc ? chip('#0c447c', '#e6f1fb', 'fa-clapperboard', 'A real use case')
                    + '<div style="background:var(--navy);color:#e2e8f0;border-radius:10px;padding:13px 16px;font-size:13.5px;line-height:1.7;margin-bottom:16px;border-left:3px solid var(--accent);">' + esc(h.uc) + '</div>' : '')
                + ((h.adv || []).length ? chip('#085041', '#e1f5ee', 'fa-gem', 'What you gain')
                    + '<div style="margin-bottom:14px;">' + (h.adv || []).map(function (a) { return '<div style="display:flex;gap:9px;align-items:flex-start;margin-bottom:7px;"><i class="fas fa-circle-check" style="color:#16a34a;font-size:14px;margin-top:2px;"></i><div style="font-size:13.5px;line-height:1.5;color:#334155;">' + esc(a) + '</div></div>'; }).join('') + '</div>' : '')
                + '</div>'
                + (((h.mk || []).length) ? '<div id="adh-mk" style="display:none;padding:18px 22px 8px;">'
                    + chip('#92400e', '#fef3c7', 'fa-triangle-exclamation', 'Common mistakes — and the right way')
                    + (h.mk || []).map(function (mk) {
                        return '<div style="border:1px solid var(--border);border-left:3px solid #ef4444;border-radius:10px;padding:11px 14px;margin-bottom:10px;">'
                            + '<div style="display:flex;gap:8px;align-items:flex-start;margin-bottom:5px;"><i class="fas fa-circle-xmark" style="color:#ef4444;font-size:13px;margin-top:3px;"></i><div style="font-size:13.5px;font-weight:700;color:#0c1929;line-height:1.45;">' + esc(mk[0]) + '</div></div>'
                            + '<div style="display:flex;gap:8px;align-items:flex-start;margin-bottom:5px;"><i class="fas fa-burst" style="color:#f59e0b;font-size:13px;margin-top:3px;"></i><div style="font-size:12.5px;color:#92400e;line-height:1.5;"><b>Impact:</b> ' + esc(mk[1]) + '</div></div>'
                            + '<div style="display:flex;gap:8px;align-items:flex-start;"><i class="fas fa-circle-check" style="color:#16a34a;font-size:13px;margin-top:3px;"></i><div style="font-size:12.5px;color:#166534;line-height:1.5;"><b>Right way:</b> ' + esc(mk[2]) + '</div></div>'
                            + '</div>';
                    }).join('') + '</div>' : '')
                + '<div style="border-top:1px solid var(--border);padding:12px 22px;display:flex;justify-content:space-between;align-items:center;background:#f8fafc;">'
                + '<span style="font-size:12px;color:#64748b;">' + (h.rel ? '<i class="fas fa-diagram-project" style="font-size:12px;"></i> ' + esc(h.rel) : '') + '</span>'
                + '<button class="btn btn-primary" style="padding:8px 16px;font-size:13px;" onclick="adHelpClose()">Got it <i class="fas fa-check"></i></button>'
                + '</div></div>';
        }
    })();
    </script>
</body>
</html>
