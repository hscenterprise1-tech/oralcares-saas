<?php

namespace App;

/**
 * What a WhatsApp number is for.
 *
 * Holding several numbers already worked — the schema allowed it and analytics
 * already filtered by one — but nothing recorded what any of them was *for*, so
 * nothing could route by it and a person reading the inbox saw only a phone
 * number. With four or five lines running at once, "9876543210" does not tell
 * anyone whether a reply is a marketing enquiry or a clinic asking for help.
 *
 * Purposes are deliberately few. Each one is a different conversation the
 * business has, not a different team or campaign — those belong to assignment
 * and tags, which already exist.
 */
enum WhatsAppNumberPurpose: string
{
    /** Outbound campaigns and everything a stranger first replies to. */
    case Marketing = 'marketing';

    /** A named salesperson working a live deal. */
    case Sales = 'sales';

    /** Walking a won customer through setup and go-live. */
    case Onboarding = 'onboarding';

    /** Existing customers with a problem. */
    case Support = 'support';

    /**
     * Lent to a clinic that has no number of its own, so their patients hear
     * from us on their behalf. Never used for our own outbound.
     */
    case ClinicPool = 'clinic_pool';

    public function label(): string
    {
        return match ($this) {
            self::Marketing => 'Marketing',
            self::Sales => 'Sales',
            self::Onboarding => 'Onboarding',
            self::Support => 'Support',
            self::ClinicPool => 'Clinic pool',
        };
    }

    /** One line explaining the choice, for the settings screen. */
    public function description(): string
    {
        return match ($this) {
            self::Marketing => 'Campaigns and first replies from people who are not customers yet.',
            self::Sales => 'A salesperson working a live deal.',
            self::Onboarding => 'Setting a won customer up and getting them live.',
            self::Support => 'Existing customers with a problem.',
            self::ClinicPool => 'Lent to clinics without their own number, for messaging their patients.',
        };
    }

    /**
     * The tone of the badge this purpose wears in the inbox.
     *
     * Values are `CrmTone` hues, so the colour comes from the same palette every
     * other badge in the product uses rather than a second set of colours.
     */
    public function tone(): string
    {
        return match ($this) {
            self::Marketing => 'violet',
            self::Sales => 'amber',
            self::Onboarding => 'teal',
            self::Support => 'coral',
            self::ClinicPool => 'slate',
        };
    }

    /** @return array<int, array{value: string, label: string, description: string, tone: string}> */
    public static function options(): array
    {
        return array_map(fn (self $case) => [
            'value' => $case->value,
            'label' => $case->label(),
            'description' => $case->description(),
            'tone' => $case->tone(),
        ], self::cases());
    }
}
