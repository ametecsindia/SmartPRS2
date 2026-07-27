<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class Device extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'name', 'ip_address', 'port', 'serial_number', 'location', 'status', 'last_sync_at',
    ];

    protected function casts(): array
    {
        return ['last_sync_at' => 'datetime'];
    }
}
