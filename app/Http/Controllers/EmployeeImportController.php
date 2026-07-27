<?php

namespace App\Http\Controllers;

use App\Services\AuditTrail;
use App\Services\EmployeeImportService as Svc;
use App\Services\FeaturePermissions;
use Illuminate\Http\Request;

/**
 * F9 — Employee import wizard endpoints.
 *
 * upload → (map) → preview → commit, plus history and a downloadable error
 * report. All writes gated by FeaturePermissions ('employee.import'). The
 * existing one-shot importer is untouched.
 */
class EmployeeImportController extends Controller
{
    /** POST /app/employee-import/upload — stage a file, return headers + suggested mapping. */
    public function upload(Request $request)
    {
        if ($deny = FeaturePermissions::guard($request, 'employee.import')) {
            return $deny;
        }
        try {
            $request->validate(['file' => ['required', 'file']]);
            $user = $request->user();
            $res = Svc::stage(
                $request->file('file'),
                $user->tenant_id ? (int) $user->tenant_id : null,
                $user->id ?? null
            );

            return response()->json($res, $res['ok'] ? 200 : 422);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['ok' => false, 'error' => collect($e->errors())->flatten()->first()], 422);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /** POST /app/employee-import/preview — validate + counts, writes nothing. */
    public function preview(Request $request)
    {
        if ($deny = FeaturePermissions::guard($request, 'employee.import')) {
            return $deny;
        }
        try {
            $user = $request->user();
            $res = Svc::preview(
                (string) $request->input('token'),
                (array) $request->input('mapping', []),
                (string) $request->input('dup_mode', 'update'),
                $user->tenant_id ? (int) $user->tenant_id : null
            );

            return response()->json($res, $res['ok'] ? 200 : 422);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /** POST /app/employee-import/commit — import for real (transactional). */
    public function commit(Request $request)
    {
        if ($deny = FeaturePermissions::guard($request, 'employee.import')) {
            return $deny;
        }
        try {
            $user = $request->user();
            $res = Svc::commit(
                (string) $request->input('token'),
                (array) $request->input('mapping', []),
                (string) $request->input('dup_mode', 'update'),
                $user->tenant_id ? (int) $user->tenant_id : null,
                $user->name ?? $user->email ?? 'HR'
            );
            if (! empty($res['ok'])) {
                AuditTrail::log($request, 'employee.import.committed', 'employee_import_jobs', 0, [
                    'created' => $res['created'] ?? 0,
                    'updated' => $res['updated'] ?? 0,
                    'skipped' => $res['skip'] ?? 0,
                    'errors' => $res['error_count'] ?? 0,
                ]);
            }

            return response()->json($res, ! empty($res['ok']) ? 200 : 422);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /** GET /app/employee-import/history */
    public function history(Request $request)
    {
        try {
            $tid = $request->user()->tenant_id ? (int) $request->user()->tenant_id : null;

            return response()->json(['ok' => true, 'items' => Svc::history($tid)]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()]);
        }
    }

    /** GET /app/employee-import/{id}/errors.csv — downloadable error report. */
    public function errorReport(Request $request, $id)
    {
        try {
            $tid = $request->user()->tenant_id ? (int) $request->user()->tenant_id : null;
            $rows = Svc::jobErrors((int) $id, $tid);
            $csv = "Row,Problem\n";
            foreach ($rows as $r) {
                $csv .= ($r['row'] ?? '').',"'.str_replace('"', '""', (string) ($r['message'] ?? '')).'"'."\n";
            }

            return response($csv, 200, [
                'Content-Type' => 'text/csv',
                'Content-Disposition' => 'attachment; filename="import-errors-'.((int) $id).'.csv"',
            ]);
        } catch (\Throwable $e) {
            return response('Row,Problem'."\n", 200, ['Content-Type' => 'text/csv']);
        }
    }
}
