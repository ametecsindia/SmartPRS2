<?php

namespace Ametecs\Qronex\Http\Controllers\Admin;

use Ametecs\Qronex\Models\AuditLog;
use Ametecs\Qronex\Models\Gate;
use Ametecs\Qronex\Models\Office;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class OfficeController extends Base
{
    public function index()
    {
        return view('qronex::offices.index', ['offices' => Office::with('gates')->withCount('employees')->orderBy('code')->get()]);
    }

    public function create()
    {
        return view('qronex::offices.form', ['office' => new Office(['lat' => 17.385, 'lng' => 78.4867, 'geofence_radius_m' => 150, 'is_active' => true])]);
    }

    public function store(Request $request)
    {
        $office = Office::create($this->validated($request));
        $office->gates()->create(['tenant_id' => $office->tenant_id, 'code' => 'gate1', 'name' => 'Main Entrance']);
        AuditLog::record('office.created', $office->code);

        return redirect()->route('qronex.offices')->with('ok', "Office {$office->name} created with a default gate.");
    }

    public function edit(int $office)
    {
        return view('qronex::offices.form', ['office' => Office::findOrFail($office)]);
    }

    public function update(Request $request, int $office)
    {
        $o = Office::findOrFail($office);
        $o->update($this->validated($request, $o));
        AuditLog::record('office.updated', $o->code, array_keys($o->getChanges()));

        return redirect()->route('qronex.offices')->with('ok', 'Office saved.');
    }

    public function storeGate(Request $request, int $office)
    {
        $o = Office::findOrFail($office);
        $d = $request->validate([
            'code' => ['required', 'alpha_dash', 'max:30', Rule::unique('qx_gates')->where('office_id', $o->id)],
            'name' => 'required|string|max:80',
        ]);
        $o->gates()->create($d + ['tenant_id' => $o->tenant_id]);
        AuditLog::record('gate.created', "{$o->code}/{$d['code']}");

        return back()->with('ok', 'Gate added.');
    }

    /** New secret → every screen showing the old link stops getting codes. */
    public function resetGate(int $gate)
    {
        $g = Gate::findOrFail($gate);
        $g->forceFill(['display_secret' => Str::random(40)])->save();
        AuditLog::record('gate.display_reset', $g->code);

        return back()->with('ok', "Display link for {$g->name} reset. Open the new link on the screen.");
    }

    public function toggleGate(int $gate)
    {
        $g = Gate::findOrFail($gate);
        $g->update(['is_active' => ! $g->is_active]);
        AuditLog::record($g->is_active ? 'gate.enabled' : 'gate.disabled', $g->code);

        return back()->with('ok', 'Gate updated.');
    }

    private function validated(Request $request, ?Office $office = null): array
    {
        $tid = $this->tid();
        $request->merge(['slug' => Str::slug($request->input('slug') ?: $request->input('name'))]);
        $d = $request->validate([
            'code' => ['required', 'alpha_dash', 'max:20', Rule::unique('qx_offices')->where('tenant_id', $tid)->ignore($office)],
            'slug' => ['required', 'max:60', Rule::unique('qx_offices')->where('tenant_id', $tid)->ignore($office)],
            'name' => 'required|string|max:120',
            'address' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:80',
            'lat' => 'required|numeric|between:-90,90',
            'lng' => 'required|numeric|between:-180,180',
            'geofence_radius_m' => 'required|integer|between:30,2000',
        ]);
        $d['code'] = strtoupper($d['code']);
        $d['is_active'] = $request->boolean('is_active');

        return $d;
    }
}
