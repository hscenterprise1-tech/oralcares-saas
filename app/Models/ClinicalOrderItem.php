<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClinicalOrderItem extends Model
{
    protected $fillable = [
        'clinical_order_id',
        'item_code',
        'item_name',
        'status',
        'details',
    ];

    protected function casts(): array
    {
        return [
            'details' => 'array',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(ClinicalOrder::class, 'clinical_order_id');
    }
}
