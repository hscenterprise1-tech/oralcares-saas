<?php

declare(strict_types=1);

namespace App\Models;

use App\CustomerChurnRisk;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerSuccessSnapshot extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'customer_success_snapshots';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'organization_id',
        'health_score',
        'health_dimensions',
        'churn_risk',
        'churn_reasons',
        'renewal_probability',
        'purchased_seats',
        'used_seats',
        'seat_utilization_pct',
        'purchased_branches',
        'active_branches',
        'branch_utilization_pct',
        'has_subscription',
        'active_subscription_id',
        'plan_id',
        'mrr',
        'ends_at',
        'days_to_expiry',
        'onboarding_status',
        'onboarding_current_step',
        'stage',
        'expansion_opportunity',
        'last_activity_at',
        'computed_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'health_score' => 'integer',
            'health_dimensions' => 'array',
            'churn_risk' => CustomerChurnRisk::class,
            'churn_reasons' => 'array',
            'renewal_probability' => 'integer',
            'purchased_seats' => 'integer',
            'used_seats' => 'integer',
            'seat_utilization_pct' => 'integer',
            'purchased_branches' => 'integer',
            'active_branches' => 'integer',
            'branch_utilization_pct' => 'integer',
            'has_subscription' => 'boolean',
            'mrr' => 'decimal:2',
            'ends_at' => 'datetime',
            'days_to_expiry' => 'integer',
            'expansion_opportunity' => 'boolean',
            'last_activity_at' => 'datetime',
            'computed_at' => 'datetime',
        ];
    }

    /**
     * The customer organization this snapshot describes.
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * The active subscription linked to this snapshot.
     */
    public function activeSubscription(): BelongsTo
    {
        return $this->belongsTo(CompanySubscription::class, 'active_subscription_id');
    }

    /**
     * The plan associated with the active subscription.
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(CompanyPlan::class, 'plan_id');
    }
}
