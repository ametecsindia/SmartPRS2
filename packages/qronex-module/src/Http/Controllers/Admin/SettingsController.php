<?php

namespace Ametecs\Qronex\Http\Controllers\Admin;

use Ametecs\Qronex\Models\AuditLog;
use Ametecs\Qronex\Qronex;
use Ametecs\Qronex\Services\EmployeeSync;
use Illuminate\Http\Request;

/** Enable/Disable switch for this tenant + attendance policy (same settings as QRONEX). */
class SettingsController extends Base
{
    public function show(Request $request)
    {
        return view('qronex::settings', ['s' => Qronex::settings($this->tid()), 'licensed' => Qronex::host()->licensed($this->tid()),
            'canConfigure' => Qronex::can($request->user(), 'configure')]);
    }

    public function toggle(Request $request)
    {
        $on = $request->boolean('enabled');
        abort_if($on && ! Qronex::host()->licensed($this->tid()), 403, 'Your plan does not include QR attendance.');
        $s = Qronex::settings($this->tid());
        $s->fill(['enabled' => $on, 'tenant_id' => $this->tid()])->save();
        if ($on) {
            EmployeeSync::run($this->tid());
        }
        AuditLog::record($on ? 'module.enabled' : 'module.disabled');

        return redirect()->route($on ? 'qronex.dashboard' : 'qronex.settings')
            ->with('ok', $on ? 'QR attendance is on. Add an office and gate, then send activation codes to employees.'
                : 'QR attendance is off. Phones and entrance screens stop working; all data is kept and comes back when you turn it on.');
    }

    public function update(Request $request)
    {
        $d = $request->validate([
            'qr_interval_s' => 'required|integer|between:2,30',
            'grace_s' => 'required|integer|between:0,15',
            'duplicate_window_min' => 'required|integer|between:0,60',
            'gps_accuracy_m' => 'required|integer|between:10,500',
            'location_notice' => 'required|string|max:500',
        ]);
        foreach (['require_device_approval', 'notify_device_requests', 'notify_remote_requests'] as $b) {
            $d[$b] = $request->boolean($b);
        }
        Qronex::settings($this->tid())->fill($d + ['tenant_id' => $this->tid()])->save();
        AuditLog::record('policy.updated', null, array_keys($d));

        return back()->with('ok', 'Attendance policy saved.');
    }
}
