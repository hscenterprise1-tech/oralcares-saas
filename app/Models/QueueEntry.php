<?php

namespace App\Models;

use App\Traits\OrganizationScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QueueEntry extends Model
{
    use OrganizationScoped;

    protected $fillable = [
        'organization_id',
        'branch_id',
        'patient_id',
        'provider_id',
        'appointment_id',
        'status',
        'priority',
        'check_in_time',
        'called_time',
        'completed_time',
    ];

    protected function casts(): array
    {
        return [
            'check_in_time' => 'datetime',
            'called_time' => 'datetime',
            'completed_time' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'provider_id');
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }
}
