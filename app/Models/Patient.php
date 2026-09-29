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
        static::saving(function (Patient $patient) {
            if (empty($patient->attributes['name'])) {
                $patient->attributes['name'] = trim(($patient->first_name ?? '').' '.($patient->last_name ?? '')) ?: 'Patient';
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
        if ($this->first_name || $this->last_name) {
            return trim("{$this->first_name} {$this->last_name}");
        }

        return $this->name ?? '';
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
        if ($this->first_name || $this->last_name) {
            return trim("{$this->first_name} {$this->last_name}");
        }

        return $value ?? '';
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

    public function alerts(): HasMany
    {
        return $this->hasMany(PatientAlert::class);
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
            $q->where('name', 'like', "%{$name}%")
                ->orWhere('first_name', 'like', "%{$name}%")
                ->orWhere('last_name', 'like', "%{$name}%");
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
     * Name matching uses a MySQL FULLTEXT index (`patients_name_fulltext`)
     * in boolean mode rather than `LIKE '%term%'`: a leading-wildcard LIKE
     * can never use a normal index no matter how the table is indexed, so
     * at hospital scale it becomes a full per-clinic table scan on every
     * keystroke. FULLTEXT has a floor — MySQL's default
     * `innodb_ft_min_token_size` ignores words under 3 characters — so a
     * 1–2 character query falls back to the old `LIKE` behaviour, which is
     * cheap precisely because it's rare (most searches settle past 2
     * characters within one debounce cycle).
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
        // The FULLTEXT index this relies on is MySQL/MariaDB-specific (see
        // the `add_fulltext_search_index_to_patients_table` migration).
        // `whereFullText` throws on any other driver — notably SQLite,
        // which the test suite runs on — so this falls back to the same
        // `LIKE '%term%'` behaviour there rather than breaking every test
        // that searches patients. Production runs MySQL, so this never
        // takes the slow path outside of tests.
        $supportsFullText = in_array(
            $query->getQuery()->getConnection()->getDriverName(),
            ['mysql', 'mariadb'],
            true
        );

        return $query->where(function ($sub) use ($search, $supportsFullText) {
            $booleanQuery = $supportsFullText ? self::toBooleanPrefixQuery($search) : null;

            if ($booleanQuery !== null) {
                $sub->whereFullText(['name', 'first_name', 'last_name'], $booleanQuery, ['mode' => 'boolean']);
            } else {
                $sub->where('name', 'like', "%{$search}%")
                    ->orWhere('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%");
            }

            $sub->orWhere('phone', 'like', "{$search}%")
                ->orWhere('alternate_phone', 'like', "{$search}%")
                ->orWhere('uhid', 'like', "{$search}%");
        });
    }

    /**
     * Turns free-typed search text into a MySQL boolean-mode fulltext
     * query (`+word*` per token — required, prefix-matched), or null when
     * the text is too short for the fulltext index to help at all.
     *
     * Boolean-mode operator characters (`+ - > < ( ) ~ * " @`) are typed
     * by users far more often as ordinary punctuation (a hyphenated name,
     * an `@` fumbled into a name field) than as an intentional fulltext
     * operator, and left in they can throw a MySQL boolean-query syntax
     * error instead of just returning no rows — so they're stripped from
     * every token before it's wrapped.
     */
    private static function toBooleanPrefixQuery(string $search): ?string
    {
        $tokens = collect(preg_split('/\s+/', trim($search)) ?: [])
            ->map(fn ($word) => preg_replace('/[+\-><()~*"@]/', '', $word))
            ->filter(fn ($word) => mb_strlen($word) >= 3);

        return $tokens->isEmpty() ? null : $tokens->map(fn ($word) => '+'.$word.'*')->implode(' ');
    }

    public function scopeAssignedTo(Builder $query, int $practitionerId): Builder
    {
        return $query->where('current_practitioner_id', $practitionerId);
    }
}
