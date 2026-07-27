@extends('policies.layout')

@section('content')
<p>This Acceptable Use Policy ("AUP") keeps SmartPRS safe, lawful and reliable for every customer. It applies to every user in every workspace, and forms part of the <a href="{{ url('/terms-and-conditions') }}">Terms &amp; Conditions</a>.</p>

<h2>1. Use it lawfully</h2>
<ul>
    <li>Use SmartPRS only for legitimate workforce management of your own organisation.</li>
    <li>Comply with applicable law — including labour law, the DPDP Act, RBI Fair Practices Code (for collections businesses), TRAI/DoT communication rules and tax law.</li>
    <li>Only upload personal data you have the legal right to hold. Do not upload another organisation's data without authority.</li>
</ul>

<h2>2. Messaging rules (WhatsApp, SMS, email)</h2>
<ul>
    <li>Messages sent through SmartPRS — including bulk recruitment campaigns and notifications — are <strong>your content and your legal responsibility</strong>.</li>
    <li>No spam: message only people who have a legitimate relationship with you (employees, candidates who shared their details, opted-in contacts).</li>
    <li>Honour opt-outs immediately. Follow Meta/WhatsApp Business and Interakt policies and TRAI regulations.</li>
    <li>Repeated spam complaints against your workspace put our shared infrastructure at risk and are treated as a serious violation.</li>
</ul>

<h2>3. Records integrity</h2>
<ul>
    <li>Do not create false records — fake attendance, fabricated payroll, fictitious employees — for evasion of statutory obligations or deception of any party.</li>
    <li>Audit-relevant records (approvals, locked commission entries, statutory registers) must reflect genuine business events.</li>
</ul>

<h2>4. Security</h2>
<ul>
    <li>No probing, scanning, penetration testing or vulnerability testing of the platform without our prior written consent.</li>
    <li>No attempts to access another tenant's workspace or data, or to bypass licence, plan or permission controls.</li>
    <li>No automated scraping; APIs and import/export functions exist for legitimate data movement.</li>
    <li>Do not share user accounts; create a user per person and use roles to manage access.</li>
</ul>

<h2>5. Platform integrity</h2>
<ul>
    <li>No reselling, sublicensing or operating SmartPRS as a bureau for third parties without a written partnership agreement with Ametecs.</li>
    <li>No reverse engineering, copying, or creating derivative works of the software.</li>
    <li>Fair use of shared resources — workloads that degrade the service for others may be throttled with notice.</li>
</ul>

<h2>6. Enforcement</h2>
<ul>
    <li><strong>Warn first:</strong> for most violations we will tell you what's wrong and ask you to fix it.</li>
    <li><strong>Suspension:</strong> repeated violations after warning may lead to suspension of the offending feature or the workspace.</li>
    <li><strong>Immediate action:</strong> illegal activity, serious abuse, security attacks, or spam at a scale that endangers our infrastructure leads to immediate suspension while we investigate, and possible termination under the Terms.</li>
    <li>Suspension for AUP violations is not a refund event (see the <a href="{{ url('/refund-policy') }}">Refund Policy</a>).</li>
</ul>

<h2>7. Reporting abuse</h2>
<p>To report misuse of SmartPRS (spam from a SmartPRS workspace, suspected fraud, a security concern), write to <a href="mailto:support@ametecsindia.com">support@ametecsindia.com</a> with subject "Abuse report". Security issues are prioritised.</p>
@endsection
