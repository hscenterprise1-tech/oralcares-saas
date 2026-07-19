<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PatientProfile extends Model
{
    protected $fillable = [
        'patient_id',
        'preferred_name',
        'secondary_phone',
        'address',
        'city',
        'state',
        'country',
        'postal_code',
        'occupation',
        'nationality',
        'emergency_contact_name',
        'emergency_contact_phone',
        'referral_source',
    ];

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }
}
