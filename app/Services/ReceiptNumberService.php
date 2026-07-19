<?php

namespace App\Services;

use App\Models\Clinic;
use App\Models\PatientBilling;

class ReceiptNumberService
{
    /**
     * Generate a unique sequential receipt number for a clinic.
     */
    public function generateReceiptNumber(Clinic $clinic): string
    {
        $count = PatientBilling::where('clinic_id', $clinic->id)->count() + 1;
        $padded = str_pad((string) $count, 5, '0', STR_PAD_LEFT);

        return "REC-{$clinic->id}-{$padded}";
    }
}
