<?php

namespace App\Services;

use App\InventoryTransactionType;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicEmployee;
use App\Models\InventoryBatch;
use App\Models\InventoryItem;
use Illuminate\Support\Facades\DB;

class StockService
{
    public function __construct(
        protected InventoryTransactionService $txService
    ) {}

    /**
     * Calculate total available stock for an item in a branch (current - reserved).
     */
    public function getAvailableStock(Branch $branch, InventoryItem $item): float
    {
        $batches = InventoryBatch::where('branch_id', $branch->id)
            ->where('inventory_item_id', $item->id)
            ->where(function ($q) {
                $q->whereNull('expiry_date')
                    ->orWhere('expiry_date', '>=', now()->toDateString());
            })
            ->get();

        return (float) $batches->sum(function ($b) {
            return $b->current_quantity - $b->reserved_quantity;
        });
    }

    /**
     * Transfer stock from a source branch to a destination branch.
     */
    public function transferStock(
        Clinic $clinic,
        Branch $source,
        Branch $destination,
        InventoryItem $item,
        InventoryBatch $sourceBatch,
        float $quantity,
        ?ClinicEmployee $doctor = null
    ): void {
        DB::transaction(function () use ($clinic, $source, $destination, $item, $sourceBatch, $quantity, $doctor) {
            // 1. Deduct from source branch
            $this->txService->recordTransaction(
                $source,
                $item,
                $sourceBatch,
                InventoryTransactionType::Transfer,
                -$quantity,
                'Transferred out to branch: '.$destination->name,
                null,
                $doctor
            );

            // 2. Add/Update batch at destination branch
            $destBatch = InventoryBatch::updateOrCreate(
                [
                    'clinic_id' => $clinic->id,
                    'branch_id' => $destination->id,
                    'inventory_item_id' => $item->id,
                    'batch_number' => $sourceBatch->batch_number,
                ],
                [
                    'manufacturing_date' => $sourceBatch->manufacturing_date,
                    'expiry_date' => $sourceBatch->expiry_date,
                    'purchase_cost' => $sourceBatch->purchase_cost ?? 0.00,
                ]
            );

            // 3. Receive at destination branch
            $this->txService->recordTransaction(
                $destination,
                $item,
                $destBatch,
                InventoryTransactionType::Transfer,
                $quantity,
                'Received transfer from branch: '.$source->name,
                null,
                $doctor
            );
        });
    }
}
