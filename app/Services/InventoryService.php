<?php

namespace App\Services;

use App\Models\InventoryItem;
use App\Models\StockBatch;
use App\Models\StockLocation;
use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class InventoryService
{
    /**
     * Receive new stock into inventory.
     */
    public function receiveStock(
        StockLocation $location,
        InventoryItem $item,
        int|float $quantity,
        ?string $lotNumber = null,
        ?string $expiryDate = null,
        ?string $notes = null
    ): void {
        DB::transaction(function () use ($location, $item, $quantity, $lotNumber, $expiryDate, $notes) {
            $batch = null;

            // Only create a batch if we have batch-specific details
            if ($lotNumber || $expiryDate) {
                $batch = StockBatch::firstOrCreate([
                    'stock_location_id' => $location->id,
                    'inventory_item_id' => $item->id,
                    'lot_number' => $lotNumber,
                    'expiry_date' => $expiryDate,
                ]);

                $batch->increment('quantity_on_hand', $quantity);
            }

            // Record transaction
            $item->transactions()->create([
                'stock_location_id' => $location->id,
                'stock_batch_id' => $batch?->id,
                'transaction_type' => 'receive',
                'quantity' => abs($quantity),
                'performed_by' => auth()->id(),
                'notes' => $notes,
            ]);
        });
    }

    /**
     * Deduct stock for a clinical event using FEFO (First-Expired, First-Out).
     */
    public function deductStock(
        StockLocation $location,
        InventoryItem $item,
        int|float $quantity,
        string $transactionType = 'consume', // consume, dispense
        ?Model $referenceable = null,
        ?string $notes = null
    ): void {
        if ($quantity <= 0) {
            throw new Exception('Deduction quantity must be strictly positive.');
        }

        DB::transaction(function () use ($location, $item, $quantity, $transactionType, $referenceable, $notes) {

            // Calculate total available from batches
            $totalInBatches = StockBatch::where('stock_location_id', $location->id)
                ->where('inventory_item_id', $item->id)
                ->where('quantity_on_hand', '>', 0)
                ->sum('quantity_on_hand');

            // Also need to know overall unbatched stock by summing all transactions
            $totalReceived = $item->transactions()
                ->where('stock_location_id', $location->id)
                ->whereIn('transaction_type', ['receive', 'adjust_up', 'transfer_in'])
                ->sum('quantity');

            $totalDeducted = $item->transactions()
                ->where('stock_location_id', $location->id)
                ->whereIn('transaction_type', ['consume', 'dispense', 'adjust_down', 'transfer_out'])
                ->sum('quantity'); // These are stored as positive values in the DB, so we sum them directly

            $netAvailable = $totalReceived - $totalDeducted;

            if ($netAvailable < $quantity) {
                throw new Exception("Insufficient stock available for deduction. Requested: {$quantity}, Available: {$netAvailable}");
            }

            $remainingToDeduct = $quantity;

            // 1. Try to deduct from specific batches first (FEFO)
            $batches = StockBatch::where('stock_location_id', $location->id)
                ->where('inventory_item_id', $item->id)
                ->where('quantity_on_hand', '>', 0)
                ->orderBy('expiry_date', 'asc') // First expired
                ->get();

            foreach ($batches as $batch) {
                if ($remainingToDeduct <= 0) {
                    break;
                }

                $deductAmount = min($batch->quantity_on_hand, $remainingToDeduct);

                $batch->decrement('quantity_on_hand', $deductAmount);
                $remainingToDeduct -= $deductAmount;

                // Log batch-specific deduction
                $this->logTransaction($location, $item, $deductAmount, $transactionType, $batch->id, $referenceable, $notes);
            }

            // 2. If there's still quantity to deduct, it must come from unbatched/general stock
            if ($remainingToDeduct > 0) {
                // Log unbatched deduction
                $this->logTransaction($location, $item, $remainingToDeduct, $transactionType, null, $referenceable, $notes);
            }
        });
    }

    private function logTransaction(
        StockLocation $location,
        InventoryItem $item,
        int|float $quantity,
        string $transactionType,
        ?int $batchId,
        ?Model $referenceable,
        ?string $notes
    ): void {
        $item->transactions()->create([
            'stock_location_id' => $location->id,
            'stock_batch_id' => $batchId,
            'transaction_type' => $transactionType,
            'quantity' => abs($quantity), // We store quantity as a positive number, context is defined by transaction_type
            'referenceable_type' => $referenceable ? get_class($referenceable) : null,
            'referenceable_id' => $referenceable ? $referenceable->getKey() : null,
            'performed_by' => auth()->id(),
            'notes' => $notes,
        ]);
    }
}
