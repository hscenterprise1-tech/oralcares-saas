<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProvisioningAudit extends Model
{
    protected $fillable = [
        'clinic_id',
        'trial_id',
        'lead_id',
        'profile',
        'status',
        'duration_ms',
        'created_entities',
        'version',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'created_entities' => 'array',
        ];
    }

    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    /**
     * The trial this run was for.
     *
     * Nullable like `clinic_id`, but for the opposite reason: a failed run has
     * no clinic and still has a trial, which is what makes the failure
     * traceable at all.
     */
    public function trial(): BelongsTo
    {
        return $this->belongsTo(Trial::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }
}
