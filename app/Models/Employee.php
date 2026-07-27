<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * First Core-HR module. Tenant-scoped via BelongsToCompany.
 * Salary math (gross/basic/allowance/deduction, loan, advance, LWP) follows
 * SmartPRS-Harvest-Reference.md §4 — to be implemented in the Payroll module.
 */
class Employee extends Model
{
    use BelongsToCompany;
    use HasFactory;
    use LogsActivity;
    use SoftDeletes;

    protected $fillable = [
        'company_id',
        'employee_code',
        'first_name',
        'last_name',
        'email',
        'phone',
        'department_id',
        'position',
        'date_of_joining',
        'employment_type',   // permanent | contract | field
        'gross_salary',      // decimal — never float
        'status',            // active | inactive | exited
        'device_user_id',    // maps to ZKTeco device user id
    ];

    protected function casts(): array
    {
        return [
            'date_of_joining' => 'date',
            'gross_salary' => 'decimal:2',
        ];
    }

    public function user(): HasOne
    {
        return $this->hasOne(User::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function fullName(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
