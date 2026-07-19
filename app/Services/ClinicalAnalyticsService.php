<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicalDiagnosis;
use App\Models\LabOrder;
use App\Models\TreatmentRecord;
use App\Models\XrayRecord;

class ClinicalAnalyticsService
{
    /**
     * Compile diagnostic frequencies, procedure metrics, and lab/imaging counts.
     */
    public function getClinicalMetrics(Clinic $clinic, ?Branch $branch, string $startDate, string $endDate): array
    {
        $txQuery = TreatmentRecord::whereHas('encounter', function ($q) use ($clinic) {
            $q->where('clinic_id', $clinic->id);
        })->whereBetween('created_at', [$startDate.' 00:00:00', $endDate.' 23:59:59']);

        $dxQuery = ClinicalDiagnosis::whereHas('encounter', function ($q) use ($clinic) {
            $q->where('clinic_id', $clinic->id);
        })->whereBetween('created_at', [$startDate.' 00:00:00', $endDate.' 23:59:59']);

        $xrQuery = XrayRecord::whereHas('patient', function ($q) use ($clinic) {
            $q->where('clinic_id', $clinic->id);
        })->whereBetween('created_at', [$startDate.' 00:00:00', $endDate.' 23:59:59']);

        $lbQuery = LabOrder::whereHas('patient', function ($q) use ($clinic) {
            $q->where('clinic_id', $clinic->id);
        })->whereBetween('created_at', [$startDate.' 00:00:00', $endDate.' 23:59:59']);

        if ($branch) {
            $txQuery->whereHas('encounter', function ($q) use ($branch) {
                $q->where('branch_id', $branch->id);
            });
            $dxQuery->whereHas('encounter', function ($q) use ($branch) {
                $q->where('branch_id', $branch->id);
            });
            $xrQuery->whereHas('encounter', function ($q) use ($branch) {
                $q->where('branch_id', $branch->id);
            });
            $lbQuery->whereHas('encounter', function ($q) use ($branch) {
                $q->where('branch_id', $branch->id);
            });
        }

        // Procedure frequency
        $procedures = [];
        foreach ($txQuery->get() as $tx) {
            $procedures[$tx->code] = ($procedures[$tx->code] ?? 0) + 1;
        }

        // Diagnosis frequency
        $diagnoses = [];
        foreach ($dxQuery->get() as $dx) {
            $diagnoses[$dx->code] = ($diagnoses[$dx->code] ?? 0) + 1;
        }

        return [
            'procedure_frequencies' => $procedures,
            'diagnosis_frequencies' => $diagnoses,
            'xray_orders_count' => $xrQuery->count(),
            'lab_orders_count' => $lbQuery->count(),
        ];
    }
}
