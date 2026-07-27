<?php

namespace App\Http\Controllers;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * rev 119 (8 Jun 2026) — MOBILE DEVICE GATE for the SmartPRS hybrid app.
 *
 * Ejaz's proven SmartDCM model: the app asks for the company web address →
 * registers THIS device with that workspace (PENDING) → an admin approves it
 * in Administration → Mobile Devices → the app unlocks and loads the real web
 * workspace with native GPS/camera/push. One app for all clients; the device
 * gate is the anti-fraud layer (revoke a lost phone by rejecting it).
 *
 * PUBLIC API (no auth — consumed by the app before login):
 *   POST /api/mobile/register    {device_id, device_name, platform, workspace?}
 *   POST /api/mobile/status      {device_id}
 *   POST /api/mobile/push-token  {device_id, token, platform}
 * ADMIN (tenant admin/HR, in /app):
 *   GET  /app/mobile-devices                 list
 *   POST /app/mobile-devices/{id}/approve
 *   POST /app/mobile-devices/{id}/reject     {reason}
 *   POST /app/mobile-devices/{id}/revoke
 */
class MobileDeviceController extends Controller
{
    public static function ensure(): void
    {
        try {
            if (! Schema::hasTable('mobile_devices')) {
                Schema::create('mobile_devices', function (Blueprint $t) {
                    $t->id();
                    $t->unsignedBigInteger('tenant_id')->nullable()->index();
                    $t->string('device_id', 80)->index();        // stable per-install id from the app
                    $t->string('device_name', 160)->nullable();  // "Samsung SM-G991 · android"
                    $t->string('platform', 20)->nullable();      // android | ios | web
                    $t->string('host', 191)->nullable();         // the address it registered from
                    $t->string('status', 12)->default('pending'); // pending | approved | rejected
                    $t->string('code', 8)->nullable();           // short code the user reads to the admin
                    $t->string('token', 64)->nullable();         // issued on approval (device gate token)
                    $t->string('push_token', 255)->nullable();   // FCM token
                    $t->string('approved_by', 120)->nullable();
                    $t->timestamp('approved_at')->nullable();
                    $t->string('reject_reason', 191)->nullable();
                    $t->unsignedBigInteger('user_id')->nullable(); // linked after first login (optional)
                    $t->timestamp('last_seen_at')->nullable();
                    $t->timestamps();
                });
            }
        } catch (\Throwable $e) {
            Log::warning('mobile_devices ensure: '.$e->getMessage());
        }
    }

    /** Resolve the tenant from the request host (custom domain) or a slug. */
    private function resolveTenant(Request $request): ?object
    {
        // 1) custom domain
        $byHost = AuthController::tenantByHost($request->getHost());
        if ($byHost) {
            return $byHost;
        }
        // 2) workspace slug (/c/{slug}) sent by the app
        $slug = strtolower(trim((string) $request->input('workspace', '')));
        $slug = preg_replace('/[^a-z0-9]/', '', $slug);
        if ($slug !== '' && Schema::hasColumn('tenants', 'subdomain')) {
            try {
                return DB::table('tenants')->whereNull('deleted_at')
                    ->whereRaw('LOWER(subdomain) = ?', [$slug])->first();
            } catch (\Throwable $e) {
            }
        }

        return null;
    }

    // ---- PUBLIC: register --------------------------------------------------
    public function register(Request $request)
    {
        try {
            self::ensure();
            $v = $request->validate([
                'device_id' => ['required', 'string', 'max:80'],
                'device_name' => ['nullable', 'string', 'max:160'],
                'platform' => ['nullable', 'string', 'max:20'],
                'workspace' => ['nullable', 'string', 'max:60'],
            ]);
            $tenant = $this->resolveTenant($request);
            // If we cannot resolve a tenant (e.g. someone typed the bare shared
            // domain with no workspace), tell them clearly how to connect.
            if (! $tenant) {
                return response()->json([
                    'ok' => false,
                    'error' => 'We could not find your workspace at this address. Enter your full company address (for example hr.youragency.com), or smartprs.com/c/your-name.',
                ], 404);
            }

            $existing = DB::table('mobile_devices')
                ->where('tenant_id', $tenant->id)->where('device_id', $v['device_id'])->first();

            $code = $existing->code ?? strtoupper(Str::substr(preg_replace('/[^A-Za-z0-9]/', '', $v['device_id']), -6)) ?: strtoupper(Str::random(6));
            if ($existing) {
                DB::table('mobile_devices')->where('id', $existing->id)->update([
                    'device_name' => $v['device_name'] ?? $existing->device_name,
                    'platform' => $v['platform'] ?? $existing->platform,
                    'host' => $request->getHost(),
                    'last_seen_at' => now(), 'updated_at' => now(),
                ]);
                $row = DB::table('mobile_devices')->where('id', $existing->id)->first();
            } else {
                $id = DB::table('mobile_devices')->insert([
                    'tenant_id' => $tenant->id, 'device_id' => $v['device_id'],
                    'device_name' => $v['device_name'] ?? null, 'platform' => $v['platform'] ?? null,
                    'host' => $request->getHost(), 'status' => 'pending', 'code' => $code,
                    'last_seen_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                ]);
                $row = DB::table('mobile_devices')->where('tenant_id', $tenant->id)
                    ->where('device_id', $v['device_id'])->first();
            }

            return response()->json([
                'ok' => true,
                'status' => $row->status,
                'code' => $row->code,
                'token' => $row->status === 'approved' ? $row->token : null,
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['ok' => false, 'error' => 'Invalid request.'], 422);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => 'Could not register the device.'], 500);
        }
    }

    // ---- PUBLIC: status poll ----------------------------------------------
    public function status(Request $request)
    {
        try {
            self::ensure();
            $deviceId = (string) $request->input('device_id', '');
            if ($deviceId === '') {
                return response()->json(['status' => 'unknown'], 422);
            }
            // The device may exist under a tenant resolved by host; if host gives
            // nothing (shared domain) fall back to the most recent row for this id.
            $tenant = $this->resolveTenant($request);
            $q = DB::table('mobile_devices')->where('device_id', $deviceId);
            if ($tenant) {
                $q->where('tenant_id', $tenant->id);
            }
            $row = $q->orderByDesc('id')->first();
            if (! $row) {
                return response()->json(['status' => 'unknown']);
            }
            DB::table('mobile_devices')->where('id', $row->id)->update(['last_seen_at' => now()]);

            return response()->json([
                'status' => $row->status,
                'reason' => $row->status === 'rejected' ? ($row->reject_reason ?: null) : null,
                'token' => $row->status === 'approved' ? $row->token : null,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'unknown']);
        }
    }

    // ---- PUBLIC: push token ------------------------------------------------
    public function pushToken(Request $request)
    {
        try {
            self::ensure();
            $v = $request->validate([
                'device_id' => ['required', 'string', 'max:80'],
                'token' => ['required', 'string', 'max:255'],
                'platform' => ['nullable', 'string', 'max:20'],
            ]);
            DB::table('mobile_devices')->where('device_id', $v['device_id'])
                ->update(['push_token' => $v['token'], 'platform' => $v['platform'] ?? DB::raw('platform'), 'updated_at' => now()]);

            return response()->json(['ok' => true]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false], 200); // never block the app on this
        }
    }

    // ---- ADMIN: list + decide ---------------------------------------------
    private function guard(Request $request): void
    {
        abort_unless($request->user()?->hasAnyRole(['super_admin', 'admin', 'hr_manager']), 403, 'Admin only.');
    }

    public function index(Request $request)
    {
        self::ensure();
        $this->guard($request);
        $tid = $request->user()->tenant_id;
        $rows = DB::table('mobile_devices')
            ->when($tid, fn ($q) => $q->where('tenant_id', $tid))
            ->orderByRaw("FIELD(status,'pending','approved','rejected')")
            ->orderByDesc('id')->limit(500)->get()
            ->map(fn ($r) => [
                'id' => $r->id, 'deviceName' => $r->device_name, 'platform' => $r->platform,
                'code' => $r->code, 'status' => $r->status, 'host' => $r->host,
                'approvedBy' => $r->approved_by, 'rejectReason' => $r->reject_reason,
                'hasPush' => ! empty($r->push_token),
                'lastSeen' => $r->last_seen_at ? \Carbon\Carbon::parse($r->last_seen_at)->diffForHumans() : null,
                'registered' => $r->created_at ? \Carbon\Carbon::parse($r->created_at)->format('d M Y H:i') : null,
            ])->values();

        return response()->json(['ok' => true, 'rows' => $rows]);
    }

    public function approve(Request $request, int $id)
    {
        self::ensure();
        $this->guard($request);
        $tid = $request->user()->tenant_id;
        $row = DB::table('mobile_devices')->where('id', $id)->when($tid, fn ($q) => $q->where('tenant_id', $tid))->first();
        if (! $row) {
            return response()->json(['ok' => false, 'error' => 'Device not found.'], 404);
        }
        DB::table('mobile_devices')->where('id', $id)->update([
            'status' => 'approved', 'token' => $row->token ?: Str::random(48),
            'approved_by' => (string) ($request->user()->name ?? ''), 'approved_at' => now(),
            'reject_reason' => null, 'updated_at' => now(),
        ]);

        return response()->json(['ok' => true, 'message' => 'Device approved — it unlocks on the phone within a few seconds.']);
    }

    public function reject(Request $request, int $id)
    {
        self::ensure();
        $this->guard($request);
        $v = $request->validate(['reason' => ['nullable', 'string', 'max:191']]);
        $tid = $request->user()->tenant_id;
        $row = DB::table('mobile_devices')->where('id', $id)->when($tid, fn ($q) => $q->where('tenant_id', $tid))->first();
        if (! $row) {
            return response()->json(['ok' => false, 'error' => 'Device not found.'], 404);
        }
        DB::table('mobile_devices')->where('id', $id)->update([
            'status' => 'rejected', 'token' => null,
            'reject_reason' => $v['reason'] ?? 'Not approved by your administrator.',
            'approved_by' => (string) ($request->user()->name ?? ''), 'updated_at' => now(),
        ]);

        return response()->json(['ok' => true, 'message' => 'Device rejected — it loses access on its next check.']);
    }

    /** Revoke = reject an already-approved device (lost/stolen phone). */
    public function revoke(Request $request, int $id)
    {
        return $this->reject($request, $id);
    }
}
