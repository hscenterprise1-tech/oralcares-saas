<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class InvoiceItem extends Model
{
    protected $fillable = [
        'invoice_id',
        'chargeable_type',
        'chargeable_id',
        'item_code',
        'description',
        'quantity',
        'unit_price',
        'tax_amount',
        'discount_amount',
        'net_amount',
        'details',
    ];

    protected function casts(): array
    {
        return [
            'details' => 'array',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function chargeable(): MorphTo
    {
        return $this->morphTo();
    }
}
