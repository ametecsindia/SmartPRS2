<?php

namespace App\Http\Controllers;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Statutory rate configuration. Stores per-tenant overrides of the Indian
 * payroll/statutory rates (PF cap & rate, ESI threshold & rates, PT, TDS new-
 * regime slabs, standard deduction, 87A rebate, cess, Sec 194H commission rate,
 * and the no-PAN higher-TDS rate) in a self-creating `statutory_settings` table.
 *
 * AppDataController reads the effective rates via SettingsController::rates()
 * so the payroll/PF/ESIC/TDS math and the statutory PDFs use the configured
 * values instead of hardcoded constants. The prototype's Statutory screen reads
 * the same rates (returned by /app/data) so the on-screen tables match.
 *
 * Table is created on first use (no migration required), matching the project's
 * self-creating-table convention (see KbController / AttendanceReportController).
 */
class SettingsController extends Controller
{
    /** Default Indian statutory rates (FY 2025-26, new regime). */
    public static function defaults(): array
    {
        return [
            'pf_wage_cap' => 15000,       // PF wage ceiling (₹)
            'pf_rate' => 12,              // PF % each side (employee & employer)
            'esi_threshold' => 21000,     // gross ≤ this is ESI-eligible (₹)
            'esi_employee_rate' => 0.75,  // ESI employee %
            'esi_employer_rate' => 3.25,  // ESI employer %
            'pt_amount' => 200,           // Professional Tax / month (₹)
            'pt_female_exempt' => 1,      // PT gender rule: exempt women in Maharashtra earning ≤ pt_female_exempt_upto (1 = on, default). Maharashtra State Tax on Professions Act.
            'pt_female_exempt_upto' => 25000, // monthly-gross ceiling for the female PT exemption (₹); above this the normal slab applies
            'std_deduction' => 75000,      // rev165: salary standard deduction (₹) — new regime
            'rebate_87a_limit' => 1200000, // rev165: 87A rebate — nil tax up to ₹12L (new regime)
            'cess_rate' => 4,             // health & education cess %
            'comm_tds_rate' => 5,         // Sec 194H commission TDS %
            'no_pan_tds_rate' => 20,      // higher TDS % when deductee has no PAN
            'lwf_enabled' => false,       // Labour Welfare Fund deduction (state-specific) — OFF by default
            'lwf_employee' => 0,          // LWF employee amount deducted per payroll month (₹) when enabled
            'conveyance_enabled' => 0,    // OPTIONAL Conveyance DEDUCTION toggle (0 = off, the default). Works exactly like PF when on.
            'conveyance_rate' => 0,       // Conveyance deduction rate — SAME CONDITIONS AS PF: rate% of min(Basic+DA, pf_wage_cap). Deducted on the payslip (own "Conveyance" line) only when enabled AND rate > 0; disabled = no deduction.
            'payslip_show_ytd' => 1,      // rev179 — show the YTD (financial-year-to-date) column on payslip PDFs: 1 = on (default), 0 = month-only payslips
            'payslip_dl_mode' => 'all',   // rev172 — employee SELF-download of payslips: all | none | dept (block listed departments) | emp (block listed emp codes). HR/Admin can always download.
            'payslip_dl_depts' => [],     // department names blocked when mode = dept
            'payslip_dl_emps' => [],      // emp codes blocked when mode = emp
            'weekly_off_day' => 'sunday', // rev172 — weekly off day; payroll working-days exclude it (plus the Saturday policy below and the Holidays calendar)
            'sat_off_mode' => 'none',     // rev172 — Saturday policy: none (all working) | all | 2_4 (2nd & 4th off) | 1_3 (1st & 3rd off)
            'lop_basis' => 'working',     // rev177 — LOP per-day basis: working (denominator = month − offs − holidays; 1 absent day costs gross/working-days) | calendar (denominator = days in month; weekly offs & holidays are PAID days — matches the common Indian payslip "Total days 31, LOP 2") | fixed30 (every month is a flat 30 days; per-day = gross/30)
            'sandwich_rule' => 0,         // rev180 — OPTIONAL sandwich rule: a weekly off/holiday BETWEEN two absent working days counts as LOP too. Off by default.
            'bonus_pct' => 8.33,          // rev180 — statutory bonus % for the Bonus Register (Payment of Bonus Act: min 8.33%, max 20%)
            'incentive_min_compliance' => 60, // F1 — min compliance score (0–100) to pay an incentive without an override note
            'dra_gate' => 'warn',         // rev181 — DRA-expiry gate at money points (incentive commit / commission approval / off-roll earning approval): off | warn (default — pays but warns + audits) | block (refuses until the DRA cert is valid)
            'points_gate_min' => 0,       // rev181 — minimum points EARNED IN THE INCENTIVE MONTH to be eligible on bulk incentive commit; 0 = gate off. Points stay a scoreboard — this gates ELIGIBILITY only, it never converts points to money.
            'incentive_payout_lag' => 0,  // rev181c (D4) — retention guard: when a commission entry / bulk commit has NO payout date, auto-set it N months after the earned month (0 = off). Incentive timing is contractual — the lag keeps 1–N months of incentive always in the pipeline.
            'late_email_enabled' => 0,        // F4 — email employees automatically on a late arrival (based on the Late Policy). 0 = off (default), 1 = on.
            'data_retention_months' => 84,    // G5 — record / recording retention period (months); 84 = 7 years
            'contact_window_start' => '08:00', // H1 — lawful borrower-contact window start (RBI 08:00–19:00)
            'contact_window_end' => '19:00',   // H1 — lawful borrower-contact window end
            'tds_slabs' => [              // rev165: new-regime annual slabs (FY2025-26); upto 0 = "and above"
                ['upto' => 400000, 'rate' => 0],
                ['upto' => 800000, 'rate' => 5],
                ['upto' => 1200000, 'rate' => 10],
                ['upto' => 1600000, 'rate' => 15],
                ['upto' => 2000000, 'rate' => 20],
                ['upto' => 2400000, 'rate' => 25],
                ['upto' => 0, 'rate' => 30],
            ],
        ];
    }

    /** Create the statutory_settings table on the fly if migrations were not run. */
    private static function ensureTable(): void
    {
        if (Schema::hasTable('statutory_settings')) {
            return;
        }
        Schema::create('statutory_settings', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->nullable()->index();
            $t->longText('value')->nullable();
            $t->timestamps();
        });
    }

    /** Effective rates for a tenant = defaults overlaid with saved overrides. */
    public static function rates(?int $tenantId): array
    {
        self::ensureTable();
        $row = DB::table('statutory_settings')->where('tenant_id', $tenantId ?? 0)->value('value');
        $saved = $row ? (json_decode($row, true) ?: []) : [];
        $rates = array_merge(self::defaults(), $saved);
        if (empty($rates['tds_slabs']) || ! is_array($rates['tds_slabs'])) {
            $rates['tds_slabs'] = self::defaults()['tds_slabs'];
        }

        return $rates;
    }

    /**
     * rev172 — may this EMPLOYEE self-download payslips under the tenant policy?
     * (HR/Admin are never blocked — callers must check roles first.)
     */
    public static function payslipSelfAllowed(object $e, ?array $rates = null): bool
    {
        $r = $rates ?: self::rates(isset($e->tenant_id) ? (int) $e->tenant_id : null);
        $mode = (string) ($r['payslip_dl_mode'] ?? 'all');
        if ($mode === 'none') {
            return false;
        }
        $lc = fn ($x) => strtolower(trim((string) $x));
        if ($mode === 'dept') {
            return ! in_array($lc($e->department ?? ''), array_map($lc, (array) ($r['payslip_dl_depts'] ?? [])), true);
        }
        if ($mode === 'emp') {
            return ! in_array($lc($e->emp_code ?? ''), array_map($lc, (array) ($r['payslip_dl_emps'] ?? [])), true);
        }

        return true;
    }

    public function index(Request $request)
    {
        return response()->json([
            'rates' => self::rates($request->user()->tenant_id),
            'defaults' => self::defaults(),
            'canManage' => $request->user()->hasAnyRole(['super_admin', 'admin']),
        ]);
    }

    public function save(Request $request)
    {
        abort_unless($request->user()->hasAnyRole(['super_admin', 'admin']), 403);
        self::ensureTable();

        $num = ['nullable', 'numeric', 'min:0'];
        $v = $request->validate([
            'pf_wage_cap' => $num, 'pf_rate' => $num,
            'esi_threshold' => $num, 'esi_employee_rate' => $num, 'esi_employer_rate' => $num,
            'pt_amount' => $num, 'pt_female_exempt' => $num, 'pt_female_exempt_upto' => $num,
            'std_deduction' => $num, 'rebate_87a_limit' => $num,
            'cess_rate' => $num, 'comm_tds_rate' => $num, 'no_pan_tds_rate' => $num,
            'conveyance_enabled' => $num, 'conveyance_rate' => $num,
            'payslip_show_ytd' => $num, // rev179 — 1/0 toggle
            'sandwich_rule' => $num,    // rev180 — 1/0 toggle
            'bonus_pct' => $num,        // rev180 — statutory bonus %
            'points_gate_min' => $num,  // rev181 — monthly points threshold (0 = off)
            'late_email_enabled' => $num, // F4 — late-arrival email toggle
            'incentive_payout_lag' => $num, // rev181c — payout lag months (0 = off)
            'dra_gate' => ['nullable', 'in:off,warn,block'], // rev181 — DRA money-point gate
            'weekly_off_day' => ['nullable', 'in:sunday,monday,tuesday,wednesday,thursday,friday,saturday'],
            'sat_off_mode' => ['nullable', 'in:none,all,2_4,1_3'],
            'lop_basis' => ['nullable', 'in:working,calendar,fixed30'], // rev177
            'payslip_dl_mode' => ['nullable', 'in:all,none,dept,emp'],
            'payslip_dl_depts' => ['nullable', 'array'], 'payslip_dl_depts.*' => ['string', 'max:120'],
            'payslip_dl_emps' => ['nullable', 'array'], 'payslip_dl_emps.*' => ['string', 'max:60'],
            'tds_slabs' => ['nullable', 'array'],
            'tds_slabs.*.upto' => ['nullable', 'numeric', 'min:0'],
            'tds_slabs.*.rate' => ['nullable', 'numeric', 'min:0'],
        ]);

        // rev172 — start from the tenant's CURRENT effective rates (defaults +
        // saved overrides) so a partial save (e.g. only the payslip policy)
        // never silently resets other saved settings back to defaults.
        $merged = self::rates($request->user()->tenant_id ?? 0);
        $strKeys = ['weekly_off_day', 'sat_off_mode', 'lop_basis', 'payslip_dl_mode', 'dra_gate']; // rev172/rev177/rev181 — string settings, no numeric cast
        $arrKeys = ['payslip_dl_depts', 'payslip_dl_emps'];               // rev172 — list settings
        foreach (self::defaults() as $k => $default) {
            if ($k === 'tds_slabs' || in_array($k, $strKeys, true) || in_array($k, $arrKeys, true)) {
                continue;
            }
            if (array_key_exists($k, $v) && $v[$k] !== null && $v[$k] !== '') {
                $merged[$k] = $v[$k] + 0; // numeric
            }
        }
        foreach ($strKeys as $k) {
            if (! empty($v[$k])) {
                $merged[$k] = (string) $v[$k];
            }
        }
        foreach ($arrKeys as $k) {
            if (array_key_exists($k, $v)) {
                $merged[$k] = array_values(array_filter(array_map(fn ($s) => trim((string) $s), (array) ($v[$k] ?? [])), fn ($s) => $s !== ''));
            }
        }
        if (! empty($v['tds_slabs'])) {
            $merged['tds_slabs'] = array_values(array_map(fn ($s) => [
                'upto' => (float) ($s['upto'] ?? 0),
                'rate' => (float) ($s['rate'] ?? 0),
            ], $v['tds_slabs']));
        }

        $tenantId = $request->user()->tenant_id ?? 0;
        DB::table('statutory_settings')->updateOrInsert(
            ['tenant_id' => $tenantId],
            ['value' => json_encode($merged), 'updated_at' => now(), 'created_at' => now()]
        );

        return response()->json(['ok' => true, 'rates' => $merged]);
    }
}
