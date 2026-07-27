@extends('policies.layout')

@section('content')
<style>
    .fq-search{width:100%;padding:13px 16px;border:1.5px solid var(--border);border-radius:12px;font-size:15px;font-family:inherit;outline:none;margin-bottom:14px;background:#f8fafc;}
    .fq-search:focus{border-color:var(--accent);background:#fff;}
    .fq-chips{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:26px;}
    .fq-chips a{font-size:.82rem;font-weight:700;color:var(--text2);background:#f1f5f9;border:1px solid var(--border);border-radius:99px;padding:6px 14px;}
    .fq-chips a:hover{color:var(--accent);border-color:var(--accent);}
    .fq-cat{font-size:1.15rem;font-weight:800;color:var(--navy);margin:34px 0 6px;padding-top:8px;}
    details.fq{border:1px solid var(--border);border-radius:12px;margin:10px 0;background:#fff;overflow:hidden;}
    details.fq summary{cursor:pointer;padding:14px 18px;font-weight:700;color:var(--navy);list-style:none;display:flex;justify-content:space-between;align-items:center;gap:12px;}
    details.fq summary::after{content:'+';color:var(--accent);font-weight:800;font-size:1.2rem;flex:0 0 auto;}
    details.fq[open] summary::after{content:'−';}
    details.fq .a{padding:0 18px 16px;color:var(--text2);}
    details.fq .a p{margin:0 0 10px;}
    .fq-deep{font-size:.86rem;font-weight:700;}
    .fq-deep a{white-space:nowrap;}
</style>

<p>Everything below is answered briefly here, with a <strong>"Read in detail"</strong> link at the bottom of each answer taking you to the full document or page. Can't find your question? Write to <a href="mailto:support@ametecsindia.com">support@ametecsindia.com</a> or WhatsApp +91 90000 98877.</p>

<input class="fq-search" id="fqs" placeholder="Type to search the FAQs — e.g. refund, GPS, coupon, AMC…" oninput="fqFilter()">
<div class="fq-chips">
    <a href="#c1">Getting started</a><a href="#c2">Pricing &amp; plans</a><a href="#c3">Coupons &amp; offers</a><a href="#c4">Payments &amp; refunds</a><a href="#c5">Data &amp; privacy</a><a href="#c6">The application</a><a href="#c7">Support &amp; updates</a><a href="#c8">On-premise &amp; licensing</a><a href="#c9">Account &amp; legal</a>
</div>

<div class="fq-cat" id="c1">Getting started &amp; demos</div>

<details class="fq"><summary>What exactly is SmartPRS?</summary><div class="a">
<p>SmartPRS is the complete workforce platform for India's collections &amp; recovery industry — 16 modules covering hiring, biometric &amp; GPS attendance, leave, statutory payroll (PF/ESI/PT/TDS), incentive &amp; commission engines, field-force compliance, letters, learning, communication and analytics. Built by Ametecs from years of working inside the industry.</p>
<p class="fq-deep">Read in detail: <a href="{{ url('/') }}#about">Why SmartPRS</a> · <a href="{{ url('/') }}#features">The 16 modules</a></p></div></details>

<details class="fq"><summary>Can I try SmartPRS before buying?</summary><div class="a">
<p>Yes, two ways. The <strong>Live Demo</strong> at smartprs.com/demo lets you explore a working workspace with sample data and a guided voice tour — entry takes one OTP, no card. Or request a <strong>personal demonstration</strong> from the demo form and our team will walk your team through it on a call.</p>
<p class="fq-deep">Read in detail: <a href="{{ url('/demo') }}">Start the Live Demo</a></p></div></details>

<details class="fq"><summary>Is the data in the live demo real?</summary><div class="a">
<p>No — every record in the demo is fictional and the whole demo workspace resets automatically every few hours. Please don't enter real personal or business data there; anything you type is temporary and visible to other demo visitors.</p>
<p class="fq-deep">Read in detail: <a href="{{ url('/data-protection') }}">Data Protection Policy</a> (section 10)</p></div></details>

<details class="fq"><summary>How fast is my workspace ready after payment?</summary><div class="a">
<p>Instantly. The moment your payment succeeds, your workspace is created, you are signed in automatically in the same browser, and your admin login with a temporary password is emailed — along with your GST tax invoice. Most clients are inside their workspace within one minute of paying.</p>
<p class="fq-deep">Read in detail: <a href="{{ url('/signup') }}">Signup page</a></p></div></details>

<details class="fq"><summary>How will my team learn to use it?</summary><div class="a">
<p>SmartPRS is built for self-service: every screen has an ⓘ guide with three tabs (how to use it, why it matters, and mistakes to avoid), there's a guided tour, and a full Training Manual. On top of that, every new client gets one free online onboarding session for their team.</p>
<p class="fq-deep">Read in detail: <a href="{{ url('/support-policy') }}">Support Policy</a> (section 6)</p></div></details>

<div class="fq-cat" id="c2">Pricing &amp; plans</div>

<details class="fq"><summary>How is SmartPRS priced?</summary><div class="a">
<p>Three plans — Starter ₹1,000/mo (25 employees included), Growth ₹2,500/mo (75) and Professional ₹5,000/mo (150) — all with <strong>all 16 modules included</strong>. Extra employees are ₹60/₹50/₹40 per month respectively, and each additional company in your group is a flat ₹1,000/month. Prices exclude GST.</p>
<p class="fq-deep">Read in detail: <a href="{{ url('/') }}#pricing">Pricing</a></p></div></details>

<details class="fq"><summary>What does "employees included" actually mean?</summary><div class="a">
<p>It is the number of <strong>active on-roll employees</strong> across your whole workspace — companies don't change it. If you subscribe for 75 employees and run 3 companies, that's 75 employees total across all three. Employees who exit free their seat.</p>
<p class="fq-deep">Read in detail: <a href="{{ url('/terms-and-conditions') }}">Terms &amp; Conditions</a> (section 3)</p></div></details>

<details class="fq"><summary>What discounts do I get for paying in advance?</summary><div class="a">
<p>The minimum billing period is 3 months (no discount). Pay 6 months in advance and get <strong>10% off</strong>; pay 12 months and get <strong>25% off</strong> — applied to the entire invoice including extra employees and companies.</p>
<p class="fq-deep">Read in detail: <a href="{{ url('/') }}#pricing">Pricing</a></p></div></details>

<details class="fq"><summary>How does GST appear on my invoice?</summary><div class="a">
<p>GST is 18%, added on the invoice. Telangana businesses are billed CGST 9% + SGST 9%; businesses in other states get IGST 18% — the total is the same. Give your GSTIN at signup and it prints on every tax invoice automatically.</p>
<p class="fq-deep">Read in detail: <a href="{{ url('/refund-policy') }}">Refund Policy</a> (section 9 — GST adjustments)</p></div></details>

<details class="fq"><summary>Can I run multiple companies under one subscription?</summary><div class="a">
<p>Yes — that's a core strength. Every plan includes one company; each additional company is ₹1,000/month flat on any plan. Employees, attendance, payroll and reports stay neatly separated per company while you manage everything from one login, including employee transfers between companies.</p>
<p class="fq-deep">Read in detail: <a href="{{ url('/terms-and-conditions') }}">Terms</a> (section 3)</p></div></details>

<details class="fq"><summary>Will my price increase after I subscribe?</summary><div class="a">
<p>Never mid-period. A period you have paid for is never repriced. If rates revise, you get at least 30 days' notice and the new price applies only from your next renewal.</p>
<p class="fq-deep">Read in detail: <a href="{{ url('/terms-and-conditions') }}">Terms</a> (section 5)</p></div></details>

<div class="fq-cat" id="c3">Coupons &amp; offers</div>

<details class="fq"><summary>How do coupon codes work?</summary><div class="a">
<p>During a campaign you'll see a "Have a coupon code?" box on the signup page (and in the renewal dialog). Type the code, click Apply, and the discount shows instantly in your price summary and on your invoice. Each code carries its own rules — expiry date, usage limits, eligible plans and billing periods.</p>
<p class="fq-deep">Read in detail: <a href="{{ url('/signup') }}">Signup page</a></p></div></details>

<details class="fq"><summary>I received an exclusive offer by email — how do I use it?</summary><div class="a">
<p>Simply use the <strong>same email address</strong> the offer was sent to. The system recognises it on the signup page or your renewal screen and applies the discount automatically — no typing needed. The offer is personal (it won't work for any other email), valid for one purchase, and has the expiry date stated in the email.</p></div></details>

<details class="fq"><summary>Do coupons combine with the advance-payment discounts?</summary><div class="a">
<p>Yes — a coupon applies on top of the 6-month or annual advance discount. An annual payer using a 10% coupon enjoys both.</p></div></details>

<details class="fq"><summary>Why don't I see any coupon box on the signup page?</summary><div class="a">
<p>The box appears only while a public campaign is running, or when your email has an exclusive offer attached. No active campaign — no box. Follow our announcements or ask the sales team about current offers.</p></div></details>

<div class="fq-cat" id="c4">Payments, invoices &amp; refunds</div>

<details class="fq"><summary>What payment methods are accepted?</summary><div class="a">
<p>All payments are processed securely by Razorpay — UPI, credit/debit cards and netbanking. We never see or store your card or banking details. There is <strong>no auto-debit</strong>: renewals happen only when you choose to pay.</p>
<p class="fq-deep">Read in detail: <a href="{{ url('/privacy-policy') }}">Privacy Policy</a> (section 1c)</p></div></details>

<details class="fq"><summary>What is the refund policy?</summary><div class="a">
<p>First-time subscribers get a <strong>7-day money-back guarantee</strong> — write to support within 7 days of your first payment for a full refund, no questions asked. After that window, advance payments are non-refundable and there are no pro-rata refunds for unused months; your workspace stays active until the paid period ends.</p>
<p class="fq-deep">Read in detail: <a href="{{ url('/refund-policy') }}">Refund &amp; Cancellation Policy</a></p></div></details>

<details class="fq"><summary>I was charged twice / money was debited but payment failed.</summary><div class="a">
<p>Verified duplicate charges and failed-but-debited amounts are <strong>always refunded in full</strong> — send the transaction reference to support and it's processed within 7–10 business days to the original payment method.</p>
<p class="fq-deep">Read in detail: <a href="{{ url('/refund-policy') }}">Refund Policy</a> (section 6)</p></div></details>

<details class="fq"><summary>My finance team needs approval before paying. Can I get a quotation?</summary><div class="a">
<p>Yes — on the signup page click <strong>"Send me a Quotation"</strong>. A GST quotation PDF is emailed to you with a secure payment link, valid 15 days. Any discount (coupon or exclusive offer) is locked into the quote and printed clearly on the PDF. The moment anyone pays through the link, the workspace creates itself.</p>
<p class="fq-deep">Read in detail: <a href="{{ url('/signup') }}">Signup page</a></p></div></details>

<details class="fq"><summary>What happens if I forget to renew?</summary><div class="a">
<p>You get reminders before expiry (email, and WhatsApp where enabled). After expiry there's a <strong>7-day grace period</strong> with full access. After grace, your admin can still sign in to renew but other users are paused. Your data stays safe for 90 days — renew any time in that window and everything is exactly as you left it.</p>
<p class="fq-deep">Read in detail: <a href="{{ url('/terms-and-conditions') }}">Terms</a> (section 4)</p></div></details>

<div class="fq-cat" id="c5">Data security &amp; privacy</div>

<details class="fq"><summary>Who owns the data we put into SmartPRS?</summary><div class="a">
<p><strong>You do — completely.</strong> Ametecs processes your workspace data only to provide the service. It is never sold, never used for advertising, never used to train third-party AI, and never visible to any other client. You can export it any time.</p>
<p class="fq-deep">Read in detail: <a href="{{ url('/data-protection') }}">Data Protection &amp; Processing Policy</a></p></div></details>

<details class="fq"><summary>Where is our data stored?</summary><div class="a">
<p>In a datacenter located <strong>in India</strong>. Workspace data is not moved outside India in the ordinary course of service — clean and simple for DPDP compliance.</p>
<p class="fq-deep">Read in detail: <a href="{{ url('/privacy-policy') }}">Privacy Policy</a> (section 5)</p></div></details>

<details class="fq"><summary>Can another company on SmartPRS see our data?</summary><div class="a">
<p>No. Every record is scoped to your workspace and cross-tenant access is structurally blocked in the application layer. Inside your workspace, role-based permissions decide who sees what — you control who can view salaries, for example.</p>
<p class="fq-deep">Read in detail: <a href="{{ url('/data-protection') }}">Data Protection Policy</a> (section 4)</p></div></details>

<details class="fq"><summary>Is GPS and selfie attendance legal for our employees?</summary><div class="a">
<p>GPS punches and selfies are features <strong>your organisation chooses to enable</strong> — as the employer, you control that data and should have the appropriate consent from employees. SmartPRS stores it securely and uses it only for the attendance function. Employees can exercise their data rights through you.</p>
<p class="fq-deep">Read in detail: <a href="{{ url('/data-protection') }}">Data Protection Policy</a> (sections 2 &amp; 7)</p></div></details>

<details class="fq"><summary>What happens to our data if we leave?</summary><div class="a">
<p>You can export everything before your subscription ends. We keep your data for <strong>90 days</strong> after expiry (so you can export or change your mind), then it is permanently deleted, including from routine backups in the following cycle.</p>
<p class="fq-deep">Read in detail: <a href="{{ url('/data-protection') }}">Data Protection Policy</a> (section 8)</p></div></details>

<div class="fq-cat" id="c6">The application</div>

<details class="fq"><summary>What attendance options does SmartPRS support?</summary><div class="a">
<p>Biometric devices (ZKTeco, eSSL and others), mobile punch with GPS and selfie, geofenced field attendance, manual entry with a searchable employee picker, and bulk upload — including the punch-log format exported by offline biometric devices, so even a device dump imports cleanly.</p>
<p class="fq-deep">Read in detail: <a href="{{ url('/') }}#features">Modules</a></p></div></details>

<details class="fq"><summary>Is the payroll really India statutory-compliant?</summary><div class="a">
<p>SmartPRS automates PF, ESI, Professional Tax, TDS and gratuity with payslips, registers and challan-ready outputs. The computations follow the rates and rules your team configures — verify filings with your CA before submission, as with any payroll software.</p>
<p class="fq-deep">Read in detail: <a href="{{ url('/disclaimer') }}">Disclaimer</a> (section 1)</p></div></details>

<details class="fq"><summary>How does SmartPRS handle collections incentives and commissions?</summary><div class="a">
<p>This is our DNA — a complete money chain. Managers publish <strong>Incentive Schemes</strong>; agents claim against them with full <strong>collection evidence</strong>; <strong>Accounts verifies</strong> the money actually arrived; the manager approves; the payslip or disbursement ledger pays. Automatic TDS, edit/approve/lock audit trail on every entry, and <strong>Live Salary</strong> — every agent sees their earnings till today, entry by entry. Off-roll field agents get their own KYC records and live earnings links without needing logins.</p>
<p class="fq-deep">Read in detail: <a href="{{ url('/demo') }}">See it in the Live Demo</a></p></div></details>

<details class="fq"><summary>What are Incentive Schemes?</summary><div class="a">
<p>Published offers your people claim against — "2% of collections on the HDFC portfolio till month-end" or "₹500 per settlement this week". A scheme defines how it pays (% of collected amount, fixed ₹, or open), who can claim (everyone, one team, or selected people), validity dates and per-person caps. Team Leaders' and Managers' schemes pass through <strong>their manager's approval</strong> before going live; on approval the targeted people are announced automatically (email, WhatsApp, notice board — your choice of channels) and their Live Salary card shows the offer as an orange ribbon.</p>
<p>Inside the claim form, the agent picks the scheme and everything fills itself — purpose, TDS, and the commission computed from the collected amount at the scheme's rate. Nobody can type a wrong rate. Schemes can be withdrawn (with a clear choice about pending claims), re-opened if withdrawn by mistake, and even edited mid-way — unlocked claims recalculate to the new rates and previously-approved ones return for re-approval.</p>
<p class="fq-deep">Read in detail: <a href="{{ url('/demo') }}">Try it in the Live Demo</a></p></div></details>

<details class="fq"><summary>How does a field agent or telecaller claim a commission?</summary><div class="a">
<p>From their own login (or mobile), with the entry locked to themselves. Two claim types: a <strong>Collection claim</strong> records the full evidence — customer name, account/ID, what was collected, date &amp; time, location, mode of collection (cash to office / deposited / client paid directly) and an optional payment proof; a <strong>Simple claim</strong> covers targets, bonuses and special incentives with just notes. Agents can even bulk-upload their own month's claims from Excel — every row is created for them only. The approver is always their reporting manager; large amounts escalate one level up.</p>
<p class="fq-deep">Read in detail: <a href="{{ url('/demo') }}">Live Demo</a></p></div></details>

<details class="fq"><summary>Who verifies that the collected money actually reached the company?</summary><div class="a">
<p>The <strong>Accounts stage</strong>. Every collection claim waits for the Accounts role (or an Admin) to confirm the money against the bank's payments-received list or the attached proof — and the manager's approve button simply refuses until that confirmation. Accounts can also flag a problem with a reason the agent and manager both see. The money trail comes before the commission, structurally.</p>
<p class="fq-deep">Read in detail: <a href="{{ url('/data-protection') }}">Data Protection Policy</a> (audit trails)</p></div></details>

<details class="fq"><summary>Can SmartPRS handle high-volume hiring from job portals?</summary><div class="a">
<p>Yes — import candidates in bulk from Naukri/LinkedIn/Indeed exports, build a searchable talent pool, send bulk WhatsApp interview and walk-in invites with delivery tracking, and run Hiring Drives with panels, rosters and outcome funnels. Selected candidates convert to employees in one click.</p>
<p class="fq-deep">Read in detail: <a href="{{ url('/') }}#features">Modules</a></p></div></details>

<details class="fq"><summary>What can SmartPRS send on WhatsApp?</summary><div class="a">
<p>Transactional messages through the official WhatsApp Business API (via Interakt) — OTPs, welcome and payment messages, renewal reminders, and recruitment campaigns. Messages use Meta-approved templates; your own campaigns must follow consent rules — the content you send is your responsibility.</p>
<p class="fq-deep">Read in detail: <a href="{{ url('/acceptable-use') }}">Acceptable Use Policy</a> (section 2)</p></div></details>

<div class="fq-cat" id="c7">Support &amp; updates</div>

<details class="fq"><summary>How do I get support?</summary><div class="a">
<p>Email <a href="mailto:support@ametecsindia.com">support@ametecsindia.com</a> or WhatsApp +91 90000 98877, Monday–Saturday 10:00–19:00 IST. Normal queries get a qualified reply within 1 business day; critical issues (system down, payroll blocked on payroll day) within 4 business hours.</p>
<p class="fq-deep">Read in detail: <a href="{{ url('/support-policy') }}">Support Policy &amp; SLA</a></p></div></details>

<details class="fq"><summary>Do you visit our office for support or installation?</summary><div class="a">
<p>All support is <strong>remote/online</strong> — email, WhatsApp and screen-sharing/remote-desktop sessions over collaboration tools like Google Meet, Zoom or AnyDesk. This keeps support fast and affordable. On-premise installations are also done remotely with your IT person at the server.</p>
<p class="fq-deep">Read in detail: <a href="{{ url('/support-policy') }}">Support Policy</a></p></div></details>

<details class="fq"><summary>How often does SmartPRS improve?</summary><div class="a">
<p>Continuously — features and fixes ship regularly. Cloud workspaces are updated centrally with automatic backup and rollback protection. On-premise clients with active AMC apply the same updates from their built-in Updates screen in two clicks.</p>
<p class="fq-deep">Read in detail: <a href="{{ url('/licence-agreement') }}">Licence Agreement</a> (section 5)</p></div></details>

<details class="fq"><summary>Can we request features?</summary><div class="a">
<p>Please do — SmartPRS is built on industry feedback since day one. Send requests to support; they go onto the product roadmap and many of today's modules started exactly that way.</p>
<p class="fq-deep">Read in detail: <a href="{{ url('/') }}#about">How we build</a></p></div></details>

<div class="fq-cat" id="c8">On-premise &amp; licensing</div>

<details class="fq"><summary>Can SmartPRS run on our own server instead of the cloud?</summary><div class="a">
<p>Yes. SmartPRS is available as perpetual on-premise editions — <strong>L1 Core</strong> (HR, attendance with GPS punch, leave, full statutory payroll), <strong>L2 Professional</strong> (adds recruitment, letters, claims, performance, learning) and <strong>L3 Collections DNA</strong> (adds the incentive engine, live salary, field-force compliance and volume hiring). One-time licence, your server, your data.</p>
<p class="fq-deep">Read in detail: <a href="{{ url('/licence-agreement') }}">On-Premise Licence Agreement</a></p></div></details>

<details class="fq"><summary>What does "perpetual licence" really mean?</summary><div class="a">
<p>Your software <strong>never stops working</strong> — that promise is built into the architecture. A lapsed AMC or even a commercial dispute blocks new activations and updates, but never switches off a running system. Your payroll is never held hostage.</p>
<p class="fq-deep">Read in detail: <a href="{{ url('/licence-agreement') }}">Licence Agreement</a> (section 3)</p></div></details>

<details class="fq"><summary>What is AMC and what does it include?</summary><div class="a">
<p>Annual Maintenance is 20% of the licence price per year — the first 12 months are included with your purchase. Active AMC gets you every product update (delivered through the in-app Updates screen with checksum verification, automatic backup and rollback) plus remote support. Lapsed AMC: software keeps running, updates and support pause until renewal.</p>
<p class="fq-deep">Read in detail: <a href="{{ url('/licence-agreement') }}">Licence Agreement</a> (section 4)</p></div></details>

<details class="fq"><summary>We're replacing our server — will the licence move?</summary><div class="a">
<p>Yes. One key activates one server, and you can move it yourself once per year through self-service deactivation. Genuine cases beyond that (hardware failure, planned migration) are handled by support and never refused unreasonably.</p>
<p class="fq-deep">Read in detail: <a href="{{ url('/licence-agreement') }}">Licence Agreement</a> (section 2)</p></div></details>

<div class="fq-cat" id="c9">Account &amp; legal</div>

<details class="fq"><summary>What am I not allowed to do on SmartPRS?</summary><div class="a">
<p>The short version: use it lawfully, message only people who have a relationship with you (no spam), keep records genuine, don't probe security, and don't resell without a partnership agreement. Violations are handled warn-first; serious abuse means immediate suspension.</p>
<p class="fq-deep">Read in detail: <a href="{{ url('/acceptable-use') }}">Acceptable Use Policy</a></p></div></details>

<details class="fq"><summary>How do I raise a complaint?</summary><div class="a">
<p>Start with support. If unresolved — or for privacy/data matters — write to the Grievance Officer with "GRIEVANCE" in the subject. Acknowledgement within 48 hours, resolution within 15 days, and you can ask for escalation to the Managing Director's office.</p>
<p class="fq-deep">Read in detail: <a href="{{ url('/grievance-redressal') }}">Grievance Redressal</a></p></div></details>

<details class="fq"><summary>Can we get our own branded login page?</summary><div class="a">
<p>Two levels: every workspace gets a branded login at smartprs.com/c/<em>yourname</em> (you choose the name at signup — 3 to 10 letters). And as a premium add-on, your own domain (like hr.yourcompany.com) can show your branded login via a simple CNAME record — ask the team.</p>
<p class="fq-deep">Read in detail: <a href="{{ url('/signup') }}">Signup page</a></p></div></details>

<details class="fq"><summary>Which courts govern the agreement?</summary><div class="a">
<p>The agreement is governed by the laws of India with exclusive jurisdiction of courts at Hyderabad, Telangana — where Ametecs India Private Limited is registered.</p>
<p class="fq-deep">Read in detail: <a href="{{ url('/terms-and-conditions') }}">Terms &amp; Conditions</a> (section 16)</p></div></details>

<script>
function fqFilter(){
    var q = document.getElementById('fqs').value.toLowerCase().trim();
    var cats = document.querySelectorAll('.fq-cat');
    document.querySelectorAll('details.fq').forEach(function(d){
        var hit = !q || d.textContent.toLowerCase().indexOf(q) !== -1;
        d.style.display = hit ? '' : 'none';
        if (q && hit) { d.open = true; } else if (!q) { d.open = false; }
    });
    cats.forEach(function(c){
        var any = false, el = c.nextElementSibling;
        while (el && !el.classList.contains('fq-cat')) {
            if (el.tagName === 'DETAILS' && el.style.display !== 'none') { any = true; }
            el = el.nextElementSibling;
        }
        c.style.display = any ? '' : 'none';
    });
}
</script>
@endsection
