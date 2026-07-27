<?php

namespace App\Http\Controllers;

use App\Services\AuditTrail;
use App\Services\FeaturePermissions;
use App\Services\GreetingService;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * F8 Greetings — config, live preview, test-send, and delivery log.
 *
 * Config is stored per-tenant in app_settings (GreetingService). Writes are
 * gated by FeaturePermissions ('greetings' => admin/hr_manager). The actual
 * greeting sweep runs from the scheduled `greetings:send` command; these
 * endpoints let HR configure and verify it. No payroll, no existing screen
 * touched.
 */
class GreetingController extends Controller
{
    /** GET /app/greetings/config — current config + defaults for the screen. */
    public function config(Request $request)
    {
        try {
            $tid = $request->user()->tenant_id ?? null;

            return response()->json([
                'ok' => true,
                'config' => GreetingService::config($tid),
                'defaults' => GreetingService::defaults(),
                'variables' => ['{{name}}', '{{first_name}}', '{{company}}', '{{years}}', '{{date}}'],
            ]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()]);
        }
    }

    /** POST /app/greetings/config — save the whole config object. */
    public function saveConfig(Request $request)
    {
        if ($deny = FeaturePermissions::guard($request, 'greetings')) {
            return $deny;
        }
        try {
            $tid = $request->user()->tenant_id ?? null;
            $cfg = $request->input('config');
            if (! is_array($cfg)) {
                return response()->json(['ok' => false, 'error' => 'Invalid config payload.'], 422);
            }
            // Merge over defaults so a partial save never drops required keys.
            $merged = array_replace_recursive(GreetingService::defaults(), $cfg);
            GreetingService::saveConfig($tid, $merged);
            AuditTrail::log($request, 'greetings.config.saved', 'app_settings', 0, ['enabled' => $merged['enabled'] ?? false]);

            return response()->json(['ok' => true, 'config' => $merged]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * POST /app/greetings/preview — render a template with sample (or a real
     * employee's) variables, so HR sees exactly what will go out. No send.
     */
    public function preview(Request $request)
    {
        try {
            $tid = $request->user()->tenant_id ?? null;
            $type = $request->input('type', 'birthday') === 'anniversary' ? 'anniversary' : 'birthday';
            $cfg = GreetingService::config($tid);
            $tpl = $cfg[$type] ?? [];
            // Preview / test never writes config: the screen posts whatever is
            // currently typed, so an unsaved edit can be tried out without
            // persisting it — and without accidentally switching greetings on.
            if ($request->filled('subject')) {
                $tpl['subject'] = (string) $request->input('subject');
            }
            if ($request->filled('message')) {
                $tpl['message'] = (string) $request->input('message');
            }

            // Sample vars, or a real employee if an id is supplied.
            $vars = ['name' => 'Asha Rao', 'first_name' => 'Asha', 'company' => 'Your Company',
                'years' => $type === 'anniversary' ? '3' : '', 'date' => now()->format('d M Y')];
            $empId = $request->input('employee_id');
            if ($empId && Schema::hasTable('employees')) {
                $e = DB::table('employees')->where('id', $empId)->where('tenant_id', $tid)->first();
                if ($e) {
                    $company = $e->company_id && Schema::hasTable('companies')
                        ? (DB::table('companies')->where('id', $e->company_id)->value('name') ?: 'our company') : 'our company';
                    $years = ($type === 'anniversary' && $e->doj) ? (now()->year - (int) date('Y', strtotime($e->doj))) : null;
                    $vars = GreetingService::vars((array) $e, $company, $years, $cfg);
                }
            }

            return response()->json([
                'ok' => true,
                'subject' => GreetingService::render($tpl['subject'] ?? '', $vars),
                'message' => GreetingService::render($tpl['message'] ?? '', $vars),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * POST /app/greetings/test — send a one-off test greeting to the current
     * user's own email/in-app so HR can confirm delivery end to end.
     */
    public function test(Request $request)
    {
        if ($deny = FeaturePermissions::guard($request, 'greetings')) {
            return $deny;
        }
        try {
            $user = $request->user();
            $tid = $user->tenant_id ?? null;
            $type = $request->input('type', 'birthday') === 'anniversary' ? 'anniversary' : 'birthday';
            $cfg = GreetingService::config($tid);
            $tpl = $cfg[$type] ?? [];
            // Preview / test never writes config: the screen posts whatever is
            // currently typed, so an unsaved edit can be tried out without
            // persisting it — and without accidentally switching greetings on.
            if ($request->filled('subject')) {
                $tpl['subject'] = (string) $request->input('subject');
            }
            if ($request->filled('message')) {
                $tpl['message'] = (string) $request->input('message');
            }
            $vars = ['name' => $user->name ?? 'there', 'first_name' => trim(explode(' ', (string) ($user->name ?? 'there'))[0]),
                'company' => 'Your Company', 'years' => $type === 'anniversary' ? '3' : '', 'date' => now()->format('d M Y')];

            $id = NotificationService::send([
                'tenant_id' => $tid, 'user_id' => $user->id ?? null,
                'kind' => 'greeting.test',
                'title' => '[TEST] '.GreetingService::render($tpl['subject'] ?? 'Greeting', $vars),
                'body' => GreetingService::render($tpl['message'] ?? '', $vars),
                'in_app' => true,
                'email' => (bool) $request->input('email', true),
                'email_to' => $user->email ?? null,
                'email_subject' => '[TEST] '.GreetingService::render($tpl['subject'] ?? 'Greeting', $vars),
            ]);
            AuditTrail::log($request, 'greetings.test.sent', 'notification_log', $id ?? 0, ['type' => $type]);

            return response()->json(['ok' => (bool) $id, 'sent_to' => $user->email ?? '(in-app only)']);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()]);
        }
    }

    /** GET /app/greetings/log — recent greeting deliveries from notification_log. */
    public function log(Request $request)
    {
        try {
            $tid = $request->user()->tenant_id ?? null;
            NotificationService::ensureTable();
            $rows = DB::table('notification_log')
                ->when($tid, fn ($q) => $q->where('tenant_id', $tid))
                ->whereIn('kind', ['greeting.birthday', 'greeting.anniversary'])
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
