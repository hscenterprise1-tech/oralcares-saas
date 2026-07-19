<?php

namespace App\Services;

use App\Models\Patient;
use App\Models\PatientProfile;
use Illuminate\Support\Carbon;

class PatientProfileService
{
    /**
     * Dynamically calculate patient age from date of birth.
     */
    public function getAge(Patient $patient): ?int
    {
        if (! $patient->date_of_birth) {
            return null;
        }

        return Carbon::parse($patient->date_of_birth)->age;
    }

    /**
     * Update or create the patient demographic profile.
     */
    public function updateProfile(Patient $patient, array $data): PatientProfile
    {
        return PatientProfile::updateOrCreate(
            ['patient_id' => $patient->id],
            $data
        );
    }
}
