<?php

namespace App\Models;

use App\PatientStatus;
use App\Services\Patient\UhidGenerator;
use App\Traits\Auditable;
use App\Traits\ClinicScoped;
use App\VisitRegistrationType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

class Patient extends Model
{
    use Auditable, ClinicScoped, HasFactory;

    protected $fillable = [
        'clinic_id',
        'organization_id',
        // Phase 7 – permanent identity
        'uhid',
        'first_name',
        'middle_name',
        'last_name',
        'age',
        'blood_group',
        'registration_type',
        // Core demographics
        'email',
        'phone',
        'alternate_phone',
        'date_of_birth',
        'gender',
        'status',
        'current_practitioner_id',
        // Legacy – kept for backward compatibility
        'patient_number',
        'name',
    ];

    protected function casts(): array
    {
        return [
            'status' => PatientStatus::class,
            'registration_type' => VisitRegistrationType::class,
            'date_of_birth' => 'date',
            // Left from the retired deceased status (removed 2026-11-02); kept empty, never written.
            'date_of_death' => 'date',
            'death_recorded_at' => 'datetime',
        ];
    }

    /**
     * The UHID is the patient's lifetime identity inside a clinic: generated
     * once when the record is inserted (unless a caller supplied one) and
     * never changed afterwards.
     *
     * Enforced in the insert/update path itself rather than in `creating`/
     * `updating` listeners, which `Event::fake()`, `saveQuietly()` and
     * `withoutEvents()` all silence.
     */
    protected function performInsert(Builder $query)
    {
        if (blank($this->uhid) && $this->clinic_id) {
            $this->uhid = app(UhidGenerator::class)->generate(Clinic::withoutGlobalScopes()->findOrFail($this->clinic_id));
        }

        return parent::performInsert($query);
    }

    protected function performUpdate(Builder $query)
    {
        if ($this->isDirty('uhid') && filled($this->getOriginal('uhid'))) {
            throw new LogicException('A patient UHID cannot be changed once it has been issued.');
        }

        return parent::performUpdate($query);
    }

    protected static function booted(): void
    {
        // `name` is the maintained full name the directory searches on: rebuilt
        // whenever a name part changes. A legacy row that only ever had `name`
        // (no parts) keeps it untouched.
        static::saving(function (Patient $patient) {
            // The date of birth is the source of age. The stored `age` is only
            // the age recorded for a patient whose date of birth is unknown
            // (and legacy data); once there is a date of birth it is never
            // written again.
            if ($patient->getAttributes()['date_of_birth'] ?? null) {
                if ($patient->isDirty('age')) {
                    $patient->attributes['age'] = $patient->getRawOriginal('age');
                }
            }

            $parts = self::joinName($patient->first_name, $patient->middle_name, $patient->last_name);
            if ($parts !== '' && ($patient->isDirty(['first_name', 'middle_name', 'last_name']) || empty($patient->attributes['name']))) {
                $patient->attributes['name'] = $parts;
            } elseif (empty($patient->attributes['name'])) {
                $patient->attributes['name'] = 'Patient';
            }
        });
    }

    // -------------------------------------------------------------------------
    // Accessors
    // -------------------------------------------------------------------------

    /**
     * Returns the full name for backward compatibility.
     * When first_name/last_name are set, they take precedence over `name`.
     */
    public function getFullNameAttribute(): string
    {
        return self::joinName($this->first_name, $this->middle_name, $this->last_name) ?: ($this->getRawOriginal('name') ?? '');
    }

    /**
     * Age from the date of birth whenever it is known — the canonical source.
     * Without one, the age recorded at registration (backward compatibility
     * for patients who did not know it); `ageSource()` says which.
     */
    public function getAgeAttribute(?int $value): ?int
    {
        $dob = $this->date_of_birth;

        return $dob ? (int) $dob->diffInYears(now()) : $value;
    }

    /** Where `age` comes from: 'date_of_birth', 'recorded' (legacy/unknown DOB) or null. */
    public function ageSource(): ?string
    {
        return match (true) {
            $this->date_of_birth !== null => 'date_of_birth',
            $this->getRawOriginal('age') !== null || ($this->attributes['age'] ?? null) !== null => 'recorded',
            default => null,
        };
    }

    /**
     * Emails are stored trimmed and lower-cased, so duplicate detection and
     * lookup can compare them with a plain indexed equality.
     */
    public function setEmailAttribute(?string $value): void
    {
        $value = $value === null ? null : mb_strtolower(trim($value));
        $this->attributes['email'] = $value === '' ? null : $value;
    }

    public static function joinName(?string ...$parts): string
    {
        return trim(implode(' ', array_filter(array_map(fn (?string $part) => trim((string) $part), $parts), fn (string $part) => $part !== '')));
    }

    /**
     * How the queue, Now/Next card and TV name a patient: first name and last
     * initial ("Rahul S."), never the full name on a shared screen.
     */
    public function getShortNameAttribute(): string
    {
        $first = trim((string) $this->first_name);
        $last = trim((string) $this->last_name);

        if ($first === '' && $last === '') {
            $parts = preg_split('/\s+/', trim((string) $this->getRawOriginal('name'))) ?: [];
            $first = $parts[0] ?? '';
            $last = count($parts) > 1 ? end($parts) : '';
        }

        return trim($first.($last !== '' ? ' '.mb_strtoupper(mb_substr($last, 0, 1)).'.' : '')) ?: 'Patient';
    }

    /**
     * Override the `name` getter so existing code reading ->name still works.
     */
    public function getNameAttribute(?string $value): string
    {
        return self::joinName($this->first_name, $this->middle_name, $this->last_name) ?: ($value ?? '');
    }

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    public function profile(): HasOne
    {
        return $this->hasOne(PatientProfile::class);
    }

    public function medicalHistory(): HasOne
    {
        return $this->hasOne(PatientMedicalHistory::class);
    }

    public function visits(): HasMany
    {
        return $this->hasMany(PatientVisit::class);
    }

    public function caseNumbers(): HasMany
    {
        return $this->hasMany(CaseNumber::class);
    }

    public function cases(): HasMany
    {
        return $this->hasMany(PatientCase::class);
    }

    public function clinicalAccessAudits(): HasMany
    {
        return $this->hasMany(PatientClinicalAccessAudit::class);
    }

    /** Current safety alerts; a removed one is kept, resolved (`resolved_at`), and is not current. */
    public function alerts(): HasMany
    {
        return $this->hasMany(PatientAlert::class)->whereNull('resolved_at');
    }

    /** Conditions, allergies and medications: the canonical clinical record. */
    public function clinicalEntries(): HasMany
    {
        return $this->hasMany(PatientClinicalEntry::class);
    }

    public function encounters(): HasMany
    {
        return $this->hasMany(ClinicalEncounter::class);
    }

    public function treatmentPlans(): HasMany
    {
        return $this->hasMany(TreatmentPlan::class);
    }

    public function treatmentRecords(): HasMany
    {
        return $this->hasMany(TreatmentRecord::class);
    }

    public function consentRecords(): HasMany
    {
        return $this->hasMany(ConsentRecord::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(AttachedDocument::class);
    }

    public function dentalChart(): HasMany
    {
        return $this->hasMany(PatientDentalChart::class);
    }

    public function timelineEvents(): HasMany
    {
        return $this->hasMany(PatientTimelineEvent::class);
    }

    public function specialties(): HasMany
    {
        return $this->hasMany(PatientSpecialty::class);
    }

    public function branchActivity(): HasMany
    {
        return $this->hasMany(PatientBranchActivity::class);
    }

    /**
     * The doctor currently responsible for this patient, if one has been
     * assigned — via `current_practitioner_id`, which (like
     * `Appointment.practitioner_id` and `PatientVisit.doctor_id`) points at
     * `clinic_employees.id`, not `doctor_profiles.id`.
     */
    public function currentPractitioner(): BelongsTo
    {
        return $this->belongsTo(ClinicEmployee::class, 'current_practitioner_id');
    }

    public function doctorAssignments(): HasMany
    {
        return $this->hasMany(PatientDoctorAssignment::class)->latest();
    }

    // -------------------------------------------------------------------------
    // Scopes – Reception Search Engine
    // -------------------------------------------------------------------------

    public function scopeSearchByUhid(Builder $query, string $uhid): Builder
    {
        return $query->where('uhid', $uhid);
    }

    public function scopeSearchByPhone(Builder $query, string $phone): Builder
    {
        return $query->where(function (Builder $q) use ($phone) {
            $q->where('phone', $phone)
                ->orWhere('alternate_phone', $phone);
        });
    }

    public function scopeSearchByName(Builder $query, string $name): Builder
    {
        return $query->where(function (Builder $q) use ($name) {
            $name = self::escapeLike(trim($name));
            $q->where('first_name', 'like', "{$name}%")
                ->orWhere('middle_name', 'like', "{$name}%")
                ->orWhere('last_name', 'like', "{$name}%")
                ->orWhere('name', 'like', "{$name}%");
        });
    }

    /**
     * Patients currently assigned to a given practitioner (a
     * `clinic_employees.id`) — the shared filter behind "My Patients" on
     * the directory, the dashboard's "My day" band, and the clinical
     * write-gate.
     */
    /**
     * Shared search constraint for the patient directory, the quick-add
     * autocomplete and the reception check-in search — both search the same columns and hit the
     * same scale problem, so both use the same strategy.
     *
     * Names match by word prefix: every typed word must start a word of the
     * patient's name ("ash sha" finds "Asha Sharma"). The callers always
     * constrain `clinic_id` first, so the work is bounded by the size of
     * that one clinic. It used to be a MySQL FULLTEXT match, but a FULLTEXT
     * index spans every tenant: InnoDB resolves the MATCH across all
     * clinics' patients before the clinic filter applies, so a 20-patient
     * clinic's search slowed as the platform grew (measured 12 ms at 1k
     * clinics, 68 ms at 10k — see docs/architecture). A dedicated search
     * engine with a mandatory clinic filter is the path for very large
     * hospitals (ADR-001 §L).
     *
     * Phone/alternate-phone/UHID search was already effectively a "type
     * the start of it" use case (a UHID is read off a card/wristband from
     * its first character; a phone number is dialled/typed from the
     * start), so those switch to a prefix `LIKE 'term%'` — which, unlike a
     * leading-wildcard LIKE, standard B-tree indexes (`patients_phone_index`,
     * `patients_alternate_phone_index`, the `uhid` half of
     * `patients_clinic_uhid_unique`) can use directly.
     */
    public function scopeMatchingSearch(Builder $query, string $search): Builder
    {
        $search = trim($search);
        $words = array_values(array_filter(preg_split('/\s+/', $search) ?: [], fn (string $word) => $word !== ''));
        $whole = self::escapeLike($search);

        // A number (a phone or the digits of an ID) is looked up in indexed
        // phone and ID columns, as well as case numbers.
        if (preg_match('/^[\d\s+()\-]+$/', $search) === 1) {
            return $query->where(fn ($sub) => $sub->where('phone', 'like', "{$whole}%")
                ->orWhere('alternate_phone', 'like', "{$whole}%")
                ->orWhere('uhid', 'like', "{$whole}%")
                ->orWhereExists(fn ($cases) => $cases->selectRaw('1')->from('patient_cases')
                    ->whereColumn('patient_cases.patient_id', 'patients.id')
                    ->whereColumn('patient_cases.clinic_id', 'patients.clinic_id')
                    ->where('patient_cases.case_number', 'like', "{$whole}%"))
                ->orWhereExists(fn ($cases) => $cases->selectRaw('1')->from('case_numbers')
                    ->whereColumn('case_numbers.patient_id', 'patients.id')
                    ->whereColumn('case_numbers.clinic_id', 'patients.clinic_id')
                    ->where('case_numbers.case_number', 'like', "{$whole}%")));
        }

        // Names: the first word typed is a first or last name (both indexed with clinic_id), which is
        // what lets the database use an index; each word may then also be a middle name. The `name`
        // column is not searched: it is the model's own copy of first + middle + last.
        return $query->where(function ($sub) use ($words, $whole) {
            $sub->where(function ($names) use ($words) {
                $first = self::escapeLike($words[0] ?? '');
                $names->where(fn ($name) => $name->where('first_name', 'like', "{$first}%")->orWhere('last_name', 'like', "{$first}%"));
                foreach (array_slice($words, 1) as $word) {
                    $word = self::escapeLike($word);
                    $names->where(fn ($name) => $name
                        ->where('first_name', 'like', "{$word}%")
                        ->orWhere('middle_name', 'like', "{$word}%")
                        ->orWhere('last_name', 'like', "{$word}%"));
                }
            });

            $sub->orWhere('uhid', 'like', "{$whole}%")
                ->orWhereExists(fn ($cases) => $cases->selectRaw('1')->from('patient_cases')
                    ->whereColumn('patient_cases.patient_id', 'patients.id')
                    ->whereColumn('patient_cases.clinic_id', 'patients.clinic_id')
                    ->where('patient_cases.case_number', 'like', "{$whole}%"))
                ->orWhereExists(fn ($cases) => $cases->selectRaw('1')->from('case_numbers')
                    ->whereColumn('case_numbers.patient_id', 'patients.id')
                    ->whereColumn('case_numbers.clinic_id', 'patients.clinic_id')
                    ->where('case_numbers.case_number', 'like', "{$whole}%"));
        });
    }

    /**
     * Typed text as a literal LIKE operand: `%` and `_` match themselves.
     */
    private static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    public function scopeAssignedTo(Builder $query, int $practitionerId): Builder
    {
        return $query->where('current_practitioner_id', $practitionerId);
    }
}
