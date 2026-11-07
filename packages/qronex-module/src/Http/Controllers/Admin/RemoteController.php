<?php

namespace Ametecs\Qronex\Http\Controllers\Admin;

use Ametecs\Qronex\Models\AttendanceEvent;
use Ametecs\Qronex\Models\AuditLog;
use Ametecs\Qronex\Models\Employee;
use Ametecs\Qronex\Models\RemotePermission;
use Ametecs\Qronex\Services\Approvals;
use Ametecs\Qronex\Services\AttendanceVerifier;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RemoteController extends Base
{
    public function index()
    {
        $today = today('Asia/Kolkata')->toDateString();

        return view('qronex::remote', [
            'counts' => [
                'today' => RemotePermission::where('status', 'approved')->whereDate('from_date', '<=', $today)->whereDate('to_date', '>=', $today)->count(),
                'pending' => RemotePermission::where('status', 'pending')->count(),
                'waived' => RemotePermission::where('status', 'approved')->where('location_waived', true)->whereDate('to_date', '>=', $today)->count(),
                'punches' => AttendanceEvent::whereNotNull('remote_permission_id')->where('recorded_at', '>=', now()->subDays(30))->count(),
            ],
            'perms' => RemotePermission::with('employee')->latest()->paginate(50),
            'events' => AttendanceEvent::with('employee', 'remotePermission')->whereNotNull('remote_permission_id')->latest('recorded_at')->take(15)->get(),
            'employees' => Employee::where('status', 'active')->orderBy('name')->get(['id', 'name', 'employee_code']),
        ]);
    }

    /** Granted by an approver = approved immediately. */
    public function store(Request $request)
    {
        if ($request->boolean('location_waived')) {
            $request->merge(['lat' => null, 'lng' => null, 'radius_m' => null, 'location_label' => null]);
        }
        $d = $request->validate([
            'employee_id' => ['required', Rule::in(Employee::pluck('id')->all())],
            'type' => ['required', Rule::in(array_keys(RemotePermission::TYPES))],
            'from_date' => 'required|date', 'to_date' => 'required|date|after_or_equal:from_date',
            'location_label' => 'nullable|string|max:120',
            'lat' => 'nullable|required_without:location_waived|numeric|between:-90,90', 'lng' => 'nullable|required_with:lat|numeric|between:-180,180',
            'radius_m' => 'nullable|integer|between:50,5000', 'reason' => 'required|string|max:255',
        ], ['lat.required_without' => 'Set the approved location, or tick “Waive location check”.']);
        $waived = $request->boolean('location_waived');
        $p = RemotePermission::create(array_merge($d, [
            'ref' => AttendanceVerifier::ref('RP'), 'location_waived' => $waived, 'status' => 'approved', 'requested_via' => 'portal',
            'decided_by' => $this->uid(), 'decided_at' => now(), 'radius_m' => $waived ? null : ($d['radius_m'] ?? 250),
        ], $waived ? ['lat' => null, 'lng' => null] : []));
        AuditLog::record('remote.granted', $p->ref, ['employee_id' => $p->employee_id, 'waived' => $waived]);
        Approvals::remoteApproved($p->load('employee'));

        return back()->with('ok', "Permission {$p->ref} granted to {$p->employee->name}.");
    }

    public function revoke(int $permission)
    {
        $p = RemotePermission::findOrFail($permission);
        abort_unless($p->status === 'approved', 422);
        $p->update(['status' => 'revoked', 'decided_by' => $this->uid(), 'decided_at' => now()]);
        AuditLog::record('remote.revoked', $p->ref);

        return back()->with('ok', "Permission {$p->ref} revoked.");
    }
}
