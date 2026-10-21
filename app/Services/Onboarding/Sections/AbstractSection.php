<?php

namespace App\Services\Onboarding\Sections;

use App\Services\Onboarding\OnboardingContext;
use App\Services\Onboarding\OnboardingSection;

/**
 * Defaults shared by most sections.
 */
abstract class AbstractSection implements OnboardingSection
{
    public function required(OnboardingContext $context): bool
    {
        return true;
    }

    public function component(): string
    {
        return $this->key();
    }

    public function prepare(OnboardingContext $context): void {}

    public function meta(OnboardingContext $context): array
    {
        return [];
    }

    public function attributes(OnboardingContext $context): array
    {
        return [];
    }

    public function summary(OnboardingContext $context): array
    {
        return [];
    }
}
