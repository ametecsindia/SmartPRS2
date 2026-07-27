@extends('policies.layout')

@section('content')
<p>SmartPRS holds some of the most sensitive data an organisation has — salaries, bank details, identity documents, GPS attendance and photographs. This policy explains exactly how that data is handled, in plain language, aligned with the Digital Personal Data Protection Act, 2023.</p>

<h2>1. Roles — who controls what</h2>
<table>
    <tr><th>Party</th><th>Role</th><th>Meaning</th></tr>
    <tr><td>Your organisation (the Customer)</td><td><strong>Data Fiduciary / owner</strong></td><td>Decides what employee data goes into SmartPRS and why; answers to its employees for it</td></tr>
    <tr><td>Ametecs</td><td><strong>Data Processor</strong></td><td>Stores and processes that data only on the Customer's instructions, only to deliver the service</td></tr>
</table>
<p>If you are an employee with a question about your records in SmartPRS, your first stop is your employer's HR team — they control the data. We assist them in fulfilling your request.</p>

<h2>2. Categories of workspace data</h2>
<ul>
    <li>Employee profiles — name, code, contact details, designation, department, photographs.</li>
    <li>Identity and KYC documents the employer uploads — e.g. PAN, Aadhaar, address proof, DRA/PCC certificates.</li>
    <li>Compensation — salary structures, payslips, bank account details, incentives, loans, advances.</li>
    <li>Attendance — punches from web, mobile (including <strong>GPS coordinates and selfies</strong> where the employer enables them) and biometric devices.</li>
    <li>Employment records — letters, transfers, performance, training, exits.</li>
</ul>

<h2>3. Purpose limitation</h2>
<p>Workspace data is used <strong>only</strong> to provide SmartPRS to the Customer. It is never sold, never used for advertising, never used to train third-party AI models, and never shown to any other tenant. Aggregated, fully anonymised statistics (e.g. total platform usage counts) may be used to improve the product.</p>

<h2>4. Security measures</h2>
<ul>
    <li><strong>Tenant isolation:</strong> every record is scoped to your workspace; cross-tenant access is structurally blocked in the application layer.</li>
    <li><strong>Encryption:</strong> HTTPS in transit; passwords stored hashed (never readable); payment gateway secrets, licence keys and similar credentials stored encrypted at rest.</li>
    <li><strong>Access control:</strong> role-based permissions inside your workspace (you decide who sees salaries); within Ametecs, production access is restricted to authorised personnel for support and operations only.</li>
    <li><strong>Audit trails:</strong> sensitive actions (approvals, edits to commission entries, licence events) are logged with who/when.</li>
    <li><strong>Backups:</strong> routine encrypted backups for disaster recovery, retained on a rolling cycle.</li>
</ul>

<h2>5. Where data is stored</h2>
<p>Production data is hosted in a datacenter located <strong>in India</strong>. We do not move workspace data outside India in the ordinary course of service.</p>

<h2>6. Sub-processors</h2>
<table>
    <tr><th>Provider</th><th>What they process</th></tr>
    <tr><td>Hosting provider (India)</td><td>All application data (encrypted infrastructure)</td></tr>
    <tr><td>Razorpay</td><td>Subscription payments — name, email, mobile, amount; never your employee records</td></tr>
    <tr><td>Interakt (WhatsApp API)</td><td>Mobile numbers + message content for transactional WhatsApp the Customer triggers</td></tr>
    <tr><td>SMTP email provider</td><td>Email addresses + message content for transactional email</td></tr>
</table>
<p>We will update this list when providers change; subscribing organisations are notified of material changes by email.</p>

<h2>7. Employee rights (Data Principals)</h2>
<p>Employees may exercise DPDP rights (access, correction, erasure, grievance) through their employer, who controls the data. Where an employee contacts us directly, we verify and forward the request to the employer and assist with fulfilment. Employers can correct or delete employee records directly in the application.</p>

<h2>8. Retention and deletion</h2>
<ul>
    <li>Active subscription: data is retained as long as the Customer keeps it.</li>
    <li>After subscription end: retained <strong>90 days</strong> (export window), then permanently deleted from production, and from routine backups within the following backup cycle (up to 30 further days).</li>
    <li>Customers can export their data at any time from within the application.</li>
</ul>

<h2>9. Breach notification</h2>
<p>If we become aware of a personal data breach affecting your workspace, we will notify the workspace owner <strong>without undue delay — targeting within 72 hours</strong> of confirmation — with what happened, what data was involved, and what we are doing about it, and will cooperate with your obligations to authorities under the DPDP Act.</p>

<h2>10. The live demo workspace</h2>
<p>All data in the public demo (smartprs.com/demo) is <strong>fictional</strong>, exists for demonstration only, and the entire demo workspace is wiped and reseeded automatically every few hours. Do not enter real personal data into the demo.</p>

<h2>11. Contact</h2>
<p>Data protection questions and requests: <a href="mailto:support@ametecsindia.com">support@ametecsindia.com</a> (subject "Data protection"). Escalation: <a href="{{ url('/grievance-redressal') }}">Grievance Redressal</a>.</p>
@endsection
