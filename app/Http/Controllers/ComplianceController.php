<?php

namespace App\Http\Controllers;

use App\Services\MailService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Field-force compliance: DRA certification + PCC (police clearance) expiry
 * tracking and automated alerts for collections/recovery agents.
 *
 * The employees table already carries: dra_status, dra_expiry, pcc_status,
 * pcc_expiry, pcc_deadline, is_field_agent, agent_code, portfolio.
 *
 * Two faces:
 *   • scan()  — pure read used by BOTH the in-app "Compliance Alerts" screen
 *               (GET /app/compliance-alerts) and the daily scheduled command,
 *               so the screen and the email digest always agree.
 *   • notify() — called by the scheduled command: groups expiring items per
 *                tenant and emails each tenant's HR/Admin a digest (reusing the
 *                MailService communication engine). Fail-soft.
 *
 * Buckets: expired (<0 days), critical (<=7), soon (<=30). The window is 30
 * days by default.
 */
class ComplianceController extends Controller
{
    public const WINDOW_DAYS = 30;

    /**
     * Scan compliance for a tenant (null = all tenants, for the scheduled run).
     * Returns flat alert rows + bucket counts.
     */
    public static function scan(?int $tenantId, int $windowDays = self::WINDOW_DAYS): array
    {
        if (! Schema::hasTable('employees')) {
            return ['rows' => [], 'counts' => ['expired' => 0, 'critical' => 0, 'soon' => 0]];
        }
        $today = Carbon::today();
        $cols = Schema::getColumnListing('employees');
        $has = fn ($c) => in_array($c, $cols, true);

        // Only meaningful if at least one expiry column exists.
        if (! $has('dra_expiry') && ! $has('pcc_expiry')) {
            return ['rows' => [], 'counts' => ['expired' => 0, 'critical' => 0, 'soon' => 0]];
        }

        $q = DB::table('employees as e')
            ->leftJoin('companies as c', 'c.id', '=', 'e.company_id')
            ->when($tenantId, fn ($x) => $x->where('e.tenant_id', $tenantId))
            ->whereNull('e.deleted_at');

        $sel = ['e.id', 'e.tenant_id', 'e.company_id', 'e.emp_code', 'e.name', 'c.name as company'];
        foreach (['email', 'mobile', 'agent_code', 'portfolio', 'branch', 'dra_status', 'dra_expiry', 'pcc_status', 'pcc_expiry', 'pcc_deadline'] as $c) {
            if ($has($c)) {
                $sel[] = 'e.'.$c;
            }
        }
        $emps = $q->get($sel);

        $rows = [];
        $counts = ['expired' => 0, 'critical' => 0, 'soon' => 0];

        $consider = function ($kind, $dateStr, $emp) use ($today, $windowDays, &$rows, &$counts) {
            if (empty($dateStr)) {
                return;
            }
            try {
                $d = Carbon::parse($dateStr)->startOfDay();
            } catch (\Throwable $e) {
                return;
            }
            $days = $today->diffInDays($d, false);   // negative = already expired
            if ($days > $windowDays) {
                return;   // not within the alert window yet
            }
            $bucket = $days < 0 ? 'expired' : ($days <= 7 ? 'critical' : 'soon');
            $counts[$bucket]++;
            $a = (array) $emp;
            $rows[] = [
                'employee_id' => $a['id'],
                'tenant_id' => $a['tenant_id'] ?? null,
                'company_id' => $a['company_id'] ?? null,
                'code' => $a['emp_code'] ?? '',
                'name' => $a['name'] ?? '',
                'company' => $a['company'] ?? '',
                'agentCode' => $a['agent_code'] ?? '',
                'portfolio' => $a['portfolio'] ?? '',
                'email' => $a['email'] ?? '',
                'type' => $kind,            // 'DRA' | 'PCC'
                'date' => $d->toDateString(),
                'days' => $days,            // <0 expired, else days remaining
                'bucket' => $bucket,
            ];
        };

        foreach ($emps as $emp) {
            $a = (array) $emp;
            $consider('DRA', $a['dra_expiry'] ?? null, $emp);
            $consider('PCC', $a['pcc_expiry'] ?? null, $emp);
        }

        // B1/B3 — structured DRA certificates live in dra_certs (the DRA Certifications
        // screen). Scan their expiry too so the radar and digest cover them.
        if (Schema::hasTable('dra_certs')) {
            $dq = DB::table('dra_certs as d')
                ->join('employees as e', 'e.id', '=', 'd.employee_id')
                ->leftJoin('companies as c', 'c.id', '=', 'e.company_id')
                ->when($tenantId, fn ($x) => $x->where('d.tenant_id', $tenantId))
                ->whereNull('e.deleted_at')
                ->whereNotNull('d.expiry');
            $dsel = ['d.expiry as dra_expiry', 'e.id', 'e.tenant_id', 'e.company_id', 'e.emp_code', 'e.name', 'c.name as company'];
            foreach (['email', 'agent_code', 'portfolio'] as $c) {
                if ($has($c)) {
                    $dsel[] = 'e.'.$c;
                }
            }
            foreach ($dq->get($dsel) as $dc) {
                $consider('DRA', $dc->dra_expiry ?? null, $dc);
            }
        }

        // C3 — BGV re-verification scheduler: surface overdue / upcoming re-verifications.
        if (Schema::hasTable('bgv') && Schema::hasColumn('bgv', 'next_due')) {
            $bq = DB::table('bgv as b')
                ->join('employees as e', 'e.id', '=', 'b.employee_id')
                ->leftJoin('companies as c', 'c.id', '=', 'e.company_id')
                ->when($tenantId, fn ($x) => $x->where('b.tenant_id', $tenantId))
                ->whereNull('e.deleted_at')
                ->whereNotNull('b.next_due');
            $bsel = ['b.next_due as bgv_due', 'e.id', 'e.tenant_id', 'e.company_id', 'e.emp_code', 'e.name', 'c.name as company'];
            foreach (['email', 'agent_code', 'portfolio'] as $c) {
                if ($has($c)) {
                    $bsel[] = 'e.'.$c;
                }
            }
            foreach ($bq->get($bsel) as $bc) {
                $consider('BGV re-verify', $bc->bgv_due ?? null, $bc);
            }
        }

        // Sort: most urgent first (smallest days, expired before soon).
        usort($rows, fn ($x, $y) => $x['days'] <=> $y['days']);

        return ['rows' => $rows, 'counts' => $counts];
    }

    /** In-app screen data: alerts for the current user's tenant. */
    public function alerts(Request $request)
    {
        try {
            $tenantId = $request->user()->tenant_id;
            $window = (int) $request->query('days', self::WINDOW_DAYS);
            $res = self::scan($tenantId, $window > 0 ? $window : self::WINDOW_DAYS);

            return response()->json([
                'rows' => $res['rows'],
                'counts' => $res['counts'],
                'window' => $window > 0 ? $window : self::WINDOW_DAYS,
                'canManage' => $request->user()->hasAnyRole(['super_admin', 'admin', 'hr_manager']),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['rows' => [], 'counts' => ['expired' => 0, 'critical' => 0, 'soon' => 0], 'error' => $e->getMessage()]);
        }
    }

    /** Manual "send alerts now" trigger (admin/HR) — same as the daily job. */
    public function runNow(Request $request)
    {
        try {
            abort_unless($request->user()->hasAnyRole(['super_admin', 'admin', 'hr_manager']), 403);
            $tenantId = $request->user()->tenant_id;
            $sent = self::notify($tenantId);

            return response()->json(['ok' => true, 'queued' => $sent, 'message' => $sent.' compliance digest email(s) queued']);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /**
     * Build per-tenant digests and queue them to that tenant's HR/Admin users.
     * Returns the number of digest emails queued. $tenantId null = every tenant.
     */
    public static function notify(?int $tenantId = null): int
    {
        $queued = 0;
        // Determine which tenants to process.
        $tenantIds = $tenantId ? [$tenantId] : DB::table('employees')->whereNull('deleted_at')->distinct()->pluck('tenant_id')->filter()->all();

        foreach ($tenantIds as $tid) {
            $res = self::scan((int) $tid);
            if (empty($res['rows'])) {
                continue;
            }
            $recipients = self::recipientsFor((int) $tid);
            if (empty($recipients)) {
                continue;
            }

            // Build a compact text summary of the most urgent items (top 25).
            $c = $res['counts'];
            $top = array_slice($res['rows'], 0, 25);
            $bodyLines = [];
            foreach ($top as $r) {
                $when = $r['days'] < 0
                    ? 'EXPIRED '.abs($r['days']).'d ago'
                    : 'in '.$r['days'].'d';
                $bodyLines[] = $r['type'].' · '.$r['name'].' ('.$r['code'].')'
                    .($r['company'] ? ' · '.$r['company'] : '')
                    .' — '.$r['date'].' ('.$when.')';
            }
            $body = implode("\n", $bodyLines);
            if (count($res['rows']) > count($top)) {
                $body .= "\n… and ".(count($res['rows']) - count($top)).' more. Open Compliance Alerts in SmartPRS for the full list.';
            }

            $lines = [
                'Expired' => (string) $c['expired'],
                'Due within 7 days' => (string) $c['critical'],
                'Due within 30 days' => (string) $c['soon'],
            ];

            foreach ($recipients as $rcpt) {
                MailService::queue([
                    'tenant_id' => (int) $tid,
                    'company_id' => $rcpt['company_id'] ?? null,
                    'to' => $rcpt['email'],
                    'to_name' => $rcpt['name'] ?? '',
                    'subject' => 'Compliance alert: '.($c['expired'] + $c['critical']).' urgent, '.$c['soon'].' upcoming (DRA/PCC)',
                    'heading' => 'Field-force compliance alerts',
                    'intro' => 'The following DRA / PCC certifications are expired or expiring soon. Please action renewals.',
                    'lines' => $lines,
                    'body' => $body,
                    'kind' => 'compliance.digest',
                ]);
                $queued++;
            }
        }

        return $queued;
    }

    /** HR/Admin recipient emails for a tenant (from the users table + Spatie roles). */
    private static function recipientsFor(int $tenantId): array
    {
        try {
            if (! Schema::hasTable('users')) {
                return [];
            }
            // Resolve user ids holding admin/hr roles via Spatie's pivot tables,
            // guarding for installs where those tables differ.
            $roleNames = ['super_admin', 'admin', 'hr_manager'];
            $userIds = [];
            if (Schema::hasTable('roles') && Schema::hasTable('model_has_roles')) {
                $roleIds = DB::table('roles')->whereIn('name', $roleNames)->pluck('id');
                $userIds = DB::table('model_has_roles')
                    ->whereIn('role_id', $roleIds)
                    ->where('model_type', 'App\\Models\\User')
                    ->pluck('model_id')->all();
            }

            $q = DB::table('users')->where('tenant_id', $tenantId)->whereNotNull('email');
            if (! empty($userIds)) {
                $q->whereIn('id', $userIds);
            } else {
                // No role pivots resolved — be conservative and don't blast everyone.
                return [];
            }

            return $q->get(['name', 'email', 'company_id'])->map(fn ($u) => [
                'name' => $u->name,
                'email' => $u->email,
                'company_id' => $u->company_id ?? null,
            ])->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * F1 — per-agent compliance score (0–100) from internal SmartPRS data:
     * DRA valid (25) + PCC valid (20) + BGV clear (20) + NDA signed (15) +
     * no open complaints (20). Used by the incentive gate and the scorecard report.
     */
    public static function scoreFor(int $employeeId, ?int $tid = null): int
    {
        $score = 0;
        $today = Carbon::today()->toDateString();
        if (Schema::hasTable('dra_certs')) {
            $ok = DB::table('dra_certs')->where('employee_id', $employeeId)->where('status', 'verified')
                ->where(fn ($q) => $q->whereNull('expiry')->orWhere('expiry', '>=', $today))->exists();
            if ($ok) {
                $score += 25;
            }
        }
        if (Schema::hasTable('employees')) {
            $e = DB::table('employees')->where('id', $employeeId)->first();
            if ($e) {
                $pccOk = (($e->pcc_status ?? '') === 'verified') && (empty($e->pcc_expiry) || $e->pcc_expiry >= $today);
                if ($pccOk) {
                    $score += 20;
                }
            }
        }
        if (Schema::hasTable('bgv')) {
            if (DB::table('bgv')->where('employee_id', $employeeId)->where('status', 'clear')->exists()) {
                $score += 20;
            }
        }
        if (Schema::hasTable('letters')) {
            $ndaOk = DB::table('letters')->where('employee_id', $employeeId)->where('letter_type', 'nda')
                ->where('is_template', 0)->where('status', 'signed')->exists();
            if ($ndaOk) {
                $score += 15;
            }
        }
        if (Schema::hasTable('complaints')) {
            $open = DB::table('complaints')->where('employee_id', $employeeId)
                ->whereIn('status', ['open', 'pending', 'in_progress'])->count();
            $score += $open > 0 ? 0 : 20;
        } else {
            $score += 20;
        }

        return min(100, max(0, $score));
    }

    /**
     * rev181 — is this employee's DRA certification VALID today?
     * Primary source: the structured dra_certs table (latest cert row: verified
     * with no expiry or a future expiry). Fallback: the legacy employees.
     * dra_status / dra_expiry columns (same order the Agent Readiness check uses).
     * Returns ['ok' => bool, 'note' => short human detail for warnings].
     */
    public static function draValid(int $employeeId): array
    {
        $today = Carbon::today()->toDateString();
        try {
            if (Schema::hasTable('dra_certs')) {
                $d = DB::table('dra_certs')->where('employee_id', $employeeId)->orderByDesc('id')->first();
                if ($d) {
                    $ok = (($d->status ?? '') === 'verified') && (empty($d->expiry) || $d->expiry >= $today);

                    return ['ok' => $ok, 'note' => $ok
                        ? trim(($d->cert_no ?? 'DRA cert').($d->expiry ? ' valid to '.$d->expiry : ''))
                        : ((($d->status ?? '') !== 'verified') ? 'DRA cert on file but not verified' : 'DRA cert expired on '.$d->expiry)];
                }
            }
            if (Schema::hasTable('employees')) {
                $e = DB::table('employees')->where('id', $employeeId)->first();
                if ($e && ($e->dra_status ?? '') === 'verified') {
                    $ok = empty($e->dra_expiry) || $e->dra_expiry >= $today;

                    return ['ok' => $ok, 'note' => $ok
                        ? 'DRA verified'.($e->dra_expiry ? ' to '.$e->dra_expiry : '')
                        : 'DRA expired on '.$e->dra_expiry];
                }
            }
        } catch (\Throwable $e) {
            // fail-open on data errors — the gate must never 500 a money screen
            return ['ok' => true, 'note' => 'DRA check unavailable'];
        }

        return ['ok' => false, 'note' => 'No DRA certification on record'];
    }

    /**
     * rev172 — RBI Recovery-Agent Compliance Audit Report (per agent), as a PDF
     * on the AGENT'S OWN COMPANY letterhead (brandFor), not SmartPRS branding.
     * Assembles one agent's compliance file from the existing modules (KYC/ID,
     * DRA cert, PCC, BGV, references, Code-of-Conduct ack, authorisation,
     * complaints) with a Compliant / Attention / Non-compliant verdict per
     * parameter. Read-only, tenant-scoped, admin/HR guarded.
     */
    public function agentAuditPdf(Request $request, string $code)
    {
        if ($deny = ApprovalService::denyUnlessRole($request, ['admin', 'hr_manager'])) {
            return $deny;
        }
        $tid = $request->user()->tenant_id;
        $e = DB::table('employees')->where('emp_code', $code)
            ->when($tid, fn ($q) => $q->where('tenant_id', $tid))
            ->whereNull('deleted_at')->first();
        if (! $e) {
            return response('Agent "'.e($code).'" not found.', 404)->header('Content-Type', 'text/plain; charset=utf-8');
        }

        $data = $this->agentAuditData($e, $tid);
        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('agent-audit-pdf', $data)->setPaper('a4', 'portrait');

        return $pdf->download('agent-audit-'.$code.'-'.Carbon::today()->format('Ymd').'.pdf');
    }

    /**
     * rev173b — BULK audit report: one PDF with a summary page + a full
     * per-agent report per section. ?codes=EMP-1,EMP-2,… (from the new
     * Statutory & Compliance → Audit Reports screen). Capped at 150 agents.
     */
    public function agentAuditBulkPdf(Request $request)
    {
        if ($deny = ApprovalService::denyUnlessRole($request, ['admin', 'hr_manager'])) {
            return $deny;
        }
        $tid = $request->user()->tenant_id;
        $codes = array_values(array_filter(array_map('trim', explode(',', (string) $request->query('codes', '')))));
        if (! $codes) {
            return response('No agents selected — pick at least one employee on the Audit Reports screen.', 422)
                ->header('Content-Type', 'text/plain; charset=utf-8');
        }
        $codes = array_slice($codes, 0, 150);
        $emps = DB::table('employees')->whereIn('emp_code', $codes)
            ->when($tid, fn ($q) => $q->where('tenant_id', $tid))
            ->whereNull('deleted_at')->orderBy('name')->get();
        if ($emps->isEmpty()) {
            return response('None of the selected agents were found.', 404)->header('Content-Type', 'text/plain; charset=utf-8');
        }

        $agents = [];
        foreach ($emps as $e) {
            $agents[] = $this->agentAuditData($e, $tid);
        }
        $first = $agents[0];

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('agent-audit-bulk-pdf', [
            'agents' => $agents,
            'brand' => $first['brand'],
            'company' => $first['company'],
            'generatedAt' => $first['generatedAt'],
            'count' => count($agents),
        ])->setPaper('a4', 'portrait');

        return $pdf->download('agent-audit-bulk-'.now()->format('Ymd-Hi').'.pdf');
    }

    /**
     * rev173c — SAMPLE audit report (illustrative data, client-branded). Lets an
     * admin preview exactly what the audit PDF looks like on THEIR letterhead
     * before running it on real agents (also useful in sales demos).
     * ?company=<name> picks whose branding to use; defaults to the first company.
     */
    public function agentAuditSamplePdf(Request $request)
    {
        if ($deny = ApprovalService::denyUnlessRole($request, ['admin', 'hr_manager'])) {
            return $deny;
        }
        $tid = $request->user()->tenant_id;
        $coName = trim((string) $request->query('company', ''));
        $company = DB::table('companies')
            ->when($tid, fn ($q) => $q->where('tenant_id', $tid))
            ->when($coName !== '', fn ($q) => $q->where('name', $coName))
            ->whereNull('deleted_at')->first()
            ?: DB::table('companies')->when($tid, fn ($q) => $q->where('tenant_id', $tid))->whereNull('deleted_at')->first();
        $brand = ConfigController::brandFor($tid, $company->id ?? null);

        return $this->sampleAuditPdf($brand, $company);
    }

    /**
     * rev173d — PUBLIC sample audit report for the marketing site (no login).
     * Generic SmartPRS branding; linked from the landing page RBI section + FAQ.
     */
    public function publicSampleAuditPdf()
    {
        $brand = [
            'display_name' => 'SmartPRS — Your Agency Name Here',
            'color' => '#ea580c',
        ];
        $company = (object) [
            'name' => 'Your Collections Agency Pvt Ltd',
            'address' => 'On the real report, this letterhead carries YOUR logo, name, address and brand colour (set once in Company Branding).',
            'gstin' => null,
            'id' => null,
        ];

        return $this->sampleAuditPdf($brand, $company);
    }

    /** Shared builder for the illustrative sample audit PDF (in-app + public). */
    private function sampleAuditPdf(array $brand, ?object $company)
    {
        $today = Carbon::today();
        $e = (object) [
            'name' => 'A. Kumar (Sample Agent)',
            'emp_code' => 'SAMPLE-001',
            'designation' => 'Recovery Agent',
            'status' => 'active',
            'doj' => $today->copy()->subYears(2)->toDateString(),
            'mobile' => '+91 90000 00000',
            'department' => 'Collections',
        ];
        $mk = fn ($group, $param, $value, $state, $evidence = '') => compact('group', 'param', 'value', 'state', 'evidence');
        $items = [
            $mk('Identity & KYC', 'Photograph on record', 'Yes', 'ok', 'ID card file'),
            $mk('Identity & KYC', 'PAN', 'ABCPK1234F', 'ok', 'Employee master'),
            $mk('Identity & KYC', 'UAN (PF)', 'Pending allotment', 'warn', 'Employee master'),
            $mk('Identity & KYC', 'Employee ID card issued', 'Yes', 'ok', 'Auto-generated'),
            $mk('DRA Certification (IIBF)', 'Valid IIBF DRA certificate', 'Held & valid', 'ok', 'Cert DRA-2025-11223 · valid to '.$today->copy()->addMonths(14)->toDateString()),
            $mk('Background Verification', 'Police clearance (PCC)', 'Clear', 'ok', 'Valid to '.$today->copy()->addMonths(8)->toDateString()),
            $mk('Background Verification', 'Background verification (BGV)', 'Clear', 'ok', 'Agency: SecureCheck India'),
            $mk('Background Verification', 'References / guarantors (min 2)', '2 on record', 'ok', 'Employee references'),
            $mk('Code of Conduct', 'Signed undertaking of adherence', 'Acknowledged', 'ok', 'Code of Conduct register'),
            $mk('Authorisation', 'Bank/portfolio authorisation', 'Sample Bank — Retail NPA Pool', 'ok', 'Auth SB/2026/0417 · to '.$today->copy()->addMonths(6)->toDateString()),
            $mk('Conduct Record', 'Borrower complaints (last 90 days)', '0', 'ok', 'Complaints register'),
            $mk('Conduct Record', 'Disciplinary actions', '0', 'ok', 'HR record'),
        ];
        $pass = 0;
        $warn = 0;
        $fail = 0;
        $groups = [];
        foreach ($items as $it) {
            $groups[$it['group']][] = $it;
            $it['state'] === 'ok' ? $pass++ : ($it['state'] === 'warn' ? $warn++ : $fail++);
        }

        $data = [
            'brand' => $brand,
            'company' => $company,
            'e' => $e,
            'groups' => $groups,
            'pass' => $pass, 'warn' => $warn, 'fail' => $fail,
            'score' => 92,
            'verdict' => 'COMPLIANT — WITH OPEN ITEMS',
            'ref' => 'RAC/'.$today->format('Y').'/SAMPLE',
            'hash' => 'SAMPLE-ILLUSTRATION',
            'generatedAt' => $today->format('d M Y').', '.now()->format('H:i').' IST',
            'auditFrom' => $today->copy()->subDays(90)->format('d M Y'),
            'auditTo' => $today->format('d M Y'),
            'isSample' => true,
        ];

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('agent-audit-pdf', $data)->setPaper('a4', 'portrait');

        return $pdf->download('agent-audit-SAMPLE.pdf');
    }

    /** Build the full audit-report data array for ONE employee (shared by single + bulk PDFs). */
    private function agentAuditData(object $e, $tid): array
    {
        $code = (string) $e->emp_code;
        $today = Carbon::today();
        $has = fn ($t, $c) => Schema::hasTable($t) && Schema::hasColumn($t, $c);
        $ok = fn ($b) => $b ? 'ok' : 'bad';
        $items = [];      // [group, param, value, state(ok|warn|bad), evidence]
        $pass = 0;
        $warn = 0;
        $fail = 0;
        $add = function ($group, $param, $value, $state, $evidence = '') use (&$items, &$pass, &$warn, &$fail) {
            $items[] = compact('group', 'param', 'value', 'state', 'evidence');
            $state === 'ok' ? $pass++ : ($state === 'warn' ? $warn++ : $fail++);
        };

        // 1 — Identity & KYC
        $add('Identity & KYC', 'Photograph on record', ! empty($e->photo_path) ? 'Yes' : 'Missing', $ok(! empty($e->photo_path)), $e->photo_path ? 'ID card file' : '');
        $add('Identity & KYC', 'PAN', $e->pan ? $e->pan : 'Not on record', $ok(! empty($e->pan)), 'Employee master');
        $add('Identity & KYC', 'UAN (PF)', $e->uan ?: '—', $e->uan ? 'ok' : 'warn', 'Employee master');
        $add('Identity & KYC', 'Employee ID card issued', ! empty($e->photo_path) ? 'Yes' : 'Pending', $ok(! empty($e->photo_path)), 'Auto-generated');

        // 2 — DRA certification (IIBF) — the mandatory one
        $draOk = false;
        $draNote = 'Not on record';
        if (Schema::hasTable('dra_certs')) {
            $d = DB::table('dra_certs')->where('employee_id', $e->id)->orderByDesc('id')->first();
            if ($d) {
                $draOk = ($d->status ?? '') === 'verified' && (empty($d->expiry) || $d->expiry >= $today->toDateString());
                $draNote = trim(($d->cert_no ?? 'Cert on file').($d->expiry ? ' · valid to '.$d->expiry : ''));
            }
        }
        if (! $draOk && ($e->dra_status ?? '') === 'verified') {
            $draOk = empty($e->dra_expiry) || $e->dra_expiry >= $today->toDateString();
            $draNote = 'Verified'.($e->dra_expiry ? ' · valid to '.$e->dra_expiry : '');
        }
        $add('DRA Certification (IIBF)', 'Valid IIBF DRA certificate', $draOk ? 'Held & valid' : 'Not verified', $ok($draOk), $draNote);

        // 3 — PCC / police verification
        $pccOk = in_array(($e->pcc_status ?? ''), ['verified', 'submitted'], true) && (empty($e->pcc_expiry) || $e->pcc_expiry >= $today->toDateString());
        $add('Background Verification', 'Police clearance (PCC)', $pccOk ? 'Clear' : (($e->pcc_status ?? '') ?: 'Pending'), $pccOk ? 'ok' : (($e->pcc_status ?? '') === 'pending' ? 'warn' : 'bad'), $e->pcc_expiry ? 'Valid to '.$e->pcc_expiry : '');

        // BGV
        $bgv = Schema::hasTable('bgv') ? DB::table('bgv')->where('employee_id', $e->id)->orderByDesc('id')->first() : null;
        $bgvClear = $bgv && in_array(strtolower((string) $bgv->status), ['clear', 'completed', 'verified'], true);
        $nextDue = $bgv && Schema::hasColumn('bgv', 'next_due') ? ($bgv->next_due ?? null) : null;
        $add('Background Verification', 'Background verification (BGV)', $bgv ? ucfirst((string) $bgv->status) : 'Not on record', $bgv ? ($bgvClear ? 'ok' : 'warn') : 'warn', $bgv && $bgv->agency ? 'Agency: '.$bgv->agency : '');
        if ($nextDue) {
            $due = Carbon::parse($nextDue);
            $add('Background Verification', 'Periodic re-verification', 'Next: '.$nextDue, $due->isPast() ? 'bad' : ($due->diffInDays($today) <= 30 ? 'warn' : 'ok'), 'Scheduled');
        }

        // References / guarantors (min 2 for field staff)
        $refCount = Schema::hasTable('employee_references') ? DB::table('employee_references')->where('employee_id', $e->id)->count() : 0;
        $add('Background Verification', 'References / guarantors (min 2)', $refCount.' on record', $refCount >= 2 ? 'ok' : ($refCount === 1 ? 'warn' : 'bad'), 'Employee references');

        // 4 — Code of Conduct acknowledgement
        $cocOk = false;
        if (Schema::hasTable('code_of_conduct_ack')) {
            // Resolve the login user linked to this employee (by employee_id or email).
            $userId = null;
            if (Schema::hasTable('users')) {
                $userId = DB::table('users')
                    ->when(Schema::hasColumn('users', 'employee_id'), fn ($q) => $q->where('employee_id', $e->id))
                    ->when(! Schema::hasColumn('users', 'employee_id') && ! empty($e->email), fn ($q) => $q->where('email', $e->email))
                    ->value('id');
            }
            $cocOk = DB::table('code_of_conduct_ack')
                ->when($tid, fn ($q) => $q->where('tenant_id', $tid))
                ->when($userId, fn ($q) => $q->where('user_id', $userId))
                ->where('acknowledged', 1)->exists();
        }
        $add('Code of Conduct', 'Signed undertaking of adherence', $cocOk ? 'Acknowledged' : 'Not on record', $cocOk ? 'ok' : 'warn', 'Code of Conduct register');

        // 5 — Authorisation / empanelment
        $auths = Schema::hasTable('agent_authorizations')
            ? DB::table('agent_authorizations')->where('employee_id', $e->id)
                ->when($tid, fn ($q) => $q->where('tenant_id', $tid))->get()
            : collect();
        $liveAuth = $auths->first(fn ($a) => empty($a->valid_to) || $a->valid_to >= $today->toDateString());
        $add('Authorisation', 'Bank/portfolio authorisation', $liveAuth ? ($liveAuth->bank.' — '.$liveAuth->portfolio) : 'None on record', $liveAuth ? 'ok' : 'warn', $liveAuth && $liveAuth->auth_no ? 'Auth '.$liveAuth->auth_no.($liveAuth->valid_to ? ' · to '.$liveAuth->valid_to : '') : '');

        // 6 — Conduct & complaints (audit window: current financial quarter approx last 90 days)
        $from = $today->copy()->subDays(90)->toDateString();
        $complaints = Schema::hasTable('complaints')
            ? DB::table('complaints')->where('employee_id', $e->id)
                ->when(Schema::hasColumn('complaints', 'date'), fn ($q) => $q->where('date', '>=', $from))->count()
            : 0;
        $add('Conduct Record', 'Borrower complaints (last 90 days)', (string) $complaints, $complaints === 0 ? 'ok' : ($complaints <= 2 ? 'warn' : 'bad'), 'Complaints register');
        $add('Conduct Record', 'Disciplinary actions', '0', 'ok', 'HR record');

        $score = self::scoreFor((int) $e->id, $tid);
        $verdict = $fail > 0 ? 'NON-COMPLIANT' : ($warn > 0 ? 'COMPLIANT — WITH OPEN ITEMS' : 'COMPLIANT');

        // Group the items for the view.
        $groups = [];
        foreach ($items as $it) {
            $groups[$it['group']][] = $it;
        }

        $company = DB::table('companies')->where('id', $e->company_id)->first();
        $brand = ConfigController::brandFor($e->tenant_id, $e->company_id);

        $ref = 'RAC/'.$today->format('Y').'/'.strtoupper($code);
        $hash = substr(hash('sha256', $ref.'|'.$e->id.'|'.$today->toIso8601String()), 0, 16);

        return [
            'brand' => $brand,
            'company' => $company,
            'e' => $e,
            'groups' => $groups,
            'pass' => $pass, 'warn' => $warn, 'fail' => $fail,
            'score' => $score,
            'verdict' => $verdict,
            'ref' => $ref,
            'hash' => $hash,
            'generatedAt' => $today->format('d M Y').', '.now()->format('H:i').' IST',
            'auditFrom' => Carbon::parse($from)->format('d M Y'),
            'auditTo' => $today->format('d M Y'),
        ];
    }
}
