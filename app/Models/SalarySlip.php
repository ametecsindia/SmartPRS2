<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalarySlip extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'salary_run_id', 'employee_id', 'gross', 'present_days',
        'absent_days', 'lwp_amount', 'loan_deduction', 'other_deduction', 'net_salary',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(SalaryRun::class, 'salary_run_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function totalDeductions(): float
    {
        return (float) $this->lwp_amount + (float) $this->loan_deduction + (float) $this->other_deduction;
    }
}
