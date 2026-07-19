<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class InventoryReservation extends Model
{
    protected $fillable = [
        'inventory_batch_id',
        'quantity',
        'reference_id',
        'reference_type',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(InventoryBatch::class, 'inventory_batch_id');
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }
}
