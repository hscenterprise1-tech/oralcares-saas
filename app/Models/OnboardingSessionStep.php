<?php

namespace App\Models;

use App\OnboardingStepStatus;
use App\Traits\ClinicScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One step of an onboarding session: its state and timestamps, the
 * auto-saved draft, and the data last submitted.
 */
class OnboardingSessionStep extends Model
{
    use ClinicScoped;

    protected $fillable = [
        'onboarding_session_id',
        'clinic_id',
        'step_key',
        'position',
        'is_required',
        'status',
        'draft',
        'data',
        'submission_count',
        'started_at',
        'draft_saved_at',
        'completed_at',
        'skipped_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => OnboardingStepStatus::class,
            'is_required' => 'boolean',
            'draft' => 'array',
            'data' => 'array',
            'started_at' => 'datetime',
            'draft_saved_at' => 'datetime',
            'completed_at' => 'datetime',
            'skipped_at' => 'datetime',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(OnboardingSession::class, 'onboarding_session_id');
    }
}
