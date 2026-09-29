<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyPayroll extends Model
{
    use HasFactory;

    protected $fillable = [
        'payroll_code',
        'employee_id',
        'month',
        'gross_amount',
        'deductions',
        'net_amount',
        'status',
        'paid_date',
    ];

    protected function casts(): array
    {
        return [
            'gross_amount' => 'decimal:2',
            'deductions' => 'decimal:2',
            'net_amount' => 'decimal:2',
            'paid_date' => 'date',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(CompanyEmployee::class, 'employee_id');
    }
}
