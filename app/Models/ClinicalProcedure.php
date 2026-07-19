<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClinicalProcedure extends Model
{
    protected $fillable = [
        'clinic_id',
        'code',
        'name',
        'description',
        'category',
        'default_duration_minutes',
        'clinical_complexity',
        'requires_tooth',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'requires_tooth' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    public function prices(): HasMany
    {
        return $this->hasMany(ClinicalProcedurePrice::class);
    }
}
