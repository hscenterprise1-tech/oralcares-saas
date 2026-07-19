<?php

namespace App\Models;

use App\LabTrialType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LabCaseTrial extends Model
{
    protected $fillable = [
        'lab_order_id',
        'trial_type',
        'scheduled_at',
        'notes',
        'outcome',
    ];

    protected function casts(): array
    {
        return [
            'trial_type' => LabTrialType::class,
            'scheduled_at' => 'datetime',
        ];
    }

    public function labOrder(): BelongsTo
    {
        return $this->belongsTo(LabOrder::class, 'lab_order_id');
    }
}
