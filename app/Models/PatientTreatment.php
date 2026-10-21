<?php

namespace App\Models;

use App\Traits\ClinicScoped;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * Care actually started for a patient: an instance of a catalog treatment,
 * pinned to the version and prices it started on — or a legacy treatment
 * record migrated as it was (`source` = legacy). Written only through
 * `Services\Treatment\PatientTreatments` (and the legacy mirror).
 */
class PatientTreatment extends Model
{
    use ClinicScoped;

    public const PLANNED = 'planned';

    public const IN_PROGRESS = 'in_progress';

    public const PAUSED = 'paused';

    public const COMPLETED = 'completed';

    public const CANCELLED = 'cancelled';

    /** Statuses in which work can still be recorded. */
    public const OPEN = [self::PLANNED, self::IN_PROGRESS];

    public const CLOSED = [self::COMPLETED, self::CANCELLED];

    public const SOURCE_CATALOG = 'catalog';

    public const SOURCE_LEGACY = 'legacy';

    public const SOURCE_PHYSIO_PLAN = 'physio_plan';

    /** Started by a clinician without a catalogue entry (e.g. an ad-hoc physiotherapy programme). */
    public const SOURCE_ADHOC = 'adhoc';

    /** What fixes the treatment to its start: never changed afterwards. */
    private const PINNED = ['catalog_treatment_id', 'catalog_treatment_version_id', 'price_version_id', 'agreed_amount', 'price_snapshot', 'charging_model', 'treatment_type', 'patient_id', 'clinic_id', 'legacy_treatment_record_id', 'legacy_physio_care_plan_id'];

    protected $fillable = [
        'clinic_id',
        'branch_id',
        'patient_id',
        'patient_specialty_id',
        'specialty_key',
        'source',
        'catalog_treatment_id',
        'catalog_treatment_version_id',
        'treatment_plan_id',
        'encounter_id',
        'practitioner_id',
        'title',
        'goal',
        'primary_diagnosis_id',
        'code',
        'treatment_type',
        'charging_model',
        'status',
        'price_version_id',
        'agreed_amount',
        'currency',
        'price_snapshot',
        'stages_total',
        'stages_completed',
        'sessions_planned',
        'sessions_completed',
        'valid_until',
        'started_at',
        'completed_at',
        'cancelled_at',
        'status_reason',
        'legacy_treatment_record_id',
        'legacy_physio_care_plan_id',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'agreed_amount' => 'decimal:2',
            'price_snapshot' => 'array',
            'valid_until' => 'date',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * Legacy rows keep following their source record (the mirror), so only a
     * catalog treatment's pinned terms are frozen.
     */
    protected function performUpdate(Builder $query)
    {
        if ($this->getRawOriginal('source') === self::SOURCE_CATALOG && array_intersect(array_keys($this->getDirty()), self::PINNED) !== []) {
            throw new LogicException('A patient treatment keeps the catalog version and prices it started on.');
        }

        return parent::performUpdate($query);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(CatalogTreatmentVersion::class, 'catalog_treatment_version_id');
    }

    public function practitioner(): BelongsTo
    {
        return $this->belongsTo(ClinicEmployee::class, 'practitioner_id');
    }

    public function stages(): HasMany
    {
        return $this->hasMany(PatientTreatmentStage::class)->orderBy('position');
    }

    public function teeth(): HasMany
    {
        return $this->hasMany(PatientTreatmentTooth::class)->orderBy('tooth_number');
    }

    public function procedures(): HasMany
    {
        return $this->hasMany(PatientProcedure::class);
    }
}
