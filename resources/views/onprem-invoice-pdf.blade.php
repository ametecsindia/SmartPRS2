<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1e293b; margin: 26px 30px; }
    .top { width: 100%; border-bottom: 3px solid #f97316; padding-bottom: 10px; margin-bottom: 14px; }
    .h { font-size: 20px; font-weight: bold; color: #0c1929; }
    .muted { color: #64748b; }
    table.meta td { padding: 2px 0; vertical-align: top; }
    table.items { width: 100%; border-collapse: collapse; margin-top: 14px; }
    table.items th { background: #0c1929; color: #fff; padding: 7px 9px; text-align: left; font-size: 10px; }
    table.items td { border-bottom: 1px solid #e2e8f0; padding: 7px 9px; }
    .right { text-align: right; }
    .tot td { font-weight: bold; background: #fff7ed; }
    .box { border: 1px solid #e2e8f0; border-radius: 6px; padding: 9px 11px; margin-top: 14px; }
</style>
</head>
<body>
    <table class="top"><tr>
        <td style="width:55%">
            @php $logo = public_path('images/logo.png'); @endphp
            @if (is_file($logo))
                <img src="{{ $logo }}" style="height:40px;">
            @else
                <div class="h">SmartPRS</div>
            @endif
        </td>
        <td class="right">
            <div class="h">TAX INVOICE</div>
            <div><strong>{{ $c->invoice_no }}</strong></div>
            <div class="muted">Date: {{ now()->format('d M Y') }}</div>
        </td>
    </tr></table>

    <table class="meta" style="width:100%"><tr>
        <td style="width:50%">
            <strong>Sold by</strong><br>
            {{ $t['seller']['name'] ?? 'AMETECS INDIA PRIVATE LIMITED' }}<br>
            {!! nl2br(e($t['seller']['address'] ?? 'Modern Profound Techpark, Hive Space, Kondapur, Hyderabad 500084')) !!}<br>
            GSTIN: {{ $t['seller']['gstin'] ?? '36AAHCT0971F1ZB' }} · State: {{ $t['seller']['state'] ?? 'Telangana (36)' }}
        </td>
        <td>
            <strong>Billed to</strong><br>
            {{ $c->company }}<br>
            @if ($c->address) {!! nl2br(e($c->address)) !!}<br> @endif
            @if ($c->gstin) GSTIN: {{ $c->gstin }} · @endif State: {{ $c->state ?: '—' }}<br>
            {{ $c->contact_name }} {{ $c->email ? '· '.$c->email : '' }} {{ $c->mobile ? '· '.$c->mobile : '' }}
        </td>
    </tr></table>

    <table class="items">
        <tr><th>Description</th><th class="right">Amount (₹)</th></tr>
        <tr>
            <td>SmartPRS-{{ strtoupper($c->edition) }} — On-premise PERPETUAL licence{{ $c->employee_band ? ' · '.$c->employee_band.' employees' : '' }}<br>
                <span class="muted">Includes first-year AMC (updates &amp; support). Renewal: {{ $c->amc_percent }}% per year.</span></td>
            <td class="right">{{ number_format($t['price'], 2) }}</td>
        </tr>
        @if ($t['intra'])
            <tr><td class="muted">CGST @ 9%</td><td class="right">{{ number_format($t['tax'] / 2, 2) }}</td></tr>
            <tr><td class="muted">SGST @ 9%</td><td class="right">{{ number_format($t['tax'] / 2, 2) }}</td></tr>
        @else
            <tr><td class="muted">IGST @ 18%</td><td class="right">{{ number_format($t['tax'], 2) }}</td></tr>
        @endif
        <tr class="tot"><td>TOTAL</td><td class="right">₹ {{ number_format($t['total'], 2) }}</td></tr>
        @if ((float) $c->paid_total > 0)
            <tr><td class="muted">Received till date</td><td class="right">{{ number_format((float) $c->paid_total, 2) }}</td></tr>
            <tr class="tot"><td>BALANCE DUE</td><td class="right">₹ {{ number_format($t['balance'], 2) }}</td></tr>
        @endif
    </table>

    <div class="box">
        <strong>Pay securely online:</strong> {{ url('/licence/'.$c->invoice_token) }}<br>
        <span class="muted">On full payment, your licence key is generated and emailed automatically. SAC 997331 — Licensing services for the right to use computer software.</span>
    </div>
    <div class="muted" style="margin-top:16px;">This is a computer-generated invoice. M/s. AMETECS INDIA PRIVATE LIMITED · smartprs.com · ejaz@ametecsindia.com · WhatsApp 9000098877</div>
</body>
</html>
