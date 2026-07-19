<?php

namespace App\Models;

use App\VisitPriority;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClinicQueueItem extends Model
{
    protected $fillable = [
        'clinic_queue_id',
        'encounter_id',
        'position',
        'priority',
        'called_at',
    ];

    protected function casts(): array
    {
        return [
            'priority' => VisitPriority::class,
            'called_at' => 'datetime',
        ];
    }

    public function queue(): BelongsTo
    {
        return $this->belongsTo(ClinicQueue::class, 'clinic_queue_id');
    }

    public function encounter(): BelongsTo
    {
        return $this->belongsTo(ClinicalEncounter::class, 'encounter_id');
    }
}
