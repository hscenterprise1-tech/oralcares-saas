<?php

namespace App\Models;

use App\Traits\ClinicScoped;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * A stage of a treatment version (Access Opening, Cleaning & Shaping). Part of
 * the version's configuration: changeable only while the version is a draft.
 */
class CatalogStage extends Model
{
    use ClinicScoped;

    protected $fillable = [
        'clinic_id',
        'catalog_treatment_version_id',
        'position',
        'name',
        'description',
        'planned_sessions',
        'duration_minutes',
        'is_optional',
    ];

    protected function casts(): array
    {
        return ['is_optional' => 'boolean'];
    }

    protected function performInsert(Builder $query)
    {
        $this->assertDraftVersion();

        return parent::performInsert($query);
    }

    protected function performUpdate(Builder $query)
    {
        $this->assertDraftVersion();

        return parent::performUpdate($query);
    }

    protected function performDeleteOnModel()
    {
        $this->assertDraftVersion();
        parent::performDeleteOnModel();
    }

    private function assertDraftVersion(): void
    {
        $status = CatalogTreatmentVersion::query()->withoutGlobalScopes()->whereKey($this->catalog_treatment_version_id)->value('status');
        if ($status !== CatalogTreatmentVersion::DRAFT) {
            throw new LogicException('Stages of a published treatment version cannot be changed. Create a new version.');
        }
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(CatalogTreatmentVersion::class, 'catalog_treatment_version_id');
    }

    public function procedures(): HasMany
    {
        return $this->hasMany(CatalogStageProcedure::class)->orderBy('position');
    }
}
