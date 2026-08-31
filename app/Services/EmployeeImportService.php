<?php

namespace App\Services;

use App\Support\SchemaHelper;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * F9 — Employee import wizard.
 *
 * The one-shot CSV importer (AppDataController::importEmployees) stays exactly
 * as it is. This adds the guided flow HR asked for:
 *
 *   1. upload   — parse the file, detect headers, suggest a column mapping
 *   2. preview  — apply the mapping, validate every row, report what WOULD
 *                 happen (create / update / skip / error) without writing
 *   3. commit   — import inside a transaction, then store a history record
 *                 with a downloadable error report
 *
 * Duplicate handling is explicit (skip | update | create) instead of the
 * implicit "always update" of the one-shot importer, and nothing is written
 * until the user has seen the preview counts.
 *
 * Yes/No handling for the DPA/PCC declaration matches F4 exactly, so both entry
 * points behave the same way.
 */
class EmployeeImportService
{
    /** Where staged uploads live (relative to the local disk). */
    public const DIR = 'imports/employees';

    /**
     * Canonical target field => accepted header aliases (lower-cased, compared
     * after stripping non-alphanumerics). First match wins.
     */
    /**
     * 28 Aug 2026 (Ejaz) — the accepted headers are now DERIVED from
     * App\Services\EmployeeFieldRules::FIELDS, the one place employee fields are
     * declared. A header with no entry here is silently ignored by the wizard,
     * which is exactly how CATEGORY, ESIC, PERMANENT ADDRESS, EMERGENCY CONTACT
     * and BANK ACCOUNT HOLDER came to be in the sample file and import as
     * nothing (rev190, and again on 19 Aug). Because the registry's own header
     * is always an accepted alias, that can no longer happen: a column cannot
     * be added to the file without the importer already knowing it.
     *
     * suggestMapping() normalises a header the same way EmployeeFieldRules::norm
     * does — lower-case, parentheticals dropped, non-alphanumerics stripped — so
     * "Commission %", "COMMISSION %" and "commission" all reach the same field.
     *
     * NOTE the one alias deliberately REMOVED today: 'employmenttype' used to
     * point at `type` (office/field), so a file with an EMPLOYMENT TYPE column
     * holding "Permanent" imported every single row as Office. That column is
     * EMPLOYMENT STAGE; a file still headed EMPLOYMENT TYPE is now left
     * unmapped for the user to assign on the mapping screen instead of being
     * silently mis-read.
     */
    public static function fields(): array
    {
        // Cached per request: suggestMapping() asks for this once per header,
        // and a 57-column file would otherwise rebuild the whole alias table
        // 57 times.
        if (self::$fieldCache === null) {
            self::$fieldCache = \App\Services\EmployeeFieldRules::importAliases();
        }

        return self::$fieldCache;
    }

    /** @var array<string,array<int,string>>|null */
    private static ?array $fieldCache = null;

    /** Fields a row cannot be imported without. */
    public const REQUIRED = ['name'];

    /** Minimum length accepted in the optional DEFAULT PASSWORD column. */
    private const PASSWORD_MIN = 6;

    /** Cached employees column list (per request). */
    private static ?array $empCols = null;

    public static function ensureSchema(): void
    {
        SchemaHelper::ensureTable('employee_import_jobs', function ($t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->nullable()->index();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('token', 64)->unique();
            $t->string('filename')->nullable();
            $t->string('status', 20)->default('staged');   // staged|imported|failed
            $t->string('dup_mode', 20)->default('update'); // skip|update|create
            $t->unsignedInteger('total_rows')->default(0);
            $t->unsignedInteger('created_count')->default(0);
            $t->unsignedInteger('updated_count')->default(0);
            $t->unsignedInteger('skipped_count')->default(0);
            $t->unsignedInteger('error_count')->default(0);
            $t->text('mapping')->nullable();               // JSON header=>field
            $t->text('errors')->nullable();                // JSON list of row errors
            $t->string('imported_by')->nullable();
            $t->timestamp('imported_at')->nullable();
            $t->timestamps();
        });
    }

    // ---- step 1: upload ----------------------------------------------------

    /**
     * Stage an uploaded file and describe it.
     *
     * @return array ok|error, token, headers, suggested mapping, sample rows, total
     */
    public static function stage($file, ?int $tid, ?int $userId): array
    {
        self::ensureSchema();
        try {
            $ext = strtolower((string) $file->getClientOriginalExtension());
            if (! in_array($ext, ['csv', 'txt', 'xlsx', 'xls'], true)) {
                return ['ok' => false, 'error' => 'Upload a .csv or .xlsx file. Got: .'.$ext];
            }

            $token = (string) Str::uuid();
            $path = self::DIR.'/'.$token.'.'.$ext;
            Storage::disk('local')->put($path, file_get_contents($file->getRealPath()));

            [$headers, $rows] = self::readRows($path, $ext);
            if (! $headers) {
                return ['ok' => false, 'error' => 'Could not read a header row from that file.'];
            }

            DB::table('employee_import_jobs')->insert([
                'tenant_id' => $tid, 'user_id' => $userId, 'token' => $token,
                'filename' => $file->getClientOriginalName(),
                'status' => 'staged', 'total_rows' => count($rows),
                'created_at' => now(), 'updated_at' => now(),
            ]);

            return [
                'ok' => true,
                'token' => $token,
                'headers' => $headers,
                'mapping' => self::suggestMapping($headers),
                'fields' => array_keys(self::fields()),
                'required' => self::REQUIRED,
                'sample' => array_slice($rows, 0, 5),
                'total' => count($rows),
            ];
        } catch (\Throwable $e) {
            Log::warning('EmployeeImportService::stage failed: '.$e->getMessage());

            return ['ok' => false, 'error' => 'Could not read that file.'];
        }
    }

    /** Guess header => canonical field using the alias table. */
    public static function suggestMapping(array $headers): array
    {
        $map = [];
        foreach ($headers as $h) {
            // rev190 (item C) — strip a parenthetical hint like "DOJ (DD-MM-YYYY)"
            // so the header still matches its field alias ("doj").
            $norm = self::norm(preg_replace('/\(.*?\)/', '', $h));
            foreach (self::fields() as $field => $aliases) {
                if (in_array($norm, $aliases, true)) {
                    $map[$h] = $field;
                    continue 2;
                }
            }
            $map[$h] = null;   // unmapped — the user can pick, or it is ignored
        }

        return $map;
    }

    // ---- step 2 + 3: preview / commit --------------------------------------

    /** Validate every row and report what would happen. Writes nothing. */
    public static function preview(string $token, array $mapping, string $dupMode, ?int $tid): array
    {
        return self::process($token, $mapping, $dupMode, $tid, null, false);
    }

    /** Import for real, inside a transaction, and record the job history. */
    public static function commit(string $token, array $mapping, string $dupMode, ?int $tid, ?string $by): array
    {
        return self::process($token, $mapping, $dupMode, $tid, $by, true);
    }

    /**
     * Shared engine for preview and commit.
     *
     * @return array ok, total, create, update, skip, errors[] (row, message)
     */
    private static function process(string $token, array $mapping, string $dupMode, ?int $tid, ?string $by, bool $write): array
    {
        self::ensureSchema();
        // rev190 — make sure the personal/bank/compliance columns exist before we
        // build payloads, otherwise those fields are silently dropped on import.
        try {
            \App\Http\Controllers\AppDataController::ensureEmployeeColumns();
        } catch (\Throwable $e) {
            // best-effort; import still runs on whatever columns exist
        }
        self::$empCols = null;   // re-read the (now complete) column list
        $dupMode = in_array($dupMode, ['skip', 'update', 'create'], true) ? $dupMode : 'update';

        $job = DB::table('employee_import_jobs')->where('token', $token)
            ->when($tid, fn ($q) => $q->where('tenant_id', $tid))->first();
        if (! $job) {
            return ['ok' => false, 'error' => 'That upload has expired — please upload the file again.'];
        }

        $found = self::locate($token);
        if (! $found) {
            return ['ok' => false, 'error' => 'The staged file is no longer available — please upload it again.'];
        }
        [$path, $ext] = $found;
        [$headers, $rows] = self::readRows($path, $ext);

        // header => field, ignoring blanks and unknown targets
        $map = [];
        foreach ($mapping as $header => $field) {
            if ($field && array_key_exists($field, self::fields())) {
                $map[$header] = $field;
            }
        }
        if (! in_array('name', $map, true)) {
            return ['ok' => false, 'error' => 'Map a column to "name" before continuing.'];
        }

        $companyId = DB::table('companies')->when($tid, fn ($q) => $q->where('tenant_id', $tid))->value('id');

        $errors = [];
        $toCreate = [];
        $toUpdate = [];
        $skipped = 0;
        $seenCodes = [];
        $seenUnique = [];   // column => [lower-cased value => the row that used it first]

        foreach ($rows as $i => $raw) {
            $lineNo = $i + 2;   // +1 for zero-index, +1 for the header row
            $v = [];
            foreach ($map as $header => $field) {
                $v[$field] = isset($raw[$header]) ? trim((string) $raw[$header]) : '';
            }

            if (count(array_filter($v, fn ($x) => $x !== '')) === 0) {
                continue;   // wholly blank line
            }

            $name = $v['name'] ?? '';
            if ($name === '') {
                $errors[] = ['row' => $lineNo, 'message' => 'Name is empty — row skipped.'];

                continue;
            }

            // field-level validation
            $rowErr = null;
            if (! empty($v['email']) && ! filter_var($v['email'], FILTER_VALIDATE_EMAIL)) {
                $rowErr = 'Invalid email "'.$v['email'].'".';
            }
            foreach (['doj', 'dob'] as $d) {
                if ($rowErr === null && ! empty($v[$d]) && self::date($v[$d]) === null) {
                    $rowErr = 'Invalid '.strtoupper($d).' "'.$v[$d].'" — use DD/MM/YYYY or YYYY-MM-DD.';
                }
            }
            if ($rowErr === null && ! empty($v['ctc']) && ! is_numeric(str_replace([',', ' '], '', $v['ctc']))) {
                $rowErr = 'CTC "'.$v['ctc'].'" is not a number.';
            }
            foreach (['dpa', 'pcc'] as $yn) {
                if ($rowErr === null && ! empty($v[$yn]) && self::yesNo($v[$yn]) === 'invalid') {
                    $rowErr = strtoupper($yn).' "'.$v[$yn].'" — use Yes or No.';
                }
            }
            // 19 Aug 2026 — optional DEFAULT PASSWORD column. The EMAIL is the login
            // ID, so a password with no email cannot work. Raised here so PREVIEW
            // shows it and it is fixed before anything is written.
            $pwd = trim((string) ($v['password'] ?? ''));
            if ($rowErr === null && $pwd !== '') {
                if (empty($v['email'])) {
                    $rowErr = 'Default Password needs an EMAIL in the same row — the email is the login ID.';
                } elseif (mb_strlen($pwd) < self::PASSWORD_MIN) {
                    $rowErr = 'Default Password must be at least '.self::PASSWORD_MIN.' characters.';
                }
            }
            // 28 Aug 2026 (Ejaz) — the SAME format rules the Employee form
            // applies in the browser and storeEmployee applies on the server.
            // PAN, IFSC, UAN, bank account, Biometric ID, mobile and the
            // compliance dates were previously unchecked on this path, so a
            // malformed value either reached the column or blew up as a raw SQL
            // error mid-transaction and rolled back the whole import.
            if ($rowErr === null) {
                $fmt = \App\Services\EmployeeFieldRules::formatErrors($v);
                if ($fmt) {
                    $rowErr = reset($fmt);
                }
            }
            if ($rowErr !== null) {
                $errors[] = ['row' => $lineNo, 'message' => $rowErr];

                continue;
            }

            $code = $v['emp_code'] ?? '';
            if ($code !== '' && isset($seenCodes[strtolower($code)])) {
                $errors[] = ['row' => $lineNo, 'message' => 'Employee ID "'.$code.'" appears more than once in this file.'];

                continue;
            }
            if ($code !== '') {
                $seenCodes[strtolower($code)] = true;
            }

            $existing = null;
            if ($code !== '' && Schema::hasTable('employees')) {
                $existing = DB::table('employees')->when($tid, fn ($q) => $q->where('tenant_id', $tid))
                    ->where('emp_code', $code)
                    // rev190 — prefer a live row over a soft-deleted ghost of the
                    // same code, so we update/restore the right one (no duplicate).
                    ->when(Schema::hasColumn('employees', 'deleted_at'), fn ($q) => $q->orderByRaw('deleted_at IS NULL DESC'))
                    ->first();
            }

            if ($existing && $dupMode === 'skip') {
                $skipped++;

                continue;
            }

            // 28 Aug 2026 (Ejaz) — "PAN will be unique to the individuals."
            // Employee Code was the only thing this wizard deduplicated on, so a
            // file could load ten people on one PAN, one Biometric ID (which is
            // what punch ingestion matches on) or one email (which is the ESS
            // login id, and whose DEFAULT PASSWORD would overwrite the other
            // person's). Checked against the database AND against the rows
            // already read from this same file — a duplicate inside one
            // spreadsheet never reaches the database at all.
            $dupErr = null;
            foreach (\App\Services\EmployeeFieldRules::uniqueFields() as $uCol => $uLabel) {
                $uVal = strtolower(trim((string) ($v[$uCol] ?? '')));
                if ($uVal === '') {
                    continue;
                }
                if (isset($seenUnique[$uCol][$uVal])) {
                    $dupErr = $uLabel.' "'.trim((string) $v[$uCol]).'" is already used on row '
                        .$seenUnique[$uCol][$uVal].' of this file. Every employee must have their own '.$uLabel.'.';
                    break;
                }
                $seenUnique[$uCol][$uVal] = $lineNo;
            }
            if ($dupErr === null) {
                $dbDup = \App\Services\EmployeeFieldRules::duplicateErrors($v, $tid, $existing->id ?? null);
                if ($dbDup) {
                    $dupErr = reset($dbDup);
                }
            }
            if ($dupErr !== null) {
                $errors[] = ['row' => $lineNo, 'message' => $dupErr];

                continue;
            }

            $payload = self::payload($v, $tid, $companyId);
            // Kept OUT of the employees payload — applied to the users table after
            // the import transaction (see applyLogin).
            $login = $pwd !== '' ? ['email' => $v['email'], 'name' => $name, 'password' => $pwd] : null;

            if ($existing && $dupMode === 'update') {
                $toUpdate[] = ['id' => $existing->id, 'payload' => $payload, 'row' => $lineNo, 'login' => $login];
            } else {
                if ($code === '') {
                    $payload['emp_code'] = 'EMP-'.random_int(1000, 9999);
                }
                $toCreate[] = ['payload' => $payload, 'row' => $lineNo, 'login' => $login];
            }
        }

        $summary = [
            'ok' => true,
            'token' => $token,
            'total' => count($rows),
            'create' => count($toCreate),
            'update' => count($toUpdate),
            'skip' => $skipped,
            'errors' => array_slice($errors, 0, 200),
            'error_count' => count($errors),
            'dup_mode' => $dupMode,
        ];

        if (! $write) {
            return $summary;
        }

        // ---- commit ----
        try {
            $created = 0;
            $updated = 0;
            DB::transaction(function () use ($toCreate, $toUpdate, &$created, &$updated) {
                foreach ($toUpdate as $u) {
                    $patch = $u['payload'] + ['updated_at' => now()];
                    // rev190 — an import must yield a VISIBLE employee. If this
                    // emp_code matched a row that was soft-deleted or moved to
                    // "Old data" (archived) in an earlier test cycle, the update
                    // would silently touch that hidden ghost and the person would
                    // never appear. Restore visibility so re-importing brings them
                    // back to the active Directory / export.
                    if (Schema::hasColumn('employees', 'deleted_at')) {
                        $patch['deleted_at'] = null;
                    }
                    if (Schema::hasColumn('employees', 'archived_at')) {
                        $patch['archived_at'] = null;
                    }
                    DB::table('employees')->where('id', $u['id'])->update($patch);
                    $updated++;
                }
                foreach ($toCreate as $c) {
                    $row = $c['payload'];
                    $row['uuid'] = (string) Str::uuid();
                    // 19 Aug 2026 — honour an imported STATUS column; default active.
                    $row['status'] = $row['status'] ?? 'active';
                    $row['created_at'] = now();
                    $row['updated_at'] = now();
                    DB::table('employees')->insert($row);
                    $created++;
                }
            });

            // 19 Aug 2026 — optional first-time ESS logins from the DEFAULT PASSWORD
            // column. Deliberately OUTSIDE the transaction: a users-table problem
            // must never roll back a successful employee import.
            $logins = 0;
            foreach (array_merge($toUpdate, $toCreate) as $item) {
                if (! empty($item['login']) && self::applyLogin($item['login'], $tid)) {
                    $logins++;
                }
            }

            DB::table('employee_import_jobs')->where('token', $token)->update([
                'status' => 'imported', 'dup_mode' => $dupMode,
                'total_rows' => count($rows),
                'created_count' => $created, 'updated_count' => $updated,
                'skipped_count' => $skipped, 'error_count' => count($errors),
                'mapping' => json_encode($map), 'errors' => json_encode(array_slice($errors, 0, 500)),
                'imported_by' => $by, 'imported_at' => now(), 'updated_at' => now(),
            ]);

            $summary['created'] = $created;
            $summary['updated'] = $updated;
            $summary['imported'] = $created + $updated;
            $summary['logins'] = $logins;

            return $summary;
        } catch (\Throwable $e) {
            Log::warning('EmployeeImportService::commit failed: '.$e->getMessage());
            DB::table('employee_import_jobs')->where('token', $token)
                ->update(['status' => 'failed', 'updated_at' => now()]);

            return ['ok' => false, 'error' => 'Import failed and was rolled back: '.$e->getMessage()];
        }
    }

    /** Build the employees-table payload, only for columns that exist. */
    private static function payload(array $v, ?int $tid, $companyId): array
    {
        // 28 Aug 2026 (Ejaz) — THE bug behind "Only Commission becomes Only
        // Salary". This map used to know only the sample file's short labels,
        // while the EXPORT wrote the long ones ("Only Commission"), and an
        // unrecognised label fell through the `?? 'only_salary'` below. So
        // exporting the directory and re-importing that same file moved every
        // commission-only employee onto salary-only, silently, and their
        // commission stopped being paid. One shared list of accepted spellings
        // now — and the export and the file agree on the label besides.
        $salaryMap = \App\Services\EmployeeFieldRules::SALARY_TYPE_IN;

        $out = ['tenant_id' => $tid, 'company_id' => $companyId, 'name' => $v['name']];
        if (! empty($v['emp_code'])) {
            $out['emp_code'] = $v['emp_code'];
        }
        foreach (['email', 'mobile', 'whatsapp', 'address', 'pan', 'uan', 'bank_acc', 'ifsc',
            'department', 'designation', 'branch', 'team', 'shift', 'device_user_id',
            'gender', 'marital_status', 'blood_group', 'father', 'mother', 'spouse',
            'national_id', 'bank_name', 'bank_branch',
            'permanent_address', 'category', 'esic_no', 'emergency_name', 'emergency_phone', 'account_holder',
            // 28 Aug 2026 (Ejaz) — the last columns the file and the form did
            // not share. `id_marks` was a form field with no column in the file;
            // `nationality` was captured by Self-Onboarding and read by nothing;
            // `team_leader` was saved by the form but had no file column;
            // `also_works_for` was a form control that was never saved at all.
            'id_marks', 'nationality', 'team_leader', 'also_works_for'] as $f) {
            if (! empty($v[$f])) {
                $out[$f] = $v[$f];
            }
        }
        // office / field employment type — same normalisation as the one-shot importer.
        if (! empty($v['type'])) {
            $out['type'] = stripos((string) $v['type'], 'field') !== false ? 'field' : 'office';
        }
        foreach (['doj', 'dob'] as $d) {
            if (! empty($v[$d]) && ($x = self::date($v[$d]))) {
                $out[$d] = $x;
            }
        }
        if (! empty($v['ctc'])) {
            $out['ctc'] = (float) str_replace([',', ' '], '', $v['ctc']);
        }
        if (! empty($v['salary_type'])) {
            $out['salary_type'] = $salaryMap[strtolower(trim((string) $v['salary_type']))] ?? 'only_salary';
        }
        // DPA/PCC → the existing dra_pcc_declared boolean (same rule as F4)
        $dpa = isset($v['dpa']) && $v['dpa'] !== '' ? self::yesNo($v['dpa']) : null;
        $pcc = isset($v['pcc']) && $v['pcc'] !== '' ? self::yesNo($v['pcc']) : null;
        $decl = array_values(array_filter([$dpa, $pcc], fn ($x) => is_bool($x)));
        if ($decl) {
            $out['dra_pcc_declared'] = ! in_array(false, $decl, true);
        }
        // 27 Aug 2026 (Ejaz) — the import wrote ONLY the combined
        // dra_pcc_declared boolean, but the Directory → Employee → Documents tab
        // reads the SEPARATE dra_declared / pcc_declared columns (that is what
        // storeEmployee writes and what bootstrap() reads back). So an imported
        // DRA/PCC declaration never appeared on the form. Write both columns in
        // the same Yes/No/NA shape the form saves. (Columns the install does not
        // have are dropped by the column filter at the end of this method.)
        foreach ([['dpa', 'dra_declared'], ['pcc', 'pcc_declared']] as [$src, $dbCol]) {
            if (! isset($v[$src]) || trim((string) $v[$src]) === '') {
                continue;
            }
            $b = self::yesNo($v[$src]);
            $out[$dbCol] = $b === true ? 'Yes' : ($b === false ? 'No' : 'NA');
        }
        // ---- 19 Aug 2026 — export-parity columns ----
        foreach (['pt_state', 'reporting_manager'] as $f) {
            if (! empty($v[$f])) {
                $out[$f] = $v[$f];
            }
        }
        if (isset($v['comm_pct']) && trim((string) $v['comm_pct']) !== '') {
            $out['comm_pct'] = (float) str_replace(['%', ',', ' '], '', (string) $v['comm_pct']);
        }
        if (isset($v['pf_applicable']) && trim((string) $v['pf_applicable']) !== '') {
            $out['pf_applicable'] = self::yesNo($v['pf_applicable']) === true;
        }
        if (isset($v['esi_applicable']) && trim((string) $v['esi_applicable']) !== '') {
            // employees.esi_applicable is the string 'auto' | 'yes' | 'no'.
            $esiV = strtolower(trim((string) $v['esi_applicable']));
            $out['esi_applicable'] = in_array($esiV, ['auto', 'yes', 'no'], true) ? $esiV : 'auto';
        }
        if (isset($v['employment_stage']) && trim((string) $v['employment_stage']) !== '') {
            // Stored as '' (Permanent) | 'probation' | 'internship' — same values
            // the Add/Edit form writes, so payroll reads them identically.
            $out['employment_stage'] = \App\Http\Controllers\AppDataController::employmentStage($v['employment_stage']);
        }
        if (! empty($v['status'])) {
            $out['status'] = strtolower(trim((string) $v['status']));
        }
        // 27 Aug 2026 (Ejaz) — DRA Certificate / PCC Status go through the ONE
        // canonical set (pending|submitted|verified), the same gate the Documents
        // tab uses. Anything else — including the retired 'Overdue' — is left
        // unset rather than written as a value the dropdown cannot display.
        foreach (['dra_status', 'pcc_status'] as $f) {
            if (! empty($v[$f]) && ($s = \App\Http\Controllers\AppDataController::complianceStatus($v[$f])) !== null) {
                $out[$f] = $s;
            }
        }
        foreach (['dra_expiry', 'pcc_deadline', 'pcc_expiry'] as $d) {
            if (! empty($v[$d]) && ($x = self::date($v[$d]))) {
                $out[$d] = $x;
            }
        }
        // Salary Schedule arrives as a NAME (the export writes "Name — Company");
        // resolve it to employees.schedule_id exactly like the Add/Edit form does.
        if (isset($v['schedule_id']) && trim((string) $v['schedule_id']) !== '') {
            try {
                if (Schema::hasTable('salary_schedules')) {
                    $schedName = trim(explode(' — ', trim((string) $v['schedule_id']))[0]);
                    $schedId = DB::table('salary_schedules')
                        ->when($tid && Schema::hasColumn('salary_schedules', 'tenant_id'), fn ($q) => $q->where('tenant_id', $tid))
                        ->where('name', $schedName)->orderByDesc('id')->value('id');
                    if ($schedId) {
                        $out['schedule_id'] = $schedId;
                    }
                }
            } catch (\Throwable $e) {
                // schedule lookup is best-effort — never fail an import over it
            }
        }

        // company name overrides the default company when it resolves
        if (! empty($v['company'])) {
            $cid = DB::table('companies')->when($tid, fn ($q) => $q->where('tenant_id', $tid))
                ->whereNull('deleted_at')->whereRaw('LOWER(name) = ?', [strtolower($v['company'])])->value('id');
            if ($cid) {
                $out['company_id'] = $cid;
            }
        }

        // drop anything the table does not have. The column list is resolved
        // once per request, not once per row — a 1,000-row file was otherwise
        // issuing 1,000 identical schema queries.
        if (self::$empCols === null) {
            self::$empCols = Schema::hasTable('employees') ? Schema::getColumnListing('employees') : [];
        }
        if (self::$empCols) {
            $out = array_intersect_key($out, array_flip(self::$empCols));
        }

        return $out;
    }

    /**
     * 19 Aug 2026 — create (or reset) the ESS login for one imported row from the
     * template's DEFAULT PASSWORD column. Mirrors the one-shot importer's rev149
     * behaviour: the row's EMAIL is the login ID, an existing user has their
     * password reset, a new user is created with the "employee" role. Never
     * throws — a login problem must not affect the employee import.
     */
    private static function applyLogin(array $login, ?int $tid): bool
    {
        try {
            if (! Schema::hasTable('users')) {
                return false;
            }
            $email = trim((string) ($login['email'] ?? ''));
            $pwd = (string) ($login['password'] ?? '');
            if ($email === '' || $pwd === '') {
                return false;
            }
            $u = DB::table('users')->whereRaw('LOWER(email) = ?', [strtolower($email)])
                ->when($tid, fn ($q) => $q->where('tenant_id', $tid))->first();
            if ($u) {
                DB::table('users')->where('id', $u->id)
                    ->update(['password' => bcrypt($pwd), 'updated_at' => now()]);

                return true;
            }
            $uid = DB::table('users')->insertGetId([
                'tenant_id' => $tid, 'name' => (string) ($login['name'] ?? $email), 'email' => $email,
                'password' => bcrypt($pwd), 'status' => 'active',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            try {
                $um = \App\Models\User::find($uid);
                if ($um && method_exists($um, 'syncRoles')) {
                    $um->syncRoles(['employee']);
                }
            } catch (\Throwable $e) {
                // role package optional
            }

            return true;
        } catch (\Throwable $e) {
            Log::warning('EmployeeImportService::applyLogin skipped: '.$e->getMessage());

            return false;
        }
    }

    /** Past import jobs for the tenant. */
    public static function history(?int $tid): array
    {
        self::ensureSchema();
        try {
            return DB::table('employee_import_jobs')
                ->when($tid, fn ($q) => $q->where('tenant_id', $tid))
                ->orderByDesc('id')->limit(50)->get()
                ->map(fn ($r) => [
                    'id' => (int) $r->id, 'file' => $r->filename, 'status' => $r->status,
                    'dup_mode' => $r->dup_mode, 'total' => (int) $r->total_rows,
                    'created' => (int) $r->created_count, 'updated' => (int) $r->updated_count,
                    'skipped' => (int) $r->skipped_count, 'errors' => (int) $r->error_count,
                    'by' => $r->imported_by, 'at' => (string) ($r->imported_at ?: $r->created_at),
                ])->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** The stored error list for one job (for the downloadable report). */
    public static function jobErrors(int $id, ?int $tid): array
    {
        try {
            $row = DB::table('employee_import_jobs')->where('id', $id)
                ->when($tid, fn ($q) => $q->where('tenant_id', $tid))->first();
            if (! $row || ! $row->errors) {
                return [];
            }

            return json_decode($row->errors, true) ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    // ---- file reading ------------------------------------------------------

    /** Find the staged file for a token (extension unknown). */
    private static function locate(string $token): ?array
    {
        foreach (['csv', 'txt', 'xlsx', 'xls'] as $ext) {
            $p = self::DIR.'/'.$token.'.'.$ext;
            if (Storage::disk('local')->exists($p)) {
                return [$p, $ext];
            }
        }

        return null;
    }

    /**
     * Read a staged file into [headers, rows] where each row is header=>value.
     * CSV is parsed natively; XLSX goes through maatwebsite/excel when present.
     */
    private static function readRows(string $path, string $ext): array
    {
        $full = Storage::disk('local')->path($path);

        if (in_array($ext, ['xlsx', 'xls'], true)) {
            try {
                if (class_exists(\Maatwebsite\Excel\Facades\Excel::class)) {
                    $sheets = \Maatwebsite\Excel\Facades\Excel::toArray([], $full);
                    $grid = $sheets[0] ?? [];
                    if (! $grid) {
                        return [[], []];
                    }
                    $headers = array_map(fn ($h) => trim((string) $h), array_shift($grid));

                    return [$headers, self::zip($headers, $grid)];
                }
            } catch (\Throwable $e) {
                Log::warning('EmployeeImportService xlsx read failed: '.$e->getMessage());
            }

            return [[], []];
        }

        $lines = @file($full, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (! $lines) {
            return [[], []];
        }
        $lines[0] = preg_replace('/^\xEF\xBB\xBF/', '', $lines[0]);   // strip Excel's BOM
        $headers = array_map(fn ($h) => trim((string) $h), str_getcsv(array_shift($lines)));
        $grid = array_map(fn ($l) => str_getcsv($l), $lines);

        return [$headers, self::zip($headers, $grid)];
    }

    /** Pad/slice each row to the header width and key it by header. */
    private static function zip(array $headers, array $grid): array
    {
        $n = count($headers);
        $out = [];
        foreach ($grid as $cells) {
            $cells = array_slice(array_pad((array) $cells, $n, null), 0, $n);
            $out[] = array_combine($headers, $cells);
        }

        return $out;
    }

    // ---- small helpers -----------------------------------------------------

    private static function norm(string $s): string
    {
        return strtolower(preg_replace('/[^a-z0-9]/i', '', $s));
    }

    /** true | false | null (blank) | 'invalid' — identical semantics to F4. */
    public static function yesNo($raw)
    {
        $z = strtolower(trim((string) $raw));
        if ($z === '') {
            return null;
        }
        if (in_array($z, ['yes', 'y', 'true', '1'], true)) {
            return true;
        }
        if (in_array($z, ['no', 'n', 'false', '0'], true)) {
            return false;
        }
        // rev190 (item C) — the DRA/PCC dropdown offers "NA"; treat it (and blanks)
        // as "no declaration", not an error.
        if (in_array($z, ['na', 'n/a', 'notapplicable', 'not applicable', '-'], true)) {
            return null;
        }

        return 'invalid';
    }

    /** Indian-friendly date normalisation → Y-m-d, or null when unparseable. */
    public static function date($s): ?string
    {
        $s = trim((string) $s);
        if ($s === '') {
            return null;
        }
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $s, $m)) {
            return checkdate((int) $m[2], (int) $m[3], (int) $m[1])
                ? sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]) : null;
        }
        if (preg_match('#^(\d{1,2})[/-](\d{1,2})[/-](\d{4})#', $s, $m)) {
            // DD/MM/YYYY first (the app standard); impossible day/month combos
            // (e.g. 04/23/2024) fall back to US m/d/Y — 2026-08-05 (Ejaz).
            if (checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
                return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);   // d/m/Y
            }
            if (checkdate((int) $m[1], (int) $m[2], (int) $m[3])) {
                return sprintf('%04d-%02d-%02d', $m[3], $m[1], $m[2]);   // m/d/Y fallback
            }

            return null;
        }
        try {
            return Carbon::parse($s)->toDateString();
        } catch (\Throwable $e) {
            return null;
        }
    }
}
