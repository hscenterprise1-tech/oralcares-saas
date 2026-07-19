<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicQueueItem;
use App\QueueItemStatus;
use Illuminate\Support\Carbon;

class QueueAnalyticsService
{
    /**
     * Compute average waiting delay, consultation times, and call-to-skip ratios.
     */
    public function getQueueMetrics(Clinic $clinic, ?Branch $branch, string $startDate, string $endDate): array
    {
        $itemQuery = ClinicQueueItem::whereHas('queue', function ($q) use ($clinic) {
            $q->where('clinic_id', $clinic->id);
        })->whereBetween('created_at', [$startDate.' 00:00:00', $endDate.' 23:59:59']);

        if ($branch) {
            $itemQuery->whereHas('queue', function ($q) use ($branch) {
                $q->where('branch_id', $branch->id);
            });
        }

        $items = $itemQuery->get();
        $totalWaitSeconds = 0;
        $calledCount = 0;
        $skippedCount = 0;
        $completedCount = 0;

        foreach ($items as $item) {
            if ($item->called_at) {
                $created = Carbon::parse($item->created_at);
                $called = Carbon::parse($item->called_at);
                $totalWaitSeconds += $called->diffInSeconds($created);
                $calledCount++;
            }

            if ($item->status === QueueItemStatus::Skipped) {
                $skippedCount++;
            } elseif ($item->status === QueueItemStatus::Completed) {
                $completedCount++;
            }
        }

        $avgWaitingTime = $calledCount > 0 ? (float) ($totalWaitSeconds / $calledCount) : 0.0;
        $totalItems = $items->count();
        $skipRate = $totalItems > 0 ? (float) (($skippedCount / $totalItems) * 100) : 0.0;

        return [
            'average_waiting_time_seconds' => $avgWaitingTime,
            'called_patients_count' => $calledCount,
            'skipped_patients_count' => $skippedCount,
            'completed_patients_count' => $completedCount,
            'skip_rate_percentage' => $skipRate,
        ];
    }
}
