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
        'clinic_id',
        'opportunity_id',
        'company_plan_id',
        'status',
        'starts_at',
        'ends_at',
        'trial_ends_at',
        'canceled_at',
        'max_users',
        'max_doctors',
        'max_branches',
        'storage_limit_gb',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'trial_ends_at' => 'datetime',
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
     * Get the clinic tenant linked to this subscription.
     *
     * @return BelongsTo<Clinic, $this>
     */
    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class, 'clinic_id');
    }

    /**
     * Get the sales opportunity associated with this subscription.
     *
     * @return BelongsTo<CompanyOpportunity, $this>
     */
    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(CompanyOpportunity::class, 'opportunity_id');
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
