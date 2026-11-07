<?php

namespace Ametecs\Qronex\Models;

use Ametecs\Qronex\Models\Concerns\Tenanted;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AttendanceEvent extends Model
{
    use Tenanted;

    public const UPDATED_AT = null;

    protected $table = 'qx_attendance_events';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['recorded_at' => 'datetime', 'created_at' => 'datetime', 'host_synced_at' => 'datetime', 'lat' => 'float', 'lng' => 'float', 'adjusted' => 'boolean'];
    }

    public function employee(): BelongsTo { return $this->belongsTo(Employee::class); }
    public function office(): BelongsTo { return $this->belongsTo(Office::class); }
    public function gate(): BelongsTo { return $this->belongsTo(Gate::class); }
    public function device(): BelongsTo { return $this->belongsTo(Device::class); }
    public function remotePermission(): BelongsTo { return $this->belongsTo(RemotePermission::class); }
    public function correction(): BelongsTo { return $this->belongsTo(PunchCorrection::class, 'punch_correction_id'); }
}
