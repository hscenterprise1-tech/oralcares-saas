<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProvisioningAudit extends Model
{
    protected $fillable = [
        'clinic_id',
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
}
