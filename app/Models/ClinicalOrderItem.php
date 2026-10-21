<?php

namespace App\Models;

use App\Traits\ClinicScoped;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

/**
 * One investigation on an order: a lab test, an imaging study or an
 * external referral. It moves ordered → collected → resulted → reviewed, or
 * is cancelled before a result arrives. Takes its clinic from its order.
 */
class ClinicalOrderItem extends Model
{
    use ClinicScoped;

    public const ORDERED = 'ordered';

    public const COLLECTED = 'collected';

    public const RESULTED = 'resulted';

    public const REVIEWED = 'reviewed';

    public const CANCELLED = 'cancelled';

    /** Statuses still waiting on someone. */
    public const OPEN = [self::ORDERED, self::COLLECTED, self::RESULTED];

    /** Kinds of investigation, with what the "collected" step means for each. */
    public const CATEGORIES = [
        'lab' => ['label' => 'Lab test', 'collect' => 'Sample collected'],
        'imaging' => ['label' => 'Imaging', 'collect' => 'Images taken'],
        'external' => ['label' => 'External referral', 'collect' => 'Sent out'],
        'other' => ['label' => 'Other', 'collect' => 'Done'],
    ];

    protected $fillable = [
        'clinic_id',
        'clinical_order_id',
        'investigation_id',
        'item_code',
        'item_name',
        'category',
        'body_site',
        'external_provider',
        'notes',
        'status',
        'details',
        'collected_at',
        'collected_by',
        'resulted_at',
        'reviewed_at',
        'reviewed_by',
        'cancelled_at',
        'cancellation_reason',
    ];

    protected function casts(): array
    {
        return [
            'details' => 'array',
            'collected_at' => 'datetime',
            'resulted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    protected function performInsert(Builder $query)
    {
        $clinicId = ClinicalOrder::query()->withoutGlobalScopes()->whereKey($this->getAttribute('clinical_order_id'))->value('clinic_id');

        if ($clinicId !== null) {
            if ($this->getAttribute('clinic_id') !== null && (int) $this->getAttribute('clinic_id') !== (int) $clinicId) {
                throw new LogicException('An order item must belong to its order\'s clinic.');
            }
            $this->setAttribute('clinic_id', $clinicId);
        }

        return parent::performInsert($query);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(ClinicalOrder::class, 'clinical_order_id');
    }

    public function investigation(): BelongsTo
    {
        return $this->belongsTo(ClinicInvestigation::class, 'investigation_id');
    }

    public function result(): HasOne
    {
        return $this->hasOne(DiagnosticResult::class, 'clinical_order_item_id');
    }

    /** Report files and images, as scanned patient documents. */
    public function documents(): HasMany
    {
        return $this->hasMany(AttachedDocument::class, 'clinical_order_item_id');
    }

    public function collector(): BelongsTo
    {
        return $this->belongsTo(ClinicEmployee::class, 'collected_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(ClinicEmployee::class, 'reviewed_by');
    }
}
