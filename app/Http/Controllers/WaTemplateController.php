<?php

namespace App\Http\Controllers;

use App\Services\WaService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * rev 92 — WhatsApp TEMPLATE manager (Ejaz: "template creation and submission
 * etc and the approval status a complete module, for SAAS and the Tenant").
 *
 * Reality check: Interakt's public API can only SEND template messages —
 * templates are created/approved in the Interakt dashboard. So this module is
 * the single registry + workflow tracker:
 *
 *   draft → (copy into Interakt dashboard, submit there) → submitted
 *         → [Send test] — a successful send PROVES approval → approved
 *         → a failed send keeps the status and stores Interakt's error text
 *
 * Scope: super admin rows have tenant_id NULL (PLATFORM templates — the ones
 * the signup/renewal/lead flows use); tenant admins manage their own rows.
 * WaService::templateNameFor() resolves the actual template name per purpose
 * from APPROVED rows here, falling back to env/defaults — so renaming a
 * template in this module is all it takes for the flows to use it.
 */
class WaTemplateController extends Controller
{
    /** Platform purposes (super admin) — wired into live flows already. */
    public const PLATFORM_PURPOSES = [
        'welcome' => 'Signup welcome',
        'payment' => 'Payment confirmation',
        'renewal' => 'Renewal reminder',
        'lead' => 'Website lead alert',
        'otp' => 'OTP / verification (Authentication)',
        'custom' => 'Custom',
    ];

    /**
     * rev 93 (Ejaz: "scan entire application… offer letter appointment etc…
     * everywhere it is required"): TENANT purposes — one per message-sending
     * point found in the code (MailService::queue callers), HR-lifecycle order.
     */
    public const TENANT_PURPOSES = [
        'offer_letter' => 'Offer letter (to candidate)',
        'appointment_letter' => 'Appointment / joining (new hire)',
        'interview_schedule' => 'Interview invite (candidate)',
        'walkin_invite' => 'Walk-in interview invite (bulk)',
        'user_invite' => 'User login invite (employee)',
        'leave_decision' => 'Leave approved / rejected',
        'approval_pending' => 'Approval request (to approver)',
        'request_decision' => 'Request approved / rejected',
        'payslip_ready' => 'Payslip ready',
        'salary_credited' => 'Salary payment made',
        'commission_approved' => 'Commission / incentive approved',
        'increment_letter' => 'Increment / appraisal letter',
        'transfer_order' => 'Transfer order (acknowledge)',
        'transfer_applied' => 'Transfer in effect',
        'compliance_alert' => 'DRA / PCC expiry alert (to HR)',
        'agent_earnings_link' => 'Off-roll agent earnings link',
        'announcement' => 'Notice / announcement',
        'custom' => 'Custom',
    ];

    private static function purposesFor(bool $isPlatform): array
    {
        return $isPlatform ? self::PLATFORM_PURPOSES : self::TENANT_PURPOSES;
    }

    public static function ensure(): void
    {
        if (Schema::hasTable('wa_templates')) {
            return;
        }
        Schema::create('wa_templates', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->nullable()->index();   // NULL = platform
            $t->string('purpose', 20)->default('custom');               // welcome|payment|renewal|lead|custom
            $t->string('name');                                         // EXACT Interakt template name
            $t->string('language', 10)->default('en');
            $t->string('category', 20)->default('utility');             // utility|marketing|authentication
            $t->text('body')->nullable();                               // with {{1}}..{{n}}
            $t->text('sample_values')->nullable();                      // comma-separated, for approval + tests
            $t->integer('var_count')->default(0);
            $t->string('status', 20)->default('draft');                 // draft|submitted|approved|rejected
            $t->timestamp('last_test_at')->nullable();
            $t->text('last_error')->nullable();
            $t->timestamps();
        });
    }

    /** The four platform defaults (only when the platform has none yet). */
    private static function seedPlatformDefaults(): void
    {
        if (DB::table('wa_templates')->whereNull('tenant_id')->exists()) {
            return;
        }
        $now = now();
        $rows = [
            ['purpose' => 'welcome', 'name' => 'smartprs_welcome', 'vars' => 5,
                'body' => "Hi {{1}}, welcome to SmartPRS! Your workspace for {{2}} is ready.\nSign in at {{3}}\nEmail: {{4}}\nTemporary password: {{5}}\nPlease change the password after your first sign-in. — Team Ametecs",
                'sample' => 'Ravi Kumar, Apex Collections Pvt Ltd, https://smartprs.com/login, ravi@apex.in, Xy7#kPq2Lm9w'],
            ['purpose' => 'payment', 'name' => 'smartprs_payment', 'vars' => 7,
                'body' => "Hi {{1}}, payment received!\nPlan: {{2}} ({{3}} employees, {{4}})\nAmount paid: {{5}}\nPayment ref: {{6}}\nSubscription active until {{7}}.\nYour GST tax invoice has been emailed. — SmartPRS by Ametecs",
                'sample' => 'Ravi Kumar, Growth, 75, Quarterly (3 months), Rs 8850.00, pay_Nx12345, 06 Sep 2026'],
            ['purpose' => 'renewal', 'name' => 'smartprs_renewal', 'vars' => 5,
                'body' => "Hi {{1}}, your SmartPRS {{2}} subscription expires on {{3}} ({{4}}).\nRenew here to avoid interruption: {{5}}\n— SmartPRS by Ametecs",
                'sample' => 'Apex Collections, Growth, 06 Sep 2026, in 7 days, https://smartprs.com/app'],
            ['purpose' => 'lead', 'name' => 'smartprs_lead', 'vars' => 5,
                'body' => "New SmartPRS demo request: {{1}} from {{2}}.\nMobile: {{3}}, City: {{4}}, Employees: {{5}}.\nOpen smartprs.com/admin/leads to follow up.",
                'sample' => 'Ravi Kumar, Apex Collections, 9876543210, Vijayawada, 26 - 75'],
        ];
        foreach ($rows as $r) {
            DB::table('wa_templates')->insert([
                'tenant_id' => null, 'purpose' => $r['purpose'], 'name' => $r['name'],
                'language' => 'en', 'category' => 'utility', 'body' => $r['body'],
                'sample_values' => $r['sample'], 'var_count' => $r['vars'],
                'status' => 'draft', 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    /**
     * rev 93: the complete TENANT template library — one ready-to-submit
     * template per message-sending point in SmartPRS. Seeded as Draft for
     * every tenant on first open; admins edit wording, then submit in Interakt.
     */
    public static function tenantLibrary(): array
    {
        return [
            ['purpose' => 'offer_letter', 'name' => 'hr_offer_letter', 'vars' => 6,
                'body' => "Hi {{1}}, congratulations! {{2}} is pleased to offer you the role of {{3}} with a CTC of {{4}}, joining on {{5}}.\nReview and accept your offer letter here: {{6}}\nWe look forward to having you on the team!",
                'sample' => 'Ravi Kumar, Apex Collections Pvt Ltd, Recovery Officer, Rs 3.6 LPA, 01 Jul 2026, https://smartprs.com/offer/abc123'],
            ['purpose' => 'appointment_letter', 'name' => 'hr_appointment', 'vars' => 5,
                'body' => "Hi {{1}}, welcome to {{2}}! Your appointment as {{3}} is confirmed with joining date {{4}}. Your employee code is {{5}}.\nPlease report to HR on your first day for onboarding formalities.",
                'sample' => 'Ravi Kumar, Apex Collections Pvt Ltd, Recovery Officer, 01 Jul 2026, EMP105'],
            ['purpose' => 'interview_schedule', 'name' => 'hr_interview', 'vars' => 5,
                'body' => "Hi {{1}}, your interview with {{2}} for the role of {{3}} is scheduled on {{4}} ({{5}}).\nPlease be available 10 minutes early. All the best!",
                'sample' => 'Ravi Kumar, Apex Collections Pvt Ltd, Recovery Officer, 10 Jun 2026 11:00 AM, Video call'],
            ['purpose' => 'walkin_invite', 'name' => 'hr_walkin', 'vars' => 5,
                'body' => "Hi {{1}}, {{2}} is conducting a WALK-IN interview for the role of {{3}}.\nWalk in on {{4}} at: {{5}}\nPlease carry your updated resume and a photo ID. We look forward to meeting you!",
                'sample' => 'Ravi Kumar, Apex Collections Pvt Ltd, Recovery Officer, 10 Jun 2026 (10 AM - 2 PM), Kondapur office, opp Google, Hyderabad'],
            ['purpose' => 'user_invite', 'name' => 'hr_user_invite', 'vars' => 4,
                'body' => "Hi {{1}}, your SmartPRS account for {{2}} is ready.\nSign in at {{3}} with your email {{4}}. Use Forgot Password to set your password if needed.",
                'sample' => 'Ravi Kumar, Apex Collections Pvt Ltd, https://smartprs.com/login, ravi@apex.in'],
            ['purpose' => 'leave_decision', 'name' => 'hr_leave_decision', 'vars' => 6,
                'body' => "Hi {{1}}, your {{2}} leave request from {{3}} to {{4}} has been {{5}} by {{6}}.\nCheck SmartPRS for the details and your leave balance.",
                'sample' => 'Ravi Kumar, Casual, 10 Jun 2026, 12 Jun 2026, APPROVED, Suresh (Manager)'],
            ['purpose' => 'approval_pending', 'name' => 'hr_approval_pending', 'vars' => 4,
                'body' => "Hi {{1}}, {{2}} has submitted a {{3}} request {{4}} and it is awaiting YOUR approval.\nOpen the SmartPRS Approvals Inbox to approve or reject.",
                'sample' => 'Suresh, Ravi Kumar, Expense Claim, of Rs 1500'],
            ['purpose' => 'request_decision', 'name' => 'hr_request_decision', 'vars' => 5,
                'body' => "Hi {{1}}, your {{2}} request has been {{3}} by {{4}}.\nRemarks: {{5}}\nSee the full details in SmartPRS.",
                'sample' => 'Ravi Kumar, Salary Advance, APPROVED, Suresh (Manager), Recovery from next 3 salaries'],
            ['purpose' => 'payslip_ready', 'name' => 'hr_payslip_ready', 'vars' => 3,
                'body' => "Hi {{1}}, your payslip for {{2}} is ready. Net pay: {{3}}.\nView and e-sign it in SmartPRS. The PDF has also been emailed to you.",
                'sample' => 'Ravi Kumar, June 2026, Rs 28000'],
            ['purpose' => 'salary_credited', 'name' => 'hr_salary_credited', 'vars' => 5,
                'body' => "Hi {{1}}, your salary payment of {{2}} for {{3}} has been made via {{4}} (ref {{5}}).\nThe complete ledger is available in SmartPRS.",
                'sample' => 'Ravi Kumar, Rs 28000, June 2026, Bank transfer, UTR12345'],
            ['purpose' => 'commission_approved', 'name' => 'hr_commission_approved', 'vars' => 4,
                'body' => "Hi {{1}}, good news — your commission entry of {{2}} for {{3}} has been APPROVED. Net payable: {{4}}.\nTrack it live in SmartPRS → Live Salary.",
                'sample' => 'Ravi Kumar, Rs 5000, Jun 2026, Rs 4750'],
            ['purpose' => 'increment_letter', 'name' => 'hr_increment', 'vars' => 4,
                'body' => "Hi {{1}}, congratulations! Your revised CTC is {{2}} ({{3}} increase), effective {{4}}.\nYour formal increment letter has been emailed to you. Keep up the great work!",
                'sample' => 'Ravi Kumar, Rs 4.2 LPA, 16%, 01 Jul 2026'],
            ['purpose' => 'transfer_order', 'name' => 'hr_transfer_order', 'vars' => 4,
                'body' => "Hi {{1}}, your transfer to {{2}} is effective {{3}}.\nPlease read your transfer order and acknowledge it here: {{4}}",
                'sample' => 'Ravi Kumar, Sentinel Recoveries - Hyderabad branch, 01 Jul 2026, https://smartprs.com/transfer/accept/abc123'],
            ['purpose' => 'transfer_applied', 'name' => 'hr_transfer_applied', 'vars' => 3,
                'body' => "Hi {{1}}, your transfer to {{2}} is now in effect from {{3}}. Your employee code stays the same.\nYour reporting manager and team will be assigned at the new location.",
                'sample' => 'Ravi Kumar, Sentinel Recoveries, 01 Jul 2026'],
            ['purpose' => 'compliance_alert', 'name' => 'hr_compliance_alert', 'vars' => 3,
                'body' => "Compliance alert for {{1}}: {{2}} certification(s) have EXPIRED and {{3}} expire within 7 days.\nOpen SmartPRS → Compliance Alerts and action the renewals before field work is affected.",
                'sample' => 'Apex Collections Pvt Ltd, 2, 5'],
            ['purpose' => 'agent_earnings_link', 'name' => 'hr_agent_earnings', 'vars' => 3,
                'body' => "Hi {{1}}, you can now see your earnings with {{2}} updated LIVE, entry by entry:\n{{3}}\nKeep this link private — it is your personal page.",
                'sample' => 'Mahesh, Apex Collections Pvt Ltd, https://smartprs.com/agent/earnings/abc123'],
            ['purpose' => 'announcement', 'name' => 'hr_announcement', 'vars' => 3,
                'body' => "Hi {{1}}, a new notice from {{2}}:\n{{3}}\nSee the full notice on the SmartPRS notice board.",
                'sample' => 'Ravi Kumar, Apex Collections HR, Office will remain closed on 17 Jun for Eid'],
        ];
    }

    /** Seed the full HR library for a tenant that has no templates yet. */
    private static function seedTenantDefaults(int $tid): void
    {
        if (DB::table('wa_templates')->where('tenant_id', $tid)->exists()) {
            return;
        }
        $now = now();
        foreach (self::tenantLibrary() as $r) {
            DB::table('wa_templates')->insert([
                'tenant_id' => $tid, 'purpose' => $r['purpose'], 'name' => $r['name'],
                'language' => 'en', 'category' => 'utility', 'body' => $r['body'],
                'sample_values' => $r['sample'], 'var_count' => $r['vars'],
                'status' => 'draft', 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    /** Manager guard + the tenant scope for this user (NULL = platform). */
    private function scope(Request $request): array
    {
        $u = $request->user();
        $isSuper = (bool) $u?->hasRole('super_admin');
        $isAdmin = (bool) ($u?->hasRole('admin') || $u?->hasRole('hr') || $u?->hasRole('hr_manager'));
        abort_unless($isSuper || $isAdmin, 403, 'Admin only.');

        return ['super' => $isSuper, 'tid' => $isSuper ? null : $u->tenant_id];
    }

    public function index(Request $request)
    {
        try {
            $s = $this->scope($request);
            self::ensure();
            if ($s['super']) {
                self::seedPlatformDefaults();
                // rev 98: the OTP template arrived after the first seeding —
                // ensure it exists even on platforms that already have rows.
                if (! DB::table('wa_templates')->whereNull('tenant_id')->where('purpose', 'otp')->exists()) {
                    DB::table('wa_templates')->insert([
                        'tenant_id' => null, 'purpose' => 'otp', 'name' => 'smartprs_otp',
                        'language' => 'en', 'category' => 'authentication',
                        'body' => "{{1}} is your SmartPRS verification code. For your security, do not share this code.",
                        'sample_values' => '482913', 'var_count' => 1,
                        'status' => 'draft', 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            } elseif ($s['tid']) {
                self::seedTenantDefaults((int) $s['tid']);   // rev 93: full HR library, ready to submit
            }
            $rows = DB::table('wa_templates')
                ->when($s['super'], fn ($q) => $q->whereNull('tenant_id'), fn ($q) => $q->where('tenant_id', $s['tid']))
                ->orderByRaw("CASE purpose WHEN 'welcome' THEN 0 WHEN 'payment' THEN 1 WHEN 'renewal' THEN 2 WHEN 'lead' THEN 3 ELSE 4 END")
                ->orderBy('name')->get()->map(fn ($r) => [
                    'id' => (int) $r->id, 'purpose' => $r->purpose, 'name' => $r->name,
                    'language' => $r->language, 'category' => $r->category,
                    'body' => $r->body, 'sampleValues' => $r->sample_values,
                    'varCount' => (int) $r->var_count, 'status' => $r->status,
                    'lastTestAt' => $r->last_test_at ? \Illuminate\Support\Carbon::parse($r->last_test_at)->format('d M Y H:i') : null,
                    'lastError' => $r->last_error,
                    'updated' => $r->updated_at ? \Illuminate\Support\Carbon::parse($r->updated_at)->format('d M Y') : '',
                ])->values();

            return response()->json([
                'ok' => true, 'rows' => $rows, 'isPlatform' => $s['super'],
                'purposes' => self::purposesFor($s['super']),   // rev 93: role-correct purpose list
                'configured' => (bool) WaService::config(),
                'dashUrl' => 'https://app.interakt.ai/templates/list',
            ]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    public function save(Request $request)
    {
        try {
            $s = $this->scope($request);
            self::ensure();
            $v = $request->validate([
                'id' => ['nullable', 'integer'],
                'purpose' => ['required', 'in:'.implode(',', array_keys(self::purposesFor($s['super'])))],
                'name' => ['required', 'string', 'max:120', 'regex:/^[a-z0-9_]+$/'],
                'language' => ['nullable', 'string', 'max:10'],
                'category' => ['nullable', 'in:utility,marketing,authentication'],
                'body' => ['required', 'string', 'max:2000'],
                'sample_values' => ['nullable', 'string', 'max:1000'],
            ], ['name.regex' => 'Template name must be lowercase letters, numbers and underscores only (WhatsApp rule), e.g. smartprs_welcome.']);

            // Variable count = highest {{n}} in the body; also catch gaps.
            preg_match_all('/\{\{(\d+)\}\}/', $v['body'], $m);
            $nums = array_map('intval', $m[1] ?? []);
            $varCount = $nums ? max($nums) : 0;
            if ($nums && count(array_unique($nums)) !== $varCount) {
                return response()->json(['ok' => false, 'error' => 'Variables must be numbered {{1}}, {{2}}, … without gaps. Found: '.implode(',', array_unique($nums))], 422);
            }

            $row = [
                'purpose' => $v['purpose'], 'name' => strtolower(trim($v['name'])),
                'language' => $v['language'] ?: 'en', 'category' => $v['category'] ?: 'utility',
                'body' => $v['body'], 'sample_values' => $v['sample_values'] ?? null,
                'var_count' => $varCount, 'updated_at' => now(),
            ];

            if (! empty($v['id'])) {
                $q = DB::table('wa_templates')->where('id', $v['id'])
                    ->when($s['super'], fn ($q2) => $q2->whereNull('tenant_id'), fn ($q2) => $q2->where('tenant_id', $s['tid']));
                $existing = $q->first();
                if (! $existing) {
                    return response()->json(['ok' => false, 'error' => 'Template not found.'], 404);
                }
                // Any content change invalidates a previous approval.
                if ($existing->status !== 'draft' && ($existing->body !== $row['body'] || $existing->name !== $row['name'] || $existing->language !== $row['language'] || $existing->category !== $row['category'])) {
                    $row['status'] = 'draft';
                    $row['last_error'] = null;
                }
                DB::table('wa_templates')->where('id', $v['id'])->update($row);

                return response()->json(['ok' => true, 'message' => 'Template saved'.(isset($row['status']) ? ' — content changed, so the status is back to Draft (re-submit in Interakt).' : '.')]);
            }

            $row += ['tenant_id' => $s['tid'], 'status' => 'draft', 'created_at' => now()];
            DB::table('wa_templates')->insert($row);

            return response()->json(['ok' => true, 'message' => 'Template created — now copy it into the Interakt dashboard and submit for approval.']);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['ok' => false, 'error' => implode(' ', collect($e->errors())->flatten()->all())], 422);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    public function destroy(Request $request, int $id)
    {
        try {
            $s = $this->scope($request);
            self::ensure();
            DB::table('wa_templates')->where('id', $id)
                ->when($s['super'], fn ($q) => $q->whereNull('tenant_id'), fn ($q) => $q->where('tenant_id', $s['tid']))
                ->delete();

            return response()->json(['ok' => true, 'message' => 'Template deleted']);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /** Manual status move (draft → submitted after pasting into Interakt, etc.). */
    public function setStatus(Request $request, int $id)
    {
        try {
            $s = $this->scope($request);
            self::ensure();
            $v = $request->validate(['status' => ['required', 'in:draft,submitted,approved,rejected']]);
            $n = DB::table('wa_templates')->where('id', $id)
                ->when($s['super'], fn ($q) => $q->whereNull('tenant_id'), fn ($q) => $q->where('tenant_id', $s['tid']))
                ->update(['status' => $v['status'], 'updated_at' => now()]);

            return response()->json(['ok' => (bool) $n, 'message' => $n ? 'Status updated' : 'Not found']);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /**
     * rev 93: CSV export of the template library (for the team working the
     * Interakt dashboard; Interakt has no bulk import, so this is the master
     * checklist + copy source).
     */
    public function export(Request $request)
    {
        $s = $this->scope($request);
        self::ensure();
        $purp = self::purposesFor($s['super']);
        $rows = DB::table('wa_templates')
            ->when($s['super'], fn ($q) => $q->whereNull('tenant_id'), fn ($q) => $q->where('tenant_id', $s['tid']))
            ->orderBy('id')->get();

        return response()->streamDownload(function () use ($rows, $purp) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");   // BOM so Excel opens UTF-8 correctly
            fputcsv($out, ['Template Name', 'Language', 'Category', 'Purpose / When sent', 'Variables', 'Message Body', 'Sample Values (in order)', 'Status in SmartPRS']);
            foreach ($rows as $r) {
                fputcsv($out, [
                    $r->name, $r->language, $r->category,
                    $purp[$r->purpose] ?? $r->purpose,
                    $r->var_count, $r->body, $r->sample_values, $r->status,
                ]);
            }
            fclose($out);
        }, 'smartprs-whatsapp-templates.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Send a TEST message with the sample values. A successful send proves the
     * template is APPROVED in Interakt (their API rejects unapproved/unknown
     * templates), so success auto-marks the row approved.
     */
    public function testSend(Request $request, int $id)
    {
        try {
            $s = $this->scope($request);
            self::ensure();
            $v = $request->validate(['mobile' => ['required', 'string', 'max:20']]);
            $row = DB::table('wa_templates')->where('id', $id)
                ->when($s['super'], fn ($q) => $q->whereNull('tenant_id'), fn ($q) => $q->where('tenant_id', $s['tid']))
                ->first();
            if (! $row) {
                return response()->json(['ok' => false, 'error' => 'Template not found.'], 404);
            }
            if (! WaService::config()) {
                return response()->json(['ok' => false, 'error' => 'Interakt is not configured yet — add the API key in WhatsApp API (Interakt) first.'], 422);
            }
            $samples = array_map('trim', explode(',', (string) ($row->sample_values ?: '')));
            $samples = array_pad(array_slice($samples, 0, max(0, (int) $row->var_count)), (int) $row->var_count, '-');

            $ok = WaService::sendTemplate([
                'tenant_id' => $s['tid'],
                'mobile' => $v['mobile'],
                'template' => $row->name,
                'lang' => $row->language ?: 'en',
                'kind' => 'template.test',
                'bodyValues' => $samples,
            ]);

            // Pull the error this attempt logged (WaService writes wa_log).
            $err = null;
            if (! $ok) {
                $err = DB::table('wa_log')->where('template', $row->name)->orderByDesc('id')->value('error');
            }
            DB::table('wa_templates')->where('id', $id)->update([
                'status' => $ok ? 'approved' : $row->status,
                'last_test_at' => $ok ? now() : $row->last_test_at,
                'last_error' => $err,
                'updated_at' => now(),
            ]);

            return response()->json([
                'ok' => $ok,
                'message' => $ok
                    ? 'Test sent! Check the WhatsApp on '.$v['mobile'].'. Template marked APPROVED.'
                    : null,
                'error' => $ok ? null : ('Send failed — '.($err ?: 'unknown error').'. If it mentions the template, it is probably not approved yet (or the name/language here does not match Interakt exactly).'),
            ], $ok ? 200 : 422);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }
}
