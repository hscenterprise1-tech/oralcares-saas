<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A change to what a tenant is entitled to.
 *
 * `company_subscriptions` holds the agreement as it stands right now — change
 * the plan and the previous one is simply gone. That is survivable while the
 * only transition is trial → paid, and unworkable once upgrades, extra seats
 * and storage top-ups exist.
 *
 * This build writes only the two events it genuinely causes:
 * `trial_started` and `converted_to_paid`. The post-sale expansion module
 * writes the rest, and inherits a true history rather than a column that was
 * overwritten.
 */
class SubscriptionEvent extends Model
{
    public const TRIAL_STARTED = 'trial_started';

    public const CONVERTED_TO_PAID = 'converted_to_paid';

    /** The trial window was pushed out; entitlement is unchanged. */
    public const TRIAL_EXTENDED = 'trial_extended';

    /** Access was revoked before the trial would have ended on its own. */
    public const TRIAL_CANCELLED = 'trial_cancelled';

    protected $fillable = [
        'company_subscription_id',
        'organization_id',
        'event',
        'from_plan_id',
        'to_plan_id',
        'changes',
        'actor_id',
        'reason',
        'commercial_document_id',
        'lead_id',
    ];

    protected function casts(): array
    {
        return [
            'changes' => 'array',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(CompanySubscription::class, 'company_subscription_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function fromPlan(): BelongsTo
    {
        return $this->belongsTo(CompanyPlan::class, 'from_plan_id');
    }

    public function toPlan(): BelongsTo
    {
        return $this->belongsTo(CompanyPlan::class, 'to_plan_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function commercialDocument(): BelongsTo
    {
        return $this->belongsTo(CommercialDocument::class);
    }
}
