<?php

namespace App\Events;

use App\Models\OnboardingSession;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The first saved change in an onboarding session.
 */
class OnboardingSessionStarted
{
    use Dispatchable;

    public function __construct(public OnboardingSession $session, public ?User $actor) {}
}
