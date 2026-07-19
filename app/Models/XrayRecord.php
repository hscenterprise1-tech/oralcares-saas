<?php

namespace App\Models;

use App\XrayCategory;
use App\XrayRegion;
use App\XrayStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class XrayRecord extends Model
{
    protected $fillable = [
        'patient_id',
        'encounter_id',
        'xray_number',
        'file_path',
        'notes',
        'status',
        'category',
        'requesting_doctor_id',
        'requested_region',
        'requested_teeth',
        'clinical_reason',
        'priority',
        'findings',
        'impression',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'category' => XrayCategory::class,
            'status' => XrayStatus::class,
            'requested_region' => XrayRegion::class,
            'requested_teeth' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function encounter(): BelongsTo
    {
        return $this->belongsTo(ClinicalEncounter::class, 'encounter_id');
    }

    public function requestingDoctor(): BelongsTo
    {
        return $this->belongsTo(ClinicEmployee::class, 'requesting_doctor_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(XrayAttachment::class)->orderBy('version');
    }
}
