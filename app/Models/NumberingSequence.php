<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NumberingSequence extends Model
{
    protected $fillable = [
        'organization_id',
        'branch_id',
        'entity_type',
        'prefix',
        'suffix',
        'padding',
        'current_number',
        'reset_frequency',
        'last_reset_date',
    ];

    protected $casts = [
        'padding' => 'integer',
        'current_number' => 'integer',
        'last_reset_date' => 'date',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
