<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        body { margin: 0; color: #0f172a; font-size: 12px; }
        .head { background: #0c1929; color: #fff; padding: 18px 24px; }
        .head table { width: 100%; }
        .brand { font-size: 18px; font-weight: bold; }
        .muted { color: #94a3b8; font-size: 11px; }
        .wrap { padding: 22px 24px; }
        .row { width: 100%; }
        .row td { vertical-align: top; }
        .label { color: #64748b; text-transform: uppercase; font-size: 9px; letter-spacing: .5px; }
        h2 { font-size: 13px; margin: 16px 0 6px; color: #0c1929; }
        table.grid { width: 100%; border-collapse: collapse; margin-top: 6px; }
        table.grid th { background: #f8fafc; text-align: left; padding: 8px 10px; font-size: 10px;
                        text-transform: uppercase; color: #64748b; border-bottom: 1px solid #e2e8f0; }
        table.grid td { padding: 8px 10px; border-bottom: 1px solid #f1f5f9; }
        .amt { text-align: right; }
        .tot td { font-weight: bold; border-top: 2px solid #0c1929; }
        .totbox { margin-top: 14px; width: 100%; }
        .totbox td { padding: 4px 10px; font-size: 12px; }
        .totbox .k { color: #475569; text-align: right; }
        .totbox .v { text-align: right; width: 130px; }
        .grand { background: #0c1929; color: #fff; font-weight: bold; font-size: 14px; }
        .pill { display: inline-block; padding: 3px 10px; border-radius: 999px; font-size: 11px; font-weight: bold; }
        .paid { background: #dcfce7; color: #166534; }
        .due { background: #fef3c7; color: #92400e; }
        .foot { margin-top: 22px; color: #94a3b8; font-size: 10px; border-top: 1px solid #e2e8f0; padding-top: 10px; }
    </style>
</head>
<body>
    <div class="head">
        <table>
            <tr>
                <td>
                    @php $logo = public_path('images/logo.png'); @endphp
                    @if(is_file($logo))
                        <img src="{{ $logo }}" alt="SmartPRS by Ametecs" style="height:38px;">
                    @else
                        <div class="brand">{{ $seller['name'] ?: 'SmartPRS' }}</div>
                    @endif
                    <div class="muted" style="margin-top:4px;">SmartPRS &mdash; HRM &amp; Payroll SaaS</div>
                </td>
                <td style="text-align:right;">
                    <div style="font-size:15px;font-weight:bold;">TAX INVOICE</div>
                    <div class="muted">{{ $inv->number }}</div>
                    <div style="margin-top:6px;">
                        <span class="pill {{ $inv->status === 'paid' ? 'paid' : 'due' }}">{{ strtoupper($inv->status) }}</span>
                    </div>
                </td>
            </tr>
        </table>
    </div>

    <div class="wrap">
        <table class="row">
            <tr>
                <td style="width:50%;padding-right:12px;">
                    <div class="label">From</div>
                    <div style="font-weight:bold;">{{ $seller['name'] ?: 'SmartPRS' }}</div>
                    @if(!empty($seller['address']))<div>{{ $seller['address'] }}</div>@endif
                    @if(!empty($seller['state']))<div>State: {{ $seller['state'] }}</div>@endif
                    @if(!empty($seller['gstin']))<div><b>GSTIN:</b> {{ $seller['gstin'] }}</div>@endif
                    @if(!empty($seller['email']))<div>{{ $seller['email'] }}</div>@endif
                    @if(!empty($seller['phone']))<div>{{ $seller['phone'] }}</div>@endif
                </td>
                <td style="width:50%;padding-left:12px;">
                    <div class="label">Bill To</div>
                    <div style="font-weight:bold;">{{ $tenant->name ?? '—' }}</div>
                    @if(!empty($tenant->owner_email))<div>{{ $tenant->owner_email }}</div>@endif
                    @if(!empty($tenant->state))<div>State / Place of supply: {{ $tenant->state }}</div>@endif
                    @if(!empty($tenant->gstin))<div><b>GSTIN:</b> {{ $tenant->gstin }}</div>@endif
                    <div style="margin-top:8px;">
                        <span class="label">Invoice date</span>
                        {{ $inv->issued_on ? \Illuminate\Support\Carbon::parse($inv->issued_on)->format('d M Y') : now()->format('d M Y') }}
                    </div>
                    <div>
                        <span class="label">Due date</span>
                        {{ $inv->due_on ? \Illuminate\Support\Carbon::parse($inv->due_on)->format('d M Y') : '—' }}
                    </div>
                    @if($inv->status === 'paid' && !empty($inv->paid_on))
                        <div><span class="label">Paid on</span> {{ \Illuminate\Support\Carbon::parse($inv->paid_on)->format('d M Y') }}</div>
                    @endif
                </td>
            </tr>
        </table>

        <h2>Subscription</h2>
        <table class="grid">
            <tr>
                <th>Description</th>
                <th>SAC</th>
                <th class="amt">Seats</th>
                <th class="amt">Amount (₹)</th>
            </tr>
            <tr>
                <td>
                    SmartPRS {{ $plan->name ?? 'Subscription' }} plan
                    @if(!empty($sub->cycle)) &mdash; {{ ucfirst($sub->cycle) }} billing @endif
                </td>
                <td>{{ $seller['sac'] }}</td>
                <td class="amt">{{ (int) ($sub->seats ?? 0) }}</td>
                <td class="amt">{{ number_format($amount, 2) }}</td>
            </tr>
            <tr class="tot">
                <td colspan="3">Taxable value</td>
                <td class="amt">{{ number_format($amount, 2) }}</td>
            </tr>
        </table>

        <table class="totbox">
            <tr><td class="k">Taxable value</td><td class="v">₹{{ number_format($amount, 2) }}</td></tr>
            @if($intra)
                <tr><td class="k">CGST @ {{ $gstRate / 2 }}%</td><td class="v">₹{{ number_format($cgst, 2) }}</td></tr>
                <tr><td class="k">SGST @ {{ $gstRate / 2 }}%</td><td class="v">₹{{ number_format($sgst, 2) }}</td></tr>
            @else
                <tr><td class="k">IGST @ {{ $gstRate }}%</td><td class="v">₹{{ number_format($igst, 2) }}</td></tr>
            @endif
            <tr class="grand"><td class="k" style="color:#fff;">Total payable</td><td class="v">₹{{ number_format($total, 2) }}</td></tr>
        </table>

        <div class="foot">
            This is a computer-generated tax invoice and does not require a physical signature.
            @if(empty($seller['gstin']))
                <br><b>Note:</b> Seller GSTIN not configured — set BILLING_SELLER_GSTIN in the environment for a compliant invoice.
            @endif
            <br>Generated {{ now()->format('d M Y, H:i') }}.
        </div>
    </div>
</body>
</html>
