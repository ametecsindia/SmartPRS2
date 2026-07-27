@extends('policies.layout')

@section('content')
<p>This agreement governs <strong>on-premise editions of SmartPRS</strong> (L1 Core, L2 Professional, L3 Collections DNA) purchased as perpetual licences from <strong>Ametecs India Private Limited</strong> and installed on the customer's own server. Cloud subscriptions are governed by the <a href="{{ url('/terms-and-conditions') }}">Terms &amp; Conditions</a> instead.</p>

<h2>1. Licence grant</h2>
<ul>
    <li>On full payment, Ametecs grants you a <strong>perpetual, non-exclusive, non-transferable</strong> licence to install and use the purchased SmartPRS edition for your internal business.</li>
    <li>The licence covers the modules of the purchased edition (L1/L2/L3) as published in the SmartPRS module catalogue at the time of sale. Edition upgrades are purchased separately.</li>
    <li>The software is licensed, not sold — Ametecs retains all intellectual property.</li>
</ul>

<h2>2. Licence key and activation</h2>
<ul>
    <li>Each licence is delivered as a key (format SPRS-XXXX-XXXX-XXXX-XXXX) issued after payment per the <a href="{{ url('/refund-policy') }}">Refund Policy</a> — full online payment issues the key automatically.</li>
    <li><strong>One key activates one server.</strong> Activation binds the key to that server's fingerprint.</li>
    <li>You may move the installation to a new server <strong>once per year</strong> through self-service deactivation; additional moves are handled by support (genuine cases — hardware failure, migration — are never refused unreasonably).</li>
    <li>The software runs without contacting our servers in daily use; activation and update checks are the only online operations. Offline installations enjoy a 60-day grace on licence checks and are <strong>never blocked from running</strong> by connectivity alone.</li>
</ul>

<h2>3. The perpetual promise</h2>
<div class="note"><strong>Your software never stops working.</strong> A lapsed AMC, an expired update entitlement, or even licence revocation for a later dispute will block <em>new activations and updates</em> — but will never switch off a production system already running with your data. Your payroll never gets held hostage.</div>

<h2>4. Annual Maintenance Contract (AMC)</h2>
<ul>
    <li>The first 12 months of maintenance are <strong>included</strong> with the licence, counted from key issue.</li>
    <li>Thereafter AMC is <strong>20% of the then-current licence price per year</strong>, invoiced annually.</li>
    <li>Active AMC includes: <strong>all product updates</strong> (features, fixes, statutory rate updates) delivered through the built-in update system, and <strong>remote support</strong> (email, WhatsApp, remote desktop) per the <a href="{{ url('/support-policy') }}">Support Policy</a>.</li>
    <li>Lapsed AMC: the software keeps running (clause 3); updates and support pause. Renewal reactivates them — renewal is counted from the renewal date forward (no back-billing for the lapsed gap when renewing within 12 months of lapse; longer gaps may require an update-catch-up fee quoted in advance).</li>
    <li>AMC fees are non-refundable once the period starts.</li>
</ul>

<h2>5. Updates</h2>
<ul>
    <li>Updates are delivered through the in-app <em>Administration → Updates &amp; Licence</em> screen: checksum-verified download, automatic backup before applying, automatic rollback on failure.</li>
    <li>Apply updates on your schedule. Statutory accuracy of old versions is your responsibility if you choose not to update.</li>
</ul>

<h2>6. Your responsibilities</h2>
<ul>
    <li>Server, operating system, database, backups of your data, network security and HTTPS on your infrastructure are <strong>your responsibility</strong>. We provide installation prerequisites and the installer.</li>
    <li>Take regular database backups. The update system backs up code before updates; <strong>data backup discipline is yours</strong>.</li>
    <li>You are the data controller for all data in your installation — the <a href="{{ url('/data-protection') }}">Data Protection Policy</a> roles apply with you holding both infrastructure and data.</li>
</ul>

<h2>7. Restrictions</h2>
<ul>
    <li>No reverse engineering, decompiling, or modifying the software (except configuration the product exposes).</li>
    <li>No resale, rental, sublicensing, or bureau use for third parties without a written partnership agreement.</li>
    <li>No circumventing licence, edition or module controls.</li>
</ul>

<h2>8. Warranty and liability</h2>
<ul>
    <li>We warrant the software will substantially conform to its documentation for 90 days from key issue; our obligation is to repair or replace.</li>
    <li>Otherwise the software is provided "as is"; total aggregate liability is capped at <strong>the licence fees paid for the affected licence</strong>; no indirect or consequential damages. The <a href="{{ url('/disclaimer') }}">Disclaimer</a> (statutory outputs depend on your configuration) applies fully.</li>
</ul>

<h2>9. Termination</h2>
<p>This licence terminates only on material breach (e.g. piracy, resale, circumvention) not cured within 30 days of written notice. Clause 3 survives for systems already running except in cases of fraud or unlicensed copies.</p>

<h2>10. Governing law</h2>
<p>Laws of India; exclusive jurisdiction of courts at Hyderabad, Telangana.</p>

<h2>11. Contact</h2>
<p>Licensing questions, transfers, AMC renewals: <a href="mailto:support@ametecsindia.com">support@ametecsindia.com</a> · WhatsApp +91 90000 98877.</p>
@endsection
