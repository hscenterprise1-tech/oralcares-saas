<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryBatch extends Model
{
    protected $fillable = [
        'clinic_id',
        'branch_id',
        'inventory_item_id',
        'batch_number',
        'manufacturing_date',
        'expiry_date',
        'purchase_cost',
        'supplier_batch_reference',
        'opening_quantity',
        'current_quantity',
        'reserved_quantity',
        'damaged_quantity',
        'expired_quantity',
    ];

    protected function casts(): array
    {
        return [
            'manufacturing_date' => 'date',
            'expiry_date' => 'date',
            'purchase_cost' => 'decimal:2',
            'opening_quantity' => 'decimal:2',
            'current_quantity' => 'decimal:2',
            'reserved_quantity' => 'decimal:2',
            'damaged_quantity' => 'decimal:2',
            'expired_quantity' => 'decimal:2',
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

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(InventoryReservation::class);
    }
}
