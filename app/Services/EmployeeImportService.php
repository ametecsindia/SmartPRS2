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
    public const FIELDS = [
        'emp_code' => ['empcode', 'employeecode', 'code', 'employeeid', 'empid'],
        'name' => ['name', 'employeename', 'fullname'],
        'email' => ['email', 'emailaddress', 'officialemail'],
        'mobile' => ['mobile', 'phone', 'mobileno', 'contact', 'contactno'],
        'whatsapp' => ['whatsapp', 'whatsappnumber'],
        'company' => ['company', 'companyname'],
        'department' => ['department', 'dept'],
        'designation' => ['designation', 'title', 'role'],
        'branch' => ['branch', 'location'],
        'team' => ['team'],
        'shift' => ['shift', 'workingshift'],
        'doj' => ['doj', 'dateofjoining', 'joiningdate'],
        'dob' => ['dob', 'dateofbirth', 'birthdate'],
        'ctc' => ['ctc', 'annualctc', 'salary', 'grosssalary'],
        'salary_type' => ['salarytype'],
        'pan' => ['pan', 'panno', 'pannumber'],
        'uan' => ['uan', 'pfnumber', 'pf', 'uannumber'],
        'bank_acc' => ['bankacc', 'bankaccount', 'accountno', 'accountnumber'],
        'ifsc' => ['ifsc', 'ifsccode'],
        'address' => ['address'],
        'dpa' => ['dpa', 'dra', 'drapcc', 'dpapcc'],
        'pcc' => ['pcc', 'policeclearance'],
    ];

    /** Fields a row cannot be imported without. */
    public const REQUIRED = ['name'];

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
                'fields' => array_keys(self::FIELDS),
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
            $norm = self::norm($h);
            foreach (self::FIELDS as $field => $aliases) {
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
            if ($field && array_key_exists($field, self::FIELDS)) {
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
                    ->where('emp_code', $code)->first();
            }

            if ($existing && $dupMode === 'skip') {
                $skipped++;

                continue;
            }

            $payload = self::payload($v, $tid, $companyId);

            if ($existing && $dupMode === 'update') {
                $toUpdate[] = ['id' => $existing->id, 'payload' => $payload, 'row' => $lineNo];
            } else {
                if ($code === '') {
                    $payload['emp_code'] = 'EMP-'.random_int(1000, 9999);
                }
                $toCreate[] = ['payload' => $payload, 'row' => $lineNo];
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
                    DB::table('employees')->where('id', $u['id'])->update($u['payload'] + ['updated_at' => now()]);
                    $updated++;
                }
                foreach ($toCreate as $c) {
                    $row = $c['payload'];
                    $row['uuid'] = (string) Str::uuid();
                    $row['status'] = 'active';
                    $row['created_at'] = now();
                    $row['updated_at'] = now();
                    DB::table('employees')->insert($row);
                    $created++;
                }
            });

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
        $salaryMap = ['salary' => 'only_salary', 'salary + commission' => 'salary_commission', 'commission' => 'only_commission'];

        $out = ['tenant_id' => $tid, 'company_id' => $companyId, 'name' => $v['name']];
        if (! empty($v['emp_code'])) {
            $out['emp_code'] = $v['emp_code'];
        }
        foreach (['email', 'mobile', 'whatsapp', 'address', 'pan', 'uan', 'bank_acc', 'ifsc',
            'department', 'designation', 'branch', 'team', 'shift'] as $f) {
            if (! empty($v[$f])) {
                $out[$f] = $v[$f];
            }
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
            $out['salary_type'] = $salaryMap[strtolower($v['salary_type'])] ?? 'only_salary';
        }
        // DPA/PCC → the existing dra_pcc_declared boolean (same rule as F4)
        $dpa = isset($v['dpa']) && $v['dpa'] !== '' ? self::yesNo($v['dpa']) : null;
        $pcc = isset($v['pcc']) && $v['pcc'] !== '' ? self::yesNo($v['pcc']) : null;
        $decl = array_values(array_filter([$dpa, $pcc], fn ($x) => is_bool($x)));
        if ($decl) {
            $out['dra_pcc_declared'] = ! in_array(false, $decl, true);
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
            return checkdate((int) $m[2], (int) $m[1], (int) $m[3])
                ? sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]) : null;   // d/m/Y
        }
        try {
            return Carbon::parse($s)->toDateString();
        } catch (\Throwable $e) {
            return null;
        }
    }
}
