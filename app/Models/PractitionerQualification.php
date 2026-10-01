<?php

namespace App\Models;

use App\Traits\ClinicScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One professional qualification of a practitioner (BDS, MDS, a fellowship,
 * a certification), optionally backed by an uploaded staff document.
 */
class PractitionerQualification extends Model
{
    use ClinicScoped;

    public const VERIFICATION = ['unverified', 'verified', 'rejected'];

    protected $fillable = [
        'clinic_id',
        'doctor_profile_id',
        'qualification',
        'degree_type',
        'specialization',
        'institution',
        'year',
        'country',
        'state',
        'staff_document_id',
        'verification_status',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'position' => 'integer',
        ];
    }

    public function practitioner(): BelongsTo
    {
        return $this->belongsTo(Practitioner::class, 'doctor_profile_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(StaffDocument::class, 'staff_document_id');
    }

    /**
     * "MDS (Orthodontics)".
     */
    public function label(): string
    {
        return $this->specialization ? "{$this->qualification} ({$this->specialization})" : $this->qualification;
    }
}
