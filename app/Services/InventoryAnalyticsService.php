<?php

namespace App\Services;

use App\InventoryTransactionType;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\InventoryBatch;
use App\Models\InventoryTransaction;

class InventoryAnalyticsService
{
    /**
     * Identify fast-moving stocks, slow-moving items, and lists of expiring batches.
     */
    public function getInventoryMetrics(Clinic $clinic, ?Branch $branch): array
    {
        $batchQuery = InventoryBatch::where('clinic_id', $clinic->id);
        $txQuery = InventoryTransaction::where('clinic_id', $clinic->id);

        if ($branch) {
            $batchQuery->where('branch_id', $branch->id);
            $txQuery->where('branch_id', $branch->id);
        }

        // 1. Expiring Inventory
        $expiringBatches = $batchQuery->whereNotNull('expiry_date')
            ->whereBetween('expiry_date', [now()->toDateString(), now()->addMonths(6)->toDateString()])
            ->with('item')
            ->get()
            ->map(function ($b) {
                return [
                    'sku' => $b->item->sku,
                    'name' => $b->item->name,
                    'batch_number' => $b->batch_number,
                    'expiry_date' => $b->expiry_date->toDateString(),
                    'remaining_qty' => (float) $b->current_quantity,
                ];
            })->toArray();

        // 2. Fast/Slow Moving Items (via consumption counts)
        $consumptions = [];
        $txs = $txQuery->where('transaction_type', InventoryTransactionType::Consumption)->with('item')->get();
        foreach ($txs as $tx) {
            $sku = $tx->item->sku;
            $consumptions[$sku] = ($consumptions[$sku] ?? 0.0) + (float) abs($tx->quantity);
        }

        arsort($consumptions);
        $fastMoving = array_slice($consumptions, 0, 5, true);

        return [
            'expiring_batches' => $expiringBatches,
            'consumption_rates' => $consumptions,
            'fast_moving_items' => $fastMoving,
        ];
    }
}
