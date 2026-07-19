<?php

namespace App\Services;

use App\InventoryTransactionType;
use App\Models\ClinicEmployee;
use App\Models\InventoryBatch;
use App\Models\InventoryReservation;
use Illuminate\Support\Facades\DB;

class InventoryReservationService
{
    public function __construct(
        protected InventoryTransactionService $txService
    ) {}

    /**
     * Place a temporary reservation on batch inventory.
     */
    public function reserveStock(InventoryBatch $batch, float $quantity, $reference = null): InventoryReservation
    {
        return DB::transaction(function () use ($batch, $quantity, $reference) {
            $reservation = InventoryReservation::create([
                'inventory_batch_id' => $batch->id,
                'quantity' => $quantity,
                'reference_id' => $reference?->id,
                'reference_type' => $reference ? get_class($reference) : null,
                'status' => 'active',
            ]);

            $batch->increment('reserved_quantity', $quantity);

            return $reservation;
        });
    }

    /**
     * Cancel an active stock reservation.
     */
    public function cancelReservation(InventoryReservation $reservation): void
    {
        DB::transaction(function () use ($reservation) {
            if ($reservation->status !== 'active') {
                return;
            }

            $reservation->update(['status' => 'cancelled']);
            $reservation->batch->decrement('reserved_quantity', $reservation->quantity);
        });
    }

    /**
     * Consume reserved stock, making the deduction permanent.
     */
    public function consumeReservation(InventoryReservation $reservation, ?ClinicEmployee $creator = null): void
    {
        DB::transaction(function () use ($reservation, $creator) {
            if ($reservation->status !== 'active') {
                return;
            }

            $reservation->update(['status' => 'consumed']);

            $batch = $reservation->batch;
            $batch->decrement('reserved_quantity', $reservation->quantity);

            // Log consumption transaction
            $this->txService->recordTransaction(
                $batch->branch,
                $batch->item,
                $batch,
                InventoryTransactionType::Consumption,
                $reservation->quantity,
                'Consumed reserved stock.',
                $reservation->reference,
                $creator
            );
        });
    }
}
