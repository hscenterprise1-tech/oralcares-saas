<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyIncentive extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'description',
        'amount',
        'awarded_date',
        'related_lead_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'awarded_date' => 'date',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(CompanyEmployee::class, 'employee_id');
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class, 'related_lead_id');
    }
}
