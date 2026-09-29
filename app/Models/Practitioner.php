<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\ClinicScoped;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Generic healthcare practitioner / provider.
 *
 * A Practitioner is a healthcare professional (Dentist, Physiotherapist,
 * General Physician, Orthopedic, Dermatologist, etc.) associated with a clinic.
 *
 * Decoupled Architecture:
 * - A Practitioner links to a `ClinicEmployee` record.
 * - A Practitioner MAY or MAY NOT have a login (`user_id` on `ClinicEmployee` is nullable).
 * - Reception/Staff can enter appointments & clinical records on behalf of any Practitioner.
 * - The actual operator who entered data is separately recorded via `created_by` / `Auditable`.
 */
class Practitioner extends Model
{
    use Auditable, ClinicScoped, HasFactory;

    protected $table = 'doctor_profiles';

    protected $fillable = [
        'clinic_id',
        'organization_id',
        'clinic_employee_id',
        'specialty',
        'registration_number',
        'qualifications',
        'experience_years',
        'consultation_fee',
        'bio',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'experience_years' => 'integer',
            'consultation_fee' => 'decimal:2',
        ];
    }

    /**
     * The clinic this practitioner belongs to.
     */
    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    /**
     * The commercial organization account.
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * The underlying clinic employee record.
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(ClinicEmployee::class, 'clinic_employee_id');
    }

    /**
     * Backwards-compatible alias for employee relationship.
     */
    public function clinicEmployee(): BelongsTo
    {
        return $this->employee();
    }

    /**
     * The user login account (if one exists).
     */
    public function getUserAttribute(): ?User
    {
        return $this->employee?->user;
    }

    /**
     * Get the practitioner's display name from employee record.
     */
    public function getNameAttribute(): string
    {
        return $this->employee?->name ?? 'Practitioner';
    }

    /**
     * Get practitioner's assigned branches through employee.
     */
    public function branches()
    {
        return $this->employee?->branches();
    }

    /**
     * Availabilities / scheduling slots.
     */
    public function availabilities(): HasMany
    {
        return $this->hasMany(DoctorAvailability::class, 'doctor_profile_id');
    }

    /**
     * Assigned specialty catalog items.
     */
    public function specialties(): BelongsToMany
    {
        return $this->belongsToMany(ClinicalSpecialty::class, 'doctor_specialties', 'doctor_profile_id');
    }

    /**
     * Appointments assigned to this practitioner.
     */
    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class, 'practitioner_id', 'clinic_employee_id');
    }
}
