<?php

namespace App\Models;

use App\Support\Specialty\PracticeAreas;
use App\Support\Specialty\SpecialtyRegistry;
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
        'profession',
        'specialty',
        'primary_specialty_id',
        'registration_number',
        'registration_authority',
        'registration_state',
        'registration_type',
        'registration_date',
        'registration_expires_at',
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
            'registration_date' => 'date',
            'registration_expires_at' => 'date',
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
     * Structured professional qualifications, in display order.
     */
    public function qualificationRecords(): HasMany
    {
        return $this->hasMany(PractitionerQualification::class, 'doctor_profile_id')->orderBy('position')->orderBy('id');
    }

    /**
     * The primary specialty, from the organisation's specialty catalogue.
     */
    public function primarySpecialty(): BelongsTo
    {
        return $this->belongsTo(ClinicalSpecialty::class, 'primary_specialty_id')->withoutGlobalScopes();
    }

    /**
     * Assigned specialty catalog items (the additional specialties).
     */
    public function specialties(): BelongsToMany
    {
        return $this->belongsToMany(ClinicalSpecialty::class, 'doctor_specialties', 'doctor_profile_id');
    }

    /**
     * The practice areas (registry keys, `PracticeAreas`) this practitioner
     * practises, primary first. Inactive profiles practise nothing.
     *
     * The specialty assignments are the source: the primary and additional
     * catalogue specialties that carry a `practice_area_key`. A profile
     * recorded before areas existed is read, without writing, from the names
     * it does have: its specialty names, then its qualifications'
     * specializations ("MDS (Orthodontics)"), then its broad title
     * ("Dentist" → General Dentistry).
     *
     * @return list<string>
     */
    public function practiceAreaKeys(): array
    {
        if (! $this->is_active) {
            return [];
        }

        $areas = app(PracticeAreas::class);
        $primary = $this->primary_specialty_id ? $this->primarySpecialty : null;
        $additional = $this->relationLoaded('specialties') ? $this->specialties : $this->specialties()->withoutGlobalScopes()->get();
        $assigned = collect([$primary])->merge($additional)->filter();

        $keyed = $assigned->pluck('practice_area_key')->filter(fn ($key) => $areas->get($key) !== null)->unique()->values()->all();
        if ($keyed !== []) {
            return $keyed;
        }

        $inferred = fn ($names, callable $match) => collect($names)->filter()->map($match)->filter()->unique()->values()->all();

        $qualifications = $this->relationLoaded('qualificationRecords') ? $this->qualificationRecords : $this->qualificationRecords()->withoutGlobalScopes()->get();

        return $inferred([$primary?->name, $this->specialty, ...$assigned->pluck('name')], fn ($name) => $areas->match($name))
            ?: $inferred($qualifications->pluck('specialization'), fn ($name) => $areas->match($name))
            ?: $inferred([$this->specialty, $this->profession], fn ($name) => $areas->forTitle($name));
    }

    /** The practitioner's main practice area, or null when none is known. */
    public function primaryPracticeArea(): ?string
    {
        return $this->practiceAreaKeys()[0] ?? null;
    }

    /**
     * The specialty-registry keys this practitioner practises: for each
     * practice area, the area's own specialty and its module's root (an
     * orthodontist practises `orthodontics` and `dental`). Inactive
     * profiles practise nothing.
     *
     * @return list<string>
     */
    public function specialtyKeys(): array
    {
        $areas = app(PracticeAreas::class);
        $keys = collect($this->practiceAreaKeys())->flatMap(fn (string $area) => $areas->specialtiesFor($area));

        return app(SpecialtyRegistry::class)->normalize($keys);
    }

    /**
     * What this practitioner may do clinically: the capabilities of their
     * practice areas (and those areas' modules). From the specialty
     * assignment only — a qualification such as MDS grants nothing by itself.
     *
     * @return list<string>
     */
    public function capabilityKeys(): array
    {
        return array_keys(app(PracticeAreas::class)->capabilities($this->practiceAreaKeys()));
    }

    /**
     * "BDS · MDS": the practitioner's qualifications, highest last, each
     * once; the legacy free-text field when none are recorded.
     */
    public function credentials(): ?string
    {
        $rank = ['certification' => 0, 'other' => 1, 'diploma' => 2, 'bachelor' => 3, 'master' => 4, 'fellowship' => 5, 'doctorate' => 6];
        $records = $this->relationLoaded('qualificationRecords') ? $this->qualificationRecords : $this->qualificationRecords()->withoutGlobalScopes()->get();

        $names = $records->sortBy(fn (PractitionerQualification $q) => [$rank[$q->degree_type] ?? 1, $q->position])
            ->pluck('qualification')->map(fn ($name) => trim((string) $name))->filter()->unique()->values();

        return $names->isNotEmpty() ? $names->implode(' · ') : ($this->qualifications ?: null);
    }

    /**
     * Appointments assigned to this practitioner.
     */
    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class, 'practitioner_id', 'clinic_employee_id');
    }
}
