<?php

namespace App\Services;

use App\Events\BatchExpired;
use App\Events\LowStockDetected;
use App\Events\StockAdjusted;
use App\Events\StockConsumed;
use App\Events\StockReceived;
use App\InventoryTransactionType;
use App\Models\Branch;
use App\Models\ClinicEmployee;
use App\Models\InventoryBatch;
use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class InventoryTransactionService
{
    /**
     * Record an inventory transaction and update stock balances.
     */
    public function recordTransaction(
        Branch $branch,
        InventoryItem $item,
        ?InventoryBatch $batch,
        InventoryTransactionType $type,
        float $quantity,
        ?string $notes = null,
        $reference = null,
        ?ClinicEmployee $creator = null
    ): InventoryTransaction {
        return DB::transaction(function () use ($branch, $item, $batch, $type, $quantity, $notes, $reference, $creator) {
            $signedQuantity = $quantity;

            if ($type !== InventoryTransactionType::Adjustment) {
                $subtractionTypes = [
                    InventoryTransactionType::Consumption,
                    InventoryTransactionType::Damage,
                    InventoryTransactionType::Expiry,
                ];

                if (in_array($type, $subtractionTypes) || ($type === InventoryTransactionType::Transfer && $quantity < 0)) {
                    $signedQuantity = -abs($quantity);
                } else {
                    $signedQuantity = abs($quantity);
                }
            }

            $tx = InventoryTransaction::create([
                'clinic_id' => $branch->clinic_id,
                'branch_id' => $branch->id,
                'inventory_item_id' => $item->id,
                'inventory_batch_id' => $batch?->id,
                'transaction_type' => $type,
                'quantity' => $signedQuantity,
                'notes' => $notes,
                'reference_id' => $reference?->id,
                'reference_type' => $reference ? get_class($reference) : null,
                'created_by' => $creator?->id,
            ]);

            if ($batch) {
                $batch->current_quantity = max(0.00, $batch->current_quantity + $signedQuantity);

                if ($type === InventoryTransactionType::Damage) {
                    $batch->damaged_quantity += abs($quantity);
                } elseif ($type === InventoryTransactionType::Expiry) {
                    $batch->expired_quantity += abs($quantity);
                }

                $batch->save();

                if ($batch->expiry_date && Carbon::parse($batch->expiry_date)->isPast()) {
                    event(new BatchExpired($batch));
                }
            }

            if ($type === InventoryTransactionType::Purchase || $type === InventoryTransactionType::OpeningStock) {
                event(new StockReceived($tx));
            } elseif ($type === InventoryTransactionType::Consumption) {
                event(new StockConsumed($tx));
            } else {
                event(new StockAdjusted($tx));
            }

            $totalStock = InventoryBatch::where('branch_id', $branch->id)
                ->where('inventory_item_id', $item->id)
                ->sum('current_quantity');

            if ($totalStock < $item->minimum_stock) {
                event(new LowStockDetected($item, $branch, $totalStock));
            }

            return $tx;
        });
    }
}
