<?php

namespace App\Services\Patient;

use App\Models\Clinic;
use App\Models\Organization;
use App\Services\Core\NumberingEngine;

class UhidGenerator
{
    public function __construct(protected NumberingEngine $engine) {}

    /**
     * Generate the next unique UHID for a patient within an organization.
     *
     * @return string e.g. "OC-00000001"
     */
    public function generate(Clinic $clinic): string
    {
        // Fallback to clinic's organization if clinic_id is being phased out,
        // but currently we still use clinic models with organization_id
        $organization = $clinic->organization;

        // If organization relation is not loaded or missing, we could throw an exception or fallback
        // assuming $clinic represents a Branch and has organization_id
        if (! $organization && isset($clinic->organization_id)) {
            $organization = Organization::find($clinic->organization_id);
        }

        if (! $organization) {
            throw new \Exception('Organization not found for UHID generation.');
        }

        $prefix = config('app.patient.uhid_prefix', 'OC-');
        $pad = config('app.patient.uhid_pad_length', 8);

        return $this->engine->generate('patient', $organization, null, [
            'prefix' => $prefix,
            'padding' => $pad,
        ]);
    }
}
