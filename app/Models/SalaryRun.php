<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalaryRun extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'month', 'status', 'total_gross', 'total_net', 'generated_at',
    ];

    protected function casts(): array
    {
        return ['generated_at' => 'datetime'];
    }

    public function slips(): HasMany
    {
        return $this->hasMany(SalarySlip::class);
    }
}
