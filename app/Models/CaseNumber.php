<?php

namespace App\Models;

use App\Services\Patient\CaseNumberFormatter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CaseNumber extends Model
{
    protected $fillable = [
        'clinic_id',
        'patient_id',
        'patient_visit_id',
        'year',
        'period_key',
        'counter_key',
        'sequence',
        'case_number',
        'search_key',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'sequence' => 'integer',
        ];
    }

    // -------------------------------------------------------------------------
    // Accessors
    // -------------------------------------------------------------------------

    /**
     * Keeps `search_key` in step with the number on every write path, so a
     * number is always findable however its separators are typed.
     */
    public function setCaseNumberAttribute(string $value): void
    {
        $this->attributes['case_number'] = $value;
        $this->attributes['search_key'] = CaseNumberFormatter::searchKey($value);
    }

    /**
     * The full number exactly as issued, e.g. "OPD-2026-000123".
     */
    public function getCaseNumberLabelAttribute(): string
    {
        return $this->case_number;
    }

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(PatientVisit::class, 'patient_visit_id');
    }

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    public function scopeForYear(Builder $query, int $year): Builder
    {
        return $query->where('year', $year);
    }

    public function scopeForClinic(Builder $query, int $clinicId): Builder
    {
        return $query->where('clinic_id', $clinicId);
    }

    public function scopeByNumber(Builder $query, string $caseNumber): Builder
    {
        return $query->where('case_number', $caseNumber);
    }
}
