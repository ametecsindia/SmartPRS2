{{--
  Self-contained shell for the SBB settings screens.

  Deliberately does NOT extend resources/views/layouts/app.blade.php: that legacy
  layout references ten named routes (dashboard, employees.index, devices.index,
  tenants.index, ...) that no longer exist in routes/web.php, so any view built on
  it throws RouteNotFoundException and 500s. These pages therefore carry their own
  markup and depend on nothing outside their own three route names.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SmartPRS — @yield('title')</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        body {
            margin: 0; background: #f4f6f9; color: #1f2430;
            font-family: "Segoe UI", system-ui, -apple-system, Arial, sans-serif;
            font-size: 14px; line-height: 1.55;
        }
        code, .mono { font-family: Consolas, "Courier New", monospace; }
        .topbar {
            background: #1f3864; color: #fff; padding: 14px 26px;
            display: flex; align-items: center; gap: 18px; flex-wrap: wrap;
        }
        .topbar .brand { font-size: 17px; font-weight: 700; letter-spacing: .2px; }
        .topbar .brand span { color: #f0a250; font-weight: 500; }
        .topbar nav { margin-left: auto; display: flex; gap: 6px; flex-wrap: wrap; }
        .topbar nav a {
            color: #dbe4f3; text-decoration: none; padding: 6px 12px;
            border-radius: 6px; font-size: 13px;
        }
        .topbar nav a:hover { background: rgba(255,255,255,.12); color: #fff; }
        .topbar nav a.on { background: #fff; color: #1f3864; font-weight: 600; }
        .wrap { max-width: 1080px; margin: 26px auto 60px; padding: 0 22px; }
        h1 { font-size: 22px; margin: 0 0 4px; color: #1f3864; }
        h1 + .sub { color: #6b7280; margin: 0 0 22px; }
        h2 { font-size: 16px; margin: 0 0 4px; color: #1f3864; }
        .card {
            background: #fff; border: 1px solid #e2e6ee; border-radius: 10px;
            padding: 20px 22px; margin-bottom: 20px;
            box-shadow: 0 1px 2px rgba(16,24,40,.04);
        }
        .muted { color: #6b7280; }
        .small { font-size: 12px; }
        label.fld { display: block; font-weight: 600; margin-bottom: 4px; }
        input[type=text], input[type=date], select {
            width: 100%; padding: 9px 10px; border: 1px solid #cbd2e0;
            border-radius: 6px; font-size: 13px; font-family: inherit; background: #fff;
        }
        input[readonly] { background: #f6f8fb; font-family: Consolas, monospace; }
        .btn {
            display: inline-block; border: 0; border-radius: 6px; cursor: pointer;
            padding: 9px 16px; font-size: 13px; font-weight: 600; font-family: inherit;
        }
        .btn-primary { background: #1f3864; color: #fff; }
        .btn-primary:hover { background: #2a4a80; }
        .btn-link { background: none; color: #c2410c; padding: 4px 6px; font-weight: 600; }
        .btn-link:hover { text-decoration: underline; }
        table { width: 100%; border-collapse: collapse; }
        th, td { text-align: left; padding: 9px 8px; border-bottom: 1px solid #eef1f6; }
        th { background: #f6f8fb; color: #1f3864; font-size: 12px; text-transform: uppercase; letter-spacing: .4px; }
        .note { border-left: 4px solid #94a3b8; background: #f8fafc; padding: 12px 16px; border-radius: 0 8px 8px 0; margin-bottom: 20px; }
        .note.ok    { border-color: #16a34a; background: #f0fdf4; }
        .note.warn  { border-color: #d97706; background: #fffbeb; }
        .note.err   { border-color: #dc2626; background: #fef2f2; }
        .note.info  { border-color: #2563eb; background: #eff6ff; }
        .note p:first-child { margin-top: 0; }
        .note p:last-child { margin-bottom: 0; }
        .pill { display: inline-block; padding: 2px 9px; border-radius: 999px; font-size: 11px; font-weight: 700; }
        .pill.green { background: #dcfce7; color: #15803d; }
        .pill.red   { background: #fee2e2; color: #b91c1c; }
        .pill.amber { background: #fef3c7; color: #b45309; }
        .row { display: flex; gap: 16px; flex-wrap: wrap; align-items: flex-end; }
        .fieldset { margin-bottom: 14px; }
    </style>
</head>
<body>

<div class="topbar">
    <div class="brand">SmartPRS <span>by Ametecs</span></div>
    <nav>
        <a href="{{ route('app.apikeys') }}" class="@yield('nav_keys')">API Keys</a>
        <a href="{{ route('app.pending') }}" class="@yield('nav_pending')">Pending Punches</a>
        <a href="/app">&larr; Back to app</a>
    </nav>
</div>

<div class="wrap">
    @yield('content')
</div>

</body>
</html>
