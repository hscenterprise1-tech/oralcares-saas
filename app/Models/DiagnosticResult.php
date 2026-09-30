<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\ClinicScoped;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class DiagnosticResult extends Model
{
    use Auditable, ClinicScoped, HasFactory;

    protected $fillable = [
        'clinic_id',
        'organization_id',
        'patient_id',
        'clinical_order_item_id',
        'status',
        'result_type',
        'reported_by',
        'verified_by',
        'content',
        'hl7_message_id',
        'dicom_study_uid',
        'reported_at',
        'verified_at',
    ];

    protected function casts(): array
    {
        return [
            'content' => 'array',
            'reported_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(ClinicalOrderItem::class, 'clinical_order_item_id');
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(ClinicalAttachment::class, 'attachable');
    }
}
