<?php

namespace App\Services;

use App\Models\Clinic;
use App\Models\UsageMetric;

class UsageMetricService
{
    /**
     * Record a new usage metric.
     */
    public function recordMetric(Clinic $clinic, string $key, float $value, ?string $date = null): UsageMetric
    {
        $recordedDate = $date ?? now()->toDateString();

        return UsageMetric::updateOrCreate(
            [
                'clinic_id' => $clinic->id,
                'metric_key' => $key,
                'recorded_date' => $recordedDate,
            ],
            [
                'metric_value' => $value,
            ]
        );
    }

    /**
     * Get aggregate metric sum in range.
     */
    public function getMetricSum(Clinic $clinic, string $key, string $startDate, string $endDate): float
    {
        return (float) UsageMetric::where('clinic_id', $clinic->id)
            ->where('metric_key', $key)
            ->whereBetween('recorded_date', [$startDate, $endDate])
            ->sum('metric_value');
    }

    /**
     * Get the latest usage metric for a clinic.
     */
    public function getCurrentUsage(Clinic $clinic, string $key): float
    {
        $metric = UsageMetric::where('clinic_id', $clinic->id)
            ->where('metric_key', $key)
            ->orderBy('recorded_date', 'desc')
            ->first();

        return $metric ? (float) $metric->metric_value : 0.00;
    }
}
