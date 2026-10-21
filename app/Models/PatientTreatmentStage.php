<?php

namespace App\Models;

use App\Traits\ClinicScoped;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * A stage of a patient treatment, with its name, order and price snapshotted
 * from the catalog when the treatment started.
 */
class PatientTreatmentStage extends Model
{
    use ClinicScoped;

    public const PENDING = 'pending';

    public const IN_PROGRESS = 'in_progress';

    public const COMPLETED = 'completed';

    public const SKIPPED = 'skipped';

    public const CLOSED = [self::COMPLETED, self::SKIPPED];

    private const PINNED = ['catalog_stage_id', 'position', 'name', 'price_version_id', 'agreed_amount', 'patient_treatment_id', 'clinic_id'];

    protected $fillable = [
        'clinic_id',
        'patient_treatment_id',
        'catalog_stage_id',
        'position',
        'name',
        'is_optional',
        'status',
        'price_version_id',
        'agreed_amount',
        'sessions_planned',
        'sessions_completed',
        'encounter_id',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'is_optional' => 'boolean',
            'agreed_amount' => 'decimal:2',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    protected function performUpdate(Builder $query)
    {
        $source = PatientTreatment::query()->withoutGlobalScopes()->whereKey($this->patient_treatment_id)->value('source');
        if ($source === PatientTreatment::SOURCE_CATALOG && array_intersect(array_keys($this->getDirty()), self::PINNED) !== []) {
            throw new LogicException('A treatment stage keeps the name, order and price it started with.');
        }

        return parent::performUpdate($query);
    }

    public function isClosed(): bool
    {
        return in_array($this->status, self::CLOSED, true);
    }

    public function treatment(): BelongsTo
    {
        return $this->belongsTo(PatientTreatment::class, 'patient_treatment_id');
    }
}
