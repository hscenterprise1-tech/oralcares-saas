<?php

namespace App;

/**
 * One onboarding step's state. A step starts pending, becomes in progress
 * on its first draft, and is completed or (when optional) skipped on
 * submission. A completed step can be resubmitted and stays completed.
 */
enum OnboardingStepStatus: string
{
    case Pending = 'pending';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Skipped = 'skipped';
    case Blocked = 'blocked';

    public function isDone(): bool
    {
        return $this === self::Completed || $this === self::Skipped;
    }
}
