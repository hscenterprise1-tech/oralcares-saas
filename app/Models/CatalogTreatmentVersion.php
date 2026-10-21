<?php

namespace App\Models;

use App\Traits\ClinicScoped;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * One configuration of a catalog treatment: type, charging model, stages.
 * A draft can be edited; once published it never changes — a new
 * configuration is a new version. Patient treatments pin the version they
 * started on, so history always reads the configuration that applied.
 */
class CatalogTreatmentVersion extends Model
{
    use ClinicScoped;

    public const DRAFT = 'draft';

    public const PUBLISHED = 'published';

    public const RETIRED = 'retired';

    /** single: one sitting · multi_stage: ordered stages · session_based: a number of sessions */
    public const TYPES = ['single', 'multi_stage', 'session_based'];

    /** upfront: the whole price at start · per_stage: each stage when completed · per_procedure: each procedure/session when done */
    public const CHARGING_MODELS = ['upfront', 'per_stage', 'per_procedure'];

    /** What may still change on a published version: its retirement, nothing else. */
    private const MUTABLE_WHEN_PUBLISHED = ['status', 'retired_at', 'updated_at'];

    protected $fillable = [
        'clinic_id',
        'catalog_treatment_id',
        'version',
        'status',
        'treatment_type',
        'charging_model',
        'planned_sessions',
        'validity_days',
        'default_duration_minutes',
        'settings',
        'published_at',
        'published_by',
        'retired_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'requires_tooth' => 'boolean',
            'settings' => 'array',
            'published_at' => 'datetime',
            'retired_at' => 'datetime',
        ];
    }

    /**
     * Targets a patient treatment on this version must name (declared by the
     * specialty extension, e.g. `tooth`). The old `requires_tooth` flag is no
     * longer read.
     *
     * @return list<string>
     */
    public function targets(): array
    {
        return array_values((array) ($this->settings['targets'] ?? []));
    }

    public function isDraft(): bool
    {
        return $this->status === self::DRAFT;
    }

    protected function performUpdate(Builder $query)
    {
        if ($this->getRawOriginal('status') !== self::DRAFT) {
            $changed = array_diff(array_keys($this->getDirty()), self::MUTABLE_WHEN_PUBLISHED);
            $retiring = $this->status === self::RETIRED;
            if ($changed !== [] || ! $retiring) {
                throw new LogicException('A published treatment version cannot be changed. Create a new version.');
            }
        }

        return parent::performUpdate($query);
    }

    protected function performDeleteOnModel()
    {
        if (! $this->isDraft()) {
            throw new LogicException('A published treatment version cannot be deleted.');
        }

        parent::performDeleteOnModel();
    }

    public function treatment(): BelongsTo
    {
        return $this->belongsTo(CatalogTreatment::class, 'catalog_treatment_id');
    }

    public function stages(): HasMany
    {
        return $this->hasMany(CatalogStage::class)->orderBy('position');
    }
}
