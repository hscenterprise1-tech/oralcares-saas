<?php

namespace App\Models;

use App\Services\Patient\CaseNumberFormatter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A case number that was merged away when case numbers moved from "one per
 * visit" to "one per patient per period". Kept so old paperwork still
 * resolves to the right patient; `merged_into_id` is the number that
 * replaced it.
 */
class CaseNumberHistory extends Model
{
    protected $table = 'case_number_history';

    protected $fillable = [
        'original_id',
        'clinic_id',
        'patient_id',
        'patient_visit_id',
        'year',
        'sequence',
        'case_number',
        'period_key',
        'counter_key',
        'search_key',
        'merged_into_id',
        'original_created_at',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'sequence' => 'integer',
            'original_created_at' => 'datetime',
        ];
    }

    public function setCaseNumberAttribute(string $value): void
    {
        $this->attributes['case_number'] = $value;
        $this->attributes['search_key'] = CaseNumberFormatter::searchKey($value);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(CaseNumber::class, 'merged_into_id');
    }
}
