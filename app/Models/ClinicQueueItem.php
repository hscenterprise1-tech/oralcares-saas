<?php

namespace App\Models;

use App\QueueItemStatus;
use App\VisitPriority;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClinicQueueItem extends Model
{
    protected $fillable = [
        'clinic_queue_id',
        'encounter_id',
        'position',
        'priority',
        'called_at',
        'status',
        'current_floor',
        'current_department',
    ];

    protected function casts(): array
    {
        return [
            'priority' => VisitPriority::class,
            'status' => QueueItemStatus::class,
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

    public function histories(): HasMany
    {
        return $this->hasMany(QueueHistory::class, 'clinic_queue_item_id');
    }
}
