@extends('policies.layout')

@section('content')
<p><strong>Ametecs India Private Limited</strong> ("Ametecs", "we", "us") operates SmartPRS, a workforce management and payroll platform available at smartprs.com and as on-premise editions. This Privacy Policy explains what personal data we collect, why we collect it, how we protect it, and the rights you have over it. It is written to align with the Digital Personal Data Protection Act, 2023 ("DPDP Act") and the Information Technology Act, 2000.</p>

<div class="note"><strong>Workforce data note:</strong> if your employer uses SmartPRS and your question is about your own employee records (salary, attendance, documents), that data is controlled by your employer. Please see our <a href="{{ url('/data-protection') }}">Data Protection &amp; Processing Policy</a> and contact your employer's HR team first.</div>

<h2>1. Information we collect</h2>
<h3>a) Website visitors</h3>
<ul>
    <li>Usage data through cookies and similar technologies, including Google Analytics and the Meta (Facebook) pixel — pages visited, approximate location, device and browser type. These help us understand how the site is used and measure our advertising.</li>
</ul>
<h3>b) Demo requests and enquiries</h3>
<ul>
    <li>Name, company, designation, city, mobile number, email address and the challenges you describe — submitted voluntarily through our demo/contact forms or WhatsApp.</li>
    <li>For the public live demo, your mobile number is verified by a one-time password (OTP) sent over WhatsApp and/or email.</li>
</ul>
<h3>c) Subscribers (workspace owners)</h3>
<ul>
    <li>Company name, contact name, email, mobile, state and GSTIN (for GST invoicing), chosen web address (slug), plan and billing details.</li>
    <li>Payment is processed by Razorpay. <strong>We never see or store your card, UPI or banking credentials</strong> — we receive only a payment confirmation and reference ID.</li>
</ul>
<h3>d) Workspace (tenant) data</h3>
<ul>
    <li>Data your organisation enters about its employees — profiles, attendance (including GPS punches and selfies where your employer enables them), payroll, documents and related records. For this data we act as a <strong>processor on your employer's instructions</strong>; full details are in the <a href="{{ url('/data-protection') }}">Data Protection &amp; Processing Policy</a>.</li>
</ul>

<h2>2. How we use your information</h2>
<ul>
    <li>To provide, operate, secure and improve SmartPRS.</li>
    <li>To respond to demo requests and enquiries, and to schedule demonstrations you ask for.</li>
    <li>To create and administer your subscription, issue GST tax invoices and send service communications (renewal reminders, payment receipts, important product notices) by email and WhatsApp.</li>
    <li>To analyse website usage and measure marketing performance (analytics and pixels described above).</li>
    <li>To comply with law — tax, accounting and lawful requests from authorities.</li>
</ul>
<p>We do <strong>not</strong> sell personal data. We do not use your workspace's employee data for advertising or any purpose other than providing the service.</p>

<h2>3. Legal basis</h2>
<p>We process personal data on the basis of your consent (forms you submit, OTP verification, cookie usage), the performance of our contract with you (operating your subscription), and our legal obligations (invoicing and tax records).</p>

<h2>4. Who we share data with</h2>
<table>
    <tr><th>Recipient</th><th>Purpose</th></tr>
    <tr><td>Razorpay Software Pvt. Ltd.</td><td>Payment processing and payment links</td></tr>
    <tr><td>Interakt (WhatsApp Business API provider)</td><td>Transactional WhatsApp messages — OTPs, welcome, payment and renewal notices</td></tr>
    <tr><td>Our hosting provider (datacenter located in India)</td><td>Application and database hosting</td></tr>
    <tr><td>Email (SMTP) service provider</td><td>Transactional email delivery</td></tr>
    <tr><td>Google Analytics &amp; Meta</td><td>Website analytics and advertising measurement (website only — never workspace data)</td></tr>
</table>
<p>Each provider receives only the minimum data needed for its purpose and is bound by its own published security and privacy commitments. We may also disclose information where required by law or to protect our legal rights.</p>

<h2>5. Where your data lives</h2>
<p>SmartPRS production systems are hosted in a datacenter located <strong>in India</strong>. Workspace data is not transferred outside India by us in the ordinary course of providing the service.</p>

<h2>6. Cookies</h2>
<p>We use essential cookies (sign-in sessions, security) and analytics/advertising cookies (Google Analytics, Meta pixel). You can block non-essential cookies in your browser settings; the application will continue to work.</p>

<h2>7. Retention</h2>
<ul>
    <li><strong>Enquiry/lead data:</strong> kept while we pursue your enquiry and for a reasonable period after; tell us to stop and we will close and stop contacting you.</li>
    <li><strong>Subscription and invoice records:</strong> kept as required by Indian tax law (currently 8 years for financial records).</li>
    <li><strong>Workspace data:</strong> retained for <strong>90 days</strong> after your subscription ends, so you can export or reactivate; permanently deleted after that, including from routine backups within the following backup cycle.</li>
</ul>

<h2>8. Your rights</h2>
<p>Under the DPDP Act you may request access to, correction of, or erasure of your personal data, and may withdraw consent for non-essential processing. Write to <a href="mailto:support@ametecsindia.com">support@ametecsindia.com</a> with the subject "Privacy request". We will respond within the timelines in our <a href="{{ url('/grievance-redressal') }}">Grievance Redressal</a> policy. If your data sits inside an employer's workspace, we will route your request to that employer, who controls it.</p>

<h2>9. Security</h2>
<p>Sign-ins are protected with hashed passwords; tenant data is logically isolated per workspace; secrets and API keys are stored encrypted; access within Ametecs is role-restricted; traffic to smartprs.com is encrypted in transit (HTTPS). See the <a href="{{ url('/data-protection') }}">Data Protection &amp; Processing Policy</a> for the full measures.</p>

<h2>10. Children</h2>
<p>SmartPRS is a business product intended for users aged 18 and above. We do not knowingly collect data from children.</p>

<h2>11. Changes to this policy</h2>
<p>We may update this policy from time to time. The effective date at the top reflects the latest version; material changes will be notified on this page and, for subscribers, by email.</p>

<h2>12. Contact</h2>
<p>Ametecs India Private Limited, Modern Profound Techpark, Ground Floor, Hive Space, opp. Google, Whitefields, Kondapur, Hyderabad, Telangana, India 500084. Email: <a href="mailto:support@ametecsindia.com">support@ametecsindia.com</a> · WhatsApp: +91 90000 98877.</p>
@endsection
