@extends('policies.layout')

@section('content')
<p>This policy describes how <strong>Ametecs India Private Limited</strong> supports SmartPRS customers — channels, hours, response commitments and what is included.</p>

<h2>1. Support channels</h2>
<table>
    <tr><th>Channel</th><th>Details</th></tr>
    <tr><td>Email</td><td><a href="mailto:support@ametecsindia.com">support@ametecsindia.com</a> — preferred for anything that needs investigation</td></tr>
    <tr><td>WhatsApp</td><td>+91 90000 98877 — quick questions and screenshots</td></tr>
    <tr><td>Remote sessions</td><td>Screen-sharing / remote-desktop sessions over collaboration tools (e.g. Google Meet, Zoom, AnyDesk) — scheduled through email or WhatsApp</td></tr>
    <tr><td>In-app self-help</td><td>Every screen has an ⓘ guide (how to use it, why it matters, do it right), a guided tour, and the SmartPRS Training Manual</td></tr>
</table>
<div class="note"><strong>All support is remote/online only.</strong> Support is delivered through the channels above — we do not provide onsite visits. Remote collaboration tools resolve issues faster and keep support affordable for everyone.</div>

<h2>2. Support hours</h2>
<p><strong>Monday to Saturday, 10:00–19:00 IST</strong>, excluding public holidays in Telangana. Messages received outside these hours are taken up the next working day.</p>

<h2>3. Response commitments</h2>
<table>
    <tr><th>Severity</th><th>Meaning</th><th>First response</th></tr>
    <tr><td><strong>Critical</strong></td><td>Service down or a blocking failure in a time-critical operation (e.g. payroll cannot be generated on payroll day, no user can sign in)</td><td>Within <strong>4 business hours</strong></td></tr>
    <tr><td><strong>Normal</strong></td><td>Everything else — questions, non-blocking bugs, configuration help</td><td>Within <strong>1 business day</strong></td></tr>
</table>
<p>"First response" means a qualified human reply working on your issue — resolution times vary with complexity. Mark critical issues clearly ("CRITICAL" in the subject / message) so they are routed fast.</p>

<h2>4. What support includes</h2>
<ul>
    <li>Help using any SmartPRS feature and guidance on recommended setup.</li>
    <li>Investigation and fixing of bugs in the SmartPRS software.</li>
    <li>Help with subscription, billing and invoice questions.</li>
</ul>

<h2>5. What support does not include</h2>
<ul>
    <li>Doing your data entry, payroll runs or statutory filings for you.</li>
    <li>Custom feature development (we welcome requests — they go on the roadmap, not the support queue).</li>
    <li>Issues in your own infrastructure — internet, devices, browsers, or third-party services (your bank, Razorpay account issues, WhatsApp/Meta template approvals beyond our guidance).</li>
    <li>Statutory, legal or tax advice — see the <a href="{{ url('/disclaimer') }}">Disclaimer</a>.</li>
</ul>

<h2>6. Onboarding and training</h2>
<ul>
    <li>SmartPRS is built for self-service: ⓘ guides on every screen, a guided tour, and the Training Manual.</li>
    <li>Every new customer gets <strong>one free online onboarding session</strong> for their team.</li>
    <li>Additional live training sessions are available at a published per-session charge.</li>
</ul>

<h2>7. On-premise customers (AMC)</h2>
<ul>
    <li>While AMC is active: all product updates plus remote support (email, WhatsApp, remote-desktop) under the same hours and severity commitments above.</li>
    <li>If AMC lapses: your software keeps running — perpetual means perpetual — but updates and support pause until AMC is renewed. See the <a href="{{ url('/licence-agreement') }}">Licence Agreement &amp; AMC Policy</a>.</li>
    <li>On-premise support is also <strong>remote/online only</strong> — installation, updates and troubleshooting are done over collaboration tools with your IT person at the server.</li>
</ul>

<h2>8. Escalation</h2>
<p>If a support experience disappoints you, escalate via the <a href="{{ url('/grievance-redressal') }}">Grievance Redressal</a> policy — acknowledged within 48 hours, resolved within 15 days.</p>
@endsection
