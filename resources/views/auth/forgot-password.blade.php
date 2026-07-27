<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SmartPRS — Forgot password</title>
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
            <h2>Forgot password</h2>
            <p>Enter your email and we'll send you a reset link.</p>

            @if (session('status'))
                <div style="background:var(--green-soft,#dcfce7);color:#15803d;font-size:13px;padding:11px 14px;border-radius:10px;margin-bottom:18px;font-family:var(--font2);">
                    <i class="fas fa-circle-check"></i> {{ session('status') }}
                </div>
            @endif
            @if ($errors->any())
                <div style="background:var(--red-soft);color:#dc2626;font-size:13px;padding:11px 14px;border-radius:10px;margin-bottom:18px;font-family:var(--font2);">
                    <i class="fas fa-circle-exclamation"></i> {{ $errors->first() }}
                </div>
            @endif

            <form method="POST" action="/forgot-password">
                @csrf
                <div class="form-group">
                    <label>Email</label>
                    <div class="input-wrap">
                        <i class="fas fa-envelope"></i>
                        <input type="email" name="email" value="{{ old('email') }}" placeholder="you@company.com" required autofocus>
                    </div>
                </div>
                <button class="btn-login" type="submit">Send reset link <i class="fas fa-paper-plane"></i></button>
            </form>

            <div class="login-footer">
                <a href="/login"><i class="fas fa-arrow-left"></i> Back to sign in</a>
            </div>
        </div>
    </div>
</div>
</body>
</html>
