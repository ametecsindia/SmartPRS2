<?php
    $color = $brand['color'] ?? '#f97316';
    $brandName = $brand['display_name'] ?? ($company->name ?? 'SmartPRS');
    $tagline = $brand['tagline'] ?? '';
    $money = fn ($n) => '₹' . number_format((float) $n, 2);
    $st = $p->line_status ?? 'pending';
    $ackd = $st === 'acknowledged';
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        body { margin: 0; color: #1f2937; font-size: 12px; }
        .wrap { padding: 28px 32px; }
        .head { border-bottom: 3px solid {{ $color }}; padding-bottom: 12px; margin-bottom: 18px; }
        .head h1 { margin: 0; font-size: 20px; color: {{ $color }}; }
        .head .sub { color: #6b7280; font-size: 12px; margin-top: 2px; }
        .doc-title { float: right; text-align: right; }
        .doc-title .t { font-size: 16px; font-weight: bold; }
        .doc-title .m { color: #6b7280; }
        table { width: 100%; border-collapse: collapse; }
        .meta td { padding: 3px 0; vertical-align: top; }
        .meta .k { color: #6b7280; width: 130px; }
        .amt { margin-top: 16px; }
        .amt th { background: #f3f4f6; text-align: left; padding: 7px 10px; font-size: 11px; text-transform: uppercase; color: #6b7280; border: 1px solid #e5e7eb; }
        .amt td { padding: 6px 10px; border: 1px solid #e5e7eb; }
        .amt td.r, .amt th.r { text-align: right; }
        .tot td { font-weight: bold; background: #f9fafb; }
        .net { margin-top: 14px; background: {{ $color }}11; border: 1px solid {{ $color }}55; padding: 10px 12px; }
        .net .big { font-size: 16px; font-weight: bold; color: {{ $color }}; }
        .ack { margin-top: 26px; border: 1px dashed #9ca3af; padding: 12px 14px; border-radius: 6px; }
        .ack .title { font-weight: bold; margin-bottom: 6px; }
        .ok { color: #16a34a; font-weight: bold; }
        .sigline { margin-top: 30px; border-top: 1px solid #9ca3af; width: 220px; padding-top: 4px; color: #6b7280; }
        .foot { margin-top: 24px; color: #9ca3af; font-size: 10px; text-align: center; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="head">
        <div class="doc-title">
            <div class="t">SALARY VOUCHER</div>
            <div class="m">{{ $monthLabel }}</div>
        </div>
        @php $brandLogo = $brand['logo_file'] ?? ($brand['logo'] ?? ''); @endphp
        @if(!empty($brandLogo))<img src="{{ $brandLogo }}" style="max-height:34px;max-width:150px;object-fit:contain;margin-bottom:4px;"><br>@endif
        <h1>{{ $brandName }}</h1>
        <div class="sub">{{ $company->name ?? '' }}@if($tagline) · {{ $tagline }}@endif</div>
    </div>

    <table class="meta">
        <tr>
            <td class="k">Employee</td><td><strong>{{ $p->emp_name }}</strong> ({{ $p->emp_code }})</td>
            <td class="k">PAN</td><td>{{ $p->pan ?: '—' }}</td>
        </tr>
        <tr>
            <td class="k">Bank A/C</td><td>{{ $p->bank_acc ?: '—' }} @if($p->ifsc) · {{ strtoupper($p->ifsc) }} @endif</td>
            <td class="k">UAN</td><td>{{ $p->uan ?: '—' }}</td>
        </tr>
        <tr>
            <td class="k">Pay Period</td><td>{{ $monthLabel }}</td>
            <td class="k">Status</td><td>{{ ucfirst($st) }}</td>
        </tr>
    </table>

    <table class="amt">
        <tr><th>Earnings</th><th class="r">Amount</th><th>Deductions</th><th class="r">Amount</th></tr>
        <?php
            $ek = array_keys($earn); $dk = array_keys($ded);
            $rowsN = max(count($ek), count($dk));
        ?>
        @for ($i = 0; $i < $rowsN; $i++)
            <tr>
                <td>{{ $ek[$i] ?? '' }}</td>
                <td class="r">{{ isset($ek[$i]) ? $money($earn[$ek[$i]]) : '' }}</td>
                <td>{{ $dk[$i] ?? '' }}</td>
                <td class="r">{{ isset($dk[$i]) ? $money($ded[$dk[$i]]) : '' }}</td>
            </tr>
        @endfor
        <tr class="tot">
            <td>Gross Earnings</td><td class="r">{{ $money($p->gross) }}</td>
            <td>Total Deductions</td><td class="r">{{ $money($p->total_ded) }}</td>
        </tr>
    </table>

    <div class="net">
        <span class="big">Net Pay: {{ $money($p->net) }}</span>
        @if(!empty($p->net_words))<div style="color:#6b7280;margin-top:3px">({{ $p->net_words }})</div>@endif
    </div>

    <div class="ack">
        <div class="title">Acknowledgement of Receipt</div>
        @if($ackd)
            <div class="ok">✔ Acknowledged (e-signed)</div>
            <div style="margin-top:4px">
                Signed by <strong>{{ $p->ack_name ?: $p->emp_name }}</strong>
                @if(!empty($p->ack_at)) on {{ \Illuminate\Support\Carbon::parse($p->ack_at)->format('d M Y H:i') }} @endif
                @if(!empty($p->ack_ip)) · IP {{ $p->ack_ip }} @endif
            </div>
        @else
            <div style="color:#6b7280">I acknowledge receipt of the net salary stated above for {{ $monthLabel }}.</div>
            <div class="sigline">Employee signature &amp; date</div>
        @endif
    </div>

    <div class="foot">Generated by SmartPRS · This is a system-generated voucher.</div>
</div>
</body>
</html>
