<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\OrganizationScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PatientProfile extends Model
{
    use Auditable, OrganizationScoped;

    protected $fillable = [
        'organization_id',
        'patient_id',
        'preferred_name',
        'secondary_phone',
        'address',
        'city',
        'state',
        'country',
        'postal_code',
        'pincode',
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
