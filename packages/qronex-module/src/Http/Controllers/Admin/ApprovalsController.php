<?php

namespace Ametecs\Qronex\Http\Controllers\Admin;

use Ametecs\Qronex\Models\Device;
use Ametecs\Qronex\Models\PunchCorrection;
use Ametecs\Qronex\Models\RemotePermission;
use Ametecs\Qronex\Services\Approvals;
use Illuminate\Http\Request;
use RuntimeException;

/** One inbox for everything waiting on an approver: phones (incl. replacements), remote requests, punch corrections. */
class ApprovalsController extends Base
{
    public static function pending(): array
    {
        return [
            'devices' => Device::with('employee.office', 'replaces')->where('status', 'pending')->oldest()->get(),
            'remote' => RemotePermission::with('employee')->where('status', 'pending')->oldest()->get(),
            'corrections' => PunchCorrection::with('employee')->where('status', 'pending')->oldest()->get(),
        ];
    }

    public function index()
    {
        return view('qronex::approvals', self::pending());
    }

    public function remote(Request $request, int $permission)
    {
        $p = RemotePermission::with('employee')->findOrFail($permission);
        if ($p->status !== 'pending') {
            return back()->withErrors(['remote' => "Remote request {$p->ref} is already {$p->status}."]);
        }
        if ($request->filled('lat')) { // approver may fix / add the location before approving
            $d = $request->validate(['lat' => 'numeric|between:-90,90', 'lng' => 'required_with:lat|numeric|between:-180,180', 'radius_m' => 'nullable|integer|between:50,5000']);
            $p->update($d + ['radius_m' => $d['radius_m'] ?? $p->radius_m ?? 250, 'location_label' => $p->location_label ?? 'Set by approver']);
        }
        if ($request->boolean('location_waived')) {
            $p->update(['location_waived' => true]);
        }
        try {
            Approvals::decideRemote($p, $request->input('decision') === 'approve', $this->uid());
        } catch (RuntimeException $e) {
            return back()->withErrors(['remote' => $e->getMessage()]);
        }

        return back()->with('ok', "Remote request {$p->ref} {$p->status}.");
    }

    public function correction(Request $request, int $correction)
    {
        $c = PunchCorrection::with('employee')->findOrFail($correction);
        try {
            $e = Approvals::decideCorrection($c, $request->input('decision') === 'approve', $this->uid());
        } catch (RuntimeException $ex) {
            return back()->withErrors(['correction' => $ex->getMessage()]);
        }

        return back()->with('ok', $e ? "Correction {$c->ref} approved — {$e->type} added as an adjusted punch." : "Correction {$c->ref} rejected.");
    }
}
