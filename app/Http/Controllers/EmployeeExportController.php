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
    /** Export columns: [Header => employees-row property]. Denormalised name
     *  columns (department/designation/branch/team/reporting_manager) are read
     *  straight off the row, exactly like AppDataController::bootstrap. */
    private const MAP = [
        'Employee Code' => 'emp_code',
        'Name' => 'name',
        'Gender' => 'gender',
        'Date of Birth' => 'dob',
        'Mobile' => 'mobile',
        'WhatsApp' => 'whatsapp',
        'Email' => 'email',
        'Address' => 'address',
        'Department' => 'department',
        'Designation' => 'designation',
        'Branch' => 'branch',
        'Team' => 'team',
        'Company' => '__company',
        'Type' => 'type',
        'Employment Stage' => 'employment_stage',
        'Reporting Manager' => 'reporting_manager',
        'Date of Joining' => 'doj',
        'CTC (annual ₹)' => 'ctc',
        'Salary Type' => 'salary_type',
        'Commission %' => 'comm_pct',
        'PF Applicable' => 'pf_applicable',
        'ESI Applicable' => 'esi_applicable',
        'PT State' => 'pt_state',
        'UAN' => 'uan',
        'PAN' => 'pan',
        'DRA Status' => 'dra_status',
        'DRA Expiry' => 'dra_expiry',
        'PCC Status' => 'pcc_status',
        'PCC Expiry' => 'pcc_expiry',
        'Bank Name' => 'bank_name',
        'Bank A/C' => 'bank_acc',
        'IFSC' => 'ifsc',
        'Status' => 'status',
    ];

    public function export(Request $request)
    {
        if ($deny = ApprovalService::denyUnlessRole($request, ['admin', 'hr_manager'])) {
            return $deny;
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

        $headers = array_keys(self::MAP);
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

        $q = DB::table('employees')
            ->when($tid, fn ($x) => $x->where('tenant_id', $tid))
            ->when($companyId, fn ($x) => $x->where('company_id', $companyId))
            ->whereNull('deleted_at');
        if (Schema::hasColumn('employees', 'archived_at')) {
            $q->whereNull('archived_at');   // hide backed-up (Old data) employees
        }
        $records = $q->orderBy('emp_code')->get();

        $boolCols = ['pf_applicable'];
        $out = [];
        foreach ($records as $rec) {
            $r = (array) $rec;
            $line = [];
            foreach (self::MAP as $header => $prop) {
                if ($prop === '__company') {
                    $val = $companyNames[$r['company_id'] ?? 0] ?? '';
                } else {
                    $val = $r[$prop] ?? '';
                }
                if (in_array($prop, $boolCols, true)) {
                    $val = ((int) $val === 1) ? 'Yes' : 'No';
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
