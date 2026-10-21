<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @deprecated Timer rows duplicating the encounter's own `started_at` /
 * `ended_at`. No longer written by `EncounterWorkspace`; existing timing is
 * folded into encounters by `patients:backfill-foundation`. Still used only
 * by the unrouted legacy `ConsultationService`. Table retirement is a later,
 * approved step.
 */
class ClinicalConsultation extends Model
{
    protected $fillable = [
        'encounter_id',
        'doctor_id',
        'status',
        'started_at',
        'paused_at',
        'ended_at',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'paused_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function encounter(): BelongsTo
    {
        return $this->belongsTo(ClinicalEncounter::class, 'encounter_id');
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(ClinicEmployee::class, 'doctor_id');
    }
}
