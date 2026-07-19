<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PatientBillingItem extends Model
{
    protected $fillable = [
        'patient_billing_id',
        'clinical_procedure_id',
        'treatment_record_id',
        'quantity',
        'unit_price',
        'discount_amount',
        'final_amount',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'decimal:2',
            'discount_amount' => 'decimal:2',
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
}
