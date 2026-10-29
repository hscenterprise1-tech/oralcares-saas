<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\ClinicScoped;
use App\Traits\InheritsClinicOwnership;
use App\Traits\OrganizationScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The one prescription model. Drafted in a consultation, finalised (signed
 * and numbered) by its prescriber, then never edited; a mistake is cancelled
 * with a reason and a new prescription written. Written only through
 * `ClinicalPrescriptionService`.
 */
class Prescription extends Model
{
    use Auditable, ClinicScoped, InheritsClinicOwnership, OrganizationScoped;

    public const DRAFT = 'draft';

    public const FINALIZED = 'finalized';

    public const CANCELLED = 'cancelled';

    protected $fillable = [
        'organization_id',
        'clinic_id',
        'encounter_id',
        'patient_id',
        'prescribed_by',
        'prescriber_id',
        'prescription_number',
        'status',
        'notes',
        'details',
        'finalized_at',
        'finalized_by',
        'signature_hash',
        'cancelled_at',
        'cancelled_by',
        'cancellation_reason',
    ];

    protected function casts(): array
    {
        return [
            'details' => 'array',
            'finalized_at' => 'datetime',
            'cancelled_at' => 'datetime',
            // Set only by `ClinicalPrescriptionService` (review and signing); never mass-assigned.
            'allergy_review' => 'array',
            'allergy_reviewed_at' => 'datetime',
        ];
    }

    public function isDraft(): bool
    {
        return $this->status === self::DRAFT;
    }

    public function isFinalized(): bool
    {
        return $this->status === self::FINALIZED;
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    public function encounter(): BelongsTo
    {
        return $this->belongsTo(ClinicalEncounter::class, 'encounter_id');
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /** The signed-in user who wrote it. */
    public function prescriber(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prescribed_by');
    }

    /** The practitioner whose prescription it is (name and registration on the print). */
    public function prescribingDoctor(): BelongsTo
    {
        return $this->belongsTo(ClinicEmployee::class, 'prescriber_id');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(ClinicEmployee::class, 'cancelled_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PrescriptionItem::class)->orderBy('sort_order')->orderBy('id');
    }
}
