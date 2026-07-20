<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UsageMetric extends Model
{
    protected $fillable = [
        'clinic_id',
        'metric_key',
        'metric_value',
        'recorded_date',
    ];

    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }
}
