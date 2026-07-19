<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResourceAllocation extends Model
{
    protected $fillable = [
        'clinic_id',
        'resource_id',
        'allocated_to_type',
        'allocated_to_id',
        'date',
        'start_time',
        'end_time',
    ];

    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    public function resource(): BelongsTo
    {
        return $this->belongsTo(Resource::class);
    }
}
