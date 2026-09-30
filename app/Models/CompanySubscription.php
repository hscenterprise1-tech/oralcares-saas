<?php

namespace App\Models;

use App\SubscriptionStatus;
use Database\Factories\CompanySubscriptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CompanySubscription extends Model
{
    /** @use HasFactory<CompanySubscriptionFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'organization_id',
        'clinic_id',
        'opportunity_id',
        'company_plan_id',
        /*
         * The recurring value, snapshotted here rather than read from the plan.
         *
         * Revenue used to sum a column that did not exist, so every figure on
         * the page was silently zero. Deriving it live from the plan would be
         * worse than that: a price change would rewrite what earlier months
         * earned. Null means not recorded, and Revenue reports it as an
         * exception rather than counting it as nothing.
         */
        'mrr',
        'mrr_currency',
        'status',
        'starts_at',
        'ends_at',
        'trial_ends_at',
        'grace_ends_at',
        'canceled_at',
        'max_users',
        'max_doctors',
        'max_staff',
        'max_branches',
        'storage_limit_gb',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    /**
     * Every change to this subscription, in order.
     *
     * The row above is the current agreement; this is how it got there.
     */
    public function events(): HasMany
    {
        return $this->hasMany(SubscriptionEvent::class)->latest('id');
    }

    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'mrr' => 'decimal:2',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'trial_ends_at' => 'datetime',
            'grace_ends_at' => 'datetime',
            'canceled_at' => 'datetime',
        ];
    }

    /**
     * Get the plan this subscription is based on.
     *
     * @return BelongsTo<CompanyPlan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(CompanyPlan::class, 'company_plan_id');
    }

    /**
     * Get the organization tenant linked to this subscription.
     *
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }

    /**
     * Get the clinic tenant linked to this subscription.
     *
     * @return BelongsTo<Clinic, $this>
     */
    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class, 'clinic_id');
    }

    /**
     * Get the feature entitlements overrides for this subscription.
     *
     * @return HasMany<CompanySubscriptionEntitlement, $this>
     */
    public function entitlements(): HasMany
    {
        return $this->hasMany(CompanySubscriptionEntitlement::class, 'company_subscription_id');
    }

    /**
     * Get the active addons purchased on this subscription.
     *
     * @return HasMany<CompanySubscriptionAddon, $this>
     */
    public function addons(): HasMany
    {
        return $this->hasMany(CompanySubscriptionAddon::class, 'company_subscription_id');
    }
}
