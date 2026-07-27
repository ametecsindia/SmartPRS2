<?php

namespace App\Http\Controllers;

use App\Services\MailService;
use App\Services\WaService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * rev 89 — Lead generation (Ejaz, 6 Jun 2026, modelled on smartdcm.app).
 *
 * Public demo-request form on the landing page → `leads` table (self-created),
 * plus FAIL-SOFT alerts: email to contact.lead_email and a WhatsApp template
 * via Interakt (env INTERAKT_TEMPLATE_LEAD) to contact.lead_wa. The numbers
 * and recipients are edited in the Landing CMS (/admin/landing), NOT in code.
 * Super-admin views/works the leads at /admin/leads.
 */
class LeadController extends Controller
{
    /** Self-create the leads table (project convention — no migration). */
    private static function ensureLeads(): void
    {
        if (Schema::hasTable('leads')) {
            return;
        }
        Schema::create('leads', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('company')->nullable();
            $t->string('designation')->nullable();
            $t->string('city')->nullable();
            $t->string('mobile')->nullable();
            $t->string('email')->nullable();
            $t->string('employees')->nullable();      // size band, e.g. "26 - 75"
            $t->text('challenges')->nullable();
            $t->string('status')->default('new');     // new|contacted|closed
            $t->text('notes')->nullable();
            $t->string('source')->default('landing');
            $t->timestamps();
        });
    }

    /** PUBLIC: POST /lead — store an enquiry and fire the alerts. */
    public function store(Request $request)
    {
        // Honeypot: real visitors never see/fill this field. Pretend success.
        if (trim((string) $request->input('website', '')) !== '') {
            return response()->json(['ok' => true, 'message' => 'Thank you! Our team will contact you shortly.']);
        }

        // rev 118: return validation problems as JSON (the form reads res.j.errors)
        // instead of letting Laravel throw — so the visitor always sees a clear
        // per-field message, never a blank generic error.
        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'name' => 'required|string|max:120',
            'company' => 'required|string|max:160',
            'designation' => 'nullable|string|max:120',
            'city' => 'required|string|max:120',
            'mobile' => 'required|string|max:20',
            'email' => 'required|email|max:160',
            'employees' => 'nullable|string|max:40',
            'challenges' => 'nullable|string|max:2000',
        ]);
        if ($validator->fails()) {
            return response()->json(['ok' => false, 'errors' => $validator->errors()->toArray()], 422);
        }
        $v = $validator->validated();

        // rev 118: the LEAD must always be saved, even if the e-mail / WhatsApp
        // alerts hit a snag — a saved enquiry the team can work beats a lost one.
        try {
            self::recordLead($v, 'landing');
        } catch (\Throwable $e) {
            Log::warning('Lead store failed: '.$e->getMessage());
            // Last-resort: store the bare row so the enquiry is never lost.
            try {
                self::ensureLeads();
                DB::table('leads')->insert([
                    'name' => $v['name'] ?? '', 'company' => $v['company'] ?? null,
                    'designation' => $v['designation'] ?? null, 'city' => $v['city'] ?? null,
                    'mobile' => $v['mobile'] ?? null, 'email' => $v['email'] ?? null,
                    'employees' => $v['employees'] ?? null, 'challenges' => $v['challenges'] ?? null,
                    'status' => 'new', 'source' => 'landing',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            } catch (\Throwable $e2) {
                Log::error('Lead store FULLY failed: '.$e2->getMessage());

                return response()->json(['ok' => false, 'message' => 'Something went wrong on our side — please reach us on WhatsApp and we will set up your demo right away.'], 500);
            }
        }

        return response()->json(['ok' => true, 'message' => 'Thank you! Our team will contact you shortly.']);
    }

    /**
     * rev 97: shared lead recorder — used by the landing form AND the public
     * Live Demo entry (and any future source). Inserts the lead + fires the
     * email/WhatsApp alerts fail-soft. Returns the lead id.
     */
    public static function recordLead(array $v, string $source = 'landing'): int
    {
        self::ensureLeads();
        $id = DB::table('leads')->insertGetId([
            'name' => $v['name'] ?? '',
            'company' => $v['company'] ?? null,
            'designation' => $v['designation'] ?? null,
            'city' => $v['city'] ?? null,
            'mobile' => $v['mobile'] ?? null,
            'email' => $v['email'] ?? null,
            'employees' => $v['employees'] ?? null,
            'challenges' => $v['challenges'] ?? null,
            'status' => 'new',
            'source' => $source,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $contact = self::landingContact();
        $what = $source === 'live_demo' ? 'is trying the LIVE DEMO right now' : 'has requested a demo';

        // Alert 1 — email to the configurable recipient (platform SMTP). Fail-soft.
        try {
            $to = trim((string) ($contact['lead_email'] ?? '')) ?: trim((string) ($contact['email'] ?? ''));
            if ($to !== '') {
                MailService::queue([
                    'tenant_id' => null,
                    'kind' => 'lead',
                    'to' => $to,
                    'subject' => ($source === 'live_demo' ? 'LIVE DEMO visitor — ' : 'New SmartPRS demo request — ').($v['company'] ?? $v['name'] ?? ''),
                    'heading' => 'New lead from the SmartPRS website',
                    'intro' => ($v['name'] ?? 'Someone').(! empty($v['designation']) ? ' ('.$v['designation'].')' : '').(! empty($v['company']) ? ' of '.$v['company'] : '').' '.$what.'.',
                    'lines' => array_filter([
                        ! empty($v['company']) ? 'Company: '.$v['company'] : null,
                        ! empty($v['city']) ? 'City: '.$v['city'] : null,
                        ! empty($v['mobile']) ? 'Mobile: '.$v['mobile'] : null,
                        ! empty($v['email']) ? 'Email: '.$v['email'] : null,
                        ! empty($v['employees']) ? 'Employees: '.$v['employees'] : null,
                        ! empty($v['challenges']) ? 'Challenges: '.$v['challenges'] : null,
                        'Lead #'.$id.' ('.$source.') — work it at /admin/leads',
                    ]),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('Lead email alert failed: '.$e->getMessage());
        }

        // Alert 2 — WhatsApp via Interakt template (needs approval; fail-soft).
        try {
            $wa = preg_replace('/\D+/', '', (string) ($contact['lead_wa'] ?? $contact['whatsapp'] ?? ''));
            if ($wa !== '') {
                WaService::sendTemplate([
                    'mobile' => $wa,
                    'template' => WaService::templateNameFor('lead'),
                    'kind' => 'lead',
                    'bodyValues' => [
                        $v['name'] ?? '-',
                        ($v['company'] ?? '-').($source === 'live_demo' ? ' (LIVE DEMO)' : ''),
                        $v['mobile'] ?? '-',
                        $v['city'] ?? '-',
                        ! empty($v['employees']) ? $v['employees'] : '-',
                    ],
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('Lead WhatsApp alert failed: '.$e->getMessage());
        }

        return $id;
    }

    /** SUPER ADMIN: GET /admin/leads — the lead work-list. */
    public function index(Request $request)
    {
        $this->guard($request);
        self::ensureLeads();
        $status = trim((string) $request->query('status', ''));
        $rows = DB::table('leads')
            ->when($status !== '' && $status !== 'all', fn ($q) => $q->where('status', $status))
            ->orderByDesc('id')->limit(500)->get();
        $counts = DB::table('leads')->selectRaw("status, count(*) n")->groupBy('status')->pluck('n', 'status');

        return view('admin.leads', ['rows' => $rows, 'counts' => $counts, 'status' => $status ?: 'all']);
    }

    /** SUPER ADMIN: POST /admin/leads/{id} — status + notes update. */
    public function update(Request $request, int $id)
    {
        $this->guard($request);
        self::ensureLeads();
        $v = $request->validate([
            'status' => 'required|in:new,contacted,closed',
            'notes' => 'nullable|string|max:2000',
        ]);
        DB::table('leads')->where('id', $id)->update([
            'status' => $v['status'],
            'notes' => $v['notes'] ?? DB::raw('notes'),
            'updated_at' => now(),
        ]);

        return redirect()->route('admin.leads', ['status' => $request->query('back', 'all')])->with('success', 'Lead #'.$id.' updated.');
    }

    private function guard(Request $request): void
    {
        abort_unless($request->user()?->hasRole('super_admin'), 403, 'Super Admin only.');
    }

    /** The landing 'contact' block (CMS-saved values win over defaults). */
    private static function landingContact(): array
    {
        try {
            $c = (new LandingController)->content();

            return is_array($c['contact'] ?? null) ? $c['contact'] : [];
        } catch (\Throwable $e) {
            return [];
        }
    }
}
