<?php

namespace App\Services\Onboarding;

use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicEmployee;
use App\Models\OnboardingSession;
use App\Models\User;

/**
 * What a section works with: the session, its clinic, the primary branch
 * (resolved on the server, never taken from the request), the acting user,
 * the staff member for staff onboarding, and the template's options.
 */
final class OnboardingContext
{
    /**
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        public readonly OnboardingSession $session,
        public readonly Clinic $clinic,
        public readonly ?Branch $branch,
        public readonly ?User $actor,
        public readonly ?ClinicEmployee $employee,
        public readonly array $options,
    ) {}

    public function option(string $key, mixed $default = null): mixed
    {
        return data_get($this->options, $key, $default);
    }
}
