<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TreatmentMaterial extends Model
{
    protected $fillable = [
        'treatment_record_id',
        'material_name',
        'quantity',
        'batch_number',
    ];

    public function treatmentRecord(): BelongsTo
    {
        return $this->belongsTo(TreatmentRecord::class, 'treatment_record_id');
    }
}
