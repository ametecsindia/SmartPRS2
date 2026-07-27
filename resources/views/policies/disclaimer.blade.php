@extends('policies.layout')

@section('content')
<h2>1. SmartPRS is a tool, not an advisor</h2>
<p>SmartPRS automates payroll, statutory computations (PF, ESI, PT, TDS, gratuity and others), compliance trackers and related workflows <strong>based entirely on the rates, rules, settings and data entered by your organisation</strong>. Outputs are only as correct as the configuration and inputs. Nothing in the software, its help content, or our communications constitutes legal, tax, accounting or professional advice. Verify statutory filings with your chartered accountant or consultant before submission.</p>

<h2>2. Starter compliance content</h2>
<p>Industry content seeded into workspaces — training material, FAQs, knowledge-base articles, code-of-conduct text, letter templates (grounded in sources such as the RBI Fair Practices Code, IIBF DRA guidance, DPDP Act and related law) — is <strong>editable starter material</strong> provided for convenience. Your organisation is responsible for reviewing and adapting it to your own legal obligations before relying on it.</p>

<h2>3. Marketing figures</h2>
<p>Statistics on our website (clients, users, uptime, pickup-rate improvements and similar) are indicative business figures at the time of publication, provided for general information, and may change.</p>

<h2>4. Third-party services</h2>
<p>Payments run on Razorpay; WhatsApp messaging runs on Meta's WhatsApp Business platform via Interakt; email runs on third-party SMTP. These services have their own terms and availability — we are not responsible for their outages, policy changes (e.g. WhatsApp template approvals) or acts.</p>

<h2>5. External links</h2>
<p>Our website and application may link to external sites (e.g. smartdcm.app, regulator websites). We are not responsible for their content.</p>

<h2>6. Demo environment</h2>
<p>The public live demo contains fictional data and is reset automatically. Anything entered there is temporary and visible to other demo visitors — do not enter real personal or business data.</p>

<h2>7. Trademarks</h2>
<p>SmartPRS, SmartDCM and the Ametecs marks belong to Ametecs India Private Limited. Client names and logos shown on our website belong to their respective owners and indicate a commercial relationship, not endorsement of any specific result.</p>

<h2>8. Limitation</h2>
<p>This disclaimer works together with the <a href="{{ url('/terms-and-conditions') }}">Terms &amp; Conditions</a> (including the limitation of liability). Where law does not permit an exclusion, it applies to the maximum extent permitted.</p>
@endsection
