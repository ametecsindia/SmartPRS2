<?php

namespace Ametecs\Qronex\Http\Controllers\Admin;

use Ametecs\Qronex\Models\AttendanceEvent;
use Ametecs\Qronex\Models\AttendanceRejection;
use Ametecs\Qronex\Models\Office;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class AttendanceController extends Base
{
    private function filtered(Request $request, string $model)
    {
        $tz = 'Asia/Kolkata';
        $from = Carbon::parse($request->query('from', today($tz)->toDateString()), $tz)->startOfDay()->setTimezone(config('app.timezone'));
        $to = Carbon::parse($request->query('to', $request->query('from', today($tz)->toDateString())), $tz)->endOfDay()->setTimezone(config('app.timezone'));
        $col = $model === AttendanceEvent::class ? 'recorded_at' : 'created_at';

        return $model::with(['employee.office', 'gate.office'])
            ->whereBetween($col, [$from, $to])
            ->when($request->office, fn ($q, $o) => $q->whereHas('employee', fn ($e) => $e->where('office_id', $o)))
            ->when($request->q, fn ($q, $s) => $q->whereHas('employee', fn ($e) => $e->where(fn ($w) => $w->where('name', 'like', "%$s%")->orWhere('employee_code', 'like', "%$s%"))))
            ->latest($col);
    }

    public function index(Request $request)
    {
        $tab = $request->query('tab') === 'rejected' ? 'rejected' : 'verified';

        return view('qronex::attendance', [
            'tab' => $tab,
            'rows' => $this->filtered($request, $tab === 'rejected' ? AttendanceRejection::class : AttendanceEvent::class)->paginate(100)->withQueryString(),
            'offices' => Office::orderBy('name')->get(),
        ]);
    }

    public function export(Request $request)
    {
        $rows = $this->filtered($request, AttendanceEvent::class)->reorder('recorded_at');

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['ref', 'employee_code', 'employee_name', 'type', 'date_ist', 'time_ist', 'office', 'gate', 'distance_m', 'accuracy_m', 'mode', 'adjusted', 'in_host']);
            $rows->chunk(1000, function ($chunk) use ($out) {
                foreach ($chunk as $e) {
                    $t = $e->recorded_at->timezone('Asia/Kolkata');
                    fputcsv($out, [$e->ref, $e->employee->employee_code, $e->employee->name, $e->type, $t->toDateString(), $t->format('H:i:s'), $e->gate?->office?->name ?? $e->employee->office?->name, $e->gate?->name, $e->distance_m, $e->accuracy_m, $e->mode, $e->adjusted ? 'yes' : '', $e->host_synced_at ? 'yes' : 'pending']);
                }
            });
        }, 'qr-attendance-'.now('Asia/Kolkata')->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv']);
    }
}
