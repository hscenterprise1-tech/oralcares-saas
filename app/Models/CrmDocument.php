<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CrmDocument extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'documentable_id',
        'documentable_type',
        'file_name',
        'file_path',
        'file_size_bytes',
        'mime_type',
        'uploaded_by',
    ];

    public function documentable(): MorphTo
    {
        return $this->morphTo();
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
