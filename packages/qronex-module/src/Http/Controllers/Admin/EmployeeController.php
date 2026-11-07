<?php

namespace Ametecs\Qronex\Http\Controllers\Admin;

use Ametecs\Qronex\Models\AuditLog;
use Ametecs\Qronex\Models\Employee;
use Ametecs\Qronex\Models\Office;
use Ametecs\Qronex\Qronex;
use Ametecs\Qronex\Services\EmployeeSync;
use Ametecs\Qronex\Services\Notify;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

/** Employees come from the host app (synced). Here: office/gate binding, scan scope, phone exception, activation codes. */
class EmployeeController extends Base
{
    public function index(Request $request)
    {
        $s = Qronex::settings($this->tid());
        if (! $s->employees_synced_at || $s->employees_synced_at->lt(now()->subMinutes(10))) {
            EmployeeSync::run($this->tid());
        }

        return view('qronex::employees.index', [
            'employees' => Employee::with('office', 'gate')->withCount(['devices as approved_devices' => fn ($q) => $q->where('status', 'approved')])
                ->when($request->q, fn ($q, $t) => $q->where(fn ($w) => $w->where('name', 'like', "%$t%")->orWhere('employee_code', 'like', "%$t%")))
                ->when($request->office === 'none', fn ($q) => $q->whereNull('office_id'), fn ($q) => $q->when($request->office, fn ($x, $o) => $x->where('office_id', $o)))
                ->when($request->status !== 'all', fn ($q) => $q->where('status', 'active'))
                ->orderBy('name')->paginate(50)->withQueryString(),
            'offices' => Office::orderBy('name')->get(),
            'syncedAt' => Qronex::settings($this->tid())->employees_synced_at,
        ]);
    }

    public function sync()
    {
        $n = EmployeeSync::run($this->tid());
        AuditLog::record('employees.synced', null, $n);

        return back()->with('ok', "Synced from ".Qronex::host()->name().": {$n['added']} added, {$n['updated']} updated, {$n['deactivated']} deactivated.");
    }

    public function edit(int $employee)
    {
        return view('qronex::employees.form', ['employee' => Employee::findOrFail($employee),
            'offices' => Office::with(['gates' => fn ($q) => $q->where('is_active', true)->orderBy('name')])->where('is_active', true)->orderBy('name')->get()]);
    }

    public function update(Request $request, int $employee)
    {
        $e = Employee::findOrFail($employee);
        $d = $request->validate([
            'office_id' => ['required', Rule::in(Office::pluck('id')->all())],
            'gate_id' => ['nullable', Rule::exists('qx_gates', 'id')->where('office_id', $request->input('office_id'))],
            'scan_scope' => 'required|in:'.implode(',', array_keys(Employee::SCAN_SCOPES)),
        ], ['gate_id.exists' => 'Pick a gate of the selected office.']);
        if ($d['scan_scope'] === 'gate' && empty($d['gate_id'])) {
            return back()->withErrors(['gate_id' => 'Pick the gate this employee is bound to.'])->withInput();
        }
        // Extra phones are an exception only an admin with the configure ability can grant.
        if (Qronex::can($request->user(), 'configure') && $request->has('max_devices')) {
            $d['max_devices'] = (int) $request->validate(['max_devices' => 'required|integer|between:1,5'])['max_devices'];
        }
        $e->update($d);
        AuditLog::record('employee.updated', $e->employee_code, array_keys($e->getChanges()));

        return redirect()->route('qronex.employees')->with('ok', "{$e->name} saved.");
    }

    /** Single-use code (72 h) the employee types into the app. Shown once on screen and emailed. */
    public function activation(int $employee)
    {
        $e = Employee::findOrFail($employee);
        abort_if($e->status !== 'active', 422, 'Employee is inactive.');
        if (! $e->office_id) {
            return back()->withErrors(['office_id' => "Assign {$e->name} to an office first."]);
        }
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $code = '';
        for ($i = 0; $i < 8; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        $hours = config('qronex.activation_hours', 72);
        $e->forceFill(['activation_code_hash' => Hash::make($code), 'activation_expires_at' => now()->addHours($hours)])->save();
        $t = Qronex::host()->tenant($this->tid());
        $pretty = substr($code, 0, 4).'-'.substr($code, 4);
        $server = rtrim(url('/'), '/');
        Notify::send($e->email, 'Activate the QRONEX attendance app', "Hi {$e->name},\n\nInstall the QRONEX app and enter:\n\nCompany: {$server}/{$t['slug']}\nEmployee code: {$e->employee_code}\nActivation code: {$pretty}\n\nThe code works once and expires in {$hours} hours.");
        AuditLog::record('employee.activation_sent', $e->employee_code);

        return back()->with('ok', "Activation for {$e->name} — company: {$server}/{$t['slug']} · employee code: {$e->employee_code} · activation code: {$pretty} (valid {$hours} h, single use).");
    }
}
