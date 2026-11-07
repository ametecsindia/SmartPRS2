<?php

namespace Ametecs\Qronex\Models;

use Ametecs\Qronex\Models\Concerns\Tenanted;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PunchCorrection extends Model
{
    use Tenanted;

    protected $table = 'qx_punch_corrections';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['punch_at' => 'datetime', 'decided_at' => 'datetime'];
    }

    public function decider(): BelongsTo { return $this->belongsTo(config('auth.providers.users.model'), 'decided_by'); }
    public function employee(): BelongsTo { return $this->belongsTo(Employee::class); }
}
