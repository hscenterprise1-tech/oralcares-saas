<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LabOrderAttachment extends Model
{
    protected $fillable = [
        'lab_order_id',
        'file_name',
        'storage_path',
        'file_type',
    ];

    public function labOrder(): BelongsTo
    {
        return $this->belongsTo(LabOrder::class, 'lab_order_id');
    }
}
