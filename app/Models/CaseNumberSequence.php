<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A clinic's case-number counter. `counter_key` is a period (CY2026 / FY2026)
 * when the clinic resets yearly, or LIFETIME when it doesn't. Only
 * `CaseNumberGenerator` writes it, always under a row lock.
 */
class CaseNumberSequence extends Model
{
    protected $fillable = [
        'clinic_id',
        'counter_key',
        'last_sequence',
    ];

    protected function casts(): array
    {
        return [
            'last_sequence' => 'integer',
        ];
    }

    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }
}
