<?php

namespace App\Http\Controllers;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Public marketing landing page + super-admin CMS editor.
 * Content is stored as JSON in settings (tenant_id 0 = platform).
 */
class LandingController extends Controller
{
    public function show()
    {
        return view('landing', ['c' => $this->content()]);
    }

    public function editor(Request $request)
    {
        $this->guard($request);

        return view('admin.landing-editor', ['c' => $this->content()]);
    }

    public function save(Request $request)
    {
        $this->guard($request);

        $data = $request->input('content', []);
        // Normalise repeatable groups (drop empty rows).
        foreach (['stats', 'features', 'plans', 'clients'] as $grp) {
            $data[$grp] = array_values(array_filter($data[$grp] ?? [], function ($row) {
                return is_array($row) && count(array_filter($row, fn ($v) => trim((string) $v) !== '')) > 0;
            }));
        }

        self::ensureTable();
        DB::table('settings')->updateOrInsert(
            ['tenant_id' => 0, 'key' => 'landing'],
            ['value' => json_encode($data), 'updated_at' => now(), 'created_at' => now()]
        );

        return redirect()->route('landing.editor')->with('success', 'Landing page updated. View it at the site root.');
    }

    /**
     * rev173d — RESET the landing page to the latest code defaults. The CMS saves
     * a FULL snapshot, so once saved, newer defaults shipped in releases never
     * show; this deletes the snapshot so the site follows defaults() again.
     */
    public function reset(Request $request)
    {
        $this->guard($request);
        try {
            self::ensureTable();
            DB::table('settings')->where('tenant_id', 0)->where('key', 'landing')->delete();
        } catch (\Throwable $e) {
            // nothing saved yet — already on defaults
        }

        return redirect()->route('landing.editor')->with('success', 'Landing page reset to the latest defaults (this release). Customise and Save again if needed.');
    }

    private function guard(Request $request): void
    {
        abort_unless($request->user()?->hasRole('super_admin'), 403, 'Super Admin only.');
    }

    /** Self-create the key/value settings table (project convention). */
    private static function ensureTable(): void
    {
        if (Schema::hasTable('settings')) {
            return;
        }
        Schema::create('settings', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->default(0)->index();
            $t->string('key')->index();
            $t->longText('value')->nullable();
            $t->timestamps();
        });
    }

    public function content(): array
    {
        // Fail soft: a brand-new install (no settings row/table yet) must still
        // render the landing page from defaults rather than 500.
        try {
            self::ensureTable();
            $row = DB::table('settings')->where('tenant_id', 0)->where('key', 'landing')->first();
            $saved = $row ? json_decode($row->value, true) : null;
            $defaults = $this->defaults();
            $merged = array_replace_recursive($defaults, is_array($saved) ? $saved : []);
            // Repeatable groups must REPLACE wholesale (not deep-merge by index, which
            // would mix a saved list with default rows of a different length).
            foreach (['stats', 'features', 'plans', 'clients'] as $grp) {
                $merged[$grp] = (is_array($saved) && ! empty($saved[$grp])) ? $saved[$grp] : $defaults[$grp];
            }

            return $merged;
        } catch (\Throwable $e) {
            return $this->defaults();
        }
    }

    public function defaults(): array
    {
        return [
            'brand' => 'SmartPRS',
            'tagline' => 'by Ametecs',
            'hero' => [
                'badge' => 'HRM · Payroll · RBI Audit-Readiness',
                'title' => 'HR, payroll & RBI audit-readiness for India\'s collections & recovery industry',
                'subtitle' => 'Biometric attendance with working shifts, automated India-compliant payroll, DRA/PCC agent compliance and one-click RBI audit reports on your own letterhead — be audit-ready before the 1 October 2026 directions.',
                'cta' => 'Request a Demo',
                'cta2' => 'Sign in',
                'image' => 'images/hero.png',
            ],
            'stats' => [
                ['n' => '500+', 'l' => 'Employees managed'],
                ['n' => '16', 'l' => 'HR modules · 119 screens'],
                ['n' => '1-click', 'l' => 'RBI agent audit file'],
                ['n' => 'SaaS + On-Prem', 'l' => 'Cloud or L1/L2/L3 licence'],
            ],
            'features' => [
                ['icon' => 'gauge-high', 'title' => 'Dashboard', 'desc' => 'Your live command centre — headcount, attendance, payroll and escalation KPIs at a glance.'],
                ['icon' => 'user-plus', 'title' => 'Hiring & Onboarding', 'desc' => 'Requisitions with approval, job-portal imports into a talent pool, hiring drives, WhatsApp invites, interviews and one-click hire.'],
                ['icon' => 'users', 'title' => 'People', 'desc' => 'One source of truth for every employee — profiles, teams, documents and org structure.'],
                ['icon' => 'fingerprint', 'title' => 'Time & Attendance', 'desc' => 'Biometric (ZKTeco, cloud, CSV) and geo-fenced selfie punch, named working shifts with night allowance, rosters and a wizard-built late policy.'],
                ['icon' => 'umbrella-beach', 'title' => 'Leave', 'desc' => 'Leave types, balances, holiday calendars and self-service approval workflows.'],
                ['icon' => 'money-check-dollar', 'title' => 'Payroll', 'desc' => 'Automated, India-compliant payroll — shift-aware late cuts, night allowance, A4 payslips with a plain-English calculation note on every slip.'],
                ['icon' => 'hand-holding-dollar', 'title' => 'Compensation & Claims', 'desc' => 'Salary structures, reimbursements, expenses, advances and loans — all with approvals.'],
                ['icon' => 'scale-balanced', 'title' => 'Statutory & Compliance', 'desc' => 'PF, ESI, PT and TDS returns — plus one-click RBI agent audit reports (single or bulk) on your own letterhead, with a verdict per agent.'],
                ['icon' => 'trophy', 'title' => 'Performance & Rewards', 'desc' => 'Goals, reviews, incentives, points and leaderboards that keep field teams driven.'],
                ['icon' => 'graduation-cap', 'title' => 'Learning & Knowledge', 'desc' => 'Training programs, courses, assessments, FAQs and a searchable knowledge base.'],
                ['icon' => 'file-signature', 'title' => 'HR Letters', 'desc' => 'Offer, appointment, increment, warning and relieving letters from smart templates.'],
                ['icon' => 'route', 'title' => 'Field Force', 'desc' => 'Off-roll agents, DRA/PCC compliance, geo-tracking and the bank escalation desk.'],
                ['icon' => 'bullhorn', 'title' => 'Communication', 'desc' => 'Notice board, announcements and targeted email, SMS and WhatsApp messaging.'],
                ['icon' => 'chart-line', 'title' => 'Reports & Analytics', 'desc' => 'Attendance, payroll, compliance and workforce insights — exportable on demand.'],
                ['icon' => 'sliders', 'title' => 'Administration', 'desc' => 'Companies, roles, masters, policy repository, exit clearance and a tamper-evident (hash-chained) audit trail of every change.'],
                ['icon' => 'building-user', 'title' => 'SaaS Platform', 'desc' => 'Run multiple companies from one tenant, with plans, billing and GST invoicing.'],
            ],
            'plans' => [
                // NOTE: the blade splits this string on COMMAS — never write an
                // amount like ₹1,000 inside a feature (it becomes two bullets).
                ['name' => 'Starter', 'price' => '₹1,000', 'period' => '/mo + GST', 'highlight' => '0', 'features' => 'Up to 25 employees, All 16 modules included, Extra employee ₹60/mo, Extra company ₹1000/mo, Minimum 3 months advance — 0% discount, 6 months payment — 10% discount, 12 months payment — 25% discount'],
                ['name' => 'Growth', 'price' => '₹2,500', 'period' => '/mo + GST', 'highlight' => '1', 'features' => 'Up to 75 employees, All 16 modules included, Extra employee ₹50/mo, Extra company ₹1000/mo, Minimum 3 months advance — 0% discount, 6 months payment — 10% discount, 12 months payment — 25% discount'],
                ['name' => 'Professional', 'price' => '₹5,000', 'period' => '/mo + GST', 'highlight' => '0', 'features' => 'Up to 150 employees, All 16 modules included, Extra employee ₹40/mo, Extra company ₹1000/mo, Minimum 3 months advance — 0% discount, 6 months payment — 10% discount, 12 months payment — 25% discount'],
            ],
            'clients' => [
                ['name' => 'Exon'], ['name' => 'Storm'], ['name' => 'Numero Uno'], ['name' => 'Vimal Enterprises'],
            ],
            // rev 111: About / Why SmartPRS section (Ejaz 8 Jun 2026) — industry-roots story,
            // deliberately NO client-count claims (skip-tracing figures are unofficial — keep off public pages).
            'about' => [
                'eyebrow' => 'Why SmartPRS',
                'title' => 'Built inside the industry, not just for it',
                'body' => "SmartPRS was not designed in a software lab. Since 2019, Ametecs has built every product on a community-development model — growing through regular feedback, real experiences, pain points and ideas shared by industry leaders, experienced professionals and domain experts from India's collections & recovery world.\n\n"
                    ."That is how SmartDCM, our debt collection platform, was built — by deeply studying each client's requirement and answering it with innovative, practical solutions. The same method now powers SmartPRS: every module — from biometric attendance and statutory payroll to incentive engines and DRA/PCC compliance — exists because someone in the industry asked for it. That is what makes SmartPRS feel familiar from the very first login.",
                // Proof strip under the story — SmartDCM figures Ejaz approved for public use (8 Jun 2026).
                'proof' => 'SmartDCM by Ametecs today serves 300+ collection agencies with 2,500+ users across India.',
                'proof_label' => 'Know more at smartdcm.app',
                'proof_url' => 'https://www.smartdcm.app',
                'founder' => 'Ejaz Hussain',
                'founder_role' => 'Founder & Managing Director, Ametecs India Pvt. Ltd.',
                'short' => 'The complete workforce platform for India\'s collections & recovery industry — built from deep industry experience and the feedback of agency teams across the country. Conceived and led by Ejaz Hussain, Managing Director, Ametecs India.',
            ],
            'contact' => [
                'email' => 'sales@ametecsindia.com',
                // rev 89: real numbers (Ejaz 6 Jun 2026) — all editable in /admin/landing.
                // NOTE: only 9000098877 has WhatsApp (9666612424 does NOT) — both
                // the Chat button and the Interakt lead alert must use 9000098877.
                'phone' => '+91 96666 12424, +91 77020 01122',
                'whatsapp' => '919000098877',           // Chat-on-WhatsApp button (wa.me)
                'lead_email' => 'sales@ametecsindia.com', // every demo request emailed here
                'lead_wa' => '919000098877',            // WhatsApp alert per lead (Interakt)
                // rev 111: multi-line (one array line = one page line; blade renders via nl2br)
                'address' => "M/s. Ametecs India Private Limited\n"
                    ."Modern Profound Techpark, Ground Floor,\n"
                    ."Hive Space, opp. Google, Whitefields,\n"
                    ."Kondapur, Hyderabad, Telangana,\n"
                    ."India 500084 · GST: 36AAHCT0971F1ZB",
            ],
            'footer' => '© '.date('Y').' Ametecs India Pvt. Ltd. All rights reserved.',
        ];
    }
}
