<?php
    $color = $brand['color'] ?? '#f97316';
    $brandName = $brand['display_name'] ?? ($company ?? 'SmartPRS');
    // Templates store rich HTML (<p>, <ul>, <strong>…). Render it as HTML; only
    // fall back to escaped + pre-wrapped text for plain-text letters.
    $bodyIsHtml = (bool) preg_match('/<(?:p|div|ul|ol|li|br|strong|em|b|i|h[1-6]|table|span|a)\b[^>]*>/i', (string) $body);
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        body { margin: 0; color: #1f2937; font-size: 13px; line-height: 1.6; }
        .wrap { padding: 36px 44px; }
        .head { border-bottom: 2px solid {{ $color }}; padding-bottom: 10px; margin-bottom: 8px; }
        .head h1 { margin: 0; font-size: 20px; color: {{ $color }}; }
        .head .addr { color: #6b7280; font-size: 11px; margin-top: 2px; }
        .meta { text-align: right; color: #6b7280; font-size: 12px; margin: 6px 0 18px; }
        .title { text-align: center; font-size: 16px; font-weight: bold; text-transform: uppercase; letter-spacing: .5px; margin: 6px 0 20px; }
        .body { white-space: pre-wrap; }
        .body.html { white-space: normal; }
        .body.html p { margin: 0 0 10px; }
        .body.html ul, .body.html ol { margin: 6px 0 12px; padding-left: 22px; }
        .body.html li { margin: 2px 0; }
        .sign { margin-top: 48px; }
        .sign .for { color: #6b7280; }
        .sign .line { margin-top: 40px; border-top: 1px solid #9ca3af; width: 240px; padding-top: 4px; color: #6b7280; font-size: 12px; }
        .foot { margin-top: 30px; color: #9ca3af; font-size: 10px; text-align: center; border-top: 1px solid #e5e7eb; padding-top: 6px; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="head">
        <h1>{{ $brandName }}</h1>
        @if(!empty($brand['tagline'])) <div style="font-size:11px;color:#666;font-style:italic;">{{ $brand['tagline'] }}</div> @endif
        @if(!empty($companyAddress))<div class="addr">{{ $companyAddress }}</div>@endif
    </div>
    <div class="meta">Date: {{ $date }}</div>

    <div class="title">{{ $title }}</div>

    <div class="body{{ $bodyIsHtml ? ' html' : '' }}">@if($bodyIsHtml){!! $body !!}@else{{ $body }}@endif</div>

    <div class="sign">
        <div class="for">For {{ $brandName }},</div>
        <div class="line">Authorised Signatory</div>
    </div>

    <div class="foot">This is a system-generated letter from SmartPRS.</div>
</div>
</body>
</html>
