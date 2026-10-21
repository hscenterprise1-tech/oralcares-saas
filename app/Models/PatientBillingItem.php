<?php

namespace App\Models;

use App\Traits\ClinicScoped;
use App\Traits\InheritsBillOwnership;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One line of a clinic bill. The description, price and tax are a snapshot
 * taken when the line was billed, so a later catalogue change never rewrites
 * an issued bill. `clinical_procedure_id` is the catalogue service (none for
 * a consultation or registration fee); `source` is the clinical record the
 * line bills, when there is one.
 */
class PatientBillingItem extends Model
{
    use ClinicScoped, InheritsBillOwnership;

    public const TYPE_SERVICE = 'service';

    public const TYPE_CONSULTATION = 'consultation';

    public const TYPE_REGISTRATION = 'registration';

    public const TYPE_OTHER = 'other';

    protected $fillable = [
        'patient_treatment_id',
        'patient_treatment_stage_id',
        'price_version_id',
        'patient_billing_id',
        'clinic_id',
        'item_type',
        'description',
        'clinical_procedure_id',
        'treatment_record_id',
        'source_type',
        'source_id',
        'quantity',
        'unit_price',
        'discount_amount',
        'tax_rate',
        'tax_amount',
        'final_amount',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'final_amount' => 'decimal:2',
        ];
    }

    public function billing(): BelongsTo
    {
        return $this->belongsTo(PatientBilling::class, 'patient_billing_id');
    }

    public function procedure(): BelongsTo
    {
        return $this->belongsTo(ClinicalProcedure::class, 'clinical_procedure_id');
    }

    public function treatmentRecord(): BelongsTo
    {
        return $this->belongsTo(TreatmentRecord::class, 'treatment_record_id');
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }
}
