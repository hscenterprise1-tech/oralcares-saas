<?php

namespace App\Models;

use App\Traits\ClinicScoped;
use App\Traits\OrganizationScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A request to remove, void or merge a governed record, decided by the
 * clinic owner. Written only through `Governance\DeletionGovernance`.
 *
 * pending ⇄ clarification_requested → executed (approved and carried out),
 * or rejected / cancelled / expired. An owner acting directly produces an
 * already-executed request, so every destructive action has one trail.
 */
class DeletionRequest extends Model
{
    use ClinicScoped, OrganizationScoped;

    public const PENDING = 'pending';

    public const CLARIFICATION = 'clarification_requested';

    public const APPROVED = 'approved';

    public const EXECUTED = 'executed';

    public const REJECTED = 'rejected';

    public const CANCELLED = 'cancelled';

    public const EXPIRED = 'expired';

    /** Days an undecided request stays open. */
    public const LIFETIME_DAYS = 30;

    protected $fillable = [
        'organization_id',
        'clinic_id',
        'branch_id',
        'patient_id',
        'requester_user_id',
        'action_type',
        'target_type',
        'target_id',
        'target_label',
        'target_fingerprint',
        'reason',
        'note',
        'payload',
        'status',
        'open_key',
        'approver_user_id',
        'approval_note',
        'rejected_reason',
        'thread',
        'direct',
        'expires_at',
        'approved_at',
        'rejected_at',
        'executed_at',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'thread' => 'array',
            'payload' => 'array',
            'direct' => 'boolean',
            'expires_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'executed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /** Waiting on the owner or on the requester. */
    public function isOpen(): bool
    {
        return in_array($this->status, [self::PENDING, self::CLARIFICATION], true);
    }

    public function isExpired(): bool
    {
        return $this->isOpen() && $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_user_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_user_id');
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
