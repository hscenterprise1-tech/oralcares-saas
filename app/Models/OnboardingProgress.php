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

    /**
     * The onboarding record a clinic user is working through: the active
     * clinic's organisation, or the user's own when the clinic has none.
     * Both the onboarding gate and the onboarding page read it through here,
     * so they always agree about which record applies.
     */
    public static function forClinicUser(User $user, ?Clinic $clinic): ?self
    {
        $organizationId = $clinic?->organization_id ?? $user->organization_id;

        if (! $organizationId) {
            return null;
        }

        return static::query()->where('organization_id', $organizationId)->orderBy('id')->first();
    }

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
