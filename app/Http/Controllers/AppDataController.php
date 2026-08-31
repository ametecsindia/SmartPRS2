<?php

namespace App\Http\Controllers;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Serves live database data to the prototype engine, mapped into the shapes the
 * prototype's screens expect. Tenant-scoped: super admin sees all tenants;
 * company users see only their tenant.
 *
 * Statutory rates (PF cap/rate, ESI threshold/rates, PT, TDS slabs, std
 * deduction, 87A, cess, 194H %, no-PAN %) come from SettingsController so
 * payroll, PF/ESIC and TDS math reflect the configured values; per-company
 * branding is surfaced so the UI can re-brand live on company switch.
 */
class AppDataController extends Controller
{
    // Labels MUST match the Add/Edit form's Salary Type dropdown options,
    // otherwise the read-back value is not a valid option and the select shows
    // blank on edit — which is why they are declared in ONE place now.
    /**
     * 28 Aug 2026 (Ejaz) — ONE set of salary-type labels, defined once in
     * EmployeeFieldRules and used by the form, the sample file, the export and
     * the importer alike. It used to read "Only Salary" / "Only Commission"
     * here and in the export while the sample file offered "Salary" /
     * "Commission", so an exported file re-imported every commission-only
     * employee as salary-only.
     *
     * Fixing it also repairs the TDS estimates below (lines ~1655/1678/1726),
     * which have always compared against the SHORT labels — so
     * `$st === 'Commission'` could never be true and commission-only employees
     * were being given a commission base of zero.
     */
    private const SALARY_TYPE = \App\Services\EmployeeFieldRules::SALARY_TYPE;

    /**
     * Add the org-hierarchy name columns to employees if missing
     * (department/designation/branch/team/reporting_manager/team_leader), used as
     * editable overrides. Self-creating per project convention; never fatal.
     */
    public static function ensureEmployeeColumns(): void
    {
        if (! Schema::hasTable('employees')) {
            return;
        }
        // 7 Aug 2026 test report (item 10c / item 2) — new personal & bank fields:
        // mother, national_id (Government ID / SSN), marital_status, bank_branch.
        $cols = ['department', 'designation', 'branch', 'team', 'reporting_manager', 'team_leader', 'father', 'mother', 'spouse', 'marital_status', 'blood_group', 'id_marks', 'gender', 'address', 'national_id', 'bank_branch', 'pt_state', 'shift', 'employment_stage', 'dra_declared', 'pcc_declared', 'dra_status', 'pcc_status', 'pcc_deadline',
            // rev190 (item C) — import/export + self-onboarding parity columns.
            'permanent_address', 'emergency_name', 'emergency_phone', 'esic_no', 'category', 'account_holder',
            // 28 Aug 2026 (Ejaz) — the last three columns the Employee form,
            // the sample file and Self-Onboarding did not share. `nationality`
            // was written by the portal and read by nothing; `also_works_for`
            // was a form control that was never saved at all; `id_marks` was
            // saved but had no column in the file. See EmployeeFieldRules.
            'nationality', 'also_works_for',
            // 27 Aug 2026 (Ejaz) — DRA EXPIRY / PCC EXPIRY are import-template
            // columns and are now captured on the Documents tab, so an install
            // patched before the create-table carried them still gets them.
            'dra_expiry', 'pcc_expiry'];
        $missing = array_values(array_filter($cols, fn ($c) => ! Schema::hasColumn('employees', $c)));
        if (! $missing) {
            return;
        }
        Schema::table('employees', function (Blueprint $t) use ($missing) {
            foreach ($missing as $c) {
                $t->string($c)->nullable();
            }
        });
    }

    /**
     * rev172 (H3) — recursively strip HTML tags and angle-brackets from every
     * string in an array. Used to sanitise user-entered names / free-text before
     * saving, so stored values can never inject script when later rendered into
     * the SPA via innerHTML. Numbers/bools/keys are left untouched.
     */
    public static function stripHtmlDeep($val)
    {
        if (is_array($val)) {
            return array_map([self::class, 'stripHtmlDeep'], $val);
        }
        if (is_string($val)) {
            return trim(str_replace(['<', '>'], '', strip_tags($val)));
        }

        return $val;
    }

    /** F5 — normalise a self-declared Yes/No compliance answer. Blank → null so
     *  editing a pre-existing employee is never forced to answer.
     *  27 Aug 2026 (Ejaz) — "NA" is preserved instead of collapsing to "No": the
     *  import template's DRA / PCC DECLARED columns offer Yes/No/NA, the
     *  Documents tab now offers the same three, and "not applicable" is not the
     *  same statement as "declared No". */
    private static function yesNo($v): ?string
    {
        $s = strtolower(trim((string) $v));
        if ($s === '') {
            return null;
        }
        if (in_array($s, ['na', 'n/a', 'notapplicable', 'not applicable', '-'], true)) {
            return 'NA';
        }

        return in_array($s, ['yes', 'y', 'true', '1'], true) ? 'Yes' : 'No';
    }

    /**
     * 28 Aug 2026 (Ejaz) — normalise an Employment Stage to the ONE stored
     * shape: '' (Permanent) | 'probation' | 'internship'. Accepts the form's
     * labels, the sample file's labels and the values already in the column, so
     * a record created any of the three ways reads the same afterwards.
     * 'Intern' and 'Contract' arrive from the retired Self-Onboarding
     * "Employment type" control: Intern is Internship; Contract has no stage
     * equivalent and stays blank (= Permanent) rather than being invented.
     */
    public static function employmentStage($v): ?string
    {
        $s = strtolower(trim((string) $v));
        if ($s === '') {
            return null;
        }
        if (in_array($s, ['probation', 'probationary'], true)) {
            return 'probation';
        }
        if (in_array($s, ['internship', 'intern'], true)) {
            return 'internship';
        }

        return '';   // Permanent
    }

    /** 27 Aug 2026 (Ejaz) — one canonical DRA / PCC certificate status set,
     *  shared by the Documents tab, the import template and the importer:
     *  pending | submitted | verified (lowercase — ComplianceController
     *  compares === 'verified'). Anything else, including the retired
     *  'overdue', reads as null rather than being stored as a value the
     *  dropdown cannot show. */
    public static function complianceStatus($v): ?string
    {
        $s = strtolower(trim((string) $v));

        return in_array($s, ['pending', 'submitted', 'verified'], true) ? $s : null;
    }

    /**
     * 2026-08-05 — tolerant date reader for uploads/forms → 'Y-m-d' or null.
     * Understands ISO (2024-05-13, 2024/05/13), Indian d/m/Y with -, /, or .
     * separators, 2-digit years (13/05/24 → 2024), textual months
     * (13-May-2024, 13 May 24), Excel date serial numbers (e.g. 45425), and
     * finally strtotime as a best effort. Returns NULL when unreadable so a
     * DATE column is never handed an invalid literal (which MySQL rejects —
     * that was the "bulk upload date is not set" bug).
     */
    public static function normDateWide($s): ?string
    {
        $s = trim((string) $s);
        if ($s === '') {
            return null;
        }
        $y4 = fn ($y) => (int) ($y < 100 ? ($y >= 70 ? 1900 + $y : 2000 + $y) : $y);
        $mk = function ($y, $m, $d) {
            return checkdate((int) $m, (int) $d, (int) $y) ? sprintf('%04d-%02d-%02d', $y, $m, $d) : null;
        };
        if (preg_match('#^(\d{4})[-/.](\d{1,2})[-/.](\d{1,2})#', $s, $m)) {
            return $mk($m[1], $m[2], $m[3]);                    // ISO Y-m-d
        }
        if (preg_match('#^(\d{1,2})[-/.](\d{1,2})[-/.](\d{2,4})#', $s, $m)) {
            // Indian DD/MM/YYYY first (the app standard); when that is impossible
            // (e.g. 04/23/2024 — month 23 doesn't exist) fall back to US m/d/Y so
            // an Excel that switched the file to mm/dd/yyyy still imports.
            return $mk($y4((int) $m[3]), $m[2], $m[1]) ?: $mk($y4((int) $m[3]), $m[1], $m[2]);
        }
        if (preg_match('#^(\d{1,2})[\s\-/.]([A-Za-z]{3,9})[\s\-/.,]+(\d{2,4})#', $s, $m)) {
            $mo = date_parse($m[2].' 1 2000')['month'] ?? 0;    // 13-May-2024 / 13 May 24
            return $mo ? $mk($y4((int) $m[3]), $mo, $m[1]) : null;
        }
        if (preg_match('/^\d{5,6}$/', $s)) {
            $ts = ((int) $s - 25569) * 86400;                   // Excel serial (days since 1900)
            return ($ts > 0) ? gmdate('Y-m-d', $ts) : null;
        }
        $ts = strtotime($s);

        return $ts ? date('Y-m-d', $ts) : null;
    }

    /** Keep only the array keys that are real columns on $table (schema-tolerant insert). */
    private function onlyExistingCols(string $table, array $row): array
    {
        static $cache = [];
        if (! isset($cache[$table])) {
            $cache[$table] = Schema::hasTable($table) ? Schema::getColumnListing($table) : array_keys($row);
        }
        $cols = $cache[$table];

        return array_intersect_key($row, array_flip($cols));
    }

    /** Branding map that never throws — branding is cosmetic, must not break /app/data. */
    private function safeBrandingMap(?int $tenantId): array
    {
        try {
            return ConfigController::brandingMap($tenantId);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * rev175 — Employee self-service isolation. Returns ['id','code'] of the
     * LOGGED-IN employee when the user is a plain 'employee' (no admin / HR /
     * super-admin); null for privileged roles (never scoped). An employee not
     * linked to any record gets a non-matching sentinel so they see nobody else.
     */
    public static function selfScope(Request $request, bool $allNonPrivileged = false): ?array
    {
        $user = $request->user();
        if (! $user || $user->hasAnyRole(['super_admin', 'admin', 'hr_manager'])) {
            return null;
        }
        // rev190 — attendance callers pass $allNonPrivileged=true so a Field Agent
        // (not just the 'employee' role) is also scoped to their own punches; the
        // Directory/bootstrap callers keep the stricter employee-only default.
        if (! $allNonPrivileged && ! $user->hasRole('employee')) {
            return null;
        }
        $tid = $user->tenant_id;
        $e = null;
        if (! empty($user->employee_id)) {
            $e = DB::table('employees')->where('id', $user->employee_id)->whereNull('deleted_at')->first();
        }
        if (! $e) {
            $e = DB::table('employees')->whereNull('deleted_at')
                ->when($tid, fn ($q) => $q->where('tenant_id', $tid))
                ->where(fn ($q) => $q->where('email', $user->email)->orWhere('name', $user->name))
                ->first();
        }
        return ['id' => (int) ($e->id ?? -1), 'code' => (string) ($e->emp_code ?? '__no_such_employee__')];
    }

    public function bootstrap(Request $request)
    {
        $tenantId = $request->user()->tenant_id;   // null = super admin (all tenants)
        $rates = SettingsController::rates($tenantId);
        try {
            self::ensureEmployeeColumns();
        } catch (\Throwable $e) {
            // adding optional hierarchy columns is best-effort; never block bootstrap
        }

        self::processDueBackups($tenantId);   // rev183d — run any elapsed 3-day backup schedules first
        $empQ = DB::table('employees')->whereNull('deleted_at');
        if (Schema::hasColumn('employees', 'archived_at')) { $empQ->whereNull('archived_at'); }   // rev183b — hide backed-up (Old data) employees
        $compQ = DB::table('companies')->whereNull('deleted_at');
        if ($tenantId) {
            $empQ->where('tenant_id', $tenantId);
            $compQ->where('tenant_id', $tenantId);
        }
        // rev175 — EMPLOYEE SELF-SERVICE ISOLATION: a plain employee sees ONLY
        // their own record; payslips / TDS / dashboard / directory all derive
        // from $emps, so scoping here isolates every one of them.
        $selfScope = self::selfScope($request);
        if ($selfScope) {
            $empQ->where('id', $selfScope['id']);
        }

        $deptNames = DB::table('departments')->pluck('name', 'id');
        $desigNames = DB::table('designations')->pluck('name', 'id');
        // 10 Aug 2026 — Salary Schedule labels (id -> "Name — Company") so the
        // employee's assigned pay schedule shows in the Add/Edit form dropdown.
        // 11 Aug 2026 — column-safe + wrapped. A fresh on-prem schema may not yet
        // have salary_schedules.company_name; selecting it explicitly threw
        // "Unknown column" and 500'd all of /app/data, which blanked the whole
        // Directory (the employees list rides on this response). ->get() + ?? ''
        // is column-safe, and the try/catch matches the payroll block below so a
        // schema mismatch can never take the app's data feed down again.
        try {
            $scheduleLabels = Schema::hasTable('salary_schedules')
                ? DB::table('salary_schedules')->get()
                    ->mapWithKeys(fn ($s) => [$s->id => trim((string) ($s->name ?? '')).' — '.trim((string) ($s->company_name ?? ''))])
                : collect();
        } catch (\Throwable $e) {
            $scheduleLabels = collect();
        }
        $compNames = DB::table('companies')->pluck('name', 'id');   // company_id -> NAME (Directory shows the name, never the raw id)
        $branchNames = DB::table('branches')->pluck('name', 'id');
        $teamRows = DB::table('teams')->get(['id', 'name', 'leader_id']);
        $teamNames = $teamRows->pluck('name', 'id');
        $teamLeaderId = $teamRows->pluck('leader_id', 'id');

        $emps = $empQ->orderBy('emp_code')->get();
        $empNames = $emps->pluck('name', 'id');   // id → name, for manager / leader resolution
        $refsByEmp = DB::table('employee_references')
            ->whereIn('employee_id', $emps->pluck('id'))->get()->groupBy('employee_id');

        $employees = $emps->map(function ($e) use ($deptNames, $desigNames, $compNames, $branchNames, $teamNames, $teamLeaderId, $empNames, $refsByEmp, $scheduleLabels) {
            $refs = ($refsByEmp[$e->id] ?? collect())->map(fn ($r) => [
                'name' => $r->name, 'relation' => $r->relation, 'aadhaar' => $r->aadhaar,
                'pan' => $r->pan, 'mobile' => $r->mobile,
                'verify' => [
                    'email' => (bool) $r->verify_email, 'sms' => (bool) $r->verify_sms,
                    'call' => (bool) $r->verify_call, 'whatsapp' => (bool) $r->verify_whatsapp,
                ],
            ])->values();

            // Cast to array so any column missing on an older deployed schema
            // (e.g. department_id, branch_id, team_id) reads as null via ?? instead
            // of throwing "Undefined property: stdClass::$...". $col() is the helper.
            $a = (array) $e;
            $col = fn ($k) => $a[$k] ?? null;
            $deptId = $col('department_id');
            $teamId = $col('team_id');
            $mgrId = $col('reporting_manager_id');

            return [
                'id' => $col('emp_code'),
                'refs' => $refs,
                'name' => $col('name'),
                'photo' => $col('photo_path') ? url('/app/emp-photo/'.$col('emp_code')) : '',
                'companyId' => (string) $col('company_id'),
                'companies' => [(string) $col('company_id')],
                // Primary company NAME for the Directory list / search / exports.
                'company' => (string) ($compNames[$col('company_id')] ?? ''),
                // Hierarchy: prefer the editable name column, else resolve the
                // normalized FK (so seeded/existing employees show correctly).
                'dept' => $col('department') ?: ($deptId ? ($deptNames[$deptId] ?? '') : ''),
                'designation' => $col('designation') ?: ($col('designation_id') ? ($desigNames[$col('designation_id')] ?? '') : ''),
                'branch' => $col('branch') ?: ($col('branch_id') ? ($branchNames[$col('branch_id')] ?? '') : ''),
                'team' => $col('team') ?: ($teamId ? ($teamNames[$teamId] ?? '') : ''),
                // Keys match the prototype Add/Edit form field ids (teamManager /
                // teamLeader) so prefill works; reporting/leader kept as aliases.
                'teamManager' => $col('reporting_manager') ?: ($mgrId ? ($empNames[$mgrId] ?? '') : ''),
                'teamLeader' => $col('team_leader') ?: (($teamId && ($teamLeaderId[$teamId] ?? null)) ? ($empNames[$teamLeaderId[$teamId]] ?? '') : ''),
                'reporting' => $col('reporting_manager') ?: ($mgrId ? ($empNames[$mgrId] ?? '') : ''),
                'leader' => $col('team_leader') ?: (($teamId && ($teamLeaderId[$teamId] ?? null)) ? ($empNames[$teamLeaderId[$teamId]] ?? '') : ''),
                // 28 Aug 2026 (Ejaz) — the sample file's EMPLOYEE TYPE column
                // offers Office / Field, so the form and the read-back use the
                // same two words. (The old 'Field / FOS' survives only as the
                // Directory badge colour key; wireEmpFormExtras re-registers it
                // for 'Field'.)
                'type' => $col('type') === 'field' ? 'Field' : 'Office',
                'employment_stage' => \App\Services\EmployeeFieldRules::EMPLOYMENT_STAGE[(string) ($col('employment_stage') ?? '')] ?? 'Permanent',
                'doj' => $col('doj') ?? '',
                // 2026-08-05 — FIX "edits revert after refresh": these form fields
                // were SAVED by storeEmployee but never sent back here, so after a
                // reload the edit form showed blanks and the next save wiped them.
                'dob' => $col('dob') ?? '',
                'gender' => $col('gender') ?? '',
                'father' => $col('father') ?? '',
                'mother' => $col('mother') ?? '',   // 7 Aug 2026 test report (item 10c) — read back so edits persist
                'spouse' => $col('spouse') ?? '',
                'maritalStatus' => $col('marital_status') ?? '',
                'nationalId' => $col('national_id') ?? '',
                'bankBranch' => $col('bank_branch') ?? '',
                'bloodGroup' => $col('blood_group') ?? '',
                'idMarks' => $col('id_marks') ?? '',
                'whatsapp' => $col('whatsapp') ?? '',
                'addr' => $col('address') ?? '',
                // 28 Aug 2026 (Ejaz) — PARITY READ-BACK. Every one of these
                // columns already existed and was already being filled by the
                // import wizard and by Self-Onboarding, but bootstrap() never
                // sent them to the browser — so the Directory showed blanks for
                // data that WAS in the row, and there was no way to see or
                // correct it from the Employee form. The matching inputs are
                // built from EmployeeFieldRules::formPanels().
                'category' => $col('category') ?? '',
                'nationality' => $col('nationality') ?? '',
                'esicNo' => $col('esic_no') ?? '',
                'permanentAddress' => $col('permanent_address') ?? '',
                'emergencyName' => $col('emergency_name') ?? '',
                'emergencyPhone' => $col('emergency_phone') ?? '',
                'accountHolder' => $col('account_holder') ?? '',
                'alsoWorksFor' => $col('also_works_for') ?? '',
                'multi' => $col('also_works_for') ?? '',   // the form field is still f_multi
                'homeLat' => $col('home_lat') ?? '',
                'homeLng' => $col('home_lng') ?? '',
                'geoStart' => $col('geo_start') ? ucfirst((string) $col('geo_start')) : '',
                'geoRadius' => $col('geo_radius_km') ?? '',
                'geoOutside' => $col('geo_outside') ? ucfirst((string) $col('geo_outside')) : '',
                'mobile' => $col('mobile') ?? '',
                'email' => $col('email') ?? '',
                'ctc' => (float) $col('ctc'),
                'pf' => $col('pf_applicable') ? 'Yes' : 'No',
                // 28 Aug 2026 (Ejaz) — DATA LOSS, fixed. This read
                //   $col('esi_applicable') === 'yes' ? 'Yes' : 'No'
                // but the column is enum('auto','yes','no') and AUTO IS THE
                // DEFAULT. So every employee left on Auto was sent to the
                // browser as "No", the form showed No, and the next save of
                // that employee — for any unrelated reason — wrote 'no' back
                // and the Auto setting was gone for good. Three values in, the
                // same three values out.
                'esi' => ucfirst(strtolower((string) ($col('esi_applicable') ?: 'auto'))),
                'pan' => $col('pan') ?? '',
                'uan' => $col('uan') ?? '',
                'ptState' => $col('pt_state') ?? '',
                'bankName' => $col('bank_name') ?? '',
                'bankAcc' => $col('bank_acc') ?? '',
                'ifsc' => $col('ifsc') ?? '',
                'status' => ucfirst((string) $col('status')),
                'backupDueAt' => $col('backup_due_at') ? \Illuminate\Support\Carbon::parse($col('backup_due_at'))->toIso8601String() : '',   // rev183d
                'salaryType' => self::SALARY_TYPE[$col('salary_type')] ?? 'Salary',
                'commPct' => (float) ($col('comm_pct') ?? 0),
                'shift' => $col('shift') ?? '',   // rev173 — default Working Shift (name)
                'schedule' => $scheduleLabels[$col('schedule_id')] ?? '',   // 10 Aug 2026 — assigned Salary Schedule
                'draDeclared' => $col('dra_declared') ?? '',   // F5 — self-onboarding DRA (Yes/No)
                'pccDeclared' => $col('pcc_declared') ?? '',   // F5 — self-onboarding PCC (Yes/No)
                // Documents tab (10 Aug 2026) — read back so the edit form shows
                // the saved values (ucfirst to match the dropdown option casing).
                'dra' => ucfirst(strtolower((string) ($col('dra_status') ?? ''))),
                'pcc' => ucfirst(strtolower((string) ($col('pcc_status') ?? ''))),
                'pccDeadline' => $col('pcc_deadline') ? substr((string) $col('pcc_deadline'), 0, 10) : '',
                // 27 Aug 2026 (Ejaz) — DRA / PCC EXPIRY are on the Documents tab
                // now; read them back or the edit form would blank them on save.
                'draExpiry' => $col('dra_expiry') ? substr((string) $col('dra_expiry'), 0, 10) : '',
                'pccExpiry' => $col('pcc_expiry') ? substr((string) $col('pcc_expiry'), 0, 10) : '',
                // Biometric Mapping — the employee's ID on the attendance device
                // (employees.device_user_id). Editable in the Directory profile;
                // punches whose device code matches it import under this employee.
                'deviceUserId' => $col('device_user_id') ?? '',
            ];
        })->values();

        $companies = $compQ->orderBy('name')->get()
            ->map(fn ($c) => ['id' => (string) $c->id, 'name' => $c->name])->values();

        // SaaS Platform → Tenants (super admin only).
        $tenants = collect();
        if (! $tenantId) {
            $planNames = DB::table('plans')->pluck('name', 'id');
            $tenants = DB::table('tenants')->whereNull('deleted_at')->orderBy('name')->get()->map(fn ($t) => [
                'id' => $t->uuid ?: (string) $t->id,
                'name' => $t->name,
                'plan' => $t->plan_id ? ($planNames[$t->plan_id] ?? '—') : '—',
                'status' => ucfirst($t->status),
                'seatsUsed' => (int) $t->seats_used,
                'seatsLicensed' => (int) $t->seats_licensed,
                'mrr' => (float) $t->mrr,
                'deployment' => $t->deployment === 'saas' ? 'SaaS' : 'On-Premise',
                'signup' => $t->created_at ? \Illuminate\Support\Carbon::parse($t->created_at)->format('d M Y') : '',
            ])->values();
        }

        // Company in scope for payroll generation (first of the tenant's companies).
        $companyId = DB::table('companies')
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->whereNull('deleted_at')->value('id');

        // Payroll history — last 12 months into normalized tables (idempotent).
        // Wrapped: a payroll-table schema mismatch must never 500 /app/data (that
        // blanks the whole app and aborts the boot script → logout/nav vanish).
        try {
            $payroll = $this->ensurePayrollHistory($tenantId, $companyId, $emps, $rates);
        } catch (\Throwable $e) {
            $payroll = ['runs' => collect(), 'payslips' => collect()];
        }

        // rev149 — org-hierarchy option lists for the Add/Edit forms' dropdowns
        // (Department / Branch / Designation / Team). Without these the in-browser
        // option arrays are empty on a fresh install, so the selects show nothing
        // even though the records exist (visible on their own list screens).
        $optList = function (string $table) use ($tenantId) {
            try {
                if (! Schema::hasTable($table)) {
                    return collect();
                }

                return DB::table($table)
                    ->when(Schema::hasColumn($table, 'deleted_at'), fn ($q) => $q->whereNull('deleted_at'))
                    ->when($tenantId && Schema::hasColumn($table, 'tenant_id'), fn ($q) => $q->where('tenant_id', $tenantId))
                    ->orderBy('name')->get(['id', 'name'])
                    ->map(fn ($r) => ['id' => (string) $r->id, 'name' => $r->name])->values();
            } catch (\Throwable $e) {
                return collect();
            }
        };

        // rev173 — Working Shifts for the form dropdowns (employee default shift +
        // roster shift). Same tolerant pattern as $optList; includes timings so
        // the UI can hint "09:30–18:30" and flag night shifts.
        $shiftList = collect();
        try {
            if (Schema::hasTable('shifts')) {
                $shiftList = DB::table('shifts')
                    ->when($tenantId && Schema::hasColumn('shifts', 'tenant_id'), fn ($q) => $q->where('tenant_id', $tenantId))
                    ->orderBy('name')->get()
                    ->filter(fn ($r) => (($r->status ?? '') !== 'inactive') && ($r->name ?? ''))
                    ->map(fn ($r) => [
                        'id' => (string) $r->id,
                        'name' => $r->name,
                        'start' => (string) ($r->start_time ?? ''),
                        'end' => (string) ($r->end_time ?? ''),
                        'night' => \App\Services\ShiftResolver::hm($r->end_time ?? '') !== null
                            && \App\Services\ShiftResolver::hm($r->start_time ?? '') !== null
                            && \App\Services\ShiftResolver::toMin(\App\Services\ShiftResolver::hm($r->end_time)) <= \App\Services\ShiftResolver::toMin(\App\Services\ShiftResolver::hm($r->start_time)),
                    ])->values();
            }
        } catch (\Throwable $e) {
            $shiftList = collect();
        }

        // rev183b — "Backed up / Old data" tab: employees moved out of the active
        // directory (archived_at set). Their data stays intact; surface a light
        // 7 Aug 2026 test report (item 11) — the Employee → Statutory & Salary
        // "Salary Schedule" dropdown showed only "Select…" because the bootstrap
        // never sent the salarySchedules collection the form maps over
        // (DB.salarySchedules.map(s => s.name + ' — ' + s.companyId)). Feed the
        // tenant's real, active schedules so the dropdown lists them.
        $salarySchedules = [];
        try {
            if (Schema::hasTable('salary_schedules')) {
                $salarySchedules = DB::table('salary_schedules')
                    ->when(Schema::hasColumn('salary_schedules', 'deleted_at'), fn ($q) => $q->whereNull('deleted_at'))
                    ->when($tenantId && Schema::hasColumn('salary_schedules', 'tenant_id'), fn ($q) => $q->where('tenant_id', $tenantId))
                    ->when(Schema::hasColumn('salary_schedules', 'status'), fn ($q) => $q->where(fn ($x) => $x->where('status', 'active')->orWhereNull('status')))
                    ->orderBy('name')->get()
                    ->map(fn ($r) => [
                        'id' => (string) ($r->id ?? ''),
                        'name' => (string) ($r->name ?? ''),
                        'companyId' => (string) ($r->company_name ?? ''),
                        'payCycle' => (string) ($r->pay_cycle ?? ''),
                    ])->values();
            }
        } catch (\Throwable $e) {
            $salarySchedules = [];
        }

        // list plus a download link to the stored JSON backup.
        $archived = [];
        try {
            $hasArch = Schema::hasColumn('employees', 'archived_at');
            $arQ = DB::table('employees');
            if ($hasArch) {
                $arQ->where(fn ($w) => $w->whereNotNull('archived_at')->orWhereNotNull('deleted_at'));
            } else {
                $arQ->whereNotNull('deleted_at');
            }
            if ($tenantId) { $arQ->where('tenant_id', $tenantId); }
            if ($selfScope) { $arQ->where('id', $selfScope['id']); }
            $arRows = $arQ->orderByRaw($hasArch ? 'COALESCE(archived_at, deleted_at) DESC' : 'deleted_at DESC')->limit(1000)->get();
            $archived = $arRows->map(function ($e) use ($deptNames, $compNames) {
                $a = (array) $e;
                $c = fn ($k) => $a[$k] ?? null;
                $isArch = ! empty($c('archived_at'));
                $when = $isArch ? $c('archived_at') : $c('deleted_at');
                return [
                    'id' => $c('emp_code'),
                    'name' => $c('name'),
                    'dept' => $deptNames[$c('department_id')] ?? ($c('department') ?? ''),
                    'designation' => $c('designation') ?? '',
                    'company' => (string) ($compNames[$c('company_id')] ?? ''),
                    'reason' => $isArch ? 'Backed up' : 'Deleted',
                    'archivedAt' => $when ? substr((string) $when, 0, 16) : '',
                    'archivedBy' => $c('archived_by') ?? '',
                    'detailUrl' => url('/app/employees/'.rawurlencode((string) $c('emp_code')).'/archive-detail'),
                    'fileUrl' => url('/app/employees/'.rawurlencode((string) $c('emp_code')).'/backup-file'),
                ];
            })->values();
        } catch (\Throwable $e) {
            $archived = [];
        }

        return response()->json([
            'employees' => $employees,
            'archivedEmployees' => $archived,
            'companies' => $companies,
            'branches' => $optList('branches'),
            'departments' => $optList('departments'),
            'designations' => $optList('designations'),
            'teams' => $optList('teams'),
            'shifts' => $shiftList,
            'salarySchedules' => $salarySchedules,
            'tenants' => $tenants,
            'payrollRuns' => $payroll['runs'],
            'payslips' => $payroll['payslips'],
            'tdsReturns' => $this->tdsReturnsHistory($emps, $rates),
            'rates' => $rates,
            // Per-company branding map (companyId → {display_name,color,logo,tagline})
            // so the frontend can re-brand live when the company switcher changes.
            'branding' => $this->safeBrandingMap($tenantId),
        ])->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
          ->header('Pragma', 'no-cache');
    }

    /** Bulk import employees from a CSV upload into the real employees table. */
    public function importEmployees(Request $request)
    {
        if ($deny = ApprovalService::denyUnlessRole($request, ['admin', 'hr_manager'])) {
            return $deny;
        }
        // rev154 — validate by EXTENSION, not MIME. A .csv saved by Excel is often
        // reported with a non-text MIME (application/vnd.ms-excel, octet-stream,
        // etc.), and `mimes:csv,txt` then rejected a perfectly valid file — which
        // looked like "the upload does nothing" on the client. We only need a real
        // uploaded file ending in .csv/.txt; the parser handles the rest.
        $request->validate(['file' => ['required', 'file']]);
        $ext = strtolower((string) $request->file('file')->getClientOriginalExtension());
        if (! in_array($ext, ['csv', 'txt'], true)) {
            return response()->json(['ok' => false, 'error' => 'Please upload the sample file as .csv (saved from Excel as "CSV UTF-8"). Got: .'.$ext], 422);
        }

        $user = $request->user();
        $tenantId = $user->tenant_id ?? DB::table('tenants')->value('id');
        $companyId = DB::table('companies')
            ->when($user->tenant_id, fn ($q) => $q->where('tenant_id', $user->tenant_id))->value('id');

        $lines = file($request->file('file')->getRealPath(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (! $lines) {
            return response()->json(['ok' => false, 'error' => 'Empty file'], 422);
        }
        // rev173g — STRIP the UTF-8 BOM Excel's "CSV UTF-8" always writes. It glued
        // itself to the FIRST header (emp_code → "\xEF\xBB\xBFemp_code"), so every
        // imported employee silently got a RANDOM EMP-xxxx code instead of the
        // file's code — re-imports then created duplicates and leave/payslips
        // couldn't match the codes people expected.
        $lines[0] = preg_replace('/^\xEF\xBB\xBF/', '', $lines[0]);
        // Header tolerance: lowercase, trim, and normalise separators so
        // "Date of Joining" / "date-of-joining" both resolve to date_of_joining.
        $header = array_map(fn ($h) => str_replace([' ', '-'], '_', strtolower(trim($h))), str_getcsv(array_shift($lines)));

        $salaryMap = ['salary' => 'only_salary', 'salary + commission' => 'salary_commission', 'commission' => 'only_commission'];

        // rev149 — make sure the richer template's columns exist on the employees
        // table (self-creating, per project convention) so they actually import.
        // rev154 — guarded: the columns are normally guaranteed by migration; if a
        // restricted DB user can't ALTER, the import must still proceed (extra
        // fields are written only when their column exists, see Schema::hasColumn
        // below), never 500 silently.
        try {
            self::ensureEmployeeColumns();
        } catch (\Throwable $e) {
        }
        try {
            if (Schema::hasTable('employees')) {
                foreach (['whatsapp', 'address', 'dob', 'doj'] as $c) {
                    if (! Schema::hasColumn('employees', $c)) {
                        Schema::table('employees', function (Blueprint $t) use ($c) {
                            $t->string($c)->nullable();
                        });
                    }
                }
            }
        } catch (\Throwable $e) {
        }

        $count = 0;
        $skipped = 0;
        $errors = [];
        foreach ($lines as $line) {
            try {
            $cells = str_getcsv($line);
            if (count(array_filter($cells, fn ($c) => trim((string) $c) !== '')) === 0) {
                continue;
            }
            // rev173g — tolerate rows with EXTRA cells (an unquoted comma in the
            // address was enough): pad AND slice to the header width. Previously
            // array_combine threw and the whole import died mid-file with no message.
            $cells = array_slice(array_pad($cells, count($header), null), 0, count($header));
            $row = array_combine($header, $cells);
            $row = array_map(fn ($v) => is_string($v) ? trim($v) : $v, $row); // rev173g — trim every cell
            $row = self::stripHtmlDeep($row); // rev172 (H3) — sanitise imported free-text against stored XSS
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                $skipped++;

                continue;
            }
            // 27 Aug 2026 (Ejaz) — "it still generates automatic Employee IDs even
            // though the IDs are in the file". This importer only ever looked for
            // `emp_code` / `code`, but the sample template's first column is
            // EMPLOYEE CODE, which this header normaliser turns into
            // `employee_code` — so the lookup ALWAYS missed and every row got a
            // random EMP-xxxx. Accept every spelling the template / export /
            // wizard use before falling back to a generated code.
            $codeRaw = '';
            foreach (['emp_code', 'employee_code', 'employeecode', 'code', 'employee_id', 'emp_id', 'employeeid', 'empid'] as $ck) {
                if (trim((string) ($row[$ck] ?? '')) !== '') {
                    $codeRaw = trim((string) $row[$ck]);
                    break;
                }
            }
            $code = $codeRaw ?: ('EMP-'.random_int(1000, 9999));
            $type = stripos((string) ($row['type'] ?? ''), 'field') !== false ? 'field' : 'office';

            $payload = [
                'tenant_id' => $tenantId, 'company_id' => $companyId, 'emp_code' => $code, 'name' => $name,
                'type' => $type, 'ctc' => (float) ($row['ctc'] ?? 0),
                'salary_type' => $salaryMap[strtolower(trim((string) ($row['salary_type'] ?? 'salary')))] ?? 'only_salary',
                'mobile' => $row['mobile'] ?? null, 'email' => $row['email'] ?? null, 'pan' => $row['pan'] ?? null,
                'uan' => $row['uan'] ?? ($row['pf_number'] ?? ($row['pf'] ?? null)),
                'bank_acc' => $row['bank_acc'] ?? null, 'ifsc' => $row['ifsc'] ?? null, 'updated_at' => now(),
            ];
            // rev149 — richer fields from the full template (only set when present;
            // the columns were ensured above, so these are schema-safe).
            // rev173g — normalise Indian date formats to Y-m-d so payroll/reports
            // never misread d/m as m/d.
            // 2026-08-05 — WIDENED: the dob/doj columns are real DATE columns, so an
            // unrecognised shape stored "as-is" was rejected by MySQL and the date
            // silently ended up NULL (Ejaz's "bulk upload date is not set" bug).
            // Now also accepts 2024/05/13, 13.05.2024, 2-digit years, "13-May-2024",
            // Excel serial numbers, and falls back to strtotime; a value that still
            // can't be read returns null (and the row keeps importing) instead of
            // feeding MySQL an invalid literal.
            $normDate = [self::class, 'normDateWide'];
            $extra = [
                'department' => trim((string) ($row['department'] ?? '')) ?: null,
                'designation' => trim((string) ($row['designation'] ?? '')) ?: null,
                'branch' => trim((string) ($row['branch'] ?? '')) ?: null,
                'team' => trim((string) ($row['team'] ?? '')) ?: null,
                'shift' => trim((string) ($row['shift'] ?? ($row['working_shift'] ?? ''))) ?: null,   // rev173g
                'whatsapp' => trim((string) ($row['whatsapp'] ?? ($row['whatsapp_number'] ?? ''))) ?: null,
                'address' => trim((string) ($row['address'] ?? '')) ?: null,
                // 7 Aug 2026 test report (item 2 / 10c) — personal + bank fields the sample file now carries.
                'gender' => trim((string) ($row['gender'] ?? '')) ?: null,
                'father' => trim((string) ($row['father'] ?? ($row['father_name'] ?? ($row["father's_name"] ?? '')))) ?: null,
                'mother' => trim((string) ($row['mother'] ?? ($row['mother_name'] ?? ($row["mother's_name"] ?? '')))) ?: null,
                'spouse' => trim((string) ($row['spouse'] ?? ($row['spouse_name'] ?? ''))) ?: null,
                'marital_status' => trim((string) ($row['marital_status'] ?? ($row['marital'] ?? ''))) ?: null,
                'blood_group' => trim((string) ($row['blood_group'] ?? ($row['blood'] ?? ''))) ?: null,
                'national_id' => trim((string) ($row['national_id'] ?? ($row['ssn'] ?? ($row['government_id'] ?? ($row['govt_id'] ?? ''))))) ?: null,
                'bank_name' => trim((string) ($row['bank_name'] ?? ($row['bank'] ?? ''))) ?: null,
                'bank_branch' => trim((string) ($row['bank_branch'] ?? ($row['branch_name'] ?? ''))) ?: null,
                'dob' => $normDate($row['dob'] ?? ($row['date_of_birth'] ?? '')),
                'doj' => $normDate($row['doj'] ?? ($row['date_of_joining'] ?? ($row['joining_date'] ?? ''))),
                // Biometric Mapping — the person's ID on the attendance device.
                'device_user_id' => trim((string) ($row['biometric_id'] ?? ($row['device_user_id'] ?? ($row['bio_id'] ?? '')))) ?: null,
            ];
            // Surface (don't skip) a date the parser could not read — otherwise the
            // import "succeeds" while the DOJ/DOB quietly stays empty.
            foreach ([['doj', 'DOJ'], ['dob', 'DOB']] as [$dk, $dl]) {
                $rawD = trim((string) ($row[$dk] ?? ''));
                if ($rawD !== '' && $extra[$dk] === null) {
                    $errors[] = 'Row "'.$name.'" ('.$code.'): '.$dl.' "'.$rawD.'" not understood - use DD/MM/YYYY or YYYY-MM-DD. Imported without it.';
                }
            }
            foreach ($extra as $k => $v) {
                if ($v !== null && Schema::hasColumn('employees', $k)) {
                    $payload[$k] = $v;
                }
            }

            // F4 (decision #1, 26 Jul 2026): DPA/DRA + PCC declaration as Yes/No
            // upload columns -> the EXISTING dra_pcc_declared boolean (no new
            // field). Header matched tolerantly (dpa | dra | combined dra_pcc,
            // and pcc); blank = leave unchanged; any non Yes/No value raises a
            // per-row error and the row is skipped.
            $findCol = function ($row, array $tokens) {
                foreach ($row as $k => $v) {
                    foreach ($tokens as $t) {
                        if (strpos((string) $k, $t) !== false) {
                            return $v;
                        }
                    }
                }
                return null;
            };
            $yn = function ($raw) {
                $z = strtolower(trim((string) $raw));
                if ($z === '') { return null; }
                if (in_array($z, ['yes', 'y', 'true', '1'], true)) { return true; }
                if (in_array($z, ['no', 'n', 'false', '0'], true)) { return false; }
                return 'invalid';
            };
            $dpaRaw = $findCol($row, ['dpa', 'dra']);
            $pccRaw = $findCol($row, ['pcc']);
            $dpa = $yn($dpaRaw);
            $pcc = $yn($pccRaw);
            if ($dpa === 'invalid' || $pcc === 'invalid') {
                $bad = $dpa === 'invalid' ? ("DPA='" . trim((string) $dpaRaw) . "'") : ("PCC='" . trim((string) $pccRaw) . "'");
                $errors[] = 'Row "' . $name . '" (' . $code . '): ' . $bad . ' - use Yes or No.';
                $skipped++;
                continue;
            }
            if (($dpa !== null || $pcc !== null) && Schema::hasColumn('employees', 'dra_pcc_declared')) {
                $vals = array_values(array_filter([$dpa, $pcc], fn ($x) => $x !== null));
                $payload['dra_pcc_declared'] = (bool) array_product(array_map(fn ($x) => $x ? 1 : 0, $vals));
            }
            // Resolve the Company name to a company_id within this tenant.
            $compName = trim((string) ($row['company'] ?? ''));
            if ($compName !== '') {
                $cid = DB::table('companies')
                    ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
                    ->whereNull('deleted_at')->whereRaw('LOWER(name) = ?', [strtolower($compName)])->value('id');
                if ($cid) {
                    $payload['company_id'] = $cid;
                }
            }

            $existing = DB::table('employees')->where('tenant_id', $tenantId)->where('emp_code', $code)->first();

            // 28 Aug 2026 (Ejaz) — the same uniqueness gate the Employee form,
            // the import wizard and Self-Onboarding now use. This legacy
            // one-shot importer wrote straight through on emp_code alone, so a
            // single CSV could give ten people the same PAN or the same
            // Biometric ID. The row is reported and skipped; the rest still
            // import.
            $uniqErrs = \App\Services\EmployeeFieldRules::duplicateErrors($payload, $tenantId, $existing->id ?? null);
            if ($uniqErrs) {
                $errors[] = 'Row "'.$name.'" ('.$code.'): '.reset($uniqErrs);
                $skipped++;

                continue;
            }

            if ($existing) {
                DB::table('employees')->where('id', $existing->id)->update($payload);
            } else {
                // SEAT LIMIT (rev 75): stop importing NEW rows once the subscribed
                // seat count is reached (updates to existing employees still apply).
                $seat = \App\Services\SubscriptionService::canAddEmployees($user->tenant_id ? (int) $user->tenant_id : null, 1);
                if (! $seat['ok']) {
                    return response()->json([
                        'ok' => $count > 0,
                        'count' => $count,
                        'error' => 'Imported '.$count.' row(s), then stopped. '.$seat['error'],
                    ], $count > 0 ? 200 : 422);
                }
                $payload['uuid'] = (string) Str::uuid();
                $payload['status'] = 'active';
                $payload['created_at'] = now();
                DB::table('employees')->insert($payload);
            }

            // rev149 — optional self-service login from the template's password
            // column. Guarded so a login problem can never break the import.
            $pwd = trim((string) ($row['password'] ?? ''));
            $loginEmail = trim((string) ($row['email'] ?? ''));
            if ($pwd !== '' && $loginEmail !== '' && Schema::hasTable('users')) {
                try {
                    $u = DB::table('users')->whereRaw('LOWER(email) = ?', [strtolower($loginEmail)])
                        ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))->first();
                    if ($u) {
                        DB::table('users')->where('id', $u->id)->update(['password' => bcrypt($pwd), 'updated_at' => now()]);
                    } else {
                        $uid = DB::table('users')->insertGetId([
                            'tenant_id' => $tenantId, 'name' => $name, 'email' => $loginEmail,
                            'password' => bcrypt($pwd), 'status' => 'active',
                            'created_at' => now(), 'updated_at' => now(),
                        ]);
                        try {
                            $um = \App\Models\User::find($uid);
                            if ($um && method_exists($um, 'syncRoles')) {
                                $um->syncRoles(['employee']);
                            }
                        } catch (\Throwable $e) {
                        }
                    }
                } catch (\Throwable $e) {
                }
            }
            $count++;
            } catch (\Throwable $rowErr) {
                // rev173g — one malformed row must never abort the whole import.
                $skipped++;
            }
        }

        return response()->json([
            'ok' => true,
            'count' => $count,
            'skipped' => $skipped,   // rev173g — surfaced so bad rows are visible, not silent
            'errors' => $errors,   // F4 — per-row DPA/PCC validation messages
        ]);
    }

    /**
     * rev 82c: bulk SOFT delete (Ejaz — select/bulk-select on ID Cards).
     * Admin only; deleted_at is set so history (payslips, attendance, ledgers)
     * stays intact while the person disappears from every screen and the seat
     * count frees up. Real exits should use Exit & FnF.
     */
    public function bulkDeleteEmployees(Request $request)
    {
        if ($deny = ApprovalService::denyUnlessRole($request, ['admin'])) {
            return $deny;
        }
        try {
            $v = $request->validate(['codes' => ['required', 'array', 'min:1', 'max:500'], 'codes.*' => ['string']]);
            $tid = $request->user()->tenant_id;
            $n = DB::table('employees')
                ->when($tid, fn ($q) => $q->where('tenant_id', $tid))
                ->whereIn('emp_code', $v['codes'])
                ->whereNull('deleted_at')
                ->update(['deleted_at' => now(), 'updated_at' => now()]);

            return response()->json(['ok' => true, 'deleted' => $n, 'message' => $n.' employee record(s) removed (history kept, seats freed).']);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['ok' => false, 'error' => collect($e->errors())->flatten()->first()], 422);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /** Download a CSV import template. */
    /**
     * rev190 (item C) — Employee import sample as a native .xlsx with in-cell
     * DROPDOWNS: Type / Department / Designation / Branch / Team / Shift (from the
     * tenant's masters) and Gender / Marital / Blood group / Salary type / DRA /
     * PCC (fixed lists). ALL-CAPS headers, DD-MM-YYYY date hints, Present +
     * Permanent Address (matches Self-Onboarding), Bank Account Holder before
     * Account Number, ESIC / Category / Emergency contact columns. Built with
     * plain ZipArchive — no external library; falls back to CSV if zip is absent.
     */
    public function employeeTemplate(Request $request)
    {
        $tenantId = optional($request->user())->tenant_id;

        // 28 Aug 2026 (Ejaz) — the headers, the sample rows and every dropdown
        // below are now DERIVED from App\Services\EmployeeFieldRules::FIELDS,
        // the one place employee fields are declared. They used to be a
        // hand-kept list here, a second hand-kept list in the Employee form, a
        // third in EmployeeExportController::MAP and a fourth in
        // EmployeeImportService::FIELDS — which is how the file came to carry
        // CATEGORY with no matching form field, and the form came to carry
        // Identification Marks with no matching column.
        //
        // DEFAULT PASSWORD is the one column the export deliberately never
        // contains (it is the person's first-time ESS login password), so it is
        // appended here rather than declared in the registry. OPTIONAL — leave
        // it blank and no login is created; fill it and the employee signs in
        // with their EMAIL + that password.
        $rules = \App\Services\EmployeeFieldRules::class;
        $headers = array_merge($rules::headers(), [$rules::PASSWORD_COLUMN]);

        $sample = [];
        foreach ($rules::sampleRows() as $i => $row) {
            $row[] = $rules::PASSWORD_SAMPLE[$i] ?? '';
            $sample[] = $row;
        }

        // Dynamic dropdown sources from the tenant's masters (names only).
        $names = function (string $table) use ($tenantId): array {
            try {
                if (! Schema::hasTable($table)) {
                    return [];
                }

                return DB::table($table)
                    ->when(Schema::hasColumn($table, 'deleted_at'), fn ($q) => $q->whereNull('deleted_at'))
                    ->when($tenantId && Schema::hasColumn($table, 'tenant_id'), fn ($q) => $q->where('tenant_id', $tenantId))
                    ->orderBy('name')->pluck('name')->filter(fn ($n) => trim((string) $n) !== '')->unique()->values()->all();
            } catch (\Throwable $e) {
                return [];
            }
        };
        // One hidden "Lists" column per dynamic source the registry references,
        // so a field declared with 'src' => '@teams' gets a real in-cell
        // dropdown of that tenant's teams without anyone maintaining a second
        // list here.
        $sources = [
            '@companies' => ['header' => 'COMPANIES', 'values' => $names('companies')],
            '@departments' => ['header' => 'DEPARTMENTS', 'values' => $names('departments')],
            '@designations' => ['header' => 'DESIGNATIONS', 'values' => $names('designations')],
            '@branches' => ['header' => 'BRANCHES', 'values' => $names('branches')],
            '@teams' => ['header' => 'TEAMS', 'values' => $names('teams')],
            // REPORTING MANAGER / TEAM LEADER are employee names — offering the
            // existing directory stops the two most-mistyped columns in the file.
            '@employees' => ['header' => 'EMPLOYEES', 'values' => $names('employees')],
            '@shifts' => ['header' => 'SHIFTS', 'values' => $names('shifts')],
            '@schedules' => ['header' => 'SALARY SCHEDULES', 'values' => $names('salary_schedules')],
            '@ptStates' => ['header' => 'PT STATES', 'values' => $rules::PT_STATES],
        ];
        $lists = array_values($sources);
        $srcCol = array_flip(array_keys($sources));

        $idx = array_flip($headers);
        $listRef = fn (int $listCol, int $count) => 'Lists!$'.self::tplColLetter($listCol + 1).'$2:$'
            .self::tplColLetter($listCol + 1).'$'.max(2, $count + 1);

        // Dropdowns, straight off the registry: a fixed option list becomes an
        // in-cell list, a dynamic source becomes a reference into the Lists
        // sheet. The file can no longer offer a value the form does not, which
        // is what made "Only Salary" vs "Salary" possible.
        $validations = [];
        foreach ($rules::FIELDS as $f) {
            if (! isset($idx[$f['h']])) {
                continue;
            }
            if (! empty($f['o'])) {
                $validations[] = ['col' => $idx[$f['h']], 'inline' => implode(',', $f['o'])];
            } elseif (! empty($f['src']) && isset($sources[$f['src']])) {
                // Only wire a master dropdown when that master actually has rows.
                $cnt = count($sources[$f['src']]['values']);
                if ($cnt > 0) {
                    $validations[] = ['col' => $idx[$f['h']], 'ref' => $listRef($srcCol[$f['src']], $cnt)];
                }
            }
        }

        $fname = 'smartprs-employee-import-template';
        if (class_exists(\ZipArchive::class)) {
            $bytes = self::buildEmployeeXlsx($headers, $sample, $validations, $lists);
            if ($bytes !== null) {
                return response($bytes, 200, [
                    'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    'Content-Disposition' => 'attachment; filename="'.$fname.'.xlsx"',
                    'Content-Length' => (string) strlen($bytes),
                ]);
            }
        }

        // CSV fallback (no dropdowns possible in CSV, but every column is present).
        $csvLine = function (array $vals): string {
            return implode(',', array_map(function ($v) {
                $v = (string) $v;

                return preg_match('/[",\r\n]/', $v) ? '"'.str_replace('"', '""', $v).'"' : $v;
            }, $vals))."\r\n";
        };
        $csv = "\xEF\xBB\xBF".$csvLine($headers);
        foreach ($sample as $row) {
            $csv .= $csvLine($row);
        }

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$fname.'.csv"',
        ]);
    }

    /** A→Z, AA→… column letter from a 1-based index. */
    private static function tplColLetter(int $n): string
    {
        $s = '';
        while ($n > 0) {
            $m = ($n - 1) % 26;
            $s = chr(65 + $m).$s;
            $n = intdiv($n - 1, 26);
        }

        return $s;
    }

    /** Inline-string sheetData rows from a 2-D array. */
    private static function tplSheetRows(array $rows): string
    {
        $esc = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES | ENT_XML1, 'UTF-8');
        $xml = '';
        $rn = 0;
        foreach ($rows as $cells) {
            $rn++;
            $cx = '';
            $cn = 0;
            foreach ($cells as $val) {
                $cn++;
                $cx .= '<c r="'.self::tplColLetter($cn).$rn.'" t="inlineStr"><is><t xml:space="preserve">'.$esc($val).'</t></is></c>';
            }
            $xml .= '<row r="'.$rn.'">'.$cx.'</row>';
        }

        return $xml;
    }

    /**
     * Two-sheet .xlsx: "Employees" (headers + samples + list dropdowns) and a
     * hidden "Lists" sheet holding the dynamic dropdown sources. Returns the file
     * bytes, or null if the zip could not be assembled (caller falls back to CSV).
     */
    private static function buildEmployeeXlsx(array $headers, array $sampleRows, array $validations, array $lists): ?string
    {
        $lastCol = self::tplColLetter(max(1, count($headers)));
        $dataRows = 1 + count($sampleRows);
        $dim = 'A1:'.$lastCol.max(1, $dataRows);

        $allRows = array_merge([$headers], $sampleRows);
        $dvXml = '';
        if ($validations) {
            $dvXml = '<dataValidations count="'.count($validations).'">';
            foreach ($validations as $v) {
                $colL = self::tplColLetter($v['col'] + 1);
                $f1 = isset($v['inline']) ? '"'.$v['inline'].'"' : $v['ref'];
                $dvXml .= '<dataValidation type="list" allowBlank="1" showInputMessage="1" showErrorMessage="1" sqref="'.$colL.'2:'.$colL.'1000">'
                    .'<formula1>'.htmlspecialchars($f1, ENT_QUOTES | ENT_XML1, 'UTF-8').'</formula1></dataValidation>';
            }
            $dvXml .= '</dataValidations>';
        }
        $sheet1 = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<dimension ref="'.$dim.'"/>'
            .'<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            .'<sheetData>'.self::tplSheetRows($allRows).'</sheetData>'
            .'<autoFilter ref="'.$dim.'"/>'.$dvXml.'</worksheet>';

        $maxLen = 0;
        foreach ($lists as $l) {
            $maxLen = max($maxLen, count($l['values']));
        }
        $listRows = [array_map(fn ($l) => $l['header'], $lists)];
        for ($i = 0; $i < $maxLen; $i++) {
            $row = [];
            foreach ($lists as $l) {
                $row[] = $l['values'][$i] ?? '';
            }
            $listRows[] = $row;
        }
        $sheet2 = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<sheetData>'.self::tplSheetRows($listRows).'</sheetData></worksheet>';

        $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'</Types>';
        $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>';
        $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets><sheet name="Employees" sheetId="1" r:id="rId1"/><sheet name="Lists" sheetId="2" state="hidden" r:id="rId2"/></sheets></workbook>';
        $workbookRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/>'
            .'</Relationships>';

        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new \ZipArchive();
        if ($zip->open($tmp, \ZipArchive::OVERWRITE) !== true) {
            @unlink($tmp);

            return null;
        }
        $zip->addFromString('[Content_Types].xml', $contentTypes);
        $zip->addFromString('_rels/.rels', $rels);
        $zip->addFromString('xl/workbook.xml', $workbook);
        $zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRels);
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheet1);
        $zip->addFromString('xl/worksheets/sheet2.xml', $sheet2);
        $zip->close();
        $bytes = @file_get_contents($tmp);
        @unlink($tmp);

        return $bytes === false ? null : $bytes;
    }

    /** Download a branded PDF payslip for an employee (computed from CTC). */
    public function payslipPdf(Request $request, string $code)
    {
        // Scope: a company user is limited to their tenant; a super-admin (null
        // tenant_id) can resolve an employee in ANY tenant/company — don't fall
        // back to "first tenant only", which 404s for everyone else's staff.
        $userTenant = $request->user()->tenant_id;
        $e = DB::table('employees')->where('emp_code', $code)
            ->when($userTenant, fn ($q) => $q->where('tenant_id', $userTenant))
            ->first();   // rev183c — include archived/deleted so Old-data payslip links work (self-scope 403 still applies)
        // rev175 — an employee may download only their OWN payslip.
        $selfScope = self::selfScope($request);
        if ($selfScope && $selfScope['code'] !== $code) {
            abort(403, 'You can only access your own payslip.');
        }

        if (! $e) {
            // Friendly message instead of a blank 404 page. Most common cause:
            // the Directory is still showing prototype demo rows that were never
            // saved to the database (run the seeder / add the employee for real).
            return response(
                'Payslip not available: employee "'.e($code).'" was not found in the database. '
                .'If you can see them in the Directory but this fails, their record is demo/sample data that has not been saved yet — '
                .'add them via Add Employee (or run the demo seeder) and try again.',
                404
            )->header('Content-Type', 'text/plain; charset=utf-8');
        }

        $company = DB::table('companies')->find($e->company_id);
        $month = $request->query('month', now()->format('Y-m'));
        $rates = SettingsController::rates($e->tenant_id);

        // rev172 — non-HR users may download ONLY their own payslip, and only if
        // the company's payslip download policy allows it (Payslips → Download
        // Policy). HR/Admin can always download (and email) any payslip.
        $user = $request->user();
        if (! $user->hasAnyRole(['super_admin', 'admin', 'hr_manager'])) {
            $own = ! empty($user->employee_id)
                ? ((int) $user->employee_id === (int) $e->id)
                : (($e->email ?? '') !== '' && strcasecmp((string) $user->email, (string) $e->email) === 0)
                    || strcasecmp((string) $user->name, (string) $e->name) === 0;
            if (! $own) {
                return response('You can only download your own payslip.', 403)
                    ->header('Content-Type', 'text/plain; charset=utf-8');
            }
            if (! SettingsController::payslipSelfAllowed($e, $rates)) {
                return response('Payslip download is disabled by your company\'s policy. Please contact HR for a copy.', 403)
                    ->header('Content-Type', 'text/plain; charset=utf-8');
            }
        }

        $data = $this->payslipViewData($e, $company, $month, $rates);

        return \Barryvdh\DomPDF\Facade\Pdf::loadView('payslip-pdf', $data)
            ->setPaper('a4', 'portrait')
            ->download('payslip-'.$code.'-'.$month.'.pdf');
    }

    /**
     * Render the payslip PDF for an employee id + month as raw bytes, for emailing
     * (e.g. attached on disbursement). Best-effort — returns null on any failure so
     * a mail problem never blocks the salary action.
     */
    public function payslipPdfString(int $employeeId, string $month): ?string
    {
        try {
            $e = DB::table('employees')->where('id', $employeeId)->whereNull('deleted_at')->first();
            if (! $e) {
                return null;
            }
            $company = DB::table('companies')->find($e->company_id);
            $rates = SettingsController::rates($e->tenant_id);
            $data = $this->payslipViewData($e, $company, $month, $rates);

            return \Barryvdh\DomPDF\Facade\Pdf::loadView('payslip-pdf', $data)
                ->setPaper('a4', 'portrait')->output();
        } catch (\Throwable $ex) {
            return null;
        }
    }

    /**
     * Build the full view-data array for the payslip PDF (prefers the stored slip
     * for the month; falls back to a full-CTC computation). Shared by the download
     * route and the email attachment. READ-ONLY — recomputes nothing stored.
     */
    private function payslipViewData($e, $company, string $month, array $rates): array
    {
        // Prefer the actual generated payslip for this month (it carries the real
        // proration, late/break cuts and the calculation note); fall back to a
        // full-CTC computation if no run exists yet.
        $slipRow = DB::table('payslips')
            ->where('employee_id', $e->id)->where('month', $month)
            ->orderByDesc('id')->first();
        $note = null;
        $earnMap = null;
        $dedMap = null;
        if ($slipRow) {
            $earn = json_decode($slipRow->earnings ?: '{}', true) ?: [];
            $ded = json_decode($slipRow->deductions ?: '{}', true) ?: [];
            $earnMap = $earn;
            $dedMap = $ded;
            $s = [
                'gross' => (float) $slipRow->gross,
                'basic' => (float) ($earn['Basic'] ?? 0),
                'hra' => (float) ($earn['HRA'] ?? 0),
                'special' => (float) ($earn['Special Allowance'] ?? 0),
                'commission' => (float) ($earn['Commission'] ?? 0),
                'pf' => (float) ($ded['PF'] ?? 0),
                'esi' => (float) ($ded['ESI'] ?? 0),
                'pt' => (float) ($ded['Professional Tax'] ?? 0),
                'tds' => (float) ($ded['TDS'] ?? 0),
                'total_ded' => (float) $slipRow->total_ded,
                'net' => (float) $slipRow->net,
            ];
            $note = Schema::hasColumn('payslips', 'calc_note') ? ($slipRow->calc_note ?? null) : null;
        } else {
            $s = self::computeSlip((float) $e->ctc, $rates, (string) ($e->employment_stage ?? ''));
        }

        // --- rev162 (Payslip Phase 1, DISPLAY-ONLY): richer, A4 payslip metadata.
        // Everything below is READ-ONLY and additive. It never changes net pay or
        // any stored figure; it only enriches what the PDF prints.
        $emp = $this->payslipEmployeeMeta($e);
        $employer = self::payslipEmployerCost($s, $rates);
        $payslipId = 'PRS/'.str_replace('-', '', $month).'/'.$e->emp_code;

        // Paid days / LOP — read from the calc note if it recorded proration,
        // otherwise assume a full month (no LOP).
        $totalDays = \Illuminate\Support\Carbon::parse($month.'-01')->daysInMonth;
        $paidDays = $totalDays;
        $lopDays = 0;
        if ($note && preg_match('/Paid\s+([\d.]+)\s+of\s+(\d+)\s+days/i', $note, $pm)) {
            $paidDays = (float) $pm[1];
            $totalDays = (int) $pm[2];
            $lopDays = round($totalDays - $paidDays, 1);
        }

        // Approved leave taken this financial-year, compact per type. Defensive.
        $leaveStr = '';
        try {
            if (Schema::hasTable('leaves')) {
                $yr = (int) substr($month, 0, 4);
                $lrows = DB::table('leaves')->where('employee_id', $e->id)
                    ->where('status', 'approved')->whereYear('from_date', $yr)
                    ->get(['type_name', 'days']);
                $agg = [];
                foreach ($lrows as $lr) {
                    $k = $lr->type_name ?: 'Leave';
                    $agg[$k] = ($agg[$k] ?? 0) + (float) $lr->days;
                }
                $parts = [];
                foreach ($agg as $k => $v) {
                    $parts[] = $k.' '.rtrim(rtrim(number_format($v, 1), '0'), '.');
                }
                $leaveStr = implode('  ·  ', $parts);
            }
        } catch (\Throwable $ex) {
            $leaveStr = '';
        }

        // --- P2 (Fixed/Variable/Reimbursement grouping) + P3 (financial-year-to-date).
        // DISPLAY-ONLY: read-only classification + aggregation; no figure is recomputed.
        $earnForGroup = $earnMap ?: array_filter([
            'Basic' => (float) ($s['basic'] ?? 0),
            'HRA' => (float) ($s['hra'] ?? 0),
            'Special Allowance' => (float) ($s['special'] ?? 0),
            'Commission' => (float) ($s['commission'] ?? 0),
        ], fn ($v) => $v != 0.0);
        $dedForShow = $dedMap ?: array_filter([
            'PF' => (float) ($s['pf'] ?? 0),
            'ESI' => (float) ($s['esi'] ?? 0),
            'Professional Tax' => (float) ($s['pt'] ?? 0),
            'TDS' => (float) ($s['tds'] ?? 0),
            'Labour Welfare Fund' => (float) ($s['lwf'] ?? 0),
            'Conveyance' => (float) ($s['conveyance'] ?? 0),
        ], fn ($v) => $v != 0.0);

        $ytd = $this->payslipYtd((int) $e->id, $month);
        $grouped = $this->payslipGroupEarnings($e, $earnForGroup, $ytd['earn'] ?? []);
        $dedLines = [];
        foreach ($dedForShow as $dn => $dv) {
            $dedLines[] = ['name' => $dn, 'amt' => (float) $dv, 'ytd' => (float) ($ytd['ded'][$dn] ?? 0)];
        }

        // F5 (rev182): if the run stored its window, show the real salary
        // period on the payslip — a 21st-to-20th cycle is otherwise
        // indistinguishable from a calendar month.
        $periodLabel = '';
        try {
            $ps = $slipRow->period_start ?? null;
            $pe = $slipRow->period_end ?? null;
            if ($ps && $pe) {
                $periodLabel = \App\Services\PeriodResolver::label(
                    \Illuminate\Support\Carbon::parse($ps),
                    \Illuminate\Support\Carbon::parse($pe)
                );
            }
        } catch (\Throwable $ePeriod) {
            $periodLabel = '';
        }

        return [
            'e' => $e,
            'company' => $company,
            'brand' => ConfigController::brandFor($e->tenant_id, $e->company_id),
            'month' => $month,
            'monthLabel' => \Illuminate\Support\Carbon::parse($month.'-01')->format('F Y'),
            'periodLabel' => $periodLabel,   // F5 — '21 Jun – 20 Jul 2026 (30 days)' or ''
            's' => $s,
            'note' => $note,
            'earnMap' => $earnMap,
            'dedMap' => $dedMap,
            'emp' => $emp,
            'employer' => $employer,
            'payslipId' => $payslipId,
            'paidDays' => $paidDays,
            'lopDays' => $lopDays,
            'totalDays' => $totalDays,
            'leaveStr' => $leaveStr,
            'grouped' => $grouped,
            'dedLines' => $dedLines,
            'ytd' => $ytd,
            'showYtd' => (int) ($rates['payslip_show_ytd'] ?? 1) !== 0, // rev179 — YTD column toggle (Payslip Policy)
            'netWords' => self::amountInWords((float) ($s['net'] ?? 0)),
            'payDate' => self::payDateFor($e->tenant_id ?? null, (string) ($company->name ?? ''), $month), // rev181b
        ];
    }

    /**
     * rev181b — resolve the PAY DATE for a company + run month from the Pay Cycle
     * / Salary Schedule masters. With a CUT-OFF day the attendance period ends
     * INSIDE the run month, so salary pays that SAME month on the pay day; a plain
     * calendar cycle only completes next month, so it pays the pay day of the
     * FOLLOWING month. Company-specific row beats an all/blank one. Fallback = the
     * run month's last day.
     */
    public static function payDateFor($tid, string $companyName, string $month): string
    {
        $cn = strtolower(trim($companyName));
        try {
            $payDay = 0; $cutoff = 0; $bestGeneric = 0; $bestGenericCut = 0;
            foreach (['salary_schedules', 'pay_cycles'] as $table) {
                if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'pay_day')) {
                    continue;
                }
                $rows = DB::table($table)
                    ->when($tid && Schema::hasColumn($table, 'tenant_id'), fn ($q) => $q->where('tenant_id', $tid))
                    ->when(Schema::hasColumn($table, 'status'), fn ($q) => $q->where(fn ($x) => $x->where('status', 'active')->orWhereNull('status')))
                    ->orderByDesc('id')->limit(50)->get();
                foreach ($rows as $row) {
                    $rcn = strtolower(trim((string) ($row->company_name ?? '')));
                    if ($rcn !== '' && $rcn !== 'all' && $rcn !== $cn) {
                        continue;
                    }
                    $pd = (int) ($row->pay_day ?? 0);
                    if ($pd < 1 || $pd > 28) {
                        continue;
                    }
                    $co = Schema::hasColumn($table, 'cutoff_day') ? (int) ($row->cutoff_day ?? 0) : 0;
                    if ($rcn !== '' && $rcn !== 'all') {
                        $payDay = $pd; $cutoff = $co;
                        break 2;
                    }
                    if ($bestGeneric === 0) { $bestGeneric = $pd; $bestGenericCut = $co; }
                }
            }
            if ($payDay === 0 && $bestGeneric > 0) { $payDay = $bestGeneric; $cutoff = $bestGenericCut; }
            if ($payDay >= 1 && $payDay <= 28) {
                $base = \Illuminate\Support\Carbon::createFromFormat('Y-m-d', $month.'-01');
                $d = ($cutoff >= 2 && $cutoff <= 28)
                    ? $base->day($payDay)                          // cut-off cycle -> pay this run month
                    : $base->addMonthNoOverflow()->day($payDay);   // calendar cycle -> pay next month
                return $d->toDateString();
            }
        } catch (\Throwable $e) {
        }

        return \Illuminate\Support\Carbon::createFromFormat('Y-m-d', $month.'-01')->endOfMonth()->toDateString();
    }

    /** "Rupees Twenty Thousand One Hundred One Only" (Indian numbering, incl. paise). */
    public static function amountInWords(float $amount): string
    {
        $amount = round(max($amount, 0), 2);
        $rupees = (int) floor($amount);
        $paise = (int) round(($amount - $rupees) * 100);
        $out = 'Rupees '.self::numToWordsIndian($rupees);
        if ($paise > 0) {
            $out .= ' and '.self::numToWordsIndian($paise).' Paise';
        }

        return $out.' Only';
    }

    /** Integer → words with Indian grouping (crore / lakh / thousand / hundred). */
    private static function numToWordsIndian(int $n): string
    {
        if ($n <= 0) {
            return 'Zero';
        }
        $ones = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
        $tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];
        $two = function (int $num) use ($ones, $tens) {
            if ($num < 20) {
                return $ones[$num];
            }
            $t = intdiv($num, 10);
            $o = $num % 10;

            return trim($tens[$t].($o ? ' '.$ones[$o] : ''));
        };
        $three = function (int $num) use ($ones, $two) {
            $h = intdiv($num, 100);
            $rest = $num % 100;
            $s = $h ? $ones[$h].' Hundred' : '';
            if ($rest) {
                $s .= ($s ? ' ' : '').$two($rest);
            }

            return $s;
        };
        $crore = intdiv($n, 10000000);
        $n %= 10000000;
        $lakh = intdiv($n, 100000);
        $n %= 100000;
        $thousand = intdiv($n, 1000);
        $hundred = $n % 1000;
        $parts = [];
        if ($crore) {
            $parts[] = $three($crore).' Crore';
        }
        if ($lakh) {
            $parts[] = $two($lakh).' Lakh';
        }
        if ($thousand) {
            $parts[] = $two($thousand).' Thousand';
        }
        if ($hundred) {
            $parts[] = $three($hundred);
        }

        return implode(' ', $parts);
    }

    /**
     * Resolve display names for the payslip header (designation / department /
     * branch / DOJ). READ-ONLY — used only by the PDF. Prefers a string column
     * if present, else looks up the *_id reference table; never throws.
     */
    private function payslipEmployeeMeta($e): array
    {
        $name = function (string $strCol, string $idCol, string $table) use ($e) {
            $v = property_exists($e, $strCol) ? trim((string) ($e->$strCol ?? '')) : '';
            if ($v !== '') {
                return $v;
            }
            $id = property_exists($e, $idCol) ? ($e->$idCol ?? null) : null;
            if ($id) {
                try {
                    return (string) (DB::table($table)->where('id', $id)->value('name') ?? '—');
                } catch (\Throwable $ex) {
                    return '—';
                }
            }
            return '—';
        };
        $doj = (property_exists($e, 'doj') && $e->doj)
            ? \Illuminate\Support\Carbon::parse($e->doj)->format('d M Y') : '—';

        return [
            'designation' => $name('designation', 'designation_id', 'designations'),
            'department' => $name('department', 'department_id', 'departments'),
            'branch' => $name('branch', 'branch_id', 'branches'),
            'doj' => $doj,
            'type' => ucfirst((string) ($e->type ?? '')),
        ];
    }

    /**
     * Indicative EMPLOYER cost for the CTC memo block (employer PF, employer ESI,
     * EDLI, gratuity accrual, and total monthly CTC). READ-ONLY and NEVER added
     * to employee deductions. Uses exact figures from computeSlip when available,
     * otherwise derives them from the stored employee PF/ESI so the memo always
     * stays consistent with the deductions actually shown.
     */
    public static function payslipEmployerCost(array $s, ?array $rates = null): array
    {
        $r = $rates ?: SettingsController::defaults();
        $basic = (float) ($s['basic'] ?? 0);
        $empPf = isset($s['pf_employer']) ? (float) $s['pf_employer'] : (float) ($s['pf'] ?? 0);
        $eeRate = (float) ($r['esi_employee_rate'] ?? 0.75);
        $erRate = (float) ($r['esi_employer_rate'] ?? 3.25);
        $empEsi = isset($s['esi_employer'])
            ? (float) $s['esi_employer']
            : ($eeRate > 0 ? round((float) ($s['esi'] ?? 0) * $erRate / $eeRate, 2) : 0.0);
        $edli = isset($s['pf_edli']) ? (float) $s['pf_edli'] : round(min($basic, 15000.0) * 0.5 / 100, 2);
        $gratuity = round($basic * 4.81 / 100, 2);
        $ctc = round((float) ($s['gross'] ?? 0) + $empPf + $empEsi + $edli + $gratuity, 2);

        return [
            'pf' => round($empPf, 2), 'esi' => round($empEsi, 2),
            'edli' => round($edli, 2), 'gratuity' => $gratuity, 'ctc' => $ctc,
        ];
    }

    /**
     * Financial-year-to-date (Apr→current month) totals per earning/deduction plus
     * gross/deductions/net, aggregated from stored payslips. READ-ONLY. Month keys
     * are 'YYYY-MM' (zero-padded) so lexicographic range compare is correct.
     */
    private function payslipYtd(int $empId, string $month): array
    {
        $out = ['earn' => [], 'ded' => [], 'gross' => 0.0, 'ded_total' => 0.0, 'net' => 0.0];
        try {
            $y = (int) substr($month, 0, 4);
            $mm = (int) substr($month, 5, 2);
            $fyStart = sprintf('%04d-04', $mm >= 4 ? $y : $y - 1);
            $rows = DB::table('payslips')->where('employee_id', $empId)
                ->where('month', '>=', $fyStart)->where('month', '<=', $month)
                ->get(['earnings', 'deductions', 'gross', 'total_ded', 'net']);
            foreach ($rows as $row) {
                $en = json_decode($row->earnings ?: '{}', true) ?: [];
                $dd = json_decode($row->deductions ?: '{}', true) ?: [];
                foreach ($en as $k => $v) {
                    $out['earn'][$k] = ($out['earn'][$k] ?? 0) + (float) $v;
                }
                foreach ($dd as $k => $v) {
                    $out['ded'][$k] = ($out['ded'][$k] ?? 0) + (float) $v;
                }
                $out['gross'] += (float) $row->gross;
                $out['ded_total'] += (float) $row->total_ded;
                $out['net'] += (float) $row->net;
            }
        } catch (\Throwable $ex) {
        }

        return $out;
    }

    /**
     * Classify earning lines into Fixed / Variable / Reimbursement for the grouped
     * payslip. Uses the component's Category (from salary_components) when set, else
     * a name heuristic. READ-ONLY. Returns groups[] + subtotals[].
     */
    private function payslipGroupEarnings($e, array $earnMap, array $ytdEarn): array
    {
        $catMap = [];
        try {
            $rows = DB::table('salary_components')
                ->when(property_exists($e, 'tenant_id') && $e->tenant_id, fn ($q) => $q->where('tenant_id', $e->tenant_id))
                ->get(['code', 'name', 'category']);
            foreach ($rows as $rc) {
                $cat = strtolower(trim((string) ($rc->category ?? '')));
                if ($cat === '') {
                    continue;
                }
                if (! empty($rc->name)) {
                    $catMap[strtolower(trim($rc->name))] = $cat;
                }
                if (! empty($rc->code)) {
                    $catMap[strtolower(trim($rc->code))] = $cat;
                }
            }
        } catch (\Throwable $ex) {
        }
        $classify = function (string $name) use ($catMap) {
            $lc = strtolower(trim($name));
            if (isset($catMap[$lc]) && in_array($catMap[$lc], ['fixed', 'variable', 'reimbursement'], true)) {
                return $catMap[$lc];
            }
            if (str_contains($lc, 'reimburs')) {
                return 'reimbursement';
            }
            foreach (['commission', 'incentive', 'bonus', 'overtime', 'arrear', 'ex-gratia', 'ex gratia', 'payout', 'variable'] as $kw) {
                if (str_contains($lc, $kw)) {
                    return 'variable';
                }
            }
            return 'fixed';
        };
        $groups = ['fixed' => [], 'variable' => [], 'reimbursement' => []];
        $sub = ['fixed' => 0.0, 'variable' => 0.0, 'reimbursement' => 0.0];
        foreach ($earnMap as $name => $amt) {
            $g = $classify((string) $name);
            $groups[$g][] = ['name' => $name, 'amt' => (float) $amt, 'ytd' => (float) ($ytdEarn[$name] ?? 0)];
            $sub[$g] += (float) $amt;
        }

        return ['groups' => $groups, 'sub' => $sub];
    }

    /** Statutory reports (PF / ESI challans, TDS statement) as downloadable PDFs. */
    public function statutoryPdf(Request $request, string $type)
    {
        $tenantId = $request->user()->tenant_id ?? DB::table('tenants')->value('id');
        $rates = SettingsController::rates($request->user()->tenant_id);
        $emps = DB::table('employees')->where('tenant_id', $tenantId)->whereNull('deleted_at')->orderBy('emp_code')->get();
        $company = DB::table('companies')->where('tenant_id', $tenantId)->first();
        $period = now()->format('F Y');
        $n2 = fn ($v) => '₹'.number_format($v, 2);

        $rows = [];
        $sum = [];
        $addSum = function (&$sum, $k, $v) { $sum[$k] = ($sum[$k] ?? 0) + $v; };

        $esiEeRate = (float) $rates['esi_employee_rate'] / 100;
        $esiErRate = (float) $rates['esi_employer_rate'] / 100;
        $esiThreshold = (float) $rates['esi_threshold'];
        $commRate = (float) $rates['comm_tds_rate'] / 100;
        $noPanRate = (float) $rates['no_pan_tds_rate'] / 100;

        if ($type === 'pf') {
            $title = 'PF ECR / Challan';
            $subtitle = 'Provident Fund contributions (employee '.((float) $rates['pf_rate']).'% + employer '.((float) $rates['pf_rate']).'%)';
            $columns = [
                ['k' => 'code', 'l' => 'Code'], ['k' => 'name', 'l' => 'Employee'], ['k' => 'uan', 'l' => 'UAN (PF No.)'],
                ['k' => 'basic', 'l' => 'PF Wages', 'amt' => true],
                ['k' => 'ee', 'l' => 'Employee', 'amt' => true],
                ['k' => 'er', 'l' => 'Employer', 'amt' => true],
                ['k' => 'total', 'l' => 'Total', 'amt' => true],
            ];
            foreach ($emps as $e) {
                $s = self::computeSlip((float) $e->ctc, $rates, (string) ($e->employment_stage ?? ''));
                $wage = min($s['basic'], (float) $rates['pf_wage_cap']);
                $ee = $s['pf']; $er = $ee; $tot = $ee + $er;
                $addSum($sum, 'basic', $wage); $addSum($sum, 'ee', $ee); $addSum($sum, 'er', $er); $addSum($sum, 'total', $tot);
                $rows[] = ['code' => $e->emp_code, 'name' => $e->name, 'uan' => $e->uan ?: '—', 'basic' => $n2($wage), 'ee' => $n2($ee), 'er' => $n2($er), 'total' => $n2($tot)];
            }
        } elseif ($type === 'esi') {
            $title = 'ESIC Challan';
            $subtitle = 'ESI contributions (employee '.((float) $rates['esi_employee_rate']).'% + employer '.((float) $rates['esi_employer_rate']).'%, gross ≤ '.$n2($esiThreshold).')';
            $columns = [
                ['k' => 'code', 'l' => 'Code'], ['k' => 'name', 'l' => 'Employee'],
                ['k' => 'gross', 'l' => 'Gross', 'amt' => true],
                ['k' => 'ee', 'l' => 'Employee', 'amt' => true],
                ['k' => 'er', 'l' => 'Employer', 'amt' => true],
                ['k' => 'total', 'l' => 'Total', 'amt' => true],
            ];
            foreach ($emps as $e) {
                $s = self::computeSlip((float) $e->ctc, $rates, (string) ($e->employment_stage ?? ''));
                $elig = $s['gross'] <= $esiThreshold;
                $ee = $elig ? round($s['gross'] * $esiEeRate, 2) : 0;
                $er = $elig ? round($s['gross'] * $esiErRate, 2) : 0;
                $tot = $ee + $er;
                $addSum($sum, 'gross', $s['gross']); $addSum($sum, 'ee', $ee); $addSum($sum, 'er', $er); $addSum($sum, 'total', $tot);
                $rows[] = ['code' => $e->emp_code, 'name' => $e->name, 'gross' => $n2($s['gross']), 'ee' => $n2($ee), 'er' => $n2($er), 'total' => $n2($tot)];
            }
        } elseif ($type === 'commtds') {
            $title = 'Commission TDS — Deductee Register (Sec 194H)';
            $subtitle = 'Commission/brokerage TDS @ '.((float) $rates['comm_tds_rate']).'% (no-PAN @ '.((float) $rates['no_pan_tds_rate']).'%) — deductee-wise details for Form 26Q';
            $columns = [
                ['k' => 'code', 'l' => 'Code'], ['k' => 'name', 'l' => 'Deductee'], ['k' => 'pan', 'l' => 'PAN'],
                ['k' => 'section', 'l' => 'Section'],
                ['k' => 'comm', 'l' => 'Commission', 'amt' => true],
                ['k' => 'rate', 'l' => 'Rate'],
                ['k' => 'tds', 'l' => 'TDS Deducted', 'amt' => true],
                ['k' => 'net', 'l' => 'Net Paid', 'amt' => true],
            ];
            foreach ($emps as $e) {
                $st = self::SALARY_TYPE[$e->salary_type] ?? 'Salary';
                $annual = (float) $e->ctc;
                $commBase = $st === 'Commission' ? $annual : ($st === 'Salary + Commission' ? $annual * (((float) ($e->comm_pct ?? 0) ?: 30) / 100) : 0);
                if ($commBase <= 0) {
                    continue;
                }
                $effRate = $e->pan ? $commRate : max($commRate, $noPanRate);
                $tds = round($commBase * $effRate, 2);
                $addSum($sum, 'comm', $commBase); $addSum($sum, 'tds', $tds); $addSum($sum, 'net', $commBase - $tds);
                $rows[] = ['code' => $e->emp_code, 'name' => $e->name, 'pan' => $e->pan ?: 'No PAN', 'section' => '194H', 'comm' => $n2($commBase), 'rate' => round($effRate * 100, 2).'%', 'tds' => $n2($tds), 'net' => $n2($commBase - $tds)];
            }
        } else { // tds
            $title = 'TDS Statement (Form 24Q)';
            $subtitle = 'Salary TDS (Sec 192, new regime) + Commission TDS (Sec 194H @ '.((float) $rates['comm_tds_rate']).'%)';
            $columns = [
                ['k' => 'code', 'l' => 'Code'], ['k' => 'name', 'l' => 'Employee'], ['k' => 'paytype', 'l' => 'Pay Type'],
                ['k' => 'saltds', 'l' => 'Salary TDS (192)', 'amt' => true],
                ['k' => 'commtds', 'l' => 'Comm. TDS (194H)', 'amt' => true],
                ['k' => 'tax', 'l' => 'Total TDS', 'amt' => true],
                ['k' => 'monthly', 'l' => 'Monthly', 'amt' => true],
            ];
            foreach ($emps as $e) {
                $annual = (float) $e->ctc;
                $st = self::SALARY_TYPE[$e->salary_type] ?? 'Salary';
                $commBase = $st === 'Commission' ? $annual : ($st === 'Salary + Commission' ? $annual * (((float) ($e->comm_pct ?? 0) ?: 30) / 100) : 0);
                $salBase = max(0, $annual - $commBase);
                $salTds = self::newRegimeTax(max(0, $salBase - (float) $rates['std_deduction']), $rates);
                $effRate = $e->pan ? $commRate : max($commRate, $noPanRate);
                $commTds = round($commBase * $effRate, 2);
                $tax = round($salTds + $commTds, 2);
                $monthly = round($tax / 12, 2);
                $addSum($sum, 'saltds', $salTds); $addSum($sum, 'commtds', $commTds); $addSum($sum, 'tax', $tax); $addSum($sum, 'monthly', $monthly);
                $rows[] = ['code' => $e->emp_code, 'name' => $e->name, 'paytype' => $st, 'saltds' => $n2($salTds), 'commtds' => $n2($commTds), 'tax' => $n2($tax), 'monthly' => $n2($monthly)];
            }
        }

        $totals = ['name' => 'TOTAL ('.count($rows).')'];
        foreach ($sum as $k => $v) { $totals[$k] = $n2($v); }

        $brand = ConfigController::brandFor($request->user()->tenant_id, $company->id ?? null);
        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('statutory-pdf', compact('title', 'subtitle', 'columns', 'rows', 'totals', 'company', 'period', 'brand'));

        return $pdf->download(strtoupper($type).'-'.now()->format('Y-m').'.pdf');
    }

    /** New-regime annual income tax incl. 87A rebate and cess, from configurable slabs. */
    private static function newRegimeTax(float $taxable, ?array $rates = null): float
    {
        $r = $rates ?: SettingsController::defaults();
        if ($taxable <= (float) $r['rebate_87a_limit']) { return 0.0; } // 87A rebate
        $tax = 0; $prev = 0;
        foreach ($r['tds_slabs'] as $slab) {
            $upto = (float) ($slab['upto'] ?? 0);
            $rate = (float) ($slab['rate'] ?? 0) / 100;
            $cap = $upto > 0 ? $upto : PHP_INT_MAX;
            if ($taxable > $prev) { $tax += (min($taxable, $cap) - $prev) * $rate; $prev = $cap; }
            if ($upto <= 0) { break; }
        }

        return round($tax * (1 + (float) $r['cess_rate'] / 100), 2);
    }

    /** Quarter-wise TDS return (24Q) history with filing status. */
    private function tdsReturnsHistory($emps, ?array $rates = null): array
    {
        $r = $rates ?: SettingsController::defaults();
        $commRate = (float) $r['comm_tds_rate'] / 100;
        $noPanRate = (float) $r['no_pan_tds_rate'] / 100;
        $totalAnnual = 0; $deductees = 0;
        foreach ($emps as $e) {
            $annual = (float) $e->ctc;
            $st = self::SALARY_TYPE[$e->salary_type] ?? 'Salary';
            $commBase = $st === 'Commission' ? $annual : ($st === 'Salary + Commission' ? $annual * (((float) ($e->comm_pct ?? 0) ?: 30) / 100) : 0);
            $salTds = self::newRegimeTax(max(0, ($annual - $commBase) - (float) $r['std_deduction']), $r);
            $effRate = $e->pan ? $commRate : max($commRate, $noPanRate);
            $t = $salTds + round($commBase * $effRate, 2);
            if ($t > 0) { $deductees++; }
            $totalAnnual += $t;
        }
        $perQ = round($totalAnnual / 4, 2);
        $labels = [4 => 'Q1', 7 => 'Q2', 10 => 'Q3', 1 => 'Q4'];
        $out = [];
        $q = \Illuminate\Support\Carbon::now()->startOfQuarter();
        for ($i = 0; $i < 6; $i++) {
            $qs = $q->copy()->subMonths(3 * $i);
            $sm = (int) $qs->month; $y = (int) $qs->year;
            $label = $labels[$sm] ?? 'Q';
            $fy = $sm >= 4 ? $y.'-'.substr((string) ($y + 1), -2) : ($y - 1).'-'.substr((string) $y, -2);
            $due = match ($sm) {
                4 => \Illuminate\Support\Carbon::create($y, 7, 31),
                7 => \Illuminate\Support\Carbon::create($y, 10, 31),
                10 => \Illuminate\Support\Carbon::create($y + 1, 1, 31),
                default => \Illuminate\Support\Carbon::create($y, 5, 31),
            };
            $status = $i === 0 ? 'Pending' : 'Filed';
            $out[] = [
                'id' => '24Q-'.$fy.'-'.$label,
                'quarter' => $fy.' '.$label,
                'deductees' => $deductees,
                'taxDeducted' => $perQ,
                'deposited' => $status === 'Filed' ? $perQ : 0,
                'dueDate' => $due->format('d M Y'),
                'status' => $status,
            ];
        }

        return $out;
    }

    /** Indian monthly payroll breakdown from CTC, using configurable statutory rates. */
    /**
     * Estimated monthly salary TDS (Sec 192) under the New Regime, FY 2025-26:
     * ₹75,000 standard deduction, 87A rebate makes tax NIL up to ₹12,00,000
     * taxable, slabs 4/8/12/16/20/24L, 4% cess, with marginal relief just above
     * ₹12L. Input is the (projected) ANNUAL CTC; returns the MONTHLY TDS.
     * It is a good-faith estimate — actual TDS depends on the employee's
     * declarations/exemptions, which a payroll admin can still override.
     */
    public static function salaryTdsMonthly(float $annualCtc, ?array $rates = null): float
    {
        // rev165: drive standard deduction / 87A rebate / slabs / cess from the
        // admin-editable Statutory Settings instead of a hardcoded table that
        // ignored config and disagreed with SettingsController::defaults(). Shares
        // the slab + cess maths with newRegimeTax() so the payslip TDS and the 24Q
        // return always use the same numbers.
        $r = $rates ?: SettingsController::defaults();
        $std = (float) ($r['std_deduction'] ?? 0);
        $rebate = (float) ($r['rebate_87a_limit'] ?? 0);
        $taxable = max(0.0, $annualCtc - $std);
        $tax = self::newRegimeTax($taxable, $r); // 0 up to the rebate ceiling; slabs + cess above it
        if ($taxable > $rebate) {
            // Marginal relief: total tax (incl. cess) can't exceed the income
            // earned above the rebate ceiling.
            $tax = min($tax, ($taxable - $rebate) * (1 + (float) ($r['cess_rate'] ?? 0) / 100));
        }

        return round(max(0.0, $tax) / 12, 2);
    }

    /**
     * rev180 — built-in STATE-WISE Professional Tax monthly slab tables, keyed by
     * a normalised fragment of the state name. Indicative defaults (verify against
     * current state law before filing); the tenant's own pt_slabs override still
     * applies when the employee has NO pt_state set. States with no PT at all are
     * listed in PT_FREE_STATES.
     */
    private const PT_FREE_STATES = ['delhi', 'uttar pradesh', 'haryana', 'rajasthan', 'himachal', 'uttarakhand', 'chandigarh', 'jammu', 'ladakh', 'arunachal'];

    private const PT_STATE_SLABS = [
        'telangana' => [[15000, 0], [20000, 150], [0, 200]],
        'andhra' => [[15000, 0], [20000, 150], [0, 200]],
        'maharashtra' => [[7500, 0], [10000, 175], [0, 200]],   // + ₹300 in February (handled below)
        'karnataka' => [[24999, 0], [0, 200]],
        'west bengal' => [[10000, 0], [15000, 110], [25000, 130], [40000, 150], [0, 200]],
        'tamil nadu' => [[3500, 0], [5000, 23], [7500, 53], [10000, 115], [12500, 171], [0, 208]],  // half-yearly slabs shown as monthly equivalents
        'gujarat' => [[12000, 0], [0, 200]],
        'madhya pradesh' => [[18750, 0], [25000, 125], [33333, 167], [0, 208]],  // annual ₹2,500 cap spread monthly
        'kerala' => [[1999, 0], [2999, 20], [4999, 30], [7499, 50], [9999, 75], [12499, 100], [16666, 125], [20833, 166], [0, 208]],  // half-yearly slabs as monthly equivalents
        'bihar' => [[25000, 0], [41666, 83], [83333, 167], [0, 208]],
        'odisha' => [[13304, 0], [25000, 125], [0, 200]],
        'assam' => [[10000, 0], [15000, 150], [25000, 180], [0, 208]],
        'jharkhand' => [[25000, 0], [41666, 100], [66666, 150], [83333, 175], [0, 208]],
        'chhattisgarh' => [[13333, 0], [16666, 150], [20833, 180], [25000, 190], [0, 200]],
        'punjab' => [[20833, 0], [0, 200]],   // PSDT — ₹200/month for income-tax-payers
        'goa' => [[15000, 0], [25000, 150], [0, 200]],
    ];

    /**
     * The PT tables compiled into the engine, exposed read-only so the Statutory
     * Configuration screen can show what it is about to override instead of
     * duplicating the figures in a second place that then drifts.
     */
    public static function ptStateSlabs(): array
    {
        return self::PT_STATE_SLABS;
    }

    public static function ptFreeStates(): array
    {
        return self::PT_FREE_STATES;
    }

    /**
     * Walk one state's PT bands and return the monthly figure. Bands are
     * [gross upto, amount] with an "upto" of 0 meaning "everything above";
     * the first band the gross falls into wins.
     *
     * Maharashtra collects Rs 300 from the top band in February so the year
     * totals Rs 2,500 (11 x 200 + 300). That rule keys on the state, so it
     * applies to a configured Maharashtra slab exactly as to the built-in one.
     */
    private static function ptWalkSlab(array $rows, float $gross, string $key, ?string $month): float
    {
        $amt = 0.0;
        foreach ($rows as $row) {
            $row = is_array($row) ? array_values($row) : null;
            if (! $row || count($row) < 2) {
                continue;
            }
            $upto = (float) $row[0];
            if ($upto <= 0 || $gross <= $upto) {
                $amt = (float) $row[1];
                break;
            }
        }
        if ($key === 'maharashtra' && $amt >= 200 && $month && substr($month, 5, 2) === '02') {
            $amt = 300.0;
        }

        return round($amt, 2);
    }

    /**
     * Professional Tax — statutory MONTHLY slab on gross (not a flat amount).
     * rev180: STATE-AWARE. Resolution order:
     *   1. Employee's pt_state matches a built-in state table (incl. PT-free states → ₹0;
     *      Maharashtra February = ₹300 for the top slab).
     *   2. No/unknown state → the tenant's pt_slabs override, else the Telangana default.
     */
    public static function ptForGross(float $gross, array $r, ?string $state = null, ?string $month = null, ?string $gender = null): float
    {
        $st = strtolower(trim((string) $state));
        // Gender-based PT exemption. Under the Maharashtra State Tax on Professions
        // Act, women drawing a monthly gross up to ₹25,000 are exempt (PT = ₹0);
        // above that the normal slab applies. Configurable via pt_female_exempt
        // (default ON) and pt_female_exempt_upto (default ₹25,000). Applied BEFORE
        // the slab lookup so it overrides the state slab for eligible employees.
        $femaleExempt = ! array_key_exists('pt_female_exempt', $r) || (int) ($r['pt_female_exempt'] ?? 1) === 1;
        if ($femaleExempt) {
            $g = strtolower(trim((string) $gender));
            $isFemale = in_array($g, ['female', 'f', 'woman', 'women'], true);
            $femUpto = (float) ($r['pt_female_exempt_upto'] ?? 25000);
            if ($isFemale && $st !== '' && str_contains($st, 'maharashtra') && $gross <= $femUpto) {
                return 0.0;
            }
        }
        if ($st !== '') {
            // F1 — CONFIGURABLE STATE SLABS. `pt_state_slabs` (a map of
            // state => [[gross upto, monthly PT], ...]) lets HR correct a slab a
            // state has changed, or add a state that is not compiled in, without
            // a code release. An explicit slab for a state is an INSTRUCTION, so
            // it outranks the built-in PT-free list; that is how a state which
            // starts levying PT gets switched on. With no config present the
            // order below is exactly the original: free states, then built-ins.
            $custom = [];
            if (! empty($r['pt_state_slabs']) && is_array($r['pt_state_slabs'])) {
                foreach ($r['pt_state_slabs'] as $k => $rows) {
                    $k = mb_strtolower(trim((string) $k));
                    if ($k !== '' && is_array($rows) && $rows) {
                        $custom[$k] = $rows;
                    }
                }
            }
            foreach ($custom as $key => $rows) {
                if (str_contains($st, $key)) {
                    return self::ptWalkSlab($rows, $gross, $key, $month);
                }
            }

            $freeStates = self::PT_FREE_STATES;
            if (! empty($r['pt_free_states']) && is_array($r['pt_free_states'])) {
                foreach ($r['pt_free_states'] as $extra) {
                    $extra = mb_strtolower(trim((string) $extra));
                    if ($extra !== '') {
                        $freeStates[] = $extra;
                    }
                }
            }
            foreach ($freeStates as $free) {
                if (str_contains($st, $free)) {
                    return 0.0;
                }
            }

            foreach (self::PT_STATE_SLABS as $key => $rows) {
                if (str_contains($st, $key)) {
                    return self::ptWalkSlab($rows, $gross, $key, $month);
                }
            }
        }
        $slabs = $r['pt_slabs'] ?? null;
        if (! is_array($slabs) || ! $slabs) {
            $slabs = [
                ['upto' => 15000.0, 'amt' => 0.0],
                ['upto' => 20000.0, 'amt' => 150.0],
                ['upto' => PHP_FLOAT_MAX, 'amt' => 200.0],
            ];
        }
        foreach ($slabs as $s) {
            if ($gross <= (float) ($s['upto'] ?? PHP_FLOAT_MAX)) {
                return round((float) ($s['amt'] ?? 0), 2);
            }
        }
        return 0.0;
    }

    /**
     * rev180 — ESI CONTRIBUTION-PERIOD RULE. Under the ESI Act, eligibility is
     * fixed at the start of each contribution period (April–September and
     * October–March): an employee contributing at the period start KEEPS
     * contributing until the period ends even if wages cross the ₹21,000
     * ceiling mid-period. This helper answers "was ESI already being deducted
     * for this employee earlier in the CURRENT period?" from stored payslips.
     * Fail-soft: any problem returns false (plain threshold rule applies).
     */
    public static function esiPeriodLock(int $empId, string $month, array $r): bool
    {
        try {
            if ($empId <= 0 || ! Schema::hasTable('payslips')) {
                return false;
            }
            $y = (int) substr($month, 0, 4);
            $m = (int) substr($month, 5, 2);
            if ($m >= 4 && $m <= 9) {
                $periodStart = sprintf('%04d-04', $y);
            } elseif ($m >= 10) {
                $periodStart = sprintf('%04d-10', $y);
            } else {
                $periodStart = sprintf('%04d-10', $y - 1);
            }
            if ($month <= $periodStart) {
                return false;   // first month of the period → fresh determination
            }
            $rows = DB::table('payslips')->where('employee_id', $empId)
                ->where('month', '>=', $periodStart)
                ->where('month', '<', $month)
                ->orderByDesc('month')->limit(24)->get(['deductions']); // ≤5 months × possible lots/regenerated slips
            foreach ($rows as $row) {
                $ded = json_decode((string) ($row->deductions ?? ''), true) ?: [];
                foreach ($ded as $k => $v) {
                    if (stripos((string) $k, 'esi') !== false && (float) $v > 0) {
                        return true;
                    }
                }
            }

            return false;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Statutory PF/ESI/PT per government rules. Returns employee deductions
     * (pf, esi, pt) PLUS employer contributions for the ECR/challan (which are
     * NOT payslip deductions, so callers must not add them to total_ded).
     *  - PF: 12% of PF wage (Basic + DA), capped at the ₹15,000 wage ceiling.
     *        Employer 12% = EPS 8.33% (on wage capped ₹15,000, max ₹1,250) + EPF (3.67% balance);
     *        EDLI 0.5% (max ₹75) shown separately.
     *  - ESI: on GROSS when gross ≤ ₹21,000; employee 0.75% + employer 3.25%,
     *        each rounded UP to the next rupee (ESIC rule).
     *  - PT: monthly slab via ptForGross().
     */
    public static function statutory(float $gross, float $pfWage, array $r, array $ctx = []): array
    {
        // rev180 — optional context: pt_state (state-wise PT), month (Maharashtra
        // February PT), esi_lock (ESI contribution-period rule: keep deducting
        // past the ceiling until the Apr–Sep / Oct–Mar period ends).
        $pfCap = (float) ($r['pf_wage_cap'] ?? 15000);
        $pfRate = (float) ($r['pf_rate'] ?? 12);
        $pfBase = min(max($pfWage, 0.0), $pfCap);
        $epsBase = min($pfBase, 15000.0); // EPS/EDLI statutory wage ceiling is ₹15,000

        $pfEmployee = round($pfBase * $pfRate / 100, 2);
        $pfEmployer = round($pfBase * $pfRate / 100, 2);
        $eps = min(round($epsBase * 8.33 / 100, 2), 1250.0);
        $epfEmployer = round($pfEmployer - $eps, 2);
        $edli = round($epsBase * 0.5 / 100, 2);

        $esiThr = (float) ($r['esi_threshold'] ?? 21000);
        $esiEligible = ($gross > 0 && $gross <= $esiThr) || (! empty($ctx['esi_lock']) && $gross > 0);
        $esiEmployee = $esiEligible ? (float) ceil($gross * ((float) ($r['esi_employee_rate'] ?? 0.75)) / 100) : 0.0;
        $esiEmployer = $esiEligible ? (float) ceil($gross * ((float) ($r['esi_employer_rate'] ?? 3.25)) / 100) : 0.0;

        // Optional Conveyance deduction — SAME FORMULA AS PF: rate% of the PF wage
        // base (min(Basic + DA, cap)). Off unless enabled + rate > 0 in Statutory Settings.
        $convRate = (float) ($r['conveyance_rate'] ?? 0);
        $conveyance = (! empty($r['conveyance_enabled']) && $convRate > 0) ? round($pfBase * $convRate / 100, 2) : 0.0;

        return [
            'pf' => $pfEmployee, 'esi' => $esiEmployee, 'pt' => self::ptForGross($gross, $r, $ctx['pt_state'] ?? null, $ctx['month'] ?? null, $ctx['gender'] ?? null),
            'conveyance' => $conveyance,
            'pf_wage' => round($pfBase, 2),
            'pf_employer' => $pfEmployer, 'pf_eps' => $eps, 'pf_epf_employer' => $epfEmployer, 'pf_edli' => $edli,
            'esi_employer' => $esiEmployer,
        ];
    }

    public static function computeSlip(float $ctc, ?array $rates = null, string $stage = '', array $ctx = []): array
    {
        $r = $rates ?: SettingsController::defaults();
        // F1 — scoped statutory overrides (company / state / branch), effective
        // dated on the payroll period. Returns $r UNCHANGED when the install has
        // no override rows, which is the default state, so this cannot alter any
        // existing calculation.
        $r = \App\Services\StatutoryConfig::applyScope($r, $ctx);
        $gross = round($ctc / 12, 2);
        $basic = round($gross * 0.5, 2);
        $hra = round($basic * 0.4, 2);
        $special = round($gross - $basic - $hra, 2);
        // No salary components defined → PF wage falls back to the assumed Basic (50% of gross).
        $st = self::statutory($gross, $basic, $r, $ctx); // rev180 — pt_state / month / esi_lock context
        $pf = $st['pf'];
        $esi = $st['esi'];
        $pt = $st['pt'];
        $tds = self::salaryTdsMonthly($ctc, $r);
        // rev174 — Probation / Internship: no statutory PF, PT or TDS (ESI, LWF,
        // Conveyance and attendance loss-of-pay still apply). Deduction section is nil.
        if (in_array(strtolower(trim($stage)), ['probation', 'internship'], true)) {
            $pf = 0.0; $pt = 0.0; $tds = 0.0;
            foreach (['pf_wage', 'pf_employer', 'pf_eps', 'pf_epf_employer', 'pf_edli'] as $z) { $st[$z] = 0; }
        }
        // Optional Labour Welfare Fund (state-specific) — OFF unless enabled in Settings.
        $lwf = (! empty($r['lwf_enabled'])) ? (float) ($r['lwf_employee'] ?? 0) : 0.0;
        // Optional Conveyance deduction — computed like PF (rate% of capped Basic).
        $conveyance = (float) ($st['conveyance'] ?? 0);
        $totalDed = round($pf + $esi + $pt + $tds + $lwf + $conveyance, 2);

        return [
            'gross' => $gross, 'basic' => $basic, 'hra' => $hra, 'special' => $special, 'conveyance' => $conveyance,
            'pf' => $pf, 'esi' => $esi, 'pt' => $pt, 'tds' => $tds, 'lwf' => round($lwf, 2),
            'pf_wage' => $st['pf_wage'], 'pf_employer' => $st['pf_employer'], 'pf_eps' => $st['pf_eps'],
            'pf_epf_employer' => $st['pf_epf_employer'], 'pf_edli' => $st['pf_edli'], 'esi_employer' => $st['esi_employer'],
            'total_ded' => $totalDed, 'net' => round(max(0, $gross - $totalDed), 2), // rev172 (M6) — never a negative payslip
        ];
    }

    /**
     * Compute a payslip from a company's defined salary components instead of the
     * fixed Basic/HRA split. Monthly gross stays CTC/12; earning components split
     * it (a "balance" component absorbs the remainder), PF is computed on the
     * component Basic, and custom deduction components are subtracted on top of
     * statutory PF/ESI/PT. Returns the same keys as computeSlip() PLUS detailed
     * 'earnings' and 'deductions' maps. Returns null if there are no components
     * (caller falls back to computeSlip).
     *
     * Each component: ctype (earning|deduction), base (fixed | pct_gross |
     * pct_basic | balance), calc_value (amount or percent), seq (order).
     */
    public static function computeSlipFromComponents(float $ctc, $components, ?array $rates = null, string $stage = '', array $ctx = []): ?array
    {
        $comps = [];
        foreach ($components as $c) {
            $comps[] = (array) $c;
        }
        if (! $comps) {
            return null;
        }
        $r = $rates ?: SettingsController::defaults();
        // F1 — scoped statutory overrides (company / state / branch), effective
        // dated on the payroll period. Returns $r UNCHANGED when the install has
        // no override rows, which is the default state, so this cannot alter any
        // existing calculation.
        $r = \App\Services\StatutoryConfig::applyScope($r, $ctx);
        $gross = round($ctc / 12, 2);

        usort($comps, fn ($a, $b) => ((int) ($a['seq'] ?? 0)) <=> ((int) ($b['seq'] ?? 0)));

        $baseOf = function (array $c) {
            $b = strtolower(trim((string) ($c['base'] ?? '')));
            if ($b !== '') {
                return $b;
            }
            // Back-compat: infer from the old calc_type column.
            return strtolower(trim((string) ($c['calc_type'] ?? 'fixed'))) === 'percent' ? 'pct_gross' : 'fixed';
        };
        $val = fn (array $c) => (float) ($c['calc_value'] ?? 0);
        $isBasic = fn (array $c) => str_contains(strtolower((string) ($c['code'] ?? '').' '.(string) ($c['name'] ?? '')), 'basic');
        $nameOf = fn (array $c) => (string) (($c['name'] ?? '') ?: ($c['code'] ?? 'Component'));

        // A component is a REIMBURSEMENT when its category says so, or its name/code
        // clearly is one. Reimbursements are paid on top of the wage: they are carved
        // out of the "balance" and EXCLUDED from the statutory (PF/ESI/PT) wage base,
        // matching how bills-based reimbursements are treated. With none present the
        // maths is byte-identical to before (wage gross = gross).
        $isReimb = function (array $c) {
            $cat = strtolower(trim((string) ($c['category'] ?? '')));
            if ($cat === 'reimbursement') {
                return true;
            }
            $txt = strtolower((string) ($c['code'] ?? '').' '.(string) ($c['name'] ?? ''));
            return str_contains($txt, 'reimburs');
        };
        $allEarn = array_values(array_filter($comps, fn ($c) => ($c['ctype'] ?? 'earning') !== 'deduction'));
        $reimbComps = array_values(array_filter($allEarn, $isReimb));
        $earnComps = array_values(array_filter($allEarn, fn ($c) => ! $isReimb($c)));
        $dedComps = array_values(array_filter($comps, fn ($c) => ($c['ctype'] ?? '') === 'deduction'));

        // Resolve Basic first (so pct_basic components can reference it).
        $basic = 0.0;
        foreach ($earnComps as $c) {
            if ($isBasic($c)) {
                $base = $baseOf($c);
                $basic += $base === 'fixed' ? $val($c) : ($val($c) / 100 * $gross);
            }
        }

        // Reimbursement amounts (usually fixed; pct supported). Summed OUT of the
        // wage gross used for the balance split and statutory deductions.
        $reimbursements = [];
        $reimbTotal = 0.0;
        foreach ($reimbComps as $c) {
            $base = $baseOf($c);
            if ($base === 'pct_basic') {
                $amt = $val($c) / 100 * $basic;
            } elseif ($base === 'fixed') {
                $amt = $val($c);
            } else { // pct_gross / anything else
                $amt = $val($c) / 100 * $gross;
            }
            $amt = round($amt, 2);
            $reimbursements[$nameOf($c)] = ($reimbursements[$nameOf($c)] ?? 0) + $amt;
            $reimbTotal += $amt;
        }
        $wageGross = round($gross - $reimbTotal, 2);

        $earnings = [];
        $earnSum = 0.0;
        foreach ($earnComps as $c) {
            if ($baseOf($c) === 'balance') {
                continue; // handled after, absorbs the remainder
            }
            $base = $baseOf($c);
            if ($isBasic($c)) {
                $amt = $base === 'fixed' ? $val($c) : ($val($c) / 100 * $gross);
            } elseif ($base === 'pct_basic') {
                $amt = $val($c) / 100 * $basic;
            } elseif ($base === 'fixed') {
                $amt = $val($c);
            } else { // pct_gross / pct_ctc / anything else
                $amt = $val($c) / 100 * $gross;
            }
            $amt = round($amt, 2);
            $earnings[$nameOf($c)] = ($earnings[$nameOf($c)] ?? 0) + $amt;
            $earnSum += $amt;
        }

        // Balance components share the remainder so earnings reconcile to the WAGE
        // gross (gross minus reimbursements). With no reimbursements this is gross.
        $balanceComps = array_values(array_filter($earnComps, fn ($c) => $baseOf($c) === 'balance'));
        $remainder = round($wageGross - $earnSum, 2);
        if ($balanceComps) {
            $n = count($balanceComps);
            $each = round($remainder / $n, 2);
            foreach ($balanceComps as $i => $c) {
                $amt = $i === $n - 1 ? round($remainder - $each * ($n - 1), 2) : $each;
                $earnings[$nameOf($c)] = ($earnings[$nameOf($c)] ?? 0) + $amt;
                $earnSum += $amt;
            }
        } elseif (abs($remainder) >= 1) {
            // No balance component → reconcile via Special Allowance.
            $earnings['Special Allowance'] = round(($earnings['Special Allowance'] ?? 0) + $remainder, 2);
            $earnSum += $remainder;
        }
        if ($basic <= 0) {
            $basic = $earnSum > 0 ? (float) reset($earnings) : 0.0; // PF fallback: first earning
        }

        // Statutory deductions per govt rules: PF on Basic + DA (capped ₹15k),
        // ESI on gross (round-up), PT by slab.
        $da = 0.0;
        foreach ($earnings as $en => $ea) {
            $lc = strtolower((string) $en);
            if (str_contains($lc, 'dearness') || preg_match('/(^|[^a-z])da([^a-z]|$)/', $lc)) {
                $da += (float) $ea;
            }
        }
        $st = self::statutory($wageGross, $basic + $da, $r, $ctx); // rev180 — pt_state / month / esi_lock context
        $pf = $st['pf'];
        $esi = $st['esi'];
        $pt = $st['pt'];
        // rev180 — TDS on TAXABLE pay: reimbursement components are bills-based
        // and non-taxable, so the annualised WAGE gross (gross − reimbursements)
        // is the TDS base, not the full CTC. With no reimbursements this is
        // byte-identical to before (wageGross × 12 = ctc).
        $tds = self::salaryTdsMonthly(round($wageGross * 12, 2), $r);
        // rev174 — Probation / Internship: no statutory PF, PT or TDS (ESI, LWF,
        // Conveyance and attendance loss-of-pay still apply). Deduction section is nil.
        if (in_array(strtolower(trim($stage)), ['probation', 'internship'], true)) {
            $pf = 0.0; $pt = 0.0; $tds = 0.0;
            foreach (['pf_wage', 'pf_employer', 'pf_eps', 'pf_epf_employer', 'pf_edli'] as $z) { $st[$z] = 0; }
        }
        $deductions = ['PF' => $pf, 'ESI' => $esi, 'Professional Tax' => $pt, 'TDS' => $tds];
        foreach ($dedComps as $c) {
            $base = $baseOf($c);
            if ($base === 'pct_basic') {
                $amt = $val($c) / 100 * $basic;
            } elseif ($base === 'fixed') {
                $amt = $val($c);
            } else {
                $amt = $val($c) / 100 * $gross;
            }
            $deductions[$nameOf($c)] = round(($deductions[$nameOf($c)] ?? 0) + round($amt, 2), 2);
        }
        // Optional Labour Welfare Fund (state-specific) — OFF unless enabled in Settings.
        $lwf = (! empty($r['lwf_enabled'])) ? (float) ($r['lwf_employee'] ?? 0) : 0.0;
        if ($lwf > 0) {
            $deductions['Labour Welfare Fund'] = round(($deductions['Labour Welfare Fund'] ?? 0) + $lwf, 2);
        }
        // Optional Conveyance deduction — SAME FORMULA AS PF (rate% of capped Basic+DA).
        $conveyance = (float) ($st['conveyance'] ?? 0);
        if ($conveyance > 0) {
            $deductions['Conveyance'] = round(($deductions['Conveyance'] ?? 0) + $conveyance, 2);
        }
        $totalDed = round(array_sum($deductions), 2);

        // Legacy keys for the fixed-column preview (computed on wage earnings only,
        // before reimbursements are folded into the display map).
        $hra = 0.0;
        foreach ($earnings as $name => $amt) {
            if (stripos($name, 'hra') !== false || stripos($name, 'rent') !== false) {
                $hra += $amt;
            }
        }
        $special = round($earnSum - $basic - $hra, 2);

        // Fold reimbursements into the earnings map so they appear on the slip.
        // gross stays CTC/12 = wage earnings + reimbursements, so net = gross - deductions.
        foreach ($reimbursements as $rn => $ra) {
            $earnings[$rn] = round(($earnings[$rn] ?? 0) + $ra, 2);
        }

        return [
            'gross' => $gross, 'basic' => round($basic, 2), 'hra' => round($hra, 2), 'special' => $special,
            'earnings' => $earnings, 'deductions' => $deductions,
            'reimbursements' => round($reimbTotal, 2), 'reimbursement_map' => $reimbursements, 'wage_gross' => $wageGross,
            'pf' => $pf, 'esi' => $esi, 'pt' => $pt, 'tds' => $tds, 'lwf' => round($lwf, 2),
            'pf_wage' => $st['pf_wage'], 'pf_employer' => $st['pf_employer'], 'pf_eps' => $st['pf_eps'],
            'pf_epf_employer' => $st['pf_epf_employer'], 'pf_edli' => $st['pf_edli'], 'esi_employer' => $st['esi_employer'],
            'total_ded' => $totalDed, 'net' => round(max(0, $gross - $totalDed), 2), // rev172 (M6) — never a negative payslip
        ];
    }

    /** Generate the last 12 months of payroll (idempotent) and return runs + payslips history. */
    private function ensurePayrollHistory(?int $tenantId, $companyId, $emps, ?array $rates = null): array
    {
        if (! $tenantId || ! $companyId || $emps->isEmpty()) {
            return ['runs' => collect(), 'payslips' => collect()];
        }

        for ($k = 0; $k < 12; $k++) {
            $month = now()->subMonths($k)->format('Y-m');
            $exists = DB::table('payroll_runs')->where('tenant_id', $tenantId)
                ->where('company_id', $companyId)->where('cycle_label', $month)->exists();
            if ($exists) {
                continue;
            }
            $__cn = DB::table('companies')->where('id', $companyId)->value('name');
            $payDate = self::payDateFor($tenantId, (string) ($__cn ?? ''), $month);
            // Only write columns that exist in THIS deployment's schema — the
            // deployed payroll_runs table has no generated_at, and payslips has no
            // uuid; including them throws "Unknown column" and 500s /app/data.
            $runRow = $this->onlyExistingCols('payroll_runs', [
                'tenant_id' => $tenantId, 'company_id' => $companyId, 'lot' => 1,
                'cycle_label' => $month, 'pay_date' => $payDate,
                'status' => $k === 0 ? 'draft' : 'paid', 'employees_count' => $emps->count(), 'net_total' => 0,
                'generated_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            $runId = DB::table('payroll_runs')->insertGetId($runRow);
            $netTotal = 0;
            foreach ($emps as $e) {
                $s = self::computeSlip((float) $e->ctc, $rates, (string) ($e->employment_stage ?? ''));
                $slipRow = $this->onlyExistingCols('payslips', [
                    'uuid' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'company_id' => $companyId,
                    'employee_id' => $e->id, 'run_id' => $runId, 'month' => $month,
                    'earnings' => json_encode(['Basic' => $s['basic'], 'HRA' => $s['hra'], 'Special Allowance' => $s['special']]),
                    'deductions' => json_encode(['PF' => $s['pf'], 'ESI' => $s['esi'], 'Professional Tax' => $s['pt'], 'TDS' => $s['tds']]),
                    'gross' => $s['gross'], 'total_ded' => $s['total_ded'], 'net' => $s['net'],
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                DB::table('payslips')->insert($slipRow);
                $netTotal += $s['net'];
            }
            DB::table('payroll_runs')->where('id', $runId)->update(['net_total' => $netTotal]);
        }

        $runs = DB::table('payroll_runs')->where('tenant_id', $tenantId)->where('company_id', $companyId)
            ->orderBy('cycle_label', 'desc')->get()->map(fn ($r) => [
                'id' => 'RUN-'.$r->id,
                'lot' => 'Lot 1',
                'payDate' => \Illuminate\Support\Carbon::parse($r->pay_date)->format('d M Y'),
                'cycle' => \Illuminate\Support\Carbon::parse($r->cycle_label.'-01')->format('M Y'),
                'components' => 'All',
                'employees' => (int) $r->employees_count,
                'net' => (float) $r->net_total,
                'slip' => 'Same day',
                'status' => ucfirst($r->status),
            ])->values();

        // Per-line lifecycle column may not exist on the deployed schema until the
        // salary-approval drill-down has created it — select it only if present.
        $hasLine = \Illuminate\Support\Facades\Schema::hasColumn('payslips', 'line_status');
        $lineLabels = [
            'pending' => 'Prepared', 'on_hold' => 'On hold', 'in_review' => 'In review',
            'approved' => 'Approved', 'disbursed' => 'Disbursed — acknowledge', 'acknowledged' => 'Acknowledged (signed)',
            'rejected' => 'Rejected',
        ];
        $psCols = ['p.id', 'p.month', 'p.gross', 'p.total_ded', 'p.net', 'e.emp_code', 'e.name', 'e.email',
            'c.name as company', 'r.status as run_status'];
        if ($hasLine) {
            $psCols[] = 'p.line_status';
        }
        $payslips = DB::table('payslips as p')->join('employees as e', 'e.id', '=', 'p.employee_id')
            ->leftJoin('companies as c', 'c.id', '=', 'p.company_id')
            ->leftJoin('payroll_runs as r', 'r.id', '=', 'p.run_id')
            ->where('p.tenant_id', $tenantId)
            ->whereIn('p.employee_id', $emps->pluck('id'))   // rev175 — employee self-service: $emps is role-scoped (self for a plain employee)
            ->orderBy('p.month', 'desc')->orderBy('e.emp_code')
            ->get($psCols)
            ->map(function ($p) use ($hasLine, $lineLabels) {
                $ls = $hasLine ? ($p->line_status ?: 'pending') : 'pending';

                return [
                    'id' => (int) $p->id,
                    'code' => $p->emp_code, 'name' => $p->name, 'email' => $p->email ?? '', 'month' => $p->month,
                    'monthLabel' => \Illuminate\Support\Carbon::parse($p->month.'-01')->format('M Y'),
                    'gross' => (float) $p->gross, 'ded' => (float) $p->total_ded, 'net' => (float) $p->net,
                    'company' => $p->company ?? '',
                    'status' => ucfirst($p->run_status ?? 'draft'),
                    'lineStatus' => $ls,
                    'lineLabel' => $lineLabels[$ls] ?? ucfirst($ls),
                ];
            })->values();

        return ['runs' => $runs, 'payslips' => $payslips];
    }

    /** Persist a new employee (from the prototype Add Employee form) into the real tables. */
    /**
     * rev183 — flip an employee between Active and Inactive. Inactive employees
     * keep ALL history but drop out of payroll (PayrollGen filters status='active')
     * and active operational lists, while staying visible in the Directory (with a
     * red badge) so they can be reactivated. Admin / HR only.
     */
    /** rev183c — collect EVERY record linked to an employee (backup + detail view). */
    public static function gatherEmployeeData($emp, $tenantId): array
    {
        $related = [];
        foreach (['attendance_logs', 'commissions', 'advances', 'loans', 'expenses', 'leaves', 'transfers', 'documents'] as $tbl) {
            try {
                if (Schema::hasTable($tbl) && Schema::hasColumn($tbl, 'emp_code')) {
                    $related[$tbl] = DB::table($tbl)->where('emp_code', $emp->emp_code)
                        ->when($tenantId && Schema::hasColumn($tbl, 'tenant_id'), fn ($q) => $q->where('tenant_id', $tenantId))
                        ->limit(2000)->get();
                }
            } catch (\Throwable $e) {
            }
        }
        foreach (['payslips' => 'employee_id', 'employee_references' => 'employee_id'] as $tbl => $key) {
            try {
                if (Schema::hasTable($tbl) && Schema::hasColumn($tbl, $key)) {
                    $related[$tbl] = DB::table($tbl)->where($key, $emp->id)->limit(2000)->get();
                }
            } catch (\Throwable $e) {
            }
        }

        return $related;
    }

    /** rev183c — full profile + all linked records for the Old-data detail view
     *  (works for backed-up AND deleted employees). Admin / HR only. */
    public function archiveDetail(Request $request, $code)
    {
        if ($deny = ApprovalService::denyUnlessRole($request, ['admin', 'hr_manager'])) {
            return $deny;
        }
        $tenantId = $request->user()->tenant_id ?? DB::table('tenants')->value('id');
        $emp = DB::table('employees')
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->where('emp_code', (string) $code)
            ->first();
        if (! $emp) {
            return response()->json(['ok' => false, 'error' => 'Employee not found'], 404);
        }

        return response()->json([
            'ok' => true,
            'employee' => (array) $emp,
            'companyName' => DB::table('companies')->where('id', $emp->company_id ?? 0)->value('name'),
            'payslipBase' => url('/app/payslip'),
            'docBase' => url('/app/documents-mgr'),
            'related' => self::gatherEmployeeData($emp, $tenantId),
        ]);
    }

    /**
     * rev183b — BACK UP a single employee's entire data into the "Backed up /
     * Old data" tab. The employee + every linked record STAY intact in the DB
     * (keyed by emp_code / employee_id); we set employees.archived_at so they
     * drop out of the active directory and payroll, snapshot everything into
     * employee_archives, and return a download URL for an offline JSON backup.
     * Admin / HR only. View-only afterwards (no in-app restore).
     */
    /**
     * rev183d — SCHEDULE a backup with a 3-day grace period instead of removing
     * immediately. Sets employees.backup_due_at = now + 3 days; the employee stays
     * in the Directory with a live countdown + Cancel until then. When due, the
     * bootstrap due-processor archives them to Old data. Emails the tenant admins
     * so an accidental click can be cancelled in time. Admin / HR only.
     */
    public function backupEmployee(Request $request, $code)
    {
        if ($deny = ApprovalService::denyUnlessRole($request, ['admin', 'hr_manager'])) {
            return $deny;
        }
        $tenantId = $request->user()->tenant_id ?? DB::table('tenants')->value('id');
        $emp = DB::table('employees')
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->where('emp_code', (string) $code)
            ->whereNull('deleted_at')
            ->first();
        if (! $emp) {
            return response()->json(['ok' => false, 'error' => 'Employee not found'], 404);
        }
        if (! Schema::hasColumn('employees', 'backup_due_at')) {
            return response()->json(['ok' => false, 'error' => 'Backup schedule not ready — run: php artisan migrate'], 422);
        }

        $by = (string) ($request->user()->name ?? '');
        $due = now()->addDays(3);
        DB::table('employees')->where('id', $emp->id)->update([
            'backup_due_at' => $due,
            'backup_by' => $by,
            'updated_at' => now(),
        ]);

        // Alert the tenant admins so an accidental schedule can be cancelled in time.
        try {
            $company = DB::table('companies')->where('id', $emp->company_id ?? 0)->value('name');
            foreach (self::adminRecipients((int) ($tenantId ?? 0)) as $rcpt) {
                \App\Services\MailService::queue([
                    'tenant_id' => (int) ($tenantId ?? 0),
                    'company_id' => $rcpt['company_id'] ?? null,
                    'to' => $rcpt['email'],
                    'to_name' => $rcpt['name'] ?? '',
                    'subject' => 'Action needed: '.$emp->name.' scheduled for backup & removal in 72 hours',
                    'heading' => 'Employee backup scheduled',
                    'intro' => $emp->name.' ('.$emp->emp_code.')'.($company ? ' of '.$company : '').' has been scheduled to be backed up and removed from the active directory in about 72 hours (on '.$due->format('d M Y, H:i').'). If this was NOT intentional, open the Employee Directory in SmartPRS and click Cancel on that employee before the timer ends.',
                    'lines' => [
                        'Employee' => $emp->name,
                        'Employee ID' => $emp->emp_code,
                        'Scheduled by' => $by,
                        'Will be removed at' => $due->format('d M Y, H:i'),
                    ],
                    'kind' => 'employee.backup.scheduled',
                ]);
            }
        } catch (\Throwable $e) {
        }

        return response()->json([
            'ok' => true,
            'dueAt' => $due->toIso8601String(),
            'message' => $emp->name.' scheduled for backup in 72 hours. An email alert was sent to the admin. Cancel any time before then.',
        ]);
    }

    /** rev183d — cancel a scheduled (not-yet-run) backup. Admin / HR only. */
    public function cancelBackup(Request $request, $code)
    {
        if ($deny = ApprovalService::denyUnlessRole($request, ['admin', 'hr_manager'])) {
            return $deny;
        }
        if (! Schema::hasColumn('employees', 'backup_due_at')) {
            return response()->json(['ok' => true, 'message' => 'Nothing scheduled.']);
        }
        $tenantId = $request->user()->tenant_id ?? DB::table('tenants')->value('id');
        $n = DB::table('employees')
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->where('emp_code', (string) $code)
            ->whereNull('archived_at')
            ->update(['backup_due_at' => null, 'backup_by' => null, 'updated_at' => now()]);

        return response()->json(['ok' => true, 'message' => $n > 0 ? 'Scheduled backup cancelled.' : 'Nothing to cancel.']);
    }

    /** rev183d — tenant admin/HR recipients for backup alert emails (Spatie pivots, guarded). */
    private static function adminRecipients(int $tenantId): array
    {
        try {
            if (! Schema::hasTable('users')) {
                return [];
            }
            $userIds = [];
            if (Schema::hasTable('roles') && Schema::hasTable('model_has_roles')) {
                $roleIds = DB::table('roles')->whereIn('name', ['super_admin', 'admin', 'hr_manager'])->pluck('id');
                $userIds = DB::table('model_has_roles')->whereIn('role_id', $roleIds)
                    ->where('model_type', 'App\Models\User')->pluck('model_id')->all();
            }
            if (empty($userIds)) {
                return [];
            }

            return DB::table('users')->where('tenant_id', $tenantId)->whereNotNull('email')
                ->whereIn('id', $userIds)->get(['name', 'email', 'company_id'])
                ->map(fn ($u) => ['name' => $u->name, 'email' => $u->email, 'company_id' => $u->company_id ?? null])->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** rev183d — run any backups whose 3-day grace has elapsed (called on /app/data load). */
    public static function processDueBackups($tenantId): void
    {
        try {
            if (! Schema::hasColumn('employees', 'backup_due_at') || ! Schema::hasColumn('employees', 'archived_at')) {
                return;
            }
            $due = DB::table('employees')
                ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
                ->whereNotNull('backup_due_at')
                ->where('backup_due_at', '<=', now())
                ->whereNull('archived_at')
                ->whereNull('deleted_at')
                ->limit(50)->get();
            foreach ($due as $emp) {
                self::archiveEmployeeNow($emp, $emp->tenant_id ?? $tenantId, (string) ($emp->backup_by ?? 'system'));
            }
        } catch (\Throwable $e) {
        }
    }

    /** rev183d — snapshot + archive one employee to Old data (used by the due-processor). */
    private static function archiveEmployeeNow($emp, $tenantId, string $by): void
    {
        try {
            $json = json_encode([
                'backed_up_at' => now()->toDateTimeString(),
                'backed_up_by' => $by,
                'employee' => (array) $emp,
                'related' => self::gatherEmployeeData($emp, $tenantId),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            if (Schema::hasTable('employee_archives')) {
                DB::table('employee_archives')->insert([
                    'tenant_id' => $tenantId,
                    'employee_id' => $emp->id,
                    'emp_code' => $emp->emp_code,
                    'name' => $emp->name,
                    'snapshot' => $json,
                    'archived_by' => $by,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            DB::table('employees')->where('id', $emp->id)->update([
                'archived_at' => now(),
                'archived_by' => $by,
                'backup_due_at' => null,
                'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
        }
    }

    /** rev183b — stream the stored JSON backup for a backed-up employee (admin/HR). */
    public function employeeBackupFile(Request $request, $code)
    {
        if ($deny = ApprovalService::denyUnlessRole($request, ['admin', 'hr_manager'])) {
            return $deny;
        }
        $tenantId = $request->user()->tenant_id ?? DB::table('tenants')->value('id');
        $row = null;
        try {
            if (Schema::hasTable('employee_archives')) {
                $row = DB::table('employee_archives')
                    ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
                    ->where('emp_code', (string) $code)
                    ->orderByDesc('id')->first();
            }
        } catch (\Throwable $e) {
        }
        if ($row) {
            $json = (string) $row->snapshot;
            $stamp = substr((string) $row->created_at, 0, 10);
        } else {
            $emp = DB::table('employees')->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
                ->where('emp_code', (string) $code)->first();
            if (! $emp) {
                return response()->json(['ok' => false, 'error' => 'No data found for '.$code], 404);
            }
            $json = json_encode(['employee' => (array) $emp, 'related' => self::gatherEmployeeData($emp, $tenantId)], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            $stamp = substr((string) now()->toDateString(), 0, 10);
        }
        $fname = 'backup-'.preg_replace('/[^A-Za-z0-9_-]/', '', (string) $code).'-'.$stamp.'.json';

        return response($json, 200, [
            'Content-Type' => 'application/json',
            'Content-Disposition' => 'attachment; filename="'.$fname.'"',
        ]);
    }

    public function setEmployeeStatus(Request $request, $code)
    {
        if ($deny = ApprovalService::denyUnlessRole($request, ['admin', 'hr_manager'])) {
            return $deny;
        }
        $tenantId = $request->user()->tenant_id ?? DB::table('tenants')->value('id');
        $to = strtolower(trim((string) $request->input('status', ''))) === 'inactive' ? 'inactive' : 'active';
        $n = DB::table('employees')
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->where('emp_code', (string) $code)
            ->whereNull('deleted_at')
            ->update(['status' => $to, 'updated_at' => now()]);

        return response()->json([
            'ok' => $n > 0,
            'status' => $to,
            'message' => $n > 0 ? ('Employee marked '.ucfirst($to)) : 'Employee not found',
        ]);
    }

    public function storeEmployee(Request $request)
    {
        if ($deny = ApprovalService::denyUnlessRole($request, ['admin', 'hr_manager'])) {
            return $deny;
        }
        $user = $request->user();
        $tenantId = $user->tenant_id ?? DB::table('tenants')->value('id');
        $companyId = DB::table('companies')
            ->when($user->tenant_id, fn ($q) => $q->where('tenant_id', $user->tenant_id))
            ->value('id');

        try {
            self::ensureEmployeeColumns();
        } catch (\Throwable $e) {
            // optional columns; continue
        }

        $e = (array) $request->input('employee', []);
        // rev172 (H3) — neutralise stored XSS at the source: employee names and
        // free-text fields are rendered into the SPA via innerHTML in many places
        // with inconsistent escaping, so strip any HTML/angle-brackets on save.
        // Legitimate names never contain < or >. Also cleans the references rows.
        $e = self::stripHtmlDeep($e);
        if (empty($e['name'])) {
            return response()->json(['ok' => false, 'error' => 'Name required'], 422);
        }

        // 7 Aug 2026 test report (item 10a) — reject invalid phone numbers instead
        // of silently saving them. Accept a 10-digit Indian mobile (optionally with
        // a 91 / +91 / 0 prefix); blank is allowed (the field is optional).
        $phoneErr = static function ($raw, string $label): ?string {
            $raw = trim((string) $raw);
            if ($raw === '') {
                return null;
            }
            // 10 Aug 2026 — reject values with characters that are not part of a
            // phone number (e.g. "9391024484rrr"): the old check stripped ALL
            // non-digits first, so letters slipped through and got saved.
            if (preg_match('/[^0-9+\-\s()]/', $raw)) {
                return $label.' looks invalid — remove letters/symbols and enter a 10-digit mobile number (starting 6-9).';
            }
            $digits = preg_replace('/\D+/', '', $raw);
            // strip a leading country code (91) or trunk 0 so 10 real digits remain.
            if (strlen($digits) === 12 && str_starts_with($digits, '91')) {
                $digits = substr($digits, 2);
            } elseif (strlen($digits) === 11 && str_starts_with($digits, '0')) {
                $digits = substr($digits, 1);
            }
            if (! preg_match('/^[6-9]\d{9}$/', $digits)) {
                return $label.' looks invalid — enter a 10-digit mobile number (starting 6-9).';
            }

            return null;
        };
        foreach ([['mobile', 'Mobile'], ['whatsapp', 'WhatsApp'], ['emergencyPhone', 'Emergency Contact Number']] as $pf) {
            if ($msg = $phoneErr($e[$pf[0]] ?? null, $pf[1])) {
                return response()->json(['ok' => false, 'error' => $msg], 422);
            }
        }

        // 10 Aug 2026 — the `pan` column is 10 chars; an over-length value (e.g.
        // "ACQPH7766766") threw a raw "Data too long" SQL error and the WHOLE save
        // was lost with an unhelpful message. Validate the PAN format up front.
        $panRaw = strtoupper(trim((string) ($e['pan'] ?? '')));
        if ($panRaw !== '' && ! preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]$/', $panRaw)) {
            return response()->json(['ok' => false, 'error' => 'PAN looks invalid — it must be 10 characters in the format ABCDE1234F.'], 422);
        }
        $e['pan'] = $panRaw ?: null;

        // 28 Aug 2026 (Ejaz) — every OTHER field with an objective format is
        // checked here too, using the same rules the browser applies
        // (EmployeeFieldRules::formatError mirrors AppController::empFieldError)
        // and the same rules Self-Onboarding now applies. Before this, IFSC,
        // UAN, bank account and Biometric ID were validated only in the browser,
        // so anything posted around the form — or provisioned from an
        // unvalidated onboarding record — reached the column unchecked.
        $rules = \App\Services\EmployeeFieldRules::class;
        $formValues = [];
        foreach ($rules::formToColumn() as $formKey => $dbCol) {
            if (array_key_exists($formKey, $e)) {
                $formValues[$dbCol] = $e[$formKey];
            }
        }
        // The two date synthetics travel under their short keys.
        foreach ([['doj', 'doj'], ['dob', 'dob']] as [$fk, $k]) {
            if (array_key_exists($fk, $e)) {
                $formValues[$k] = $e[$fk];
            }
        }
        if ($fmtErrs = $rules::formatErrors($formValues)) {
            return response()->json(['ok' => false, 'error' => reset($fmtErrs)], 422);
        }

        // 28 Aug 2026 (Ejaz) — ONE list of accepted salary-type spellings, shared
        // with the import wizard (which used to know only the short forms and
        // silently downgraded everything else to Only Salary).
        $salaryMap = \App\Services\EmployeeFieldRules::SALARY_TYPE_IN;
        $type = stripos($e['type'] ?? '', 'field') !== false ? 'field' : 'office';
        $code = trim((string) ($e['id'] ?? ''));
        if ($code === '') {
            do {
                $code = 'EMP-'.random_int(1000, 9999);
            } while (DB::table('employees')->where('tenant_id', $tenantId)->where('emp_code', $code)->exists());
        }
        $origCode = trim((string) ($e['orig_id'] ?? ''));

        // 2026-08-05 — FIX: the form's Primary Company was IGNORED and every save
        // force-reset company_id to the tenant's first company, so an edited
        // company reverted after refresh. Resolve the company the form actually
        // sent — by id (companyId) or by name (companyName/company) within this
        // tenant; fall back to the employee's EXISTING company on edit, and only
        // default to the first company for a brand-new employee with no choice.
        $chosenCompanyId = null;
        $cidRaw = $e['companyId'] ?? null;
        if ($cidRaw !== null && $cidRaw !== '' && is_numeric($cidRaw)) {
            $chosenCompanyId = DB::table('companies')
                ->when($user->tenant_id, fn ($q) => $q->where('tenant_id', $user->tenant_id))
                ->whereNull('deleted_at')->where('id', (int) $cidRaw)->value('id');
        }
        if (! $chosenCompanyId) {
            $cName = trim((string) ($e['companyName'] ?? ($e['company'] ?? '')));
            if ($cName !== '') {
                $chosenCompanyId = DB::table('companies')
                    ->when($user->tenant_id, fn ($q) => $q->where('tenant_id', $user->tenant_id))
                    ->whereNull('deleted_at')->whereRaw('LOWER(name) = ?', [strtolower($cName)])->value('id');
            }
        }

        $payload = [
            'tenant_id' => $tenantId,
            'company_id' => $chosenCompanyId ?: $companyId,
            'emp_code' => $code,
            'name' => $e['name'],
            'type' => $type,
            'ctc' => (float) ($e['ctc'] ?? 0),
            'salary_type' => $salaryMap[strtolower(trim((string) ($e['salaryType'] ?? 'Salary')))] ?? 'only_salary',
            'mobile' => $e['mobile'] ?? null,
            'whatsapp' => $e['whatsapp'] ?? null,
            'email' => $e['email'] ?? null,
            'pan' => $e['pan'] ?? null,
            'uan' => $e['uan'] ?? null,
            'pt_state' => $e['ptState'] ?? null,
            'bank_name' => $e['bankName'] ?? null,
            'bank_acc' => $e['bankAcc'] ?? null,
            'ifsc' => $e['ifsc'] ?? null,
            'doj' => ! empty($e['doj']) ? $e['doj'] : null,
            // Org hierarchy (names from the form dropdowns). The prototype form
            // uses keys dept/designation/branch/team/teamManager/teamLeader; we
            // also accept reporting/leader as fallbacks for API callers.
            'department' => $e['dept'] ?? null,
            'designation' => $e['designation'] ?? null,
            'branch' => $e['branch'] ?? null,
            'team' => $e['team'] ?? null,
            'shift' => $e['shift'] ?? null,   // rev173 — default Working Shift (name)
            'reporting_manager' => $e['teamManager'] ?? ($e['reporting'] ?? null),
            'team_leader' => $e['teamLeader'] ?? ($e['leader'] ?? null),
            // rev160: Personal Details — Father / Spouse / Blood group / ID marks (+ gender, address, dob).
            'father' => $e['father'] ?? null,
            'mother' => $e['mother'] ?? null,   // 7 Aug 2026 test report (item 10c)
            'spouse' => $e['spouse'] ?? null,
            'marital_status' => $e['maritalStatus'] ?? ($e['marital_status'] ?? null),
            'national_id' => $e['nationalId'] ?? ($e['national_id'] ?? null),   // Government ID / SSN
            'bank_branch' => $e['bankBranch'] ?? ($e['bank_branch'] ?? null),
            'blood_group' => $e['bloodGroup'] ?? null,
            'id_marks' => $e['idMarks'] ?? null,
            'gender' => $e['gender'] ?? null,
            // 28 Aug 2026 (Ejaz) — the form used to store the LABEL ('Permanent',
            // 'Probation', 'Internship') while the importer stored the canonical
            // value ('' | probation | internship), so the same employee read
            // differently depending on how they were created. Normalise on the
            // way in; EmployeeFieldRules::EMPLOYMENT_STAGE is the only map.
            'employment_stage' => self::employmentStage($e['employment_stage'] ?? ($e['employmentStage'] ?? null)),
            // ---- 28 Aug 2026 (Ejaz) — PARITY COLUMNS -----------------------
            // Present in the sample file and in Self-Onboarding since rev190,
            // but the Employee form had no inputs and storeEmployee never wrote
            // them, so anything HR typed on the Directory screen was discarded
            // and anything imported could not be corrected there.
            'category' => $e['category'] ?? null,
            'nationality' => $e['nationality'] ?? null,
            'esic_no' => $e['esicNo'] ?? ($e['esic_no'] ?? null),
            'permanent_address' => $e['permanentAddress'] ?? ($e['permanent_address'] ?? null),
            'emergency_name' => $e['emergencyName'] ?? ($e['emergency_name'] ?? null),
            'emergency_phone' => $e['emergencyPhone'] ?? ($e['emergency_phone'] ?? null),
            'account_holder' => $e['accountHolder'] ?? ($e['account_holder'] ?? null),
            // "Also works for" was a live control on the Job & Company tab that
            // was never saved anywhere — the string `multi` did not appear once
            // in app/. It is a real column now, and a real sample-file column.
            'also_works_for' => $e['multi'] ?? ($e['alsoWorksFor'] ?? ($e['also_works_for'] ?? null)),
            // F5 — self-onboarding compliance self-declaration (Yes/No), normalised.
            'dra_declared' => self::yesNo($e['draDeclared'] ?? null),
            'pcc_declared' => self::yesNo($e['pccDeclared'] ?? null),
            // Documents tab (10 Aug 2026) — DRA Certificate status, PCC status &
            // PCC deadline were on the form but NEVER written, so they blanked
            // after a refresh. Stored lowercase to match ComplianceController.
            // 27 Aug 2026 (Ejaz) — both now go through complianceStatus() so the
            // ONE canonical set (pending|submitted|verified) is enforced on the
            // way in, identically to the importer; DRA / PCC EXPIRY are written
            // too, and all three dates are normalised (they are real DATE
            // columns — an unrecognised shape used to be rejected outright).
            'dra_status' => self::complianceStatus($e['dra'] ?? null),
            'pcc_status' => self::complianceStatus($e['pcc'] ?? null),
            'pcc_deadline' => self::normDateWide(trim((string) ($e['pccDeadline'] ?? '')) ?: null),
            'status' => (strtolower(trim((string) ($e['status'] ?? 'active'))) === 'inactive') ? 'inactive' : 'active',   // rev183 — Active/Inactive from the form
            'address' => $e['addr'] ?? ($e['address'] ?? null),
            'dob' => $e['dob'] ?? null,
            // Biometric Mapping — ID on the attendance device (Directory field).
            'device_user_id' => trim((string) ($e['deviceUserId'] ?? ($e['device_user_id'] ?? ''))) ?: null,
            'updated_at' => now(),
        ];
        // 27 Aug 2026 (Ejaz) — DRA / PCC EXPIRY from the Documents tab. Schema-
        // guarded: ensureEmployeeColumns() above adds them, but a restricted DB
        // user that cannot ALTER must still be able to save the rest.
        foreach ([['draExpiry', 'dra_expiry'], ['pccExpiry', 'pcc_expiry']] as [$formKey, $dbCol]) {
            if ((array_key_exists($formKey, $e) || array_key_exists($dbCol, $e)) && Schema::hasColumn('employees', $dbCol)) {
                $raw = trim((string) ($e[$formKey] ?? ($e[$dbCol] ?? '')));
                $payload[$dbCol] = $raw !== '' ? self::normDateWide($raw) : null;
            }
        }
        // 2026-08-05 — FIX "edits revert after refresh": PF / ESI / commission %
        // and the geo-fence fields were on the form but NEVER written to the
        // employee row, so those edits silently disappeared.
        if (array_key_exists('pf', $e)) {
            $payload['pf_applicable'] = strtolower(trim((string) $e['pf'])) === 'yes';
        }
        if (array_key_exists('esi', $e)) {
            $esiV = strtolower(trim((string) $e['esi']));
            $payload['esi_applicable'] = in_array($esiV, ['auto', 'yes', 'no'], true) ? $esiV : 'auto';
        }
        if (array_key_exists('commPct', $e)) {
            $payload['comm_pct'] = (float) $e['commPct'];
        }
        if (array_key_exists('homeLat', $e)) {
            $payload['home_lat'] = is_numeric($e['homeLat']) ? (float) $e['homeLat'] : null;
        }
        if (array_key_exists('homeLng', $e)) {
            $payload['home_lng'] = is_numeric($e['homeLng']) ? (float) $e['homeLng'] : null;
        }
        if (array_key_exists('geoStart', $e)) {
            $gsV = strtolower(trim((string) $e['geoStart']));
            $payload['geo_start'] = in_array($gsV, ['home', 'office'], true) ? $gsV : null;
        }
        if (array_key_exists('geoRadius', $e)) {
            $payload['geo_radius_km'] = is_numeric($e['geoRadius']) ? (float) $e['geoRadius'] : null;
        }
        if (array_key_exists('geoOutside', $e)) {
            $goV = strtolower(trim((string) $e['geoOutside']));
            $payload['geo_outside'] = in_array($goV, ['strict', '1km', '2km'], true) ? $goV : null;
        }
        // 10 Aug 2026 — Salary Schedule assignment: resolve the form's
        // "Name — Company" string to a salary_schedules.id so the employee's pay
        // schedule persists (employees.schedule_id); blank clears it.
        if (array_key_exists('schedule', $e) && Schema::hasColumn('employees', 'schedule_id')) {
            $schedId = null;
            $schedRaw = trim((string) $e['schedule']);
            if ($schedRaw !== '' && Schema::hasTable('salary_schedules')) {
                $schedName = trim(explode(' — ', $schedRaw)[0]);
                $schedId = DB::table('salary_schedules')
                    ->when($tenantId && Schema::hasColumn('salary_schedules', 'tenant_id'), fn ($q) => $q->where('tenant_id', $tenantId))
                    ->where('name', $schedName)->orderByDesc('id')->value('id');
            }
            $payload['schedule_id'] = $schedId;
        }
        // DATE columns reject unknown shapes — normalise both (null when unreadable).
        $payload['doj'] = self::normDateWide($payload['doj']);
        $payload['dob'] = self::normDateWide($payload['dob']);

        // Upsert by (tenant_id, emp_code). On EDIT the form sends the ORIGINAL code
        // (orig_id) so the Employee ID can be RENAMED on the same record without
        // creating a duplicate; the rename cascades to emp_code-keyed history. rev171.
        $findCode = $origCode !== '' ? $origCode : $code;
        $existing = DB::table('employees')->where('tenant_id', $tenantId)->where('emp_code', $findCode)->first();
        // On EDIT with no resolvable company in the payload, keep the record's
        // current company instead of silently resetting it to the first company.
        if (! $chosenCompanyId && $existing && ! empty($existing->company_id)) {
            $payload['company_id'] = $existing->company_id;
        }
        if ($origCode === '' && $existing) {
            return response()->json(['ok' => false, 'error' => 'Employee ID "'.$code.'" is already in use — choose a different one.'], 422);
        }
        if ($existing && $code !== $existing->emp_code) {
            $clash = DB::table('employees')->where('tenant_id', $tenantId)
                ->where('emp_code', $code)->where('id', '!=', $existing->id)->exists();
            if ($clash) {
                return response()->json(['ok' => false, 'error' => 'Employee ID "'.$code.'" is already in use — choose a different one.'], 422);
            }
        }

        // 28 Aug 2026 (Ejaz) — "PAN will be unique to the individuals. It is
        // accepting the PAN multiple times for different employees."
        // Employee Code was the ONLY thing anything checked. PAN, UAN, National
        // ID, ESIC, bank account, mobile, email and Biometric ID are all unique
        // to a person and are all checked now, from this path, the import wizard
        // and Self-Onboarding alike. Biometric ID and email matter most:
        // device_user_id is what punch ingestion matches on, and email is the
        // ESS login id.
        if ($dupErrs = $rules::duplicateErrors($payload, $tenantId, $existing->id ?? null)) {
            return response()->json(['ok' => false, 'error' => reset($dupErrs)], 422);
        }
        try {
        if ($existing) {
            $oldCode = $existing->emp_code;
            DB::table('employees')->where('id', $existing->id)->update($payload);
            $empId = $existing->id;
            DB::table('employee_references')->where('employee_id', $empId)->delete();
            // rev171 — cascade an Employee-ID rename to every table that keys rows by
            // emp_code, so attendance / commissions / leaves / etc. history follows.
            if ($oldCode !== $code) {
                foreach (['attendance_logs', 'commissions', 'advances', 'loans', 'expenses', 'leaves', 'transfers', 'documents'] as $tbl) {
                    try {
                        if (Schema::hasTable($tbl) && Schema::hasColumn($tbl, 'emp_code')) {
                            $cq = DB::table($tbl)->where('emp_code', $oldCode);
                            if (Schema::hasColumn($tbl, 'tenant_id')) {
                                $cq->where('tenant_id', $tenantId);
                            }
                            $cq->update(['emp_code' => $code]);
                        }
                    } catch (\Throwable $e2) {
                    }
                }
            }
        } else {
            // SEAT LIMIT (rev 75): a NEW employee must fit within the subscribed
            // seats (active on-roll count). Edits of existing employees are never
            // blocked. Tenants without a seat limit on record are unrestricted.
            $seat = \App\Services\SubscriptionService::canAddEmployees($user->tenant_id ? (int) $user->tenant_id : null, 1);
            if (! $seat['ok']) {
                return response()->json(['ok' => false, 'error' => $seat['error']], 422);
            }
            $payload['uuid'] = (string) Str::uuid();
            $payload['status'] = $payload['status'] ?? 'active';
            $payload['created_at'] = now();
            $empId = DB::table('employees')->insertGetId($payload);
        }

        foreach ((array) ($e['refs'] ?? []) as $r) {
            if (empty($r['name'])) {
                continue;
            }
            $v = (array) ($r['verify'] ?? []);
            DB::table('employee_references')->insert([
                'employee_id' => $empId,
                'name' => $r['name'],
                'relation' => $r['relation'] ?? null,
                'aadhaar' => $r['aadhaar'] ?? null,
                'pan' => $r['pan'] ?? null,
                'mobile' => $r['mobile'] ?? null,
                'verify_email' => ! empty($v['email']),
                'verify_sms' => ! empty($v['sms']),
                'verify_call' => ! empty($v['call']),
                'verify_whatsapp' => ! empty($v['whatsapp']),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        } catch (\Illuminate\Database\QueryException $qe) {
            // 10 Aug 2026 — the employee row is written directly (every payload key
            // must be a real column of the right width). A too-long / bad value used
            // to surface as a raw 500 and the generic "server rejected" toast, losing
            // the whole edit. Convert it to an actionable, field-named message.
            $col = null;
            if (preg_match("/column '([^']+)'/", $qe->getMessage(), $m)) {
                $col = $m[1];
            }
            $friendly = $col
                ? 'Couldn\'t save — the value for "'.$col.'" is invalid or too long. Please correct that field and save again.'
                : 'Couldn\'t save — one of the values is invalid or too long. Please check the form and save again.';

            return response()->json(['ok' => false, 'error' => $friendly], 422);
        }

        return response()->json(['ok' => true, 'id' => $empId, 'emp_code' => $code]);
    }
}
