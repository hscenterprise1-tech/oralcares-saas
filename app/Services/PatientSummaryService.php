<?php

namespace App\Services;

use App\Models\Patient;

class PatientSummaryService
{
    /**
     * Compile a clinical patient summary block.
     */
    public function getSummary(Patient $patient): array
    {
        $patient->load(['profile', 'medicalHistory', 'alerts', 'encounters', 'treatmentPlans', 'treatmentRecords']);

        return [
            'patient' => [
                'name' => $patient->name,
                'email' => $patient->email,
                'phone' => $patient->phone,
                'date_of_birth' => $patient->date_of_birth,
                'gender' => $patient->gender,
                'status' => $patient->status,
            ],
            'profile' => $patient->profile ? $patient->profile->toArray() : [],
            'medical_history' => $patient->medicalHistory ? [
                'allergies' => $patient->medicalHistory->allergies ?? [],
                'current_medications' => $patient->medicalHistory->current_medications ?? [],
                'conditions' => [
                    'diabetes' => $patient->medicalHistory->diabetes,
                    'hypertension' => $patient->medicalHistory->hypertension,
                    'cardiac_condition' => $patient->medicalHistory->cardiac_condition,
                    'blood_disorder' => $patient->medicalHistory->blood_disorder,
                ],
            ] : [],
            'alerts' => $patient->alerts->map(function ($a) {
                return [
                    'type' => $a->type,
                    'description' => $a->description,
                    'severity' => $a->severity,
                ];
            })->toArray(),
            'encounters' => $patient->encounters->map(function ($e) {
                return [
                    'encounter_number' => $e->encounter_number,
                    'status' => $e->status,
                    'started_at' => $e->started_at,
                ];
            })->toArray(),
            'treatment_plans' => $patient->treatmentPlans->map(function ($tp) {
                return [
                    'title' => $tp->title,
                    'status' => $tp->status,
                ];
            })->toArray(),
            'treatment_records' => $patient->treatmentRecords->map(function ($tr) {
                return [
                    'code' => $tr->code,
                    'description' => $tr->description,
                    'tooth_number' => $tr->tooth_number,
                    'status' => $tr->status,
                ];
            })->toArray(),
        ];
    }
}
