<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Loan extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'employee_id', 'type', 'principal', 'installment_amount',
        'installments_total', 'installments_paid', 'start_month', 'status', 'note',
    ];

    protected function casts(): array
    {
        return [
            'principal' => 'decimal:2',
            'installment_amount' => 'decimal:2',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** Installment due this run if the loan is still active and not fully paid. */
    public function dueInstallment(): float
    {
        if ($this->status === 'active' && $this->installments_paid < $this->installments_total) {
            return (float) $this->installment_amount;
        }

        return 0.0;
    }
}
