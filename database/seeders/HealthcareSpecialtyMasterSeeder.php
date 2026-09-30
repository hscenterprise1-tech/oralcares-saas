<?php

namespace Database\Seeders;

use App\Models\HealthcareSpecialtyMaster;
use Illuminate\Database\Seeder;

/**
 * The specialty catalogue, across every domain the product is sold into.
 *
 * Idempotent on `key`, so re-running updates names and ordering without
 * orphaning the leads that reference them. Nothing is ever deleted here — a
 * specialty that is no longer offered is deactivated, because leads recorded
 * against it still have to render.
 */
class HealthcareSpecialtyMasterSeeder extends Seeder
{
    /**
     * Ordered within each category the way a practitioner would expect rather
     * than alphabetically — "General Medicine" leads primary care because that
     * is what most of these practices call themselves.
     *
     * @var array<string, list<array{0:string, 1:string}>>
     */
    private const CATALOGUE = [
        'general' => [
            ['general_medicine', 'General Medicine'],
            ['family_practice', 'Family Practice'],
            ['diabetology', 'Diabetology'],
        ],
        'maternal_child' => [
            ['pediatrics', 'Pediatrics'],
            ['gynecology', 'Gynecology'],
            ['obstetrics', 'Obstetrics'],
            ['ivf_fertility', 'IVF / Fertility'],
        ],
        'surgical_specialty' => [
            ['orthopedics', 'Orthopedics'],
            ['ophthalmology', 'Ophthalmology'],
            ['ent', 'ENT'],
            ['dermatology', 'Dermatology'],
            ['general_surgery', 'General Surgery'],
            ['urology', 'Urology'],
        ],
        'super_specialty' => [
            ['cardiology', 'Cardiology'],
            ['neurology', 'Neurology'],
            ['gastroenterology', 'Gastroenterology'],
            ['oncology', 'Oncology'],
            ['nephrology', 'Nephrology'],
        ],
        'dental' => [
            ['general_dentistry', 'General Dentistry'],
            ['orthodontics', 'Orthodontics'],
            ['endodontics', 'Endodontics'],
            ['oral_surgery', 'Oral Surgery'],
        ],
        'rehabilitation' => [
            ['physiotherapy', 'Physiotherapy'],
            ['occupational_therapy', 'Occupational Therapy'],
            ['speech_therapy', 'Speech Therapy'],
            ['nutrition', 'Nutrition & Dietetics'],
        ],
        'ayush' => [
            ['homeopathy', 'Homeopathy'],
            ['ayurveda', 'Ayurveda'],
            ['naturopathy', 'Naturopathy'],
            ['unani', 'Unani'],
        ],
        'diagnostics' => [
            ['pathology_lab', 'Pathology Lab'],
            ['radiology_imaging', 'Radiology / Imaging'],
            ['dialysis_center', 'Dialysis Centre'],
        ],
    ];

    public function run(): void
    {
        $seeded = 0;

        foreach (self::CATALOGUE as $category => $specialties) {
            $categoryLabel = HealthcareSpecialtyMaster::CATEGORIES[$category]['label'] ?? $category;

            foreach ($specialties as $position => [$key, $name]) {
                HealthcareSpecialtyMaster::updateOrCreate(
                    ['key' => $key],
                    [
                        'name' => $name,
                        'category' => $category,
                        'category_label' => $categoryLabel,
                        'position' => $position,
                        'is_active' => true,
                    ],
                );

                $seeded++;
            }
        }

        $this->command?->info("Seeded {$seeded} healthcare specialties across ".count(self::CATALOGUE).' categories.');
    }
}
