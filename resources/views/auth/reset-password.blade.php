<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SmartPRS — Set password</title>
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
            <h2>Set a new password</h2>
            <p>Choose a strong password of at least 8 characters.</p>

            @if ($errors->any())
                <div style="background:var(--red-soft);color:#dc2626;font-size:13px;padding:11px 14px;border-radius:10px;margin-bottom:18px;font-family:var(--font2);">
                    <i class="fas fa-circle-exclamation"></i> {{ $errors->first() }}
                </div>
            @endif

            <form method="POST" action="/reset-password">
                @csrf
                <input type="hidden" name="token" value="{{ $token }}">
                <div class="form-group">
                    <label>Email</label>
                    <div class="input-wrap">
                        <i class="fas fa-envelope"></i>
                        <input type="email" name="email" value="{{ old('email', $email) }}" placeholder="you@company.com" required>
                    </div>
                </div>
                <div class="form-group">
                    <label>New password</label>
                    <div class="input-wrap">
                        <i class="fas fa-lock"></i>
                        <input type="password" name="password" placeholder="••••••••" required autofocus minlength="8">
                    </div>
                </div>
                <div class="form-group">
                    <label>Confirm new password</label>
                    <div class="input-wrap">
                        <i class="fas fa-lock"></i>
                        <input type="password" name="password_confirmation" placeholder="••••••••" required minlength="8">
                    </div>
                </div>
                <button class="btn-login" type="submit">Set password <i class="fas fa-check"></i></button>
            </form>

            <div class="login-footer">
                <a href="/login"><i class="fas fa-arrow-left"></i> Back to sign in</a>
            </div>
        </div>
    </div>
</div>
</body>
</html>
