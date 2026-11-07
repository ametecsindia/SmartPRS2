<?php

namespace Ametecs\Qronex\Models;

use Ametecs\Qronex\Models\Concerns\Tenanted;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Office extends Model
{
    use Tenanted;

    protected $table = 'qx_offices';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['lat' => 'float', 'lng' => 'float', 'is_active' => 'boolean'];
    }

    public function gates(): HasMany { return $this->hasMany(Gate::class); }
    public function employees(): HasMany { return $this->hasMany(Employee::class); }
}
