<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicalConsultation;
use App\Models\PatientBilling;
use App\Models\TreatmentRecord;

class DoctorAnalyticsService
{
    /**
     * Calculate consultation counts, completed treatments, and invoice volumes grouped by doctor.
     */
    public function getDoctorMetrics(Clinic $clinic, ?Branch $branch, string $startDate, string $endDate): array
    {
        $consultQuery = ClinicalConsultation::whereHas('encounter', function ($q) use ($clinic) {
            $q->where('clinic_id', $clinic->id);
        })->whereBetween('created_at', [$startDate.' 00:00:00', $endDate.' 23:59:59']);

        $txQuery = TreatmentRecord::whereHas('encounter', function ($q) use ($clinic) {
            $q->where('clinic_id', $clinic->id);
        })->where('status', 'completed')
            ->whereBetween('updated_at', [$startDate.' 00:00:00', $endDate.' 23:59:59']);

        $billQuery = PatientBilling::whereHas('patient', function ($q) use ($clinic) {
            $q->where('clinic_id', $clinic->id);
        })->whereBetween('created_at', [$startDate.' 00:00:00', $endDate.' 23:59:59']);

        if ($branch) {
            $consultQuery->whereHas('encounter', function ($q) use ($branch) {
                $q->where('branch_id', $branch->id);
            });
            $txQuery->whereHas('encounter', function ($q) use ($branch) {
                $q->where('branch_id', $branch->id);
            });
            $billQuery->where('branch_id', $branch->id);
        }

        // Aggregate grouped by doctor
        $results = [];

        // 1. Consultations
        foreach ($consultQuery->get() as $c) {
            $docId = $c->doctor_id;
            if (! isset($results[$docId])) {
                $results[$docId] = ['consultations_count' => 0, 'completed_treatments_count' => 0, 'revenue_generated' => 0.0];
            }
            $results[$docId]['consultations_count']++;
        }

        // 2. Completed Treatments (TreatmentRecords do not have doctor_id directly, they check encounter doctor)
        foreach ($txQuery->with('encounter')->get() as $tx) {
            $docId = $tx->encounter->doctor_id;
            if ($docId) {
                if (! isset($results[$docId])) {
                    $results[$docId] = ['consultations_count' => 0, 'completed_treatments_count' => 0, 'revenue_generated' => 0.0];
                }
                $results[$docId]['completed_treatments_count']++;
            }
        }

        // 3. Billing Revenue
        foreach ($billQuery->get() as $b) {
            $docId = $b->doctor_id;
            if ($docId) {
                if (! isset($results[$docId])) {
                    $results[$docId] = ['consultations_count' => 0, 'completed_treatments_count' => 0, 'revenue_generated' => 0.0];
                }
                $results[$docId]['revenue_generated'] += (float) $b->total_amount;
            }
        }

        return $results;
    }
}
