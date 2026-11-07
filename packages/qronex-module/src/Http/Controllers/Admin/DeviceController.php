<?php

namespace Ametecs\Qronex\Http\Controllers\Admin;

use Ametecs\Qronex\Models\AttendanceEvent;
use Ametecs\Qronex\Models\AuditLog;
use Ametecs\Qronex\Models\Device;
use Ametecs\Qronex\Models\Employee;
use Ametecs\Qronex\Models\Office;
use Ametecs\Qronex\Qronex;
use Ametecs\Qronex\Services\Approvals;
use Ametecs\Qronex\Services\Notify;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DeviceController extends Base
{
    public function index(Request $request)
    {
        $devices = Device::with('employee.office', 'replaces')
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when($request->q, fn ($q, $s) => $q->where(fn ($w) => $w->where('model', 'like', "%$s%")->orWhere('install_ref', 'like', "%$s%")
                ->orWhereHas('employee', fn ($e) => $e->where('name', 'like', "%$s%")->orWhere('employee_code', 'like', "%$s%"))))
            ->orderByRaw("CASE status WHEN 'pending' THEN 0 WHEN 'approved' THEN 1 WHEN 'rejected' THEN 2 ELSE 3 END")->latest()
            ->paginate(50)->withQueryString();

        return view('qronex::devices.index', ['devices' => $devices, 'counts' => Device::selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status')]);
    }

    public function show(int $device)
    {
        $d = Device::with('employee.office', 'replaces', 'events')->findOrFail($device);

        return view('qronex::devices.show', [
            'd' => $d,
            'others' => Device::where('employee_id', $d->employee_id)->whereKeyNot($d->id)->latest()->get(),
            'punches' => AttendanceEvent::where('device_id', $d->id)->with('office', 'gate')->latest('recorded_at')->take(10)->get(),
        ]);
    }

    public function act(Request $request, int $device, string $action)
    {
        $device = Device::with('employee')->findOrFail($device);
        $allowed = ['approve' => ['pending', 'rejected'], 'reject' => ['pending'], 'revoke' => ['approved']];
        if (! in_array($device->status, $allowed[$action], true)) {
            return back()->withErrors(['device' => "Cannot $action a phone that is {$device->status}."]);
        }
        // ROVEXY rules: approving needs the match code shown on the phone; rejecting/revoking needs a reason.
        if ($action === 'approve' && $device->approval_code && ! Device::codeMatches($device->approval_code, $request->input('code'))) {
            return back()->withErrors(['code' => "The code doesn't match the one on {$device->employee->name}'s phone. Ask them to open the app and read it out."]);
        }
        $reason = null;
        if ($action === 'approve') {
            // Approving binds the employee to an office + gate and sets where they may scan (defaults: the current binding).
            $emp = $device->employee;
            $request->mergeIfMissing(['office_id' => $emp->office_id, 'gate_id' => $emp->gate_id, 'scan_scope' => $emp->scan_scope ?: 'office']);
            $b = $request->validate([
                'office_id' => ['required', Rule::in(Office::pluck('id')->all())],
                'gate_id' => ['nullable', Rule::exists('qx_gates', 'id')->where('office_id', $request->input('office_id'))],
                'scan_scope' => 'required|in:'.implode(',', array_keys(Employee::SCAN_SCOPES)),
            ], ['office_id.required' => 'Pick the office.', 'gate_id.exists' => 'Pick a gate of the selected office.']);
            if ($b['scan_scope'] === 'gate' && empty($b['gate_id'])) {
                return back()->withErrors(['gate_id' => 'Pick the gate this employee is bound to.']);
            }
            try {
                Approvals::approveDevice($device, $this->uid());
            } catch (\RuntimeException $e) {
                return back()->withErrors(['device' => $e->getMessage()]);
            }
            $emp->update($b); // only once the phone is actually approved
        } else {
            $reason = $request->validate(['reason' => 'required|string|min:3|max:255'], ['reason.required' => 'Enter a reason — it is kept in the phone history.'])['reason'];
            $device->update(['status' => $action === 'reject' ? 'rejected' : 'revoked'] + ($action === 'revoke' ? ['token_hash' => null, 'approval_code' => null] : []));
            $device->log($action === 'reject' ? 'rejected' : 'revoked', $this->uid(), $reason);
            if ($action === 'reject') {
                Notify::send($device->employee->email, 'Your phone was not approved', "Hi {$device->employee->name},\n\nYour phone ({$device->model}) was not approved for attendance: {$reason}");
            }
        }
        AuditLog::record("device.$action", $device->install_ref, ['employee' => $device->employee->employee_code, 'model' => $device->model, 'reason' => $reason]);

        return back()->with('ok', "Phone {$action}d.");
    }
}
