<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicalEncounter;
use App\Models\PatientBilling;
use App\Models\TreatmentRecord;

class BranchAnalyticsService
{
    /**
     * Generate comparative reports across clinic branch offices.
     */
    public function compareBranches(Clinic $clinic, string $startDate, string $endDate): array
    {
        $branches = Branch::where('clinic_id', $clinic->id)->get();
        $comparison = [];

        foreach ($branches as $branch) {
            $billingSum = (float) PatientBilling::where('branch_id', $branch->id)
                ->whereBetween('created_at', [$startDate.' 00:00:00', $endDate.' 23:59:59'])
                ->sum('total_amount');

            $encountersCount = ClinicalEncounter::where('branch_id', $branch->id)
                ->whereBetween('created_at', [$startDate.' 00:00:00', $endDate.' 23:59:59'])
                ->count();

            $proceduresCount = TreatmentRecord::whereHas('encounter', function ($q) use ($branch) {
                $q->where('branch_id', $branch->id);
            })->whereBetween('created_at', [$startDate.' 00:00:00', $endDate.' 23:59:59'])
                ->count();

            $comparison[$branch->id] = [
                'branch_name' => $branch->name,
                'total_revenue' => $billingSum,
                'encounters_count' => $encountersCount,
                'procedures_performed_count' => $proceduresCount,
            ];
        }

        return $comparison;
    }
}
