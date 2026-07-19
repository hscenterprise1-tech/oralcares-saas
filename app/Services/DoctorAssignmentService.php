<?php

namespace App\Services;

use App\Events\DoctorAssigned;
use App\Models\ClinicalEncounter;
use App\Models\ClinicEmployee;

class DoctorAssignmentService
{
    /**
     * Assign or reassign a doctor to a clinical encounter.
     */
    public function assignDoctor(ClinicalEncounter $encounter, ClinicEmployee $doctor): void
    {
        $encounter->update(['doctor_id' => $doctor->id]);

        event(new DoctorAssigned($encounter, $doctor));
    }
}
