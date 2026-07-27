<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        body { margin: 0; color: #0f172a; font-size: 12px; }
        .head { background: #0c1929; color: #fff; padding: 18px 24px; }
        .head table { width: 100%; }
        .brand { font-size: 19px; font-weight: bold; }
        .brand span { color: #f97316; }
        .muted { color: #94a3b8; font-size: 11px; }
        .wrap { padding: 22px 24px; }
        .row { width: 100%; }
        .row td { vertical-align: top; }
        .label { color: #64748b; text-transform: uppercase; font-size: 9px; letter-spacing: .5px; }
        h2 { font-size: 13px; margin: 18px 0 6px; color: #0c1929; }
        p.intro { font-size: 11.5px; color: #334155; line-height: 1.55; margin: 4px 0 0; }
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
        .feat { width: 100%; border-collapse: collapse; margin-top: 4px; }
        .feat td { padding: 3px 6px; font-size: 10.5px; color: #334155; width: 25%; }
        .paybox { margin-top: 18px; background: #fff7ed; border: 1px solid #fed7aa; border-radius: 8px; padding: 12px 14px; }
        .paybox a { color: #c2410c; font-weight: bold; word-break: break-all; }
        .foot { margin-top: 20px; color: #94a3b8; font-size: 10px; border-top: 1px solid #e2e8f0; padding-top: 10px; }
    </style>
</head>
<body>
    <div class="head">
        <table>
            <tr>
                <td>
                    @php $logo = public_path('images/logo.png'); @endphp
                    @if(is_file($logo))
                        <img src="{{ $logo }}" alt="SmartPRS by Ametecs" style="height:40px;">
                    @else
                        <div class="brand">Smart<span>PRS</span></div>
                    @endif
                    <div class="muted" style="margin-top:4px;">HRM · Payroll · Workforce Compliance — by Ametecs</div>
                </td>
                <td style="text-align:right;">
                    <div style="font-size:15px;font-weight:bold;">QUOTATION</div>
                    <div class="muted">{{ $s->quote_no }}</div>
                    <div class="muted">Valid until {{ $validUntil }}</div>
                </td>
            </tr>
        </table>
    </div>

    <div class="wrap">
        <table class="row">
            <tr>
                <td style="width:50%;padding-right:12px;">
                    <div class="label">From</div>
                    <div style="font-weight:bold;">{{ $seller['name'] ?: 'Ametecs India Private Limited' }}</div>
                    @if(!empty($seller['address']))<div>{{ $seller['address'] }}</div>@endif
                    @if(!empty($seller['state']))<div>State: {{ $seller['state'] }}</div>@endif
                    @if(!empty($seller['gstin']))<div><b>GSTIN:</b> {{ $seller['gstin'] }}</div>@endif
                    @if(!empty($seller['email']))<div>{{ $seller['email'] }}</div>@endif
                    @if(!empty($seller['phone']))<div>{{ $seller['phone'] }}</div>@endif
                </td>
                <td style="width:50%;padding-left:12px;">
                    <div class="label">Prepared for</div>
                    <div style="font-weight:bold;">{{ $s->company }}</div>
                    <div>{{ $s->admin_name }}</div>
                    @if(!empty($s->admin_email))<div>{{ $s->admin_email }}</div>@endif
                    @if(!empty($s->mobile))<div>{{ $s->mobile }}</div>@endif
                    @if(!empty($s->state))<div>State: {{ $s->state }}</div>@endif
                    @if(!empty($s->gstin))<div><b>GSTIN:</b> {{ $s->gstin }}</div>@endif
                    <div style="margin-top:6px;"><span class="label">Date</span> {{ \Illuminate\Support\Carbon::parse($s->quoted_at ?? now())->format('d M Y') }}</div>
                </td>
            </tr>
        </table>

        <h2>About SmartPRS</h2>
        <p class="intro">SmartPRS by Ametecs is the complete HR, payroll, attendance and field-force platform built specifically for India's collections &amp; recovery industry. Every plan includes all 16 modules — from hiring and biometric attendance to India-compliant payroll, statutory filings, off-roll agent management with DRA/PCC compliance, incentives and analytics — for the number of employees you choose, across all your group companies. A GST tax invoice is issued on payment, and your workspace is created within minutes.</p>

        <table class="feat">
            <tr><td>• Dashboard</td><td>• Hiring &amp; Onboarding</td><td>• People</td><td>• Time &amp; Attendance</td></tr>
            <tr><td>• Leave</td><td>• Payroll</td><td>• Compensation &amp; Claims</td><td>• Statutory &amp; Compliance</td></tr>
            <tr><td>• Performance &amp; Rewards</td><td>• Learning &amp; Knowledge</td><td>• HR Letters</td><td>• Field Force</td></tr>
            <tr><td>• Communication</td><td>• Reports &amp; Analytics</td><td>• Administration</td><td>• SaaS Platform</td></tr>
        </table>

        <h2>Quotation</h2>
        <table class="grid">
            <tr><th>Description</th><th class="amt">Qty</th><th class="amt">Amount (₹)</th></tr>
            <tr>
                <td>SmartPRS {{ $plan->name ?? 'Subscription' }} plan — all 16 modules ({{ $cycleLabel }})</td>
                <td class="amt">{{ (int) $s->seats }} emp</td>
                <td class="amt">{{ number_format($price['amount_before_coupon'] ?? $price['amount'], 2) }}</td>
            </tr>
            @if($companies > 1)
            <tr><td style="color:#64748b;">Includes {{ $companies }} companies ({{ $companies - 1 }} additional × ₹1,000/mo)</td><td></td><td></td></tr>
            @endif
            {{-- rev 113: discount stated CLEARLY on the quotation (Ejaz) --}}
            @if(($price['coupon_discount'] ?? 0) > 0)
            <tr>
                <td style="color:#166534;font-weight:bold;">DISCOUNT — coupon {{ $price['coupon_code'] }} (special offer for {{ $s->company }})</td>
                <td></td>
                <td class="amt" style="color:#166534;font-weight:bold;">− {{ number_format($price['coupon_discount'], 2) }}</td>
            </tr>
            @endif
            <tr class="tot"><td colspan="2">Taxable value{{ ($price['coupon_discount'] ?? 0) > 0 ? ' (after discount)' : '' }}</td><td class="amt">{{ number_format($price['amount'], 2) }}</td></tr>
        </table>

        <table class="totbox">
            <tr><td class="k">Taxable value</td><td class="v">₹{{ number_format($price['amount'], 2) }}</td></tr>
            @if($intra)
                <tr><td class="k">CGST @ 9%</td><td class="v">₹{{ number_format($cgst, 2) }}</td></tr>
                <tr><td class="k">SGST @ 9%</td><td class="v">₹{{ number_format($cgst, 2) }}</td></tr>
            @else
                <tr><td class="k">IGST @ 18%</td><td class="v">₹{{ number_format($igst, 2) }}</td></tr>
            @endif
            <tr class="grand"><td class="k" style="color:#fff;">Total payable</td><td class="v">₹{{ number_format($price['total'], 2) }}</td></tr>
        </table>

        <div class="paybox">
            <b>To proceed:</b> review and approve this quotation, then pay securely to create your workspace instantly at:<br>
            <a href="{{ $payLink }}">{{ $payLink }}</a>
            <div style="font-size:10px;color:#9a3412;margin-top:6px;">This quotation is valid until {{ $validUntil }}. Payment is processed securely by Razorpay; a GST tax invoice is emailed on payment.</div>
        </div>

        <div class="foot">
            This is a computer-generated quotation. Prices are in INR and include GST as shown. Minimum billing period is 3 months in advance.
            @if(empty($seller['gstin']))<br><b>Note:</b> Seller GSTIN not configured.@endif
            <br>Generated {{ now()->format('d M Y, H:i') }} · Ametecs India Pvt. Ltd.
        </div>
    </div>
</body>
</html>
