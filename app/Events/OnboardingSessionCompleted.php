<?php

namespace App\Events;

use App\Models\OnboardingSession;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An onboarding session finished its final step.
 */
class OnboardingSessionCompleted
{
    use Dispatchable;

    public function __construct(public OnboardingSession $session, public ?User $actor) {}
}
