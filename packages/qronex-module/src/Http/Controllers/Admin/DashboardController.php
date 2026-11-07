<?php

namespace Ametecs\Qronex\Http\Controllers\Admin;

use Ametecs\Qronex\Models\AttendanceEvent;
use Ametecs\Qronex\Models\AttendanceRejection;
use Ametecs\Qronex\Models\AuditLog;
use Ametecs\Qronex\Models\Employee;
use Ametecs\Qronex\Models\Office;

class DashboardController extends Base
{
    public function __invoke()
    {
        $start = now('Asia/Kolkata')->startOfDay()->setTimezone(config('app.timezone'));
        $in = AttendanceEvent::where('recorded_at', '>=', $start)->where('type', 'IN')->distinct()->count('employee_id');

        return view('qronex::dashboard', [
            'stats' => [
                'employees' => Employee::where('status', 'active')->count(),
                'present' => $in,
                'punches' => AttendanceEvent::where('recorded_at', '>=', $start)->count(),
                'rejected' => AttendanceRejection::where('created_at', '>=', $start)->count(),
                'unsynced' => AttendanceEvent::whereNull('host_synced_at')->count(),
            ],
            'pending' => collect(ApprovalsController::pending())->map->count(),
            'offices' => Office::withCount(['gates', 'employees'])->orderBy('name')->get(),
            'audit' => AuditLog::latest('created_at')->latest('id')->take(8)->get(),
        ]);
    }
}
