<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanySubscriptionEntitlement extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'company_subscription_id',
        'company_feature_id',
        'is_enabled',
        'usage_limit',
        'is_unlimited',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'is_unlimited' => 'boolean',
        ];
    }

    /**
     * Get the subscription details.
     *
     * @return BelongsTo<CompanySubscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(CompanySubscription::class, 'company_subscription_id');
    }

    /**
     * Get the feature description.
     *
     * @return BelongsTo<CompanyFeature, $this>
     */
    public function feature(): BelongsTo
    {
        return $this->belongsTo(CompanyFeature::class, 'company_feature_id');
    }
}
