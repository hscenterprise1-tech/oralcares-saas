<?php

namespace App\Services;

use App\Events\PatientCheckedIn;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicalEncounter;
use App\Models\ClinicEmployee;
use App\Models\Patient;
use App\VisitStatus;
use Illuminate\Support\Facades\DB;

class VisitCheckInService
{
    public function __construct(
        protected ClinicalIdentifierService $identifierService
    ) {}

    /**
     * Check in a patient for a visit, creating a new Clinical Encounter.
     */
    public function checkIn(
        Clinic $clinic,
        Branch $branch,
        Patient $patient,
        ?ClinicEmployee $doctor = null
    ): ClinicalEncounter {
        return DB::transaction(function () use ($clinic, $branch, $patient, $doctor) {
            $visitNumber = $this->identifierService->generateVisitNumber($clinic);

            $encounter = ClinicalEncounter::create([
                'clinic_id' => $clinic->id,
                'patient_id' => $patient->id,
                'branch_id' => $branch->id,
                'doctor_id' => $doctor?->id,
                'encounter_number' => $visitNumber,
                'status' => VisitStatus::CheckedIn,
                'started_at' => now(),
            ]);

            event(new PatientCheckedIn($encounter));

            return $encounter;
        });
    }
}
