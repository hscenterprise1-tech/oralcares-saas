<?php

namespace App\Models;

use App\Traits\ClinicScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class AttachedDocument extends Model
{
    // A removed document leaves the record (soft delete) but its file is kept
    // for retention; removal goes through owner-approved governance only.
    use ClinicScoped, SoftDeletes;

    protected $fillable = [
        'clinic_id',
        'patient_id',
        'encounter_id',
        'clinical_order_item_id',
        'uploaded_by',
        'file_name',
        'file_path',
        'file_size_bytes',
        'document_type',
        'scan_status',
        'scanned_at',
        'scanner',
        'file_sha256',
        'removed_by',
        'removal_reason',
        'tooth_numbers',
    ];

    /** Only a document with this scan status is ever served. */
    public const SCAN_CLEAN = 'clean';

    protected function casts(): array
    {
        return [
            'scanned_at' => 'datetime',
            'tooth_numbers' => 'array',
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

    public function encounter(): BelongsTo
    {
        return $this->belongsTo(ClinicalEncounter::class, 'encounter_id');
    }

    /** The investigation this file is a result of, if any. */
    public function clinicalOrderItem(): BelongsTo
    {
        return $this->belongsTo(ClinicalOrderItem::class, 'clinical_order_item_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
