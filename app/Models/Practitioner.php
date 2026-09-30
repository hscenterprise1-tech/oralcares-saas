<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\ClinicScoped;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * Generic healthcare practitioner / provider.
 *
 * A Practitioner is a healthcare professional (Dentist, Physiotherapist,
 * General Physician, Orthopedic, Dermatologist, etc.) associated with a clinic.
 *
 * This is the one practitioner profile model (table `doctor_profiles`).
 * The staff chain is User → ClinicMembership → ClinicEmployee → this profile:
 * - Clinical identity is the `ClinicEmployee`; every appointment, visit,
 *   queue item and encounter points at `clinic_employees.id`. This profile
 *   only adds what a clinician has on top (registration, specialty, fee).
 * - One profile per staff record, in the staff record's own clinic.
 * - The staff record MAY or MAY NOT have a login (`clinic_employees.user_id`).
 * - Specialties are data (`specialty`, `doctor_specialties`), so a new
 *   discipline needs no new model. `DoctorProfile` is a deprecated alias.
 */
class Practitioner extends Model
{
    use Auditable, ClinicScoped, HasFactory;

    protected $table = 'doctor_profiles';

    /**
     * The profile always lives in its staff record's clinic: the clinic is
     * taken from the staff record when not given, and a mismatch is refused.
     */
    protected function performInsert(Builder $query)
    {
        $this->assertOwnClinic();

        return parent::performInsert($query);
    }

    protected function performUpdate(Builder $query)
    {
        $this->assertOwnClinic();

        return parent::performUpdate($query);
    }

    private function assertOwnClinic(): void
    {
        $employeeClinic = ClinicEmployee::withoutGlobalScopes()->whereKey($this->clinic_employee_id)->value('clinic_id');

        if (! $employeeClinic) {
            throw new LogicException('A practitioner profile needs a staff record.');
        }

        $this->clinic_id ??= $employeeClinic;
        $this->organization_id ??= Clinic::query()->whereKey($employeeClinic)->value('organization_id');

        if ((int) $this->clinic_id !== (int) $employeeClinic) {
            throw new LogicException('A practitioner profile must belong to its staff record\'s clinic.');
        }
    }

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
    /**
     * @deprecated Legacy weekly windows keyed by profile. Use `schedules()`.
     */
    public function availabilities(): HasMany
    {
        return $this->hasMany(DoctorAvailability::class, 'doctor_profile_id');
    }

    /**
     * Weekly windows at each branch (`practitioner_schedules`).
     */
    public function schedules(): HasMany
    {
        return $this->hasMany(PractitionerSchedule::class, 'clinic_employee_id', 'clinic_employee_id');
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
