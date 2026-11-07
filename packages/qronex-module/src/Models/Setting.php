<?php

namespace Ametecs\Qronex\Models;

use Ametecs\Qronex\Models\Concerns\Tenanted;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Setting extends Model
{
    use Tenanted;

    protected $table = 'qx_settings';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'require_device_approval' => 'boolean', 'notify_device_requests' => 'boolean', 'notify_remote_requests' => 'boolean', 'employees_synced_at' => 'datetime'];
    }

    public static function defaults(): array
    {
        return [
            'enabled' => false, 'qr_interval_s' => 3, 'grace_s' => 5, 'require_device_approval' => true, 'duplicate_window_min' => 2, 'gps_accuracy_m' => 50,
            'notify_device_requests' => true, 'notify_remote_requests' => true,
            'location_notice' => 'Your phone location is checked only at the moment you mark attendance. Location is not tracked at any other time.',
        ];
    }
}
