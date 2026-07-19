<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnalyticsSnapshot extends Model
{
    protected $fillable = [
        'clinic_id',
        'branch_id',
        'metric_key',
        'metric_value',
        'dimension_date',
        'calculated_at',
    ];

    protected function casts(): array
    {
        return [
            'metric_value' => 'array',
            'calculated_at' => 'datetime',
        ];
    }

    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
