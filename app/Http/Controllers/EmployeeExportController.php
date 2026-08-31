<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Employee data export — CSV and native Excel (.xlsx).
 *
 * Exports the full employee master (personal, job, statutory, compliance and
 * bank fields) for the current tenant. Admin / HR only, tenant-scoped, excludes
 * soft-deleted and archived (Old data) records. XLSX is produced with
 * PhpSpreadsheet (bundled with maatwebsite/excel, already required); if that
 * library is unavailable for any reason the export falls back to CSV so it can
 * never hard-fail.
 *
 * GET /app/employees/export?format=csv|xlsx[&company_id=N]
 */
class EmployeeExportController extends Controller
{
    /**
     * 28 Aug 2026 (Ejaz) — the export column list used to be a hand-kept copy of
     * the sample file's column list, which is how the two drifted apart: the
     * file gained CATEGORY / ESIC / PERMANENT ADDRESS while the export kept its
     * own order and its own labels ("Only Salary" where the file said "Salary").
     *
     * Both are now derived from App\Services\EmployeeFieldRules::FIELDS, so the
     * export IS the sample file minus DEFAULT PASSWORD — an exported file can be
     * edited and re-imported with nothing renamed and nothing lost.
     *
     * Columns that do not exist on a given install are silently skipped (see
     * effectiveMap()), so a header appears the moment its column does.
     */
    private static function map(): array
    {
        return \App\Services\EmployeeFieldRules::exportMap();
    }

    /** Column code → the label used by the form, the sample file and the export.
     *  One definition, in EmployeeFieldRules — it read "Only Salary" here and
     *  "Salary" in the file, and the importer only understood the file's
     *  spelling, so a re-imported export downgraded every commission-only
     *  employee to salary-only without a word. */
    private const SALARY_TYPE_LABEL = \App\Services\EmployeeFieldRules::SALARY_TYPE;

    /** MAP filtered to columns that actually exist on this install (denormalised
     *  name columns + the synthetic __company are always kept). Prevents a header
     *  with no backing column from showing a permanently blank column. */
    private static function effectiveMap(): array
    {
        $keepAlways = ['department', 'designation', 'branch', 'team', 'reporting_manager'];
        $out = [];
        foreach (self::map() as $header => $prop) {
            // __schedule resolves employees.schedule_id → the schedule's name, so
            // it is only meaningful when that column exists on this install.
            if ($prop === '__schedule') {
                if (Schema::hasColumn('employees', 'schedule_id')) {
                    $out[$header] = $prop;
                }

                continue;
            }
            // Every other synthetic (__company / __salaryType / __dra / __pcc /
            // __doj / __dob) is always computed in rows(), so always keep it.
            if (str_starts_with($prop, '__') || in_array($prop, $keepAlways, true) || Schema::hasColumn('employees', $prop)) {
                $out[$header] = $prop;
            }
        }

        return $out;
    }

    public function export(Request $request)
    {
        if ($deny = ApprovalService::denyUnlessRole($request, ['admin', 'hr_manager'])) {
            return $deny;
        }

        // rev190 — guarantee the personal/bank/compliance columns exist so every
        // header (ESIC, Category, Emergency contacts, Permanent Address, Account
        // Holder, …) is emitted, not silently dropped by effectiveMap().
        try {
            AppDataController::ensureEmployeeColumns();
        } catch (\Throwable $e) {
            // best-effort; export still runs on whatever columns exist
        }

        $format = strtolower(trim((string) $request->query('format', 'csv')));
        if (! in_array($format, ['csv', 'xlsx'], true)) {
            $format = 'csv';
        }

        $tid = $request->user()->tenant_id ? (int) $request->user()->tenant_id : null;
        $companyId = $request->query('company_id') ? (int) $request->query('company_id') : null;

        $rows = $this->rows($tid, $companyId);

        // Access/export audit log (best-effort, mirrors ReportController).
        try {
            if (Schema::hasTable('activity_logs')) {
                DB::table('activity_logs')->insert([
                    'tenant_id' => $tid, 'user_id' => optional($request->user())->id,
                    'action' => 'employee_export', 'entity' => 'employees', 'entity_id' => 0,
                    'detail' => json_encode(['format' => $format, 'rows' => count($rows)]),
                    'ip' => $request->ip(), 'created_at' => now(),
                ]);
            }
        } catch (\Throwable $e) {
            // logging is best-effort
        }

        $headers = array_keys(self::effectiveMap());
        $fname = 'smartprs-employees-'.now()->format('Ymd-His');

        if ($format === 'xlsx' && class_exists(\ZipArchive::class)) {
            return $this->xlsx($headers, $rows, $fname.'.xlsx');
        }

        return $this->csv($headers, $rows, $fname.'.csv');
    }

    /** Build the export rows (header keys => scalar cell values). */
    private function rows(?int $tid, ?int $companyId): array
    {
        $companyNames = [];
        try {
            $companyNames = DB::table('companies')->pluck('name', 'id')->all();
        } catch (\Throwable $e) {
            // companies table optional
        }

        // schedule_id → "Name — Company" label, matching the Directory display.
        $scheduleNames = [];
        try {
            if (Schema::hasTable('salary_schedules')) {
                foreach (DB::table('salary_schedules')->get(['id', 'name', 'company_name']) as $s) {
                    $scheduleNames[$s->id] = trim((string) $s->name)
                        .($s->company_name ? ' — '.trim((string) $s->company_name) : '');
                }
            }
        } catch (\Throwable $e) {
            // salary_schedules table optional
        }

        $q = DB::table('employees')
            ->when($tid, fn ($x) => $x->where('tenant_id', $tid))
            ->when($companyId, fn ($x) => $x->where('company_id', $companyId))
            ->whereNull('deleted_at');
        if (Schema::hasColumn('employees', 'archived_at')) {
            $q->whereNull('archived_at');   // hide backed-up (Old data) employees
        }
        $records = $q->orderBy('emp_code')->get();

        $boolCols = ['pf_applicable'];
        $map = self::effectiveMap();
        $out = [];
        foreach ($records as $rec) {
            $r = (array) $rec;
            $line = [];
            foreach ($map as $header => $prop) {
                if ($prop === '__company') {
                    $val = $companyNames[$r['company_id'] ?? 0] ?? '';
                } elseif ($prop === '__schedule') {
                    $val = $scheduleNames[$r['schedule_id'] ?? 0] ?? '';
                } elseif ($prop === '__salaryType') {
                    // Emit the import-template label, not the raw column code.
                    $val = self::SALARY_TYPE_LABEL[$r['salary_type'] ?? ''] ?? '';
                } elseif ($prop === '__dra') {
                    $val = $r['dra_declared'] ?? '';   // Yes / No, matching the import DRA column
                } elseif ($prop === '__pcc') {
                    $val = $r['pcc_declared'] ?? '';
                } elseif ($prop === '__doj' || $prop === '__dob') {
                    // Import accepts DD-MM-YYYY; convert the stored Y-m-d so the value
                    // matches its header hint and re-imports cleanly.
                    $raw = trim((string) ($r[$prop === '__doj' ? 'doj' : 'dob'] ?? ''));
                    $val = preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $raw, $m) ? ($m[3].'-'.$m[2].'-'.$m[1]) : $raw;
                } else {
                    $val = $r[$prop] ?? '';
                }
                if (in_array($prop, $boolCols, true)) {
                    $val = ((int) $val === 1) ? 'Yes' : 'No';
                }
                // 28 Aug 2026 (Ejaz) — emit the value the sample file's dropdown
                // offers, not the raw column code. These columns are stored
                // lower-case ('office', 'auto', 'pending', 'active') and were
                // exported that way, so Excel flagged every one of them against
                // its own in-cell list and a re-imported file looked wrong even
                // though it read back correctly.
                if ($prop === 'employment_stage') {
                    $val = \App\Services\EmployeeFieldRules::EMPLOYMENT_STAGE[(string) $val] ?? 'Permanent';
                } elseif (in_array($prop, ['type', 'esi_applicable', 'status', 'dra_status', 'pcc_status'], true)) {
                    $val = $val === '' || $val === null ? '' : ucfirst(strtolower((string) $val));
                }
                $line[$header] = $val === null ? '' : (string) $val;
            }
            $out[] = $line;
        }

        return $out;
    }

    /** Stream a CSV response (RFC-4180-ish, UTF-8 BOM for Excel). */
    private function csv(array $headers, array $rows, string $fname)
    {
        $line = function (array $vals): string {
            $cells = array_map(function ($v) {
                $v = (string) $v;
                if (preg_match('/[",\r\n]/', $v)) {
                    $v = '"'.str_replace('"', '""', $v).'"';
                }

                return $v;
            }, $vals);

            return implode(',', $cells)."\r\n";
        };

        $out = "\xEF\xBB\xBF";   // BOM so Excel reads UTF-8 (₹, names) correctly
        $out .= $line($headers);
        foreach ($rows as $row) {
            $out .= $line(array_values($row));
        }

        return response($out, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$fname.'"',
        ]);
    }

    /** A→Z, AA→… column letter from a 1-based index. */
    private static function colLetter(int $n): string
    {
        $s = '';
        while ($n > 0) {
            $m = ($n - 1) % 26;
            $s = chr(65 + $m).$s;
            $n = intdiv($n - 1, 26);
        }

        return $s;
    }

    /**
     * Build a native .xlsx (Office Open XML) with PHP's own ZipArchive — no
     * external library needed, so it works on any install with the zip
     * extension. Every cell is an inline string (keeps codes / PAN / IFSC exact,
     * no leading-zero loss, no formula injection). Falls back to CSV if the zip
     * extension is somehow unavailable.
     */
    private function xlsx(array $headers, array $rows, string $fname)
    {
        $esc = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES | ENT_XML1, 'UTF-8');

        $allRows = [];
        $allRows[] = array_values($headers);
        foreach ($rows as $r) {
            $allRows[] = array_values($r);
        }

        $sheetRows = '';
        $rowNum = 0;
        foreach ($allRows as $cells) {
            $rowNum++;
            $cellsXml = '';
            $colNum = 0;
            foreach ($cells as $val) {
                $colNum++;
                $ref = self::colLetter($colNum).$rowNum;
                $cellsXml .= '<c r="'.$ref.'" t="inlineStr"><is><t xml:space="preserve">'.$esc($val).'</t></is></c>';
            }
            $sheetRows .= '<row r="'.$rowNum.'">'.$cellsXml.'</row>';
        }

        $lastCol = self::colLetter(max(1, count($headers)));
        $dim = 'A1:'.$lastCol.max(1, $rowNum);
        $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<dimension ref="'.$dim.'"/><sheetViews><sheetView workbookViewId="0">'
            .'<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            .'<sheetData>'.$sheetRows.'</sheetData>'
            .'<autoFilter ref="'.$dim.'"/></worksheet>';

        $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'</Types>';
        $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>';
        $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets><sheet name="Employees" sheetId="1" r:id="rId1"/></sheets></workbook>';
        $workbookRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            .'</Relationships>';

        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new \ZipArchive();
        if ($zip->open($tmp, \ZipArchive::OVERWRITE) !== true) {
            @unlink($tmp);

            return $this->csv($headers, $rows, preg_replace('/\.xlsx$/', '.csv', $fname));
        }
        $zip->addFromString('[Content_Types].xml', $contentTypes);
        $zip->addFromString('_rels/.rels', $rels);
        $zip->addFromString('xl/workbook.xml', $workbook);
        $zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRels);
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
        $zip->close();

        $content = (string) file_get_contents($tmp);
        @unlink($tmp);

        return response($content, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$fname.'"',
            'Content-Length' => (string) strlen($content),
        ]);
    }
}
