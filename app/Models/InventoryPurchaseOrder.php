<?php

namespace App\Models;

use App\PurchaseOrderStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryPurchaseOrder extends Model
{
    protected $fillable = [
        'clinic_id',
        'branch_id',
        'vendor_id',
        'po_number',
        'status',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => PurchaseOrderStatus::class,
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

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(InventoryVendor::class, 'vendor_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(ClinicEmployee::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(InventoryPurchaseOrderItem::class, 'inventory_purchase_order_id');
    }
}
