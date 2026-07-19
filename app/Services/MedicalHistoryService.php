<?php

namespace App\Services;

use App\Models\Patient;
use App\Models\PatientMedicalHistory;

class MedicalHistoryService
{
    /**
     * Update the patient medical history and synchronize high-severity alerts.
     */
    public function updateMedicalHistory(Patient $patient, array $data): PatientMedicalHistory
    {
        $history = PatientMedicalHistory::updateOrCreate(
            ['patient_id' => $patient->id],
            $data
        );

        $patient->alerts()->delete();

        if ($history->cardiac_condition) {
            $patient->alerts()->create([
                'type' => 'medical_condition',
                'description' => 'Cardiac Condition Alert',
                'severity' => 'critical',
            ]);
        }

        if ($history->diabetes) {
            $patient->alerts()->create([
                'type' => 'medical_condition',
                'description' => 'Diabetic Alert',
                'severity' => 'high',
            ]);
        }

        if ($history->blood_disorder) {
            $patient->alerts()->create([
                'type' => 'medical_condition',
                'description' => 'Blood Disorder Alert',
                'severity' => 'critical',
            ]);
        }

        if (! empty($history->allergies)) {
            foreach ($history->allergies as $allergy) {
                $patient->alerts()->create([
                    'type' => 'allergy',
                    'description' => 'Allergy: '.$allergy,
                    'severity' => 'high',
                ]);
            }
        }

        return $history;
    }
}
