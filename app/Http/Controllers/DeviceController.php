<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Device;
use App\Models\Employee;
use App\Services\LateArrivalService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DeviceController extends Controller
{
    public function index()
    {
        $devices = Device::orderBy('name')->get();

        return view('devices.index', compact('devices'));
    }

    public function create()
    {
        return view('devices.form', [
            'device' => new Device(['port' => 4370, 'status' => 'unknown']),
            'mode' => 'create',
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validateDevice($request);
        $data['company_id'] = auth()->user()->company_id ?? Company::value('id');

        Device::create($data);

        return redirect()->route('devices.index')->with('success', 'Device registered.');
    }

    public function edit(Device $device)
    {
        return view('devices.form', ['device' => $device, 'mode' => 'edit']);
    }

    public function update(Request $request, Device $device)
    {
        $device->update($this->validateDevice($request));

        return redirect()->route('devices.index')->with('success', 'Device updated.');
    }

    public function destroy(Device $device)
    {
        $device->delete();

        return back()->with('success', 'Device removed.');
    }

    /**
     * Pull attendance logs from the ZKTeco device over the LAN and import them.
     * Requires the device to be reachable on its IP:port. Gracefully reports
     * if the rats/zkteco library or the device is unavailable.
     */
    public function sync(Device $device)
    {
        $class = '\Rats\Zkteco\Lib\ZKTeco';

        if (! class_exists($class)) {
            return back()->with('error', 'ZKTeco library not installed.');
        }

        try {
            $zk = new $class($device->ip_address, (int) $device->port);

            if (! $zk->connect()) {
                $device->update(['status' => 'offline']);

                return back()->with('error', "Could not reach {$device->name} at {$device->ip_address}:{$device->port}. Check the device is on and on the same network.");
            }

            $logs = $zk->getAttendance() ?: [];
            $zk->disconnect();

            $imported = $this->importLogs($device, $logs);
            $device->update(['status' => 'online', 'last_sync_at' => now()]);

            return back()->with('success', "Synced {$device->name}: {$imported} attendance record(s) imported.");
        } catch (\Throwable $e) {
            $device->update(['status' => 'offline']);

            return back()->with('error', "Sync failed for {$device->name}: device unreachable ({$device->ip_address}).");
        }
    }

    /** In-app sync (JSON): triggered per device from the Biometric Devices screen. */
    public function syncById(Request $request, int $id)
    {
        if ($deny = ApprovalService::denyUnlessRole($request, ['admin', 'hr_manager'])) {
            return $deny;
        }
        $device = Device::find($id);
        if (! $device) {
            return response()->json(['ok' => false, 'error' => 'Device not found.'], 404);
        }
        if (empty($device->ip_address)) {
            return response()->json(['ok' => false, 'error' => 'Set the device IP address (and port) first.'], 422);
        }
        $class = '\Rats\Zkteco\Lib\ZKTeco';
        if (! class_exists($class)) {
            return response()->json(['ok' => false, 'error' => 'ZKTeco library not installed on the server.'], 422);
        }
        try {
            $zk = new $class($device->ip_address, (int) ($device->port ?: 4370));
            if (! $zk->connect()) {
                $device->update(['status' => 'offline']);

                return response()->json(['ok' => false, 'error' => 'Could not reach '.$device->name.' at '.$device->ip_address.':'.($device->port ?: 4370).'. Check it is on the same network.'], 422);
            }
            $logs = $zk->getAttendance() ?: [];
            $zk->disconnect();
            $imported = $this->importLogs($device, $logs);
            $device->update(['status' => 'online', 'last_sync_at' => now()]);

            return response()->json(['ok' => true, 'message' => 'Synced '.$device->name.': '.$imported.' attendance record(s) imported.']);
        } catch (\Throwable $e) {
            $device->update(['status' => 'offline']);

            return response()->json(['ok' => false, 'error' => 'Sync failed for '.$device->name.' — device unreachable ('.$device->ip_address.').'], 422);
        }
    }

    /**
     * Map device punch logs to employees (by device_user_id) and mark attendance.
     */
    private function importLogs(Device $device, array $logs): int
    {
        $companyId = $device->company_id;
        $byUserDate = [];

        foreach ($logs as $log) {
            $deviceUserId = $log['id'] ?? ($log['user_id'] ?? null);
            $timestamp = $log['timestamp'] ?? ($log['record_time'] ?? null);
            if (! $deviceUserId || ! $timestamp) {
                continue;
            }
            $date = Carbon::parse($timestamp)->toDateString();
            $time = Carbon::parse($timestamp)->format('H:i:s');
            $key = $deviceUserId.'|'.$date;

            if (! isset($byUserDate[$key])) {
                $byUserDate[$key] = ['device_user_id' => $deviceUserId, 'date' => $date, 'in' => $time, 'out' => $time];
            } else {
                $byUserDate[$key]['in'] = min($byUserDate[$key]['in'], $time);
                $byUserDate[$key]['out'] = max($byUserDate[$key]['out'], $time);
            }
        }

        $imported = 0;
        foreach ($byUserDate as $row) {
            $employee = Employee::where('company_id', $companyId)
                ->where('device_user_id', $row['device_user_id'])->first();
            if (! $employee) {
                continue;
            }

            // attendance_logs is the SINGLE source of truth for attendance —
            // payroll (LOP/late cuts), the attendance reports, Points and ESS all
            // read it. Device punches are written straight here as in/out rows. The
            // legacy `attendance` summary table (check_in/check_out) is intentionally
            // no longer written: it had no live reader and caused a two-store seam.
            $this->writeLog($employee, $row['date'], $row['in'], 'in', $companyId);
            $this->writeLog($employee, $row['date'], $row['out'], 'out', $companyId);
            $imported++;
            // F4 — immediate late-arrival email (fail-soft, OFF unless enabled).
            LateArrivalService::evaluate($employee->tenant_id ?? null, (string) $employee->emp_code, (string) $row['date']);
        }

        return $imported;
    }

    /** Mirror a device punch into attendance_logs (emp_code/log_date) for payroll + reports. */
    private function writeLog(Employee $employee, string $date, string $time, string $direction, $companyId): void
    {
        if (! $employee->emp_code) {
            return;
        }
        $this->ensureLogsTable();
        // rev172 — tenant_id in the MATCH keys (when present): emp codes repeat
        // across tenants, so without it one tenant's device punch could overwrite
        // another tenant's row for the same code/date.
        $match = ['emp_code' => $employee->emp_code, 'log_date' => $date, 'direction' => $direction, 'source' => 'device'];
        if (! empty($employee->tenant_id)) {
            $match['tenant_id'] = $employee->tenant_id;
        }
        DB::table('attendance_logs')->updateOrInsert(
            $match,
            [
                'emp_name' => $employee->name,
                'punch_at' => $date.' '.$time,
                'tenant_id' => $employee->tenant_id ?? null,
                'company_id' => $companyId,
                'updated_at' => now(),
            ]
        );
    }

    /** Create attendance_logs with the columns payroll/reports expect, if it doesn't exist yet. */
    private function ensureLogsTable(): void
    {
        if (Schema::hasTable('attendance_logs')) {
            return;
        }
        Schema::create('attendance_logs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->nullable()->index();
            $t->unsignedBigInteger('company_id')->nullable();
            $t->string('emp_code')->nullable()->index();
            $t->string('emp_name')->nullable();
            $t->date('log_date')->nullable()->index();
            $t->dateTime('punch_at')->nullable();
            $t->string('direction')->nullable();
            $t->string('source')->nullable();
            $t->timestamps();
        });
    }

    private function validateDevice(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'ip_address' => ['required', 'string', 'max:45'],
            'port' => ['required', 'integer', 'min:1', 'max:65535'],
            'serial_number' => ['nullable', 'string', 'max:100'],
            'location' => ['nullable', 'string', 'max:150'],
        ]);
    }
}
