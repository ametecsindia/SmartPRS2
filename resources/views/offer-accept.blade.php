<?php
    $color = $brand['color'] ?? '#f97316';
    $brandName = $brand['display_name'] ?? ($company ?? 'SmartPRS');
    $name = $letter->candidate ?? 'Candidate';
    $position = $cand->position ?? '';
    $issued = $letter->issued_on ? \Illuminate\Support\Carbon::parse($letter->issued_on)->format('d M Y') : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Your Offer — {{ $brandName }}</title>
    <style>
        * { box-sizing: border-box; font-family: -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif; }
        body { margin: 0; background: #f1f5f9; color: #1f2937; }
        .wrap { max-width: 560px; margin: 40px auto; padding: 0 16px; }
        .card { background: #fff; border-radius: 16px; box-shadow: 0 12px 40px rgba(0,0,0,.08); overflow: hidden; }
        .top { background: {{ $color }}; color: #fff; padding: 26px 28px; }
        .top h1 { margin: 0; font-size: 22px; }
        .top p { margin: 4px 0 0; opacity: .92; font-size: 14px; }
        .body { padding: 26px 28px; }
        .row { display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 15px; }
        .row .k { color: #6b7280; }
        .row .v { font-weight: 600; }
        .accepted { background: #ecfdf5; color: #047857; border: 1px solid #6ee7b7; padding: 14px 16px; border-radius: 10px; font-weight: 600; margin-top: 18px; text-align: center; }
        .btn { display: inline-block; width: 100%; text-align: center; background: {{ $color }}; color: #fff; border: 0; padding: 14px; border-radius: 10px; font-size: 16px; font-weight: 600; cursor: pointer; margin-top: 22px; }
        .muted { color: #9ca3af; font-size: 12px; text-align: center; margin-top: 16px; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <div class="top">
            <h1>{{ $brandName }}</h1>
            @if(!empty($brand['tagline'])) <div style="font-size:12px;color:#666;font-style:italic;">{{ $brand['tagline'] }}</div> @endif
            <p>Employment Offer</p>
        </div>
        <div class="body">
            <p style="font-size:16px">Dear <strong>{{ $name }}</strong>,</p>
            <p style="color:#4b5563">We are delighted to offer you the following position. Please review and confirm your acceptance.</p>

            <div style="margin-top:14px">
                <div class="row"><span class="k">Candidate</span><span class="v">{{ $name }}</span></div>
                @if($position)<div class="row"><span class="k">Position</span><span class="v">{{ $position }}</span></div>@endif
                <div class="row"><span class="k">Company</span><span class="v">{{ $company ?: $brandName }}</span></div>
                @if($issued)<div class="row"><span class="k">Offer Date</span><span class="v">{{ $issued }}</span></div>@endif
            </div>

            @if($accepted || session('justAccepted'))
                <div class="accepted">✔ You have accepted this offer. Thank you — our team will be in touch.</div>
            @else
                <form method="POST" action="{{ route('offer.accept', $token) }}" onsubmit="return confirm('Please confirm: do you accept this offer from {{ addslashes($company ?: $brandName) }}?');">
                    @csrf
                    <button type="submit" class="btn">✓ I Accept This Offer</button>
                </form>
                <div class="muted">By clicking Accept you confirm your acceptance of the above offer. A timestamp will be recorded.</div>
            @endif
        </div>
    </div>
    <div class="muted" style="margin-top:18px">Powered by SmartPRS</div>
</div>
</body>
</html>
