<?php

namespace App\Models;

use App\Traits\ClinicScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttachedDocument extends Model
{
    use ClinicScoped;

    protected $fillable = [
        'clinic_id',
        'patient_id',
        'uploaded_by',
        'file_name',
        'file_path',
        'file_size_bytes',
        'document_type',
        'scan_status',
        'scanned_at',
        'scanner',
        'file_sha256',
    ];

    /** Only a document with this scan status is ever served. */
    public const SCAN_CLEAN = 'clean';

    protected function casts(): array
    {
        return [
            'scanned_at' => 'datetime',
        ];
    }

    public function isCleared(): bool
    {
        return $this->scan_status === self::SCAN_CLEAN;
    }

    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
