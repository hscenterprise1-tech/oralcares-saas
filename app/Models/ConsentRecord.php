<?php

namespace App\Models;

use App\Traits\ClinicScoped;
use App\Traits\InheritsClinicOwnership;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Evidence that a patient agreed to something: which text (version + hash),
 * how and by whom it was captured, and whether it was later revoked. A
 * revoked consent is kept; a new agreement is a new row.
 */
class ConsentRecord extends Model
{
    use ClinicScoped, InheritsClinicOwnership;

    public const GRANTED = 'granted';

    public const REVOKED = 'revoked';

    /** How a consent can be captured. */
    public const METHODS = ['verbal', 'written', 'digital', 'imported'];

    protected $fillable = [
        'clinic_id',
        'patient_id',
        'consent_type',
        'consent_version',
        'text_hash',
        'status',
        'capture_method',
        'captured_by',
        'captured_at',
        'signed_at',
        'revoked_at',
        'revoked_by',
        'revocation_reason',
    ];

    protected function casts(): array
    {
        return [
            'signed_at' => 'datetime',
            'captured_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function capturedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'captured_by');
    }

    /** Consents in force: granted and not revoked. */
    public function scopeInForce(Builder $query): Builder
    {
        return $query->where('status', self::GRANTED)->whereNull('revoked_at');
    }
}
