<?php

namespace App\Services\Provisioning;

use App\OrganizationType;

/**
 * Replaceable starter data for a brand-new clinic.
 *
 * A trial that opens onto empty department, room and treatment lists cannot be
 * evaluated — the customer's first act would be twenty minutes of data entry
 * before they see anything the product does. So a new tenant starts with a
 * small, plausible set.
 *
 * This is explicitly *starter* data and is labelled as such in the rows it
 * creates, because it is not a clinical catalogue. Prices are absent by design:
 * inventing a fee schedule and presenting it as the clinic's own is worse than
 * leaving it blank. Everything here is meant to be edited or deleted.
 */
class StarterCatalogue
{
    /** Stamped into descriptions so the customer can see what is safe to delete. */
    public const MARKER = 'Starter data — edit or remove.';

    /**
     * Which starter set an organisation type calls for.
     *
     * The product is not dental-only, so a hospital must not open onto a
     * dental catalogue. Anything unrecognised gets the general set, which is
     * the safer of the two to be wrong with.
     */
    public function profile(?OrganizationType $type = null): string
    {
        return $type === OrganizationType::DentalClinic ? 'dental' : 'general';
    }

    /**
     * Departments a general clinic has on day one.
     *
     * @return list<array{name: string, key: string}>
     */
    public function departments(?OrganizationType $type = null): array
    {
        $base = [
            ['name' => 'Reception', 'key' => 'reception'],
            ['name' => 'Consultation', 'key' => 'consultation'],
            ['name' => 'Billing', 'key' => 'billing'],
        ];

        return array_merge($base, match ($this->profile($type)) {
            'general' => [
                ['name' => 'Emergency', 'key' => 'emergency'],
                ['name' => 'Inpatient', 'key' => 'inpatient'],
                ['name' => 'Laboratory', 'key' => 'laboratory'],
                ['name' => 'Radiology', 'key' => 'radiology'],
                ['name' => 'Pharmacy', 'key' => 'pharmacy'],
            ],
            default => [
                ['name' => 'Treatment', 'key' => 'treatment'],
                ['name' => 'Radiology', 'key' => 'radiology'],
            ],
        });
    }

    /**
     * @return list<array{name: string, key: string, type: string}>
     */
    public function rooms(?OrganizationType $type = null): array
    {
        $base = [
            ['name' => 'Consultation Room 1', 'key' => 'consult-1', 'type' => 'consultation'],
            ['name' => 'Procedure Room 1', 'key' => 'procedure-1', 'type' => 'procedure'],
        ];

        if ($this->profile($type) === 'general') {
            $base[] = ['name' => 'Emergency Bay 1', 'key' => 'emergency-1', 'type' => 'emergency'];
            $base[] = ['name' => 'Ward A', 'key' => 'ward-a', 'type' => 'ward'];
        }

        return $base;
    }

    /**
     * @return list<string>
     */
    public function specialties(?OrganizationType $type = null): array
    {
        return match ($this->profile($type)) {
            'general' => [
                'General Medicine', 'General Surgery', 'Paediatrics',
                'Obstetrics & Gynaecology', 'Orthopaedics', 'Dermatology',
            ],
            default => ['General Dentistry', 'Orthodontics', 'Endodontics', 'Periodontics', 'Oral Surgery'],
        };
    }

    /**
     * A minimal treatment list, grouped by the specialty it belongs to.
     *
     * Deliberately short. The purpose is to let a customer book, chart and bill
     * something on their first day, not to hand them a priced menu they did not
     * agree to.
     *
     * @return list<array{name: string, category: string}>
     */
    public function treatments(?OrganizationType $type = null): array
    {
        if ($this->profile($type) === 'general') {
            return [
                ['name' => 'New Patient Consultation', 'category' => 'General Medicine'],
                ['name' => 'Follow-up Consultation', 'category' => 'General Medicine'],
                ['name' => 'Dressing', 'category' => 'General Surgery'],
                ['name' => 'Immunisation', 'category' => 'Paediatrics'],
                ['name' => 'Antenatal Check', 'category' => 'Obstetrics & Gynaecology'],
                ['name' => 'Plaster Application', 'category' => 'Orthopaedics'],
            ];
        }

        return [
            ['name' => 'New Patient Consultation', 'category' => 'General Dentistry'],
            ['name' => 'Scaling & Polishing', 'category' => 'General Dentistry'],
            ['name' => 'Composite Filling', 'category' => 'General Dentistry'],
            ['name' => 'Root Canal Treatment', 'category' => 'Endodontics'],
            ['name' => 'Tooth Extraction', 'category' => 'Oral Surgery'],
            ['name' => 'Orthodontic Consultation', 'category' => 'Orthodontics'],
        ];
    }

    /**
     * Opening hours: Monday to Saturday, closed Sunday.
     *
     * `day_of_week` follows Carbon's convention, 0 = Sunday.
     *
     * A closed day still carries times because both columns are NOT NULL;
     * `is_closed` is the flag that decides, and the zeroed times are there to
     * satisfy the schema, not to be read.
     *
     * @return list<array{day_of_week: int, open_time: string, close_time: string, is_closed: bool}>
     */
    public function operatingHours(): array
    {
        $hours = [];

        for ($day = 0; $day <= 6; $day++) {
            $closed = $day === 0;

            $hours[] = [
                'day_of_week' => $day,
                'open_time' => $closed ? '00:00:00' : '09:00:00',
                'close_time' => $closed ? '00:00:00' : '18:00:00',
                'is_closed' => $closed,
            ];
        }

        return $hours;
    }
}
