<?php

namespace App\Models;

use App\Traits\ClinicScoped;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One medicine on a prescription, as written. Takes its clinic from its
 * prescription; one naming another clinic is refused (and the composite
 * foreign key refuses it in the database too).
 */
class PrescriptionItem extends Model
{
    use ClinicScoped;

    protected $fillable = [
        'clinic_id',
        'prescription_id',
        'drug_master_id',
        'medicine_id',
        'drug_name',
        'form',
        'strength',
        'dosage',
        'frequency',
        'duration',
        'route',
        'timing',
        'instructions',
        'details',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'details' => 'array',
            'sort_order' => 'integer',
        ];
    }

    protected function performInsert(Builder $query)
    {
        $clinicId = Prescription::query()->withoutGlobalScopes()->whereKey($this->getAttribute('prescription_id'))->value('clinic_id');

        if ($clinicId !== null) {
            if ($this->getAttribute('clinic_id') !== null && (int) $this->getAttribute('clinic_id') !== (int) $clinicId) {
                throw new LogicException('A prescription line must belong to its prescription\'s clinic.');
            }
            $this->setAttribute('clinic_id', $clinicId);
        }

        return parent::performInsert($query);
    }

    public function prescription(): BelongsTo
    {
        return $this->belongsTo(Prescription::class);
    }

    public function medicine(): BelongsTo
    {
        return $this->belongsTo(ClinicMedicine::class, 'medicine_id');
    }
}
