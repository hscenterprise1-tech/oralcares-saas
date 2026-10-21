<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @deprecated The first waiting-room display model. No production path uses it (only
 * `QueueDisplayService` and its test); its table is empty. A future display
 * will be built on `ClinicQueueItem`. Kept until its test is retired with
 * approval; build nothing on it.
 */
class QueueDisplay extends Model
{
    protected $fillable = [
        'clinic_id',
        'branch_id',
        'floor',
        'department',
        'display_name',
        'display_type',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
