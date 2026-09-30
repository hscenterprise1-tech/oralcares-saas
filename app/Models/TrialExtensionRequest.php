<?php

namespace App\Models;

use App\TrialExtensionRequestStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One ask to push a trial's end date out, and what happened to it.
 *
 * Created for every extension — a manager's own request is stamped approved
 * by itself in the same transaction that applies it, a rep's sits pending
 * until someone with authority acts on it. Either way, `TrialExtensionApprovalService`
 * is the only thing that writes here, and the actual date move always runs
 * through `TrialLifecycleService::extend()` once a request is approved — this
 * table is the gate and the audit trail, never the thing that moves the date
 * itself.
 */
class TrialExtensionRequest extends Model
{
    protected $fillable = [
        'trial_id',
        'requested_by',
        'approved_by',
        'additional_days',
        'reason',
        'status',
        'rejection_reason',
    ];

    protected function casts(): array
    {
        return [
            'additional_days' => 'integer',
            'status' => TrialExtensionRequestStatus::class,
        ];
    }

    public function trial(): BelongsTo
    {
        return $this->belongsTo(Trial::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
