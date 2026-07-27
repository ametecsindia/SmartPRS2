<?php

namespace App\Http\Controllers;

use App\Services\AbsenceService;
use App\Services\AuditTrail;
use App\Services\FeaturePermissions;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * F10 — previous-day absence notification: config + manual test + log.
 *
 * The sweep itself runs from the scheduled `absence:notify` command. These
 * endpoints let HR configure it (per-tenant, in app_settings), run it manually
 * for a date to preview, and read the delivery log. Writes gated by
 * FeaturePermissions ('absence.notify'). No payroll, no existing screen touched.
 */
class AbsenceController extends Controller
{
    /** GET /app/absence/config */
    public function config(Request $request)
    {
        try {
            $tid = $request->user()->tenant_id ?? null;

            return response()->json([
                'ok' => true,
                'config' => AbsenceService::config($tid),
                'defaults' => AbsenceService::defaults(),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()]);
        }
    }

    /** POST /app/absence/config */
    public function saveConfig(Request $request)
    {
        if ($deny = FeaturePermissions::guard($request, 'absence.notify')) {
            return $deny;
        }
        try {
            $tid = $request->user()->tenant_id ?? null;
            $cfg = $request->input('config');
            if (! is_array($cfg)) {
                return response()->json(['ok' => false, 'error' => 'Invalid config payload.'], 422);
            }
            $merged = array_replace_recursive(AbsenceService::defaults(), $cfg);
            AbsenceService::saveConfig($tid, $merged);
            AuditTrail::log($request, 'absence.config.saved', 'app_settings', 0, ['enabled' => $merged['enabled'] ?? false]);

            return response()->json(['ok' => true, 'config' => $merged]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * POST /app/absence/run — manually run the sweep for a date (defaults to
     * yesterday), ignoring the send_hour gate so HR can preview results now.
     */
    public function run(Request $request)
    {
        if ($deny = FeaturePermissions::guard($request, 'absence.notify')) {
            return $deny;
        }
        try {
            $tid = $request->user()->tenant_id ? (int) $request->user()->tenant_id : null;
            $date = $request->input('date') ?: now()->subDay()->toDateString();
            $res = AbsenceService::runForDate($tid, $date, null);   // null hourGate = run regardless
            AuditTrail::log($request, 'absence.manual_run', 'notification_log', 0, ['date' => $date] + $res);

            return response()->json(['ok' => true, 'date' => $date] + $res);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()]);
        }
    }

    /** GET /app/absence/log — recent absence notifications. */
    public function log(Request $request)
    {
        try {
            $tid = $request->user()->tenant_id ?? null;
            NotificationService::ensureTable();
            $rows = DB::table('notification_log')
                ->when($tid, fn ($q) => $q->where('tenant_id', $tid))
                ->whereIn('kind', ['absence.prev_day', 'absence.prev_day.manager'])
                ->orderByDesc('id')->limit(200)
                ->get()
                ->map(fn ($r) => [
                    'id' => (int) $r->id, 'kind' => $r->kind, 'employee_id' => $r->employee_id,
                    'title' => $r->title, 'email_status' => $r->email_status, 'at' => (string) $r->created_at,
                ])->all();

            return response()->json(['ok' => true, 'items' => $rows]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()]);
        }
    }
}
