<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class XrayAttachment extends Model
{
    protected $fillable = [
        'xray_record_id',
        'file_name',
        'storage_path',
        'mime_type',
        'file_size_bytes',
        'version',
        'uploaded_by',
        'uploaded_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'file_size_bytes' => 'integer',
            'uploaded_at' => 'datetime',
        ];
    }

    public function xrayRecord(): BelongsTo
    {
        return $this->belongsTo(XrayRecord::class, 'xray_record_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(ClinicEmployee::class, 'uploaded_by');
    }
}
