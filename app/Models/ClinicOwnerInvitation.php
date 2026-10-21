<?php

namespace App\Models;

use App\OwnerInvitationStatus;
use App\Traits\ClinicScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The invitation that hands a provisioned clinic to its owner. Only the
 * sha256 of the token is stored. Looked up before sign-in, so lookups by
 * token deliberately bypass the tenant scope.
 */
class ClinicOwnerInvitation extends Model
{
    use ClinicScoped;

    protected $fillable = [
        'clinic_id',
        'user_id',
        'provisioning_run_id',
        'email',
        'token_hash',
        'status',
        'send_count',
        'expires_at',
        'sent_at',
        'opened_at',
        'accepted_at',
        'profile_completed_at',
        'onboarding_started_at',
        'onboarding_completed_at',
        'activated_at',
        'revoked_at',
        'created_by',
    ];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'status' => OwnerInvitationStatus::class,
            'expires_at' => 'datetime',
            'sent_at' => 'datetime',
            'opened_at' => 'datetime',
            'accepted_at' => 'datetime',
            'profile_completed_at' => 'datetime',
            'onboarding_started_at' => 'datetime',
            'onboarding_completed_at' => 'datetime',
            'activated_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function isExpired(): bool
    {
        return $this->status === OwnerInvitationStatus::Expired
            || ($this->status->isOpen() && $this->expires_at->isPast());
    }

    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
