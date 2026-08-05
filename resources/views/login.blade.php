<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SmartPRS — Sign in</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=DM+Sans:wght@400;500;600;700&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="/css/smartprs.css">
    <link rel="icon" type="image/png" href="{{ asset('images/logo-icon.png') }}">
    <style>
      /* rev 120 (mobile fix): the desktop two-column login did NOT collapse on a
         phone — the form was pushed half off-screen. Stack it: a slim navy brand
         bar on top, the form full-width below. */
      html,body{overflow-x:hidden;}
      @media (max-width: 860px){
        #login-page{flex-direction:column !important;min-height:100vh;}
        .login-left{width:100% !important;min-height:auto !important;height:auto !important;
                    flex:0 0 auto !important;padding:30px 20px 22px !important;text-align:center;}
        .login-features{display:none !important;}      /* hide the 3 feature rows on small screens */
        .login-brand img{width:190px !important;margin:0 auto 4px !important;}
        .login-brand p{margin:0 !important;}
        .login-right{width:100% !important;flex:1 1 auto !important;padding:30px 18px 36px !important;}
        .login-form-wrap{width:100% !important;max-width:440px !important;margin:0 auto !important;}
        .login-form-wrap h2{font-size:26px;}
      }
      @media (max-width: 420px){
        .login-left{padding:24px 16px 18px !important;}
        .login-brand img{width:160px !important;}
        .login-right{padding:24px 14px 30px !important;}
      }
    </style>
</head>
<body>
<div id="login-page">
    <div class="login-left">
        <div class="login-brand">
            {{-- rev 91: real Ametecs logo (transparent PNG, white text — for dark panels) --}}
            <img src="{{ asset('images/logo.png') }}" alt="SmartPRS — Reputation | Relationships | Results" style="width:240px;max-width:80%;height:auto;display:block;margin:0 auto 10px;">
            <p>HRM · Payroll · Workforce Compliance</p>
        </div>
        <div class="login-features">
            <div class="login-feat">
                <div class="login-feat-icon" style="background:var(--accent-soft);color:var(--accent);"><i class="fas fa-fingerprint"></i></div>
                <div><strong>Biometric Attendance</strong><p>ZKTeco device sync, real-time</p></div>
            </div>
            <div class="login-feat">
                <div class="login-feat-icon" style="background:var(--blue-soft);color:var(--blue);"><i class="fas fa-money-check-dollar"></i></div>
                <div><strong>Automated Payroll</strong><p>Statutory-ready, India compliant</p></div>
            </div>
            <div class="login-feat">
                <div class="login-feat-icon" style="background:var(--green-soft);color:var(--green);"><i class="fas fa-building-user"></i></div>
                <div><strong>Multi-Company</strong><p>SaaS &amp; on-premise, one platform</p></div>
            </div>
        </div>
    </div>

    <div class="login-right">
        <div class="login-form-wrap">
            @if (!empty($superMode))
                <div style="display:inline-flex;align-items:center;gap:7px;background:#1e293b;color:#fff;font-size:12px;font-weight:600;padding:5px 12px;border-radius:20px;margin-bottom:12px;font-family:var(--font2);">
                    <i class="fas fa-shield-halved"></i> Platform Super Admin
                </div>
                <h2>Super Admin sign in</h2>
                <p>Restricted platform access — Super Admins only</p>
            @else
                <h2>Welcome back</h2>
                <p>Sign in to your SmartPRS workspace</p>
            @endif

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

            <form method="POST" action="/login">
                @csrf
                @if (!empty($superMode))
                    <input type="hidden" name="super" value="1">
                @endif
                <div class="form-group">
                    <label>Email</label>
                    <div class="input-wrap">
                        <i class="fas fa-envelope"></i>
                        <input type="email" name="email" value="{{ old('email') }}" placeholder="you@company.com" required autofocus>
                    </div>
                </div>
                <div class="form-group">
                    <label>Password</label>
                    <div class="input-wrap">
                        <i class="fas fa-lock"></i>
                        <input type="password" name="password" placeholder="••••••••" required>
                    </div>
                </div>
                {{-- rev139/141: on-prem License Code (LC) prompt — appears below
                     the login form only when this install needs activation or
                     its licence has expired. On SaaS it is never rendered.
                     The Super Admin's "on expiry" setting decides whether an
                     expired licence shows a renewal FIELD or a NOTICE only. --}}
                @if (!empty($needLc) && ($lcDisplay ?? 'field') === 'notice')
                    {{-- NOTIFY mode: an "LC Expired" message, no input. The user
                         signs in normally; that re-checks the licence online and
                         recovers automatically once Ametecs has renewed it. --}}
                    <div style="background:var(--red-soft);border:1px solid #fecaca;border-radius:10px;padding:12px 14px;margin:2px 0 4px;font-family:var(--font2);">
                        <div style="font-weight:700;color:#dc2626;font-size:14px;">
                            <i class="fas fa-triangle-exclamation"></i> Licence Expired
                        </div>
                        <p style="font-size:12.5px;color:var(--text2);margin:6px 0 0;line-height:1.55;">
                            Your SmartPRS licence expired on <strong>{{ $lcState['expires_on'] ?? '—' }}</strong>.
                            Please contact Ametecs to renew, then sign in again — your access restores automatically.
                            <br>WhatsApp <strong>9000098877</strong> · ejaz@ametecsindia.com
                        </p>
                    </div>
                @elseif (!empty($needLc))
                    {{-- RENEW / first activation: the License Code input. --}}
                    <div class="form-group" style="margin-top:2px;">
                        <label>License Code (LC)</label>
                        <div class="input-wrap">
                            <i class="fas fa-key"></i>
                            <input type="text" name="license_code" value="{{ old('license_code') }}"
                                   placeholder="SPRS-XXXX-XXXX-XXXX-XXXX" autocomplete="off" spellcheck="false"
                                   style="text-transform:uppercase;letter-spacing:.5px;">
                        </div>
                        <p style="font-size:12px;color:var(--text3);margin:7px 2px 0;font-family:var(--font2);line-height:1.5;">
                            @if (($lcState['state'] ?? '') === 'expired')
                                <i class="fas fa-triangle-exclamation" style="color:#dc2626;"></i>
                                Your licence expired on <strong>{{ $lcState['expires_on'] ?? '—' }}</strong>. Enter a new License Code from Ametecs to continue.
                            @else
                                <i class="fas fa-circle-info"></i>
                                This SmartPRS installation needs activation. Enter the License Code provided by Ametecs (WhatsApp 9000098877).
                            @endif
                        </p>
                    </div>
                @endif
                <div class="form-group" style="display:flex;align-items:center;justify-content:space-between;gap:8px;">
                    <span style="display:flex;align-items:center;gap:8px;">
                        <input type="checkbox" name="remember" id="remember" style="width:auto;">
                        <label for="remember" style="margin:0;text-transform:none;letter-spacing:0;font-weight:500;">Keep me signed in</label>
                    </span>
                    <a href="/forgot-password" style="font-size:13px;color:var(--accent);font-family:var(--font2);">Forgot password?</a>
                </div>
                <button class="btn-login" type="submit">Sign in <i class="fas fa-arrow-right"></i></button>
            </form>

            {{-- Demo-credentials hint: LOCAL/DEMO ONLY. Never shown in production
                 (rev 88 — Ejaz live check 6 Jun 2026: this was visible on smartprs.com). --}}
            @if (config('app.env') !== 'production' && filter_var(env('SMARTPRS_DEMO_DATA', false), FILTER_VALIDATE_BOOLEAN))
            <div class="login-footer">
                Demo: <a href="#">admin@smartprs.local</a> · password: <strong style="color:var(--text2);">password</strong>
            </div>
            @endif
        </div>
    </div>
</div>
</body>
</html>
