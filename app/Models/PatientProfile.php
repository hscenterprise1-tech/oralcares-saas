<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\ClinicScoped;
use App\Traits\InheritsClinicOwnership;
use App\Traits\OrganizationScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PatientProfile extends Model
{
    use Auditable, ClinicScoped, InheritsClinicOwnership, OrganizationScoped;

    protected $fillable = [
        'clinic_id',
        'organization_id',
        'patient_id',
        'preferred_name',
        'address',
        'locality',
        'city',
        'state',
        'country',
        'postal_code',
        'pincode',
        'preferred_channel',
        'occupation',
        'nationality',
        'emergency_contact_name',
        'emergency_contact_phone',
        'allergy_status',
        'referral_source',
    ];

    /** Channels a patient can prefer to be contacted on. */
    public const CHANNELS = ['phone', 'sms', 'whatsapp', 'email'];

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /**
     * `postal_code` is the canonical column. Writers that still say
     * `pincode` land there; the legacy `pincode` column is only read for
     * rows the foundation backfill has not consolidated yet.
     */
    public function setPincodeAttribute(?string $value): void
    {
        $this->attributes['postal_code'] = $value;
    }

    public function getPincodeAttribute(?string $value): ?string
    {
        return $this->attributes['postal_code'] ?? $value;
    }
}
