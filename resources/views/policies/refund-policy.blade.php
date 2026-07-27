@extends('policies.layout')

@section('content')
<p>This policy explains when payments to <strong>Ametecs India Private Limited</strong> for SmartPRS are refundable. It applies to cloud subscriptions bought at smartprs.com and to on-premise licence purchases.</p>

<h2>1. 7-day money-back guarantee (new subscriptions)</h2>
<ul>
    <li>If you are a <strong>first-time subscriber</strong> and SmartPRS is not right for you, write to us within <strong>7 days of your first payment</strong> and we will refund it in full.</li>
    <li>Send the request to <a href="mailto:support@ametecsindia.com">support@ametecsindia.com</a> from your registered email, quoting your invoice number.</li>
    <li>The refund is processed to the original payment method through Razorpay, normally within <strong>7–10 business days</strong> of approval.</li>
    <li>The workspace is closed on refund; any data you entered is deleted per our retention policy.</li>
</ul>

<h2>2. After 7 days — no pro-rata refunds</h2>
<ul>
    <li>Subscriptions are billed in advance (quarterly, half-yearly or annual). After the 7-day window, <strong>advance payments are not refundable</strong>, in full or pro-rata, if you stop using the service mid-period.</li>
    <li>Your workspace stays fully usable until the end of the period you paid for.</li>
</ul>

<h2>3. Renewals and cancellation</h2>
<ul>
    <li>There is no auto-debit — renewal happens only when you pay. To cancel, simply do not renew; access continues until your paid period ends.</li>
    <li>A renewal payment, once made, is treated like any advance payment (clause 2). The 7-day money-back applies only to a customer's first-ever payment.</li>
</ul>

<h2>4. Upgrades</h2>
<p>Mid-term upgrades (more seats, more companies, higher plan) are charged pro-rata for the remaining period and are <strong>not refundable</strong>, including if you later reduce usage. Downgrades take effect from the next renewal.</p>

<h2>5. On-premise licences and AMC</h2>
<ul>
    <li>An on-premise licence payment is refundable only <strong>before the licence key is issued</strong>. Once a key is generated and delivered, the sale is final.</li>
    <li>Annual Maintenance (AMC) fees are non-refundable once the AMC period has started.</li>
</ul>

<h2>6. Duplicate or failed payments</h2>
<p>If you are charged twice for the same invoice, or money is debited but the payment shows failed, tell us with the transaction reference — verified duplicates and failed-but-debited amounts are <strong>always refunded in full</strong>, normally within 7–10 business days (bank timelines may add to this).</p>

<h2>7. Suspension is not a refund event</h2>
<p>Suspension for non-payment, or for violations under the <a href="{{ url('/acceptable-use') }}">Acceptable Use Policy</a> or <a href="{{ url('/terms-and-conditions') }}">Terms</a>, does not create a right to refund of amounts already paid.</p>

<h2>8. Chargebacks</h2>
<p>Please contact us before raising a chargeback with your bank — most issues (duplicate charge, wrong amount, service question) are resolved faster through <a href="mailto:support@ametecsindia.com">support@ametecsindia.com</a>. Chargebacks raised without contacting us may lead to workspace suspension while the dispute is investigated.</p>

<h2>9. How refunds are processed</h2>
<ul>
    <li>All refunds go to the <strong>original payment method</strong> via Razorpay. We cannot refund to a different account or in cash.</li>
    <li>GST on refunded invoices is adjusted by credit note as per GST rules.</li>
</ul>

<h2>10. Contact</h2>
<p>Refund requests and questions: <a href="mailto:support@ametecsindia.com">support@ametecsindia.com</a> · WhatsApp +91 90000 98877 (Mon–Sat 10:00–19:00 IST). Unresolved issues can be escalated via <a href="{{ url('/grievance-redressal') }}">Grievance Redressal</a>.</p>
@endsection
