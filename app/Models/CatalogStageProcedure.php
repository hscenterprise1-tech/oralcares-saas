<?php

namespace App\Models;

use App\Traits\ClinicScoped;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * A procedure allowed (or expected, when required) in a stage. Part of the
 * version's configuration: changeable only while the version is a draft.
 */
class CatalogStageProcedure extends Model
{
    use ClinicScoped;

    protected $fillable = [
        'clinic_id',
        'catalog_stage_id',
        'clinical_procedure_id',
        'position',
        'quantity',
        'is_required',
    ];

    protected function casts(): array
    {
        return ['is_required' => 'boolean', 'quantity' => 'integer'];
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
        $versionId = CatalogStage::query()->withoutGlobalScopes()->whereKey($this->catalog_stage_id)->value('catalog_treatment_version_id');
        $status = CatalogTreatmentVersion::query()->withoutGlobalScopes()->whereKey($versionId)->value('status');
        if ($status !== CatalogTreatmentVersion::DRAFT) {
            throw new LogicException('Procedures of a published treatment version cannot be changed. Create a new version.');
        }
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(CatalogStage::class, 'catalog_stage_id');
    }

    public function procedure(): BelongsTo
    {
        return $this->belongsTo(ClinicalProcedure::class, 'clinical_procedure_id');
    }
}
