<?php

namespace Ametecs\Qronex\Models;

use Ametecs\Qronex\Models\Concerns\Tenanted;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AttendanceRejection extends Model
{
    use Tenanted;

    public const UPDATED_AT = null;

    protected $table = 'qx_attendance_rejections';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['checks' => 'array', 'created_at' => 'datetime'];
    }

    public function employee(): BelongsTo { return $this->belongsTo(Employee::class); }
    public function gate(): BelongsTo { return $this->belongsTo(Gate::class); }
}
