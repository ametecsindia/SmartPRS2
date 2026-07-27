<?php

namespace App\Http\Controllers;

use App\Services\FeaturePermissions;
use App\Services\ProbationService;
use Illuminate\Http\Request;

/**
 * F6/F7 Probation — board, config, confirm, extend, history.
 *
 * The board lists employees currently on probation (existing employment_stage
 * flag) with their computed end date + days-left. HR confirms (→ permanent) or
 * extends (mandatory remarks). Writes gated by FeaturePermissions. No payroll
 * recalculation here — confirmation just clears the stage so statutory resumes
 * on the NEXT run.
 */
class ProbationController extends Controller
{
    /** GET /app/probation/board — upcoming + overdue probations. */
    public function board(Request $request)
    {
        try {
            $tid = $request->user()->tenant_id ?? null;
            ProbationService::ensureSchema();

            return response()->json(['ok' => true, 'items' => $tid ? ProbationService::board((int) $tid) : []]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()]);
        }
    }

    /** GET /app/probation/config */
    public function config(Request $request)
    {
        try {
            $tid = $request->user()->tenant_id ?? null;

            return response()->json([
                'ok' => true,
                'config' => ProbationService::config($tid),
                'defaults' => ProbationService::defaults(),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()]);
        }
    }

    /** POST /app/probation/config */
    public function saveConfig(Request $request)
    {
        if ($deny = FeaturePermissions::guard($request, 'probation.config')) {
            return $deny;
        }
        try {
            $tid = $request->user()->tenant_id ?? null;
            $cfg = $request->input('config');
            if (! is_array($cfg)) {
                return response()->json(['ok' => false, 'error' => 'Invalid config payload.'], 422);
            }
            $merged = array_replace_recursive(ProbationService::defaults(), $cfg);
            ProbationService::saveConfig($tid, $merged);
            \App\Services\AuditTrail::log($request, 'probation.config.saved', 'app_settings', 0, ['default_months' => $merged['default_months'] ?? null]);

            return response()->json(['ok' => true, 'config' => $merged]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()]);
        }
    }

    /** POST /app/probation/{emp}/confirm */
    public function confirm(Request $request, $emp)
    {
        if ($deny = FeaturePermissions::guard($request, 'probation.manage')) {
            return $deny;
        }
        try {
            $user = $request->user();
            $tid = (int) ($user->tenant_id ?? 0);
            $by = $user->name ?? $user->email ?? 'HR';
            $ok = ProbationService::confirm($tid, (int) $emp, $by, $request->input('remarks'));

            return response()->json(['ok' => $ok]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()]);
        }
    }

    /** POST /app/probation/{emp}/extend  { new_end, remarks (required) } */
    public function extend(Request $request, $emp)
    {
        if ($deny = FeaturePermissions::guard($request, 'probation.manage')) {
            return $deny;
        }
        try {
            $newEnd = $request->input('new_end');
            $remarks = trim((string) $request->input('remarks', ''));
            if (! $newEnd) {
                return response()->json(['ok' => false, 'error' => 'A new probation end date is required.'], 422);
            }
            if ($remarks === '') {
                return response()->json(['ok' => false, 'error' => 'Remarks are mandatory when extending probation.'], 422);
            }
            $user = $request->user();
            $tid = (int) ($user->tenant_id ?? 0);
            $by = $user->name ?? $user->email ?? 'HR';
            $ok = ProbationService::extend($tid, (int) $emp, $newEnd, $by, $remarks);

            return response()->json(['ok' => $ok]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()]);
        }
    }

    /** GET /app/probation/{emp}/history */
    public function history(Request $request, $emp)
    {
        try {
            $tid = (int) ($request->user()->tenant_id ?? 0);

            return response()->json(['ok' => true, 'items' => ProbationService::history($tid, (int) $emp)]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()]);
        }
    }
}
