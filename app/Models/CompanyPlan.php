<?php

namespace App\Models;

use Database\Factories\CompanyPlanFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CompanyPlan extends Model
{
    /** @use HasFactory<CompanyPlanFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'key',
        'description',
        'price',
        'billing_period',
        'version',
        'max_users',
        'additional_user_price',
        'max_doctors',
        'max_staff',
        'max_branches',
        'storage_limit_gb',
        'is_active',
        'is_trial_available',
        'trial_duration_days',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'additional_user_price' => 'decimal:2',
            'is_active' => 'boolean',
            'is_trial_available' => 'boolean',
            'trial_duration_days' => 'integer',
        ];
    }

    /**
     * Get the features catalog items attached to this plan.
     *
     * @return BelongsToMany<CompanyFeature, $this>
     */
    public function features(): BelongsToMany
    {
        return $this->belongsToMany(
            CompanyFeature::class,
            'company_plan_features',
            'company_plan_id',
            'company_feature_id'
        );
    }

    /**
     * Get the subscriptions using this plan.
     *
     * @return HasMany<CompanySubscription, $this>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(CompanySubscription::class, 'company_plan_id');
    }

    /**
     * Get the CRM trials offered against this plan.
     *
     * @return HasMany<Trial, $this>
     */
    public function trials(): HasMany
    {
        return $this->hasMany(Trial::class);
    }
}
