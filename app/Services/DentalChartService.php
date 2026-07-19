<?php

namespace App\Services;

use App\Models\ClinicEmployee;
use App\Models\Patient;
use App\Models\PatientDentalChart;
use InvalidArgumentException;

class DentalChartService
{
    /**
     * Check if a tooth number is valid in FDI notation.
     */
    public function isValidFdiToothNumber(int $num): bool
    {
        $valid = [
            11, 12, 13, 14, 15, 16, 17, 18,
            21, 22, 23, 24, 25, 26, 27, 28,
            31, 32, 33, 34, 35, 36, 37, 38,
            41, 42, 43, 44, 45, 46, 47, 48,
            51, 52, 53, 54, 55,
            61, 62, 63, 64, 65,
            71, 72, 73, 74, 75,
            81, 82, 83, 84, 85,
        ];

        return in_array($num, $valid);
    }

    /**
     * Update the status of a specific tooth.
     */
    public function updateToothStatus(
        Patient $patient,
        int $toothNumber,
        string $status,
        ?string $notes = null,
        ?ClinicEmployee $doctor = null
    ): PatientDentalChart {
        if (! $this->isValidFdiToothNumber($toothNumber)) {
            throw new InvalidArgumentException("Tooth number '{$toothNumber}' is not a valid FDI identifier.");
        }

        return PatientDentalChart::updateOrCreate(
            [
                'patient_id' => $patient->id,
                'tooth_number' => $toothNumber,
            ],
            [
                'status' => $status,
                'notes' => $notes,
                'updated_by_doctor_id' => $doctor?->id,
            ]
        );
    }
}
