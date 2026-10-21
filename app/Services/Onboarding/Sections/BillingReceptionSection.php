<?php

namespace App\Services\Onboarding\Sections;

use App\Models\NumberingSequence;
use App\Services\Onboarding\OnboardingContext;
use Illuminate\Validation\Rule;

/**
 * How the front desk bills: invoice numbering, tax, accepted payment
 * methods, the registration fee and the receipt footer. Stored in
 * `clinics.billing_config` and the branch's invoice numbering sequence.
 */
class BillingReceptionSection extends AbstractSection
{
    public const TAX_MODES = ['none', 'inclusive', 'exclusive'];

    public const DEFAULTS = [
        'tax_mode' => 'none',
        'tax_percent' => 0,
        'payment_methods' => ['cash', 'upi', 'card'],
        'registration_fee' => null,
        'receipt_footer' => 'Thank you for visiting.',
    ];

    public function key(): string
    {
        return 'billing_reception';
    }

    public function title(OnboardingContext $context): string
    {
        return 'Billing & reception';
    }

    public function description(OnboardingContext $context): string
    {
        return 'How invoices are numbered and paid at the front desk.';
    }

    public function initial(OnboardingContext $context): array
    {
        return [
            'invoice_prefix' => $this->sequence($context)?->prefix ?? 'INV-',
            ...array_replace(self::DEFAULTS, array_intersect_key((array) ($context->clinic->billing_config ?? []), self::DEFAULTS)),
        ];
    }

    public function meta(OnboardingContext $context): array
    {
        return ['payment_methods' => config('clinic_onboarding.payment_methods'), 'tax_modes' => self::TAX_MODES];
    }

    public function rules(OnboardingContext $context): array
    {
        return [
            'invoice_prefix' => ['required', 'string', 'max:12', 'regex:/^[A-Za-z0-9\-\/]+$/'],
            'tax_mode' => ['required', Rule::in(self::TAX_MODES)],
            'tax_percent' => ['required', 'numeric', 'min:0', 'max:28'],
            'payment_methods' => ['required', 'array', 'min:1'],
            'payment_methods.*' => ['distinct', Rule::in(config('clinic_onboarding.payment_methods'))],
            'registration_fee' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'receipt_footer' => ['nullable', 'string', 'max:300'],
        ];
    }

    public function apply(OnboardingContext $context, array $data): void
    {
        $context->clinic->update(['billing_config' => [
            ...(array) ($context->clinic->billing_config ?? []),
            'tax_mode' => $data['tax_mode'],
            'tax_percent' => $data['tax_mode'] === 'none' ? 0 : (float) $data['tax_percent'],
            'payment_methods' => array_values($data['payment_methods']),
            'registration_fee' => isset($data['registration_fee']) ? (float) $data['registration_fee'] : null,
            'receipt_footer' => $data['receipt_footer'] ?? null,
        ]]);

        $sequence = $this->sequence($context) ?? new NumberingSequence([
            'organization_id' => $context->clinic->organization_id,
            'branch_id' => $context->branch?->id,
            'entity_type' => 'invoice',
            // Nothing issued yet: the first invoice takes 1.
            'current_number' => 0,
            'padding' => config('clinic_numbering.documents.invoice.padding'),
            'reset_frequency' => 'financial_yearly',
        ]);
        $sequence->prefix = strtoupper($data['invoice_prefix']);
        $sequence->save();
    }

    public function summary(OnboardingContext $context): array
    {
        $values = $this->initial($context);

        return [
            'Invoices start with '.$values['invoice_prefix'],
            $values['tax_mode'] === 'none' ? 'No tax on invoices' : "{$values['tax_percent']}% tax, {$values['tax_mode']}",
            'Payments: '.collect($values['payment_methods'])->map(fn (string $method) => str_replace('_', ' ', $method))->implode(', '),
        ];
    }

    private function sequence(OnboardingContext $context): ?NumberingSequence
    {
        return NumberingSequence::query()
            ->where('organization_id', $context->clinic->organization_id)
            ->where('branch_id', $context->branch?->id)
            ->where('entity_type', 'invoice')
            ->first();
    }
}
