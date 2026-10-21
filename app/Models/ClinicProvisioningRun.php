<?php

namespace App\Models;

use App\ProvisioningRunStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One request to provision a clinic, from a won deal or a retry.
 *
 * Not clinic scoped: a run exists before its clinic does, and it is read by
 * the company side across tenants. Each completed stage is recorded, so a
 * retry skips what already happened.
 */
class ClinicProvisioningRun extends Model
{
    /** In order. The onboarding session exists before the invitation is sent. */
    public const STAGES = ['tenant', 'primary_branch', 'specialty', 'onboarding_session', 'owner_invitation'];

    public const SOURCE_DEAL_WON = 'deal_won';

    /** Renewal, expansion or a converted trial: the clinic and its owner already exist. */
    public const SOURCE_EXISTING_CUSTOMER = 'existing_customer';

    /**
     * The stages this run goes through.
     *
     * @return list<string>
     */
    public function stages(): array
    {
        return $this->source === self::SOURCE_EXISTING_CUSTOMER ? ['tenant'] : self::STAGES;
    }

    protected $fillable = [
        'idempotency_key',
        'source',
        'lead_id',
        'organization_id',
        'clinic_id',
        'branch_id',
        'owner_user_id',
        'requested_by',
        'status',
        'completed_stages',
        'current_stage',
        'attempts',
        'last_error',
        'queued_at',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => ProvisioningRunStatus::class,
            'completed_stages' => 'array',
            'queued_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function hasCompleted(string $stage): bool
    {
        return in_array($stage, $this->completed_stages ?? [], true);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }
}
