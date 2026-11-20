<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Clinic;
use App\Services\Patient\ClinicSpecialties;
use App\Services\Physio\PhysioCareSummary;

/**
 * The addresses of the owner's settings pages, for the tabs shown above each
 * one, so a page never builds a path by hand. Physio appears only for a
 * clinic that practises physiotherapy.
 */
trait LinksSettingsPages
{
    /**
     * @return array{general: string, patient_form: string, automation: string, consents: string, physio: string|null}
     */
    protected function settingsLinks(Clinic $clinic): array
    {
        return [
            'general' => route('admin.settings.index'),
            'patient_form' => route('admin.settings.patient-form'),
            'automation' => route('admin.settings.automation'),
            'consents' => route('admin.consents.index'),
            'physio' => app(ClinicSpecialties::class)->active($clinic, PhysioCareSummary::SPECIALTY) !== null ? route('admin.settings.physio') : null,
        ];
    }
}
