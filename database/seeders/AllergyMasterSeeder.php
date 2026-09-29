<?php

namespace Database\Seeders;

use App\Models\AllergyMaster;
use Illuminate\Database\Seeder;

/**
 * A generic, specialty-agnostic allergy catalogue — deliberately not
 * dental- or physio-specific, since a `Patient` record is shared across
 * whatever clinical modules this clinic runs (see [[clinic_phase3_generic_healthcare_patterns]]
 * in the project's own convention of keeping Core specialty-agnostic).
 * Covers the medication/material/food allergies a front-desk registration
 * most commonly needs to flag, including the two materials a dental or
 * physio visit specifically brings into contact with a patient (latex
 * gloves, adhesive tape) alongside the standard clinical list.
 *
 * Idempotent on `key`, same convention as `HealthcareSpecialtyMasterSeeder`.
 */
class AllergyMasterSeeder extends Seeder
{
    /**
     * @var array<string, list<array{0:string, 1:string}>>
     */
    private const CATALOGUE = [
        'medication' => [
            ['penicillin', 'Penicillin'],
            ['sulfa_drugs', 'Sulfa Drugs'],
            ['aspirin_nsaids', 'Aspirin / NSAIDs'],
            ['local_anesthetic', 'Local Anesthetic'],
            ['codeine_opioids', 'Codeine / Opioids'],
            ['iodine_contrast_dye', 'Iodine / Contrast Dye'],
        ],
        'material' => [
            ['latex', 'Latex'],
            ['adhesive_tape', 'Adhesive Tape'],
            ['nickel', 'Nickel'],
        ],
        'food' => [
            ['peanuts', 'Peanuts'],
            ['tree_nuts', 'Tree Nuts'],
            ['shellfish', 'Shellfish'],
            ['eggs', 'Eggs'],
            ['sulfites', 'Sulfites'],
        ],
    ];

    public function run(): void
    {
        $seeded = 0;

        foreach (self::CATALOGUE as $category => $allergies) {
            foreach ($allergies as $position => [$key, $name]) {
                AllergyMaster::updateOrCreate(
                    ['key' => $key],
                    [
                        'name' => $name,
                        'category' => $category,
                        'position' => $position,
                        'is_active' => true,
                    ],
                );

                $seeded++;
            }
        }

        $this->command?->info("Seeded {$seeded} allergies across ".count(self::CATALOGUE).' categories.');
    }
}
