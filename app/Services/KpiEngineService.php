<?php

namespace App\Services;

use App\Models\AnalyticsSnapshot;
use App\Models\Branch;
use App\Models\Clinic;

class KpiEngineService
{
    /**
     * Store a precalculated KPI snapshot metric.
     */
    public function storeKpi(
        Clinic $clinic,
        ?Branch $branch,
        string $key,
        array $value,
        string $date
    ): AnalyticsSnapshot {
        return AnalyticsSnapshot::updateOrCreate(
            [
                'clinic_id' => $clinic->id,
                'branch_id' => $branch?->id,
                'metric_key' => $key,
                'dimension_date' => $date,
            ],
            [
                'metric_value' => $value,
                'calculated_at' => now(),
            ]
        );
    }

    /**
     * Retrieve a precalculated KPI snapshot metric.
     */
    public function getKpi(
        Clinic $clinic,
        ?Branch $branch,
        string $key,
        string $date
    ): ?array {
        $snapshot = AnalyticsSnapshot::where('clinic_id', $clinic->id)
            ->where('branch_id', $branch?->id)
            ->where('metric_key', $key)
            ->where('dimension_date', $date)
            ->first();

        return $snapshot?->metric_value;
    }
}
