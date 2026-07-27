<?php

namespace App\Http\Controllers;

/**
 * rev 111 (8 Jun 2026): Public legal/policy pages — required for Razorpay LIVE
 * approval and DPDP/IT-Rules compliance. Content decisions taken with Ejaz in
 * the 8-Jun Q&A session (support@ametecsindia.com + WA 9000098877; Mon–Sat
 * 10–19 IST, 1-business-day normal / 4-business-hour critical; 7-day money-back
 * then no pro-rata; India datacenter; GA + Meta pixel; Razorpay/Interakt/host/
 * SMTP sub-processors; generic Grievance Officer; 90-day retention; 3-month-fee
 * liability cap; 99% best-effort; 30-day price notice; warn-then-suspend;
 * AMC 20%/yr — first year included with the licence).
 *
 * Each page is a blade at resources/views/policies/{slug}.blade.php extending
 * policies/layout.blade.php. Adding a page = add blade + entry in PAGES.
 */
class PolicyController extends Controller
{
    /** slug => [title, short description for the header] */
    public const PAGES = [
        'privacy-policy' => ['Privacy Policy', 'What we collect, why, and your rights'],
        'terms-and-conditions' => ['Terms & Conditions', 'The agreement governing your SmartPRS subscription'],
        'refund-policy' => ['Refund & Cancellation Policy', 'Money-back window, renewals and cancellations'],
        'support-policy' => ['Support Policy & SLA', 'Channels, hours and response commitments'],
        'data-protection' => ['Data Protection & Processing Policy', 'How we safeguard your workforce data'],
        'acceptable-use' => ['Acceptable Use Policy', 'What may and may not be done on SmartPRS'],
        'grievance-redressal' => ['Grievance Redressal', 'How to raise and escalate a complaint'],
        'disclaimer' => ['Disclaimer', 'Scope and limits of the information we provide'],
        'licence-agreement' => ['On-Premise Licence Agreement & AMC Policy', 'Perpetual editions, activation and annual maintenance'],
        // rev 114: complete FAQ page — answers link to the deep documents above.
        'faqs' => ['Frequently Asked Questions', 'Every question answered — with links to the detailed documents'],
    ];

    public const EFFECTIVE = '8 June 2026';

    public function show(string $slug)
    {
        abort_unless(isset(self::PAGES[$slug]), 404);
        [$title, $sub] = self::PAGES[$slug];

        return view('policies.'.$slug, [
            'title' => $title,
            'sub' => $sub,
            'effective' => self::EFFECTIVE,
            'pages' => self::PAGES,
            'slug' => $slug,
        ]);
    }
}
