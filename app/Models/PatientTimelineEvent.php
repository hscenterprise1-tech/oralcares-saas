<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\ClinicScoped;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PatientTimelineEvent extends Model
{
    use Auditable, ClinicScoped, HasFactory;

    protected $fillable = [
        'organization_id',
        'clinic_id',
        'patient_id',
        'actor_id',
        'event_type',
        'context',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
        ];
    }

    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
