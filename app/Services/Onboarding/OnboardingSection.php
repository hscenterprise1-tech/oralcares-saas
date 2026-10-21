<?php

namespace App\Services\Onboarding;

/**
 * One onboarding section. Sections are stateless: everything they need
 * arrives in the context, and everything they write goes to the clinic's
 * real configuration (hours, services, settings), never to onboarding
 * tables. The engine owns step state, drafts and idempotency.
 */
interface OnboardingSection
{
    public function key(): string;

    public function title(OnboardingContext $context): string;

    public function description(OnboardingContext $context): string;

    /** Optional sections may be skipped; required ones must be submitted. */
    public function required(OnboardingContext $context): bool;

    /** Which form the client renders for this section. */
    public function component(): string;

    /**
     * Create whatever the section needs to exist before the owner sees it
     * (for example suggested services). Must be idempotent.
     */
    public function prepare(OnboardingContext $context): void;

    /**
     * The form's starting values, read from the clinic's current
     * configuration.
     *
     * @return array<string, mixed>
     */
    public function initial(OnboardingContext $context): array;

    /**
     * Read-only facts and option lists the form shows.
     *
     * @return array<string, mixed>
     */
    public function meta(OnboardingContext $context): array;

    /**
     * @return array<string, mixed>
     */
    public function rules(OnboardingContext $context): array;

    /**
     * How fields are named in validation messages ("address", not
     * "address line1").
     *
     * @return array<string, string>
     */
    public function attributes(OnboardingContext $context): array;

    /**
     * Write validated data to the clinic's configuration. Runs inside the
     * engine's transaction; throw ValidationException to refuse.
     *
     * @param  array<string, mixed>  $data
     */
    public function apply(OnboardingContext $context, array $data): void;

    /**
     * Short lines for the final review.
     *
     * @return list<string>
     */
    public function summary(OnboardingContext $context): array;
}
