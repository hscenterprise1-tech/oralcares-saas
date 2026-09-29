<?php

namespace App;

/**
 * What a prospective clinic actually practises.
 *
 * Deliberately separate from ClinicType and OrganizationType rather than
 * reusing either. Those two describe what an organization *is* — a chain, a
 * diagnostic centre, a hospital — and they overlap with each other already.
 * This describes what is practised inside it, which is a different question:
 * a multi-specialty hospital is one organization type carrying several
 * specialties at once, and no single-valued type column can say that.
 *
 * Neither of the older enums is touched. ClinicType is used clinic-side for
 * provisioning and its cases carry meaning there; adding CRM's vocabulary to
 * it would push a sales concern into the clinic product.
 *
 * Values are stored, so they are stable identifiers rather than display text —
 * the label is what a person reads.
 */
enum HealthcareSpecialty: string
{
    case Dental = 'dental';
    case Ophthalmology = 'ophthalmology';
    case Physiotherapy = 'physiotherapy';
    case Dermatology = 'dermatology';
    case ENT = 'ent';
    case GeneralHospital = 'general_hospital';
    case MultiSpecialty = 'multi_specialty';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Dental => 'Dental',
            self::Ophthalmology => 'Eye / Ophthalmology',
            self::Physiotherapy => 'Physiotherapy',
            self::Dermatology => 'Dermatology',
            self::ENT => 'ENT',
            self::GeneralHospital => 'General Hospital',
            self::MultiSpecialty => 'Multi-specialty',
            self::Other => 'Other',
        };
    }

    /**
     * Whether picking this specialty means others must be picked alongside it.
     *
     * "Multi-specialty" is a claim that the organization does several things,
     * so on its own it says nothing. The form uses this to reveal the rest of
     * the list rather than hard-coding the same rule in the UI.
     */
    public function requiresMore(): bool
    {
        return $this === self::MultiSpecialty;
    }

    /**
     * @return list<array{value: string, label: string, requires_more: bool}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $case) => [
                'value' => $case->value,
                'label' => $case->label(),
                'requires_more' => $case->requiresMore(),
            ],
            self::cases()
        );
    }
}
