<?php

namespace App\Services\Onboarding\Sections;

use App\Services\Onboarding\OnboardingContext;
use Illuminate\Validation\ValidationException;

/**
 * Confirm the primary branch provisioned from CRM. There is no branch
 * choice: the branch is resolved on the server, and more branches are added
 * later from branch management.
 */
class PrimaryBranchSection extends AbstractSection
{
    private const FIELDS = ['name', 'address_line1', 'address_line2', 'city', 'state', 'pincode', 'phone', 'whatsapp_number', 'gstin', 'clinical_establishment_registration_number'];

    public function key(): string
    {
        return 'primary_branch';
    }

    public function title(OnboardingContext $context): string
    {
        return 'Primary branch';
    }

    public function description(OnboardingContext $context): string
    {
        return 'Confirm the address and numbers patients use for your main branch.';
    }

    public function initial(OnboardingContext $context): array
    {
        $branch = $context->branch;

        return collect(self::FIELDS)->mapWithKeys(fn (string $field) => [$field => (string) ($branch?->{$field} ?? '')])->all();
    }

    public function meta(OnboardingContext $context): array
    {
        return [
            'note' => 'You can add more branches after setup from Branch management.',
        ];
    }

    public function rules(OnboardingContext $context): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'address_line1' => ['required', 'string', 'max:255'],
            'address_line2' => ['nullable', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:80'],
            'state' => ['required', 'string', 'max:80'],
            'pincode' => ['required', 'string', 'regex:/^[1-9][0-9]{5}$/'],
            'phone' => ['required', 'string', 'regex:/^[0-9+\-\s]{7,20}$/'],
            'whatsapp_number' => ['nullable', 'string', 'regex:/^[0-9+\-\s]{7,20}$/'],
            'gstin' => ['nullable', 'string', 'regex:/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][0-9A-Z]Z[0-9A-Z]$/'],
            'clinical_establishment_registration_number' => ['nullable', 'string', 'max:80'],
        ];
    }

    public function attributes(OnboardingContext $context): array
    {
        return [
            'name' => 'branch name',
            'address_line1' => 'address',
            'address_line2' => 'area',
            'pincode' => 'PIN code',
            'whatsapp_number' => 'WhatsApp number',
            'gstin' => 'GSTIN',
            'clinical_establishment_registration_number' => 'registration number',
        ];
    }

    public function apply(OnboardingContext $context, array $data): void
    {
        if (! $context->branch) {
            throw ValidationException::withMessages(['name' => 'This clinic has no primary branch yet. Contact OralCares support.']);
        }

        $context->branch->update(array_intersect_key($data, array_flip(self::FIELDS)) + ['is_main' => true]);
    }

    public function summary(OnboardingContext $context): array
    {
        $branch = $context->branch?->fresh();

        return array_values(array_filter([
            $branch?->name,
            collect([$branch?->address_line1, $branch?->city, $branch?->pincode])->filter()->implode(', '),
            $branch?->phone ? 'Phone '.$branch->phone : null,
        ]));
    }
}
