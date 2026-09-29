<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

class OnboardingProgress extends Model
{
    protected $fillable = [
        'organization_id',
        'onboarding_template_id',
        'current_step',
        'status',
        'completed_steps',
    ];

    protected $casts = [
        'completed_steps' => 'array',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(OnboardingTemplate::class, 'onboarding_template_id');
    }

    /**
     * The sequence this account is being taken through.
     *
     * Held on the row once resolved rather than looked up each time, so editing
     * a template later cannot silently rewrite the checklist an account is
     * already half way through. An older row that predates templates resolves
     * by clinic type on read and is left alone.
     *
     * @return Collection<int, OnboardingTemplateStep>
     */
    public function steps(): Collection
    {
        $template = $this->template
            ?? OnboardingTemplate::forClinicType($this->organization?->clinic_type);

        return $template?->steps ?? collect();
    }

    /**
     * Whether every required step has been recorded as complete.
     *
     * Advisory: `markGoLive()` does not refuse on a false, because a customer
     * success rep occasionally knows a step is satisfied outside the system.
     * It is what lets the page say so rather than pretending.
     */
    public function hasCompletedRequiredSteps(): bool
    {
        $done = $this->completed_steps ?? [];

        return $this->steps()
            ->where('is_required', true)
            ->every(fn (OnboardingTemplateStep $step) => in_array($step->step_key, $done, true));
    }
}
