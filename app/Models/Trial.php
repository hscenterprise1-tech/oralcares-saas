<?php

namespace App\Models;

use App\TrialExtensionRequestStatus;
use App\TrialOutcome;
use App\TrialStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Trial extends Model
{
    use HasFactory;

    protected $fillable = [
        'lead_id',
        'customer_organization_id',
        'clinic_id',
        'company_plan_id',
        'plan',
        'duration_days',
        'assigned_user_id',
        'start_date',
        'end_date',
        'grace_ends_at',
        'status',
        'provisioned_at',
        'provisioning_error',
        'expected_users',
        'max_doctors',
        'notes',
        'onboarding_checklist',
        'lifecycle_events',
        'outcome',
        'feedback',
        'objections',
        'requested_features',
        'next_action',
        'next_followup_at',
        'extension_count',
        'original_end_date',
        'extended_at',
        'extension_reason',
        'completed_at',
        'cancelled_at',
        'cancellation_reason',
        'lost_at',
        'lost_reason',
        'lost_competitor',
        'usage_telemetry',
        'last_login',
        'users_created',
        'usage_metrics',
        'usage_percent',
        'inactive_days',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'grace_ends_at' => 'datetime',
            'provisioned_at' => 'datetime',
            'original_end_date' => 'date',
            'extended_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'lost_at' => 'datetime',
            'usage_telemetry' => 'array',
            'next_followup_at' => 'datetime',
            'last_login' => 'datetime',
            'status' => TrialStatus::class,
            'outcome' => TrialOutcome::class,
            'onboarding_checklist' => 'array',
            'lifecycle_events' => 'array',
            'usage_metrics' => 'array',
            'usage_percent' => 'decimal:2',
            'duration_days' => 'integer',
            'extension_count' => 'integer',
            'expected_users' => 'integer',
            'max_doctors' => 'integer',
        ];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function companyPlan(): BelongsTo
    {
        return $this->belongsTo(CompanyPlan::class);
    }

    /**
     * The customer organisation this trial provisioned.
     *
     * Not to be confused with `leads.organization_id`, which is the vendor
     * tenant that owns the deal. This one is the customer's own tenant, and it
     * is the same organisation they keep when the deal is won.
     */
    public function customerOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'customer_organization_id');
    }

    /** The operational tenant the clinic portal routes by. */
    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    /** Whether the customer has somewhere to log in yet. */
    public function isProvisioned(): bool
    {
        return $this->provisioned_at !== null && $this->clinic_id !== null;
    }

    public function extensionRequests(): HasMany
    {
        return $this->hasMany(TrialExtensionRequest::class);
    }

    /** The one request Deal 360 banners on — never more than one open at a time. */
    public function pendingExtensionRequest(): HasOne
    {
        return $this->hasOne(TrialExtensionRequest::class)
            ->ofMany(['created_at' => 'max'], function ($query) {
                $query->where('status', TrialExtensionRequestStatus::Pending);
            });
    }
}
