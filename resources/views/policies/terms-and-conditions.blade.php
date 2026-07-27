@extends('policies.layout')

@section('content')
<p>These Terms &amp; Conditions ("Terms") form the agreement between <strong>Ametecs India Private Limited</strong> ("Ametecs", "we") and the organisation subscribing to SmartPRS ("Customer", "you"). By creating a workspace, ticking the consent box at signup, or using SmartPRS, you accept these Terms on behalf of your organisation.</p>

<h2>1. The service</h2>
<p>SmartPRS is a multi-tenant workforce management and payroll platform covering HR, attendance, leave, payroll, statutory computations, incentives, recruitment, field-force compliance and related modules, delivered as a cloud subscription at smartprs.com. On-premise perpetual editions are governed additionally by the <a href="{{ url('/licence-agreement') }}">On-Premise Licence Agreement</a>.</p>

<h2>2. Eligibility and account</h2>
<ul>
    <li>SmartPRS is for business use. The person signing up confirms they are 18+ and authorised to bind the Customer.</li>
    <li>You are responsible for the confidentiality of all user credentials in your workspace and for all activity under them. Notify us immediately of any suspected unauthorised access.</li>
    <li>Information you provide at signup (company name, state, GSTIN, contacts) must be accurate; GST invoices are issued from this data.</li>
</ul>

<h2>3. Subscription, seats and companies</h2>
<ul>
    <li>Plans include a stated number of employees ("seats"). The seat count applies at <strong>workspace level across all companies</strong> in the workspace, and counts <strong>active on-roll employees</strong>. Extra seats and additional companies are billed at the published rates.</li>
    <li>Billing is in advance — minimum quarterly, with published discounts for half-yearly and annual payment. All prices exclude GST.</li>
    <li>Mid-term upgrades are charged pro-rata for the remaining period; the billing cycle does not change. Upgrades are not refundable (see the <a href="{{ url('/refund-policy') }}">Refund Policy</a>).</li>
</ul>

<h2>4. Renewal, grace and suspension</h2>
<ul>
    <li>We send renewal reminders before your period ends (email and WhatsApp where enabled).</li>
    <li>After expiry there is a <strong>7-day grace period</strong> with full access. After grace, administrator access is limited to the renewal page and other users cannot sign in until renewal.</li>
    <li>Workspace data is retained for <strong>90 days</strong> after expiry and then permanently deleted. Export your data before that date if you do not intend to renew.</li>
</ul>

<h2>5. Pricing changes</h2>
<p>We may revise prices with at least <strong>30 days' notice</strong>. Revised prices apply only from your next renewal — a period you have already paid for is never repriced.</p>

<h2>6. Service availability</h2>
<p>We target <strong>99% monthly availability</strong> on a best-effort basis, excluding scheduled maintenance (announced in advance where practical) and events beyond our reasonable control. No service credits are offered. Support commitments are described in the <a href="{{ url('/support-policy') }}">Support Policy</a>.</p>

<h2>7. Your data</h2>
<ul>
    <li><strong>You own your workspace data.</strong> We process it only to provide the service, per the <a href="{{ url('/data-protection') }}">Data Protection &amp; Processing Policy</a>.</li>
    <li>You confirm you have the legal right (and where required, employee consent) for the data you store in SmartPRS, including GPS attendance, selfies and identity documents.</li>
    <li>You can export your data at any time through the application's export functions.</li>
</ul>

<h2>8. Acceptable use and communications liability</h2>
<ul>
    <li>Use of SmartPRS must comply with the <a href="{{ url('/acceptable-use') }}">Acceptable Use Policy</a>.</li>
    <li>Messages you send through the platform (WhatsApp campaigns, SMS, email) are <strong>your content and your responsibility</strong>, including compliance with TRAI/DoT, Meta/WhatsApp commerce policies and applicable consent requirements. Ametecs provides the pipe, not the message.</li>
    <li>Violations are handled warn-first; repeated or serious violations (illegal use, spam complaints, abuse) may lead to immediate suspension or termination.</li>
</ul>

<h2>9. Statutory computations — important</h2>
<p>SmartPRS automates payroll and statutory computations (PF, ESI, PT, TDS and others) <strong>based on the rates, rules and data your team configures and enters</strong>. SmartPRS is a tool, not a chartered accountant or legal advisor; you remain responsible for verifying filings and amounts before submission to authorities. See the <a href="{{ url('/disclaimer') }}">Disclaimer</a>.</p>

<h2>10. Intellectual property</h2>
<p>Ametecs owns the SmartPRS software, design, and all related IP. You receive a non-exclusive, non-transferable right to use the service for your internal business during the subscription. Your data and your uploaded content remain yours. Feedback you give us may be used to improve the product without obligation.</p>

<h2>11. Confidentiality</h2>
<p>Each party will protect the other's confidential information with at least reasonable care and use it only for purposes of this agreement.</p>

<h2>12. Limitation of liability</h2>
<ul>
    <li>To the maximum extent permitted by law, Ametecs' total aggregate liability under this agreement is limited to <strong>the subscription fees paid by you in the 3 months preceding the claim</strong>.</li>
    <li>Neither party is liable for indirect, incidental, special or consequential damages, loss of profits, or loss of data (beyond our restoration obligations), even if advised of the possibility.</li>
    <li>Nothing limits liability for fraud or wilful misconduct.</li>
</ul>

<h2>13. Indemnity</h2>
<p>You will indemnify Ametecs against third-party claims arising from your data, your messages sent through the platform, or your breach of these Terms or applicable law.</p>

<h2>14. Termination</h2>
<ul>
    <li>You may stop renewing at any time; access continues until the end of the paid period.</li>
    <li>We may suspend or terminate for non-payment (after grace), for material breach not cured within 15 days of notice, or immediately for serious violations under the Acceptable Use Policy.</li>
    <li>On termination, data handling follows clause 4 (90-day retention, then deletion).</li>
</ul>

<h2>15. Force majeure</h2>
<p>Neither party is liable for delay or failure caused by events beyond reasonable control (natural disasters, war, internet or power failures of national scale, government action).</p>

<h2>16. Governing law and jurisdiction</h2>
<p>These Terms are governed by the laws of India. Courts at <strong>Hyderabad, Telangana</strong> have exclusive jurisdiction.</p>

<h2>17. Changes to these Terms</h2>
<p>We may update these Terms with notice on this page; material changes will be emailed to workspace owners at least 15 days before taking effect. Continued use after the effective date constitutes acceptance.</p>

<h2>18. Contact</h2>
<p>Questions about these Terms: <a href="mailto:support@ametecsindia.com">support@ametecsindia.com</a>. Complaints follow the <a href="{{ url('/grievance-redressal') }}">Grievance Redressal</a> policy.</p>
@endsection
