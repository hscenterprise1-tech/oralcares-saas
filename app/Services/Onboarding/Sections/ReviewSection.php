<?php

namespace App\Services\Onboarding\Sections;

use App\Services\Onboarding\OnboardingContext;
use App\Services\Onboarding\OnboardingEngine;

/**
 * The last step: every earlier step summarised, then confirmation. The
 * engine refuses it until every required step is done and completes the
 * session when it is submitted.
 */
class ReviewSection extends AbstractSection
{
    public function key(): string
    {
        return 'review';
    }

    public function title(OnboardingContext $context): string
    {
        return 'Review & finish';
    }

    public function description(OnboardingContext $context): string
    {
        return $context->employee
            ? 'Check your details, then finish to open the clinic workspace.'
            : 'Check everything once more. Finishing opens the clinic to your team.';
    }

    public function component(): string
    {
        return 'review';
    }

    public function initial(OnboardingContext $context): array
    {
        return ['confirmed' => false];
    }

    public function meta(OnboardingContext $context): array
    {
        return ['sections' => app(OnboardingEngine::class)->review($context->session, $context->actor)];
    }

    public function rules(OnboardingContext $context): array
    {
        return ['confirmed' => ['accepted']];
    }

    public function apply(OnboardingContext $context, array $data): void {}
}
