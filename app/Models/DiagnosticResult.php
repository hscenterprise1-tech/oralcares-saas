<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\ClinicScoped;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use LogicException;

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
        'summary',
        'is_abnormal',
        'reported_by',
        'reporter_id',
        'verified_by',
        'reviewer_id',
        'content',
        'hl7_message_id',
        'dicom_study_uid',
        'reported_at',
        'verified_at',
        'review_note',
    ];

    /** Entered, not yet reviewed by a clinician. */
    public const DRAFT = 'draft';

    /** Reviewed: the record of what the investigation showed. */
    public const FINAL = 'final';

    /**
     * A result takes its clinic from its order item (one naming another
     * clinic is refused; the composite foreign key refuses it too).
     */
    protected function performInsert(Builder $query)
    {
        $clinicId = ClinicalOrderItem::query()->withoutGlobalScopes()->whereKey($this->getAttribute('clinical_order_item_id'))->value('clinic_id');

        if ($clinicId !== null) {
            if ($this->getAttribute('clinic_id') !== null && (int) $this->getAttribute('clinic_id') !== (int) $clinicId) {
                throw new LogicException('A result must belong to its order item\'s clinic.');
            }
            $this->setAttribute('clinic_id', $clinicId);
        }

        return parent::performInsert($query);
    }

    protected function casts(): array
    {
        return [
            'content' => 'array',
            'is_abnormal' => 'boolean',
            'reported_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    public function reportingStaff(): BelongsTo
    {
        return $this->belongsTo(ClinicEmployee::class, 'reporter_id');
    }

    public function reviewingClinician(): BelongsTo
    {
        return $this->belongsTo(ClinicEmployee::class, 'reviewer_id');
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
