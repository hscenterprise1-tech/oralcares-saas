<?php

namespace App\Models;

use App\Traits\ClinicScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A branch's close of one business day. Written by `DayClosing` only.
 */
class DailyCashClosing extends Model
{
    use ClinicScoped;

    protected $fillable = [
        'clinic_id',
        'branch_id',
        'business_date',
        'methods',
        'payments_count',
        'expected_cash',
        'counted_cash',
        'difference',
        'notes',
        'closed_by',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'business_date' => 'date',
            'methods' => 'array',
            'expected_cash' => 'decimal:2',
            'counted_cash' => 'decimal:2',
            'difference' => 'decimal:2',
            'closed_at' => 'datetime',
        ];
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(ClinicEmployee::class, 'closed_by');
    }
}
