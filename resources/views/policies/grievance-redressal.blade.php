@extends('policies.layout')

@section('content')
<p>We want every issue — service, billing, data, conduct — resolved quickly and fairly. This page tells you exactly how to complain and what happens next, in line with the Information Technology Rules and the DPDP Act, 2023.</p>

<h2>1. Step 1 — Support first</h2>
<p>Most issues are fastest through normal support: <a href="mailto:support@ametecsindia.com">support@ametecsindia.com</a> or WhatsApp +91 90000 98877 (Mon–Sat 10:00–19:00 IST). See the <a href="{{ url('/support-policy') }}">Support Policy</a> for response times.</p>

<h2>2. Step 2 — Grievance Officer</h2>
<p>If support did not resolve your issue, or your complaint concerns personal data, privacy, or conduct, write to our <strong>Grievance Officer</strong>:</p>
<table>
    <tr><th>To</th><td>The Grievance Officer, Ametecs India Private Limited</td></tr>
    <tr><th>Email</th><td><a href="mailto:support@ametecsindia.com">support@ametecsindia.com</a> — subject line "<strong>GRIEVANCE</strong>"</td></tr>
    <tr><th>Post</th><td>Modern Profound Techpark, Ground Floor, Hive Space, opp. Google, Whitefields, Kondapur, Hyderabad, Telangana, India 500084</td></tr>
</table>
<p>Include: your name and organisation, registered email, what happened, when, any ticket/invoice numbers, and the outcome you seek.</p>

<h2>3. Our commitments</h2>
<ul>
    <li><strong>Acknowledgement within 48 hours</strong> of receiving a grievance.</li>
    <li><strong>Resolution within 15 days</strong> — with a written explanation of what we found and what we did.</li>
    <li>If we need more time (complex investigations), we will tell you why and give a date.</li>
</ul>

<h2>4. Data protection grievances</h2>
<p>Complaints about personal data handling (access, correction, erasure, consent, suspected breach) follow the same path and timelines. If your data sits inside an employer's SmartPRS workspace, we will coordinate with your employer, who controls that data — see the <a href="{{ url('/data-protection') }}">Data Protection &amp; Processing Policy</a>. If you remain unsatisfied, you may approach the Data Protection Board of India under the DPDP Act.</p>

<h2>5. Escalation within Ametecs</h2>
<p>If the Grievance Officer's resolution does not satisfy you, ask for escalation to the office of the Managing Director in your reply — escalations are reviewed personally.</p>
@endsection
