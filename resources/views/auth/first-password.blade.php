<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SmartPRS — Create your password</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=DM+Sans:wght@400;500;600;700&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="/css/smartprs.css">
</head>
<body>
<div id="login-page">
    <div class="login-left">
        <div class="login-brand">
            <img src="{{ asset('images/logo.png') }}" alt="SmartPRS — Reputation | Relationships | Results" style="width:240px;max-width:80%;height:auto;display:block;margin:0 auto 10px;">
            <p>HRM · Payroll · Workforce Compliance</p>
        </div>
    </div>

    <div class="login-right">
        <div class="login-form-wrap">
            <h2>Welcome, {{ $user->name }}!</h2>
            <p>Your workspace is ready. One last step — create your own password for <strong>{{ $user->email }}</strong>. You will use it every time you sign in.</p>

            @if ($errors->any())
                <div style="background:var(--red-soft);color:#dc2626;font-size:13px;padding:11px 14px;border-radius:10px;margin-bottom:18px;font-family:var(--font2);">
                    <i class="fas fa-circle-exclamation"></i> {{ $errors->first() }}
                </div>
            @endif

            <form method="POST" action="/app/first-password">
                @csrf
                <div class="form-group">
                    <label>Create password (minimum 8 characters)</label>
                    <div class="input-wrap">
                        <i class="fas fa-lock"></i>
                        <input type="password" name="password" placeholder="••••••••" required autofocus minlength="8" autocomplete="new-password">
                    </div>
                </div>
                <div class="form-group">
                    <label>Confirm password</label>
                    <div class="input-wrap">
                        <i class="fas fa-lock"></i>
                        <input type="password" name="password_confirmation" placeholder="••••••••" required minlength="8" autocomplete="new-password">
                    </div>
                </div>
                <button class="btn-login" type="submit">Create password &amp; open my workspace <i class="fas fa-arrow-right"></i></button>
            </form>

            <div class="login-footer">
                <span style="font-size:12px;color:#64748b;font-family:var(--font2);"><i class="fas fa-shield-halved"></i> Keep this password safe — you can change it anytime from Account settings.</span>
            </div>
        </div>
    </div>
</div>
</body>
</html>
