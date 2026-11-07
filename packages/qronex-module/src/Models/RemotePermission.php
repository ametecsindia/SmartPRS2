<?php

namespace Ametecs\Qronex\Models;

use Ametecs\Qronex\Models\Concerns\Tenanted;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RemotePermission extends Model
{
    use Tenanted;

    public const TYPES = ['wfh' => 'Work from home', 'field_duty' => 'Field duty', 'client_site' => 'Client-site work', 'temporary_remote' => 'Temporary remote duty'];

    /** Event mode written for remote punches of each type. */
    public const MODES = ['wfh' => 'WFH_APPROVED', 'field_duty' => 'FIELD_APPROVED', 'client_site' => 'CLIENT_SITE_APPROVED', 'temporary_remote' => 'REMOTE_APPROVED'];

    protected $table = 'qx_remote_permissions';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['from_date' => 'date', 'to_date' => 'date', 'location_waived' => 'boolean', 'decided_at' => 'datetime', 'lat' => 'float', 'lng' => 'float'];
    }

    public function decider(): BelongsTo { return $this->belongsTo(config('auth.providers.users.model'), 'decided_by'); }
    public function employee(): BelongsTo { return $this->belongsTo(Employee::class); }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    /** Approved permission covering today's IST date for this employee. */
    public static function activeFor(int $employeeId): ?self
    {
        $today = now('Asia/Kolkata')->toDateString();

        return static::withoutGlobalScopes()->where('employee_id', $employeeId)->where('status', 'approved')
            ->whereDate('from_date', '<=', $today)->whereDate('to_date', '>=', $today)->latest('id')->first();
    }

    public function toApi(): array
    {
        return [
            'id' => $this->ref, 'employee_code' => $this->employee?->employee_code, 'type' => $this->type, 'type_label' => $this->typeLabel(),
            'from' => $this->from_date->toDateString(), 'to' => $this->to_date->toDateString(), 'status' => $this->status,
            'location' => $this->location_waived ? null : ['label' => $this->location_label, 'lat' => $this->lat, 'lng' => $this->lng, 'radius_m' => $this->radius_m],
            'location_waived' => $this->location_waived, 'reason' => $this->reason,
        ];
    }
}
