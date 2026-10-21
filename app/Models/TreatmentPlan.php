<?php

namespace App\Models;

use App\Traits\ClinicScoped;
use App\Traits\InheritsClinicOwnership;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A patient's treatment plan: proposed in a consultation, accepted by the
 * patient, then worked through item by item across visits. Written through
 * `Dental\DentalRecord`.
 */
class TreatmentPlan extends Model
{
    use ClinicScoped, InheritsClinicOwnership;

    public const PROPOSED = 'proposed';

    public const ACCEPTED = 'accepted';

    public const COMPLETED = 'completed';

    public const CANCELLED = 'cancelled';

    public const DRAFT = 'draft';

    /** Accepted and under way: at least one treatment has been done. */
    public const ACTIVE = 'active';

    public const PAUSED = 'paused';

    public const DECLINED = 'declined';

    /** Plans whose treatments can still be done. */
    public const WORKABLE = [self::ACCEPTED, self::ACTIVE];

    /** Plans that are finished one way or another. */
    public const CLOSED = [self::COMPLETED, self::DECLINED, self::CANCELLED];

    protected $fillable = [
        'clinic_id',
        'patient_id',
        'encounter_id',
        'title',
        'status',
        'notes',
        'created_by',
        'accepted_at',
        'completed_at',
        'paused_at',
        'declined_at',
        'cancelled_at',
        'status_reason',
    ];

    protected function casts(): array
    {
        return [
            'accepted_at' => 'datetime',
            'completed_at' => 'datetime',
            'paused_at' => 'datetime',
            'declined_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [self::PROPOSED, self::ACCEPTED, 'draft'], true);
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

    public function author(): BelongsTo
    {
        return $this->belongsTo(ClinicEmployee::class, 'created_by');
    }

    public function records(): HasMany
    {
        return $this->hasMany(TreatmentRecord::class);
    }
}
