<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Per-tenant application state persistence. Stores the prototype engine's full
 * working data (all 113 screens' collections) as JSON in the settings table,
 * so every screen's add/edit/delete survives reloads and is shared across the
 * tenant's users. Employees remain normalized in their own table (layered on
 * top via AppDataController); other collections persist here until normalized.
 */
class AppStateController extends Controller
{
    private function tenantKey(Request $request): int
    {
        return (int) ($request->user()->tenant_id ?? 0);   // 0 = super admin / platform
    }

    public function show(Request $request)
    {
        $row = DB::table('settings')
            ->where('tenant_id', $this->tenantKey($request))
            ->where('key', 'app_state')
            ->first();

        $state = $row ? json_decode($row->value, true) : null;
        // Employees are authoritative from the employees table (layered in by the
        // boot script via /app/data). Never serve employees out of saved state, so
        // old prototype/sample rows (e.g. EMP-0142) can't resurface in the Directory.
        if (is_array($state)) {
            unset($state['employees']);
        }

        return response()->json(['state' => $state]);
    }

    public function save(Request $request)
    {
        $state = $request->input('state');
        if (! is_array($state)) {
            return response()->json(['ok' => false], 422);
        }

        DB::table('settings')->updateOrInsert(
            ['tenant_id' => $this->tenantKey($request), 'key' => 'app_state'],
            ['value' => json_encode($state), 'updated_at' => now(), 'created_at' => now()]
        );

        return response()->json(['ok' => true]);
    }
}
