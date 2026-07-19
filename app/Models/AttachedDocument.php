<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttachedDocument extends Model
{
    protected $fillable = [
        'patient_id',
        'file_name',
        'file_path',
        'file_size_bytes',
        'document_type',
    ];

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }
}
