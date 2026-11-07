<?php

namespace Ametecs\Qronex\Models;

use Ametecs\Qronex\Models\Concerns\Tenanted;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DeviceEvent extends Model
{
    use Tenanted;

    public const UPDATED_AT = null;

    public const LABELS = [
        'registered' => 'Phone activated — waiting for approval', 're_registered' => 'Phone re-activated',
        'replacement_requested' => 'Replacement phone requested', 'approved' => 'Approved', 'rejected' => 'Rejected',
        'revoked' => 'Revoked', 'replaced' => 'Revoked — replaced by a new phone', 'auto_approved' => 'Activated (approval not required)',
    ];

    protected $table = 'qx_device_events';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['meta' => 'array', 'created_at' => 'datetime'];
    }

    public function actor(): BelongsTo { return $this->belongsTo(config('auth.providers.users.model'), 'actor_user_id'); }
    public function device(): BelongsTo { return $this->belongsTo(Device::class); }
}
