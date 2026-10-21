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
 * A request for investigations, placed from a consultation. Its items
 * (tests, imaging studies, external referrals) each move through their own
 * fulfilment; the order is complete when every item is reviewed or
 * cancelled. Written only through `ClinicalOrderService`.
 */
class ClinicalOrder extends Model
{
    use Auditable, ClinicScoped, InheritsClinicOwnership, OrganizationScoped;

    public const ACTIVE = 'active';

    public const COMPLETED = 'completed';

    public const CANCELLED = 'cancelled';

    protected $fillable = [
        'clinic_id',
        'branch_id',
        'organization_id',
        'encounter_id',
        'patient_id',
        'ordered_by',
        'orderer_id',
        'order_number',
        'order_type',
        'status',
        'priority',
        'clinical_instructions',
        'details',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'details' => 'array',
            'completed_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function encounter(): BelongsTo
    {
        return $this->belongsTo(ClinicalEncounter::class, 'encounter_id');
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function orderer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ordered_by');
    }

    /** The clinician who ordered it. */
    public function orderingDoctor(): BelongsTo
    {
        return $this->belongsTo(ClinicEmployee::class, 'orderer_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ClinicalOrderItem::class);
    }
}
