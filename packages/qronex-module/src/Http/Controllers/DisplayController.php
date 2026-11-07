<?php

namespace Ametecs\Qronex\Http\Controllers;

use Ametecs\Qronex\Models\Gate;
use Ametecs\Qronex\Qronex;
use Ametecs\Qronex\Services\QrToken;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/** Public entrance screen: /qronex/display/{gate}?key=… — the key is the gate's display secret. */
class DisplayController extends Controller
{
    private function gate(int $gate, Request $request): Gate
    {
        $g = Gate::withoutGlobalScopes()->with('office')->findOrFail($gate);
        abort_unless(hash_equals((string) $g->display_secret, (string) $request->query('key')), 403, 'Display link is invalid or was reset.');

        return $g;
    }

    public function show(Request $request, int $gate)
    {
        $g = $this->gate($gate, $request);

        return view('qronex::display', ['gate' => $g, 'company' => Qronex::host()->tenant($g->tenant_id)['name'] ?? '',
            'tokenUrl' => route('qronex.display.token', [$g->id, 'key' => $request->query('key')])]);
    }

    /** Polled by the display every rotation. Also the display heartbeat. */
    public function token(Request $request, int $gate)
    {
        $g = $this->gate($gate, $request);
        $interval = Qronex::settings($g->tenant_id)->qr_interval_s ?: 3;
        $g->forceFill(['display_last_seen_at' => now(), 'display_agent' => mb_substr((string) $request->userAgent(), 0, 250)])->save();
        $active = $g->is_active && $g->office->is_active && Qronex::enabled($g->tenant_id);

        return response()->json(($active ? QrToken::make($g, $interval) : ['qr' => null, 'expires_in' => 10]) + [
            'interval' => $interval, 'active' => $active, 'server_time' => now()->toIso8601ZuluString(),
        ])->header('Cache-Control', 'no-store');
    }
}
