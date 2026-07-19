<?php

namespace App\Services;

use App\Events\PurchaseOrderCreated;
use App\InventoryTransactionType;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicEmployee;
use App\Models\InventoryBatch;
use App\Models\InventoryPurchaseOrder;
use App\Models\InventoryPurchaseOrderItem;
use App\Models\InventoryVendor;
use App\PurchaseOrderStatus;
use Illuminate\Support\Facades\DB;

class PurchaseOrderService
{
    public function __construct(
        protected InventoryTransactionService $txService
    ) {}

    /**
     * Create a purchase order.
     */
    public function createPurchaseOrder(
        Clinic $clinic,
        Branch $branch,
        InventoryVendor $vendor,
        array $itemsData,
        ?ClinicEmployee $creator = null
    ): InventoryPurchaseOrder {
        return DB::transaction(function () use ($clinic, $branch, $vendor, $itemsData, $creator) {
            $count = InventoryPurchaseOrder::where('clinic_id', $clinic->id)->count() + 1;
            $poNumber = "PO-{$clinic->id}-".str_pad((string) $count, 5, '0', STR_PAD_LEFT);

            $po = InventoryPurchaseOrder::create([
                'clinic_id' => $clinic->id,
                'branch_id' => $branch->id,
                'vendor_id' => $vendor->id,
                'po_number' => $poNumber,
                'status' => PurchaseOrderStatus::Draft,
                'created_by' => $creator?->id,
            ]);

            foreach ($itemsData as $item) {
                InventoryPurchaseOrderItem::create([
                    'inventory_purchase_order_id' => $po->id,
                    'inventory_item_id' => $item['inventory_item_id'],
                    'quantity_ordered' => $item['quantity_ordered'],
                    'unit_cost' => $item['unit_cost'],
                ]);
            }

            event(new PurchaseOrderCreated($po));

            return $po;
        });
    }

    /**
     * Receive stock from a purchase order.
     */
    public function receiveStock(
        InventoryPurchaseOrder $po,
        array $receiveData,
        ?ClinicEmployee $receiver = null
    ): void {
        DB::transaction(function () use ($po, $receiveData, $receiver) {
            $fullyReceived = true;

            foreach ($receiveData as $rec) {
                $poItem = $po->items()->where('inventory_item_id', $rec['inventory_item_id'])->firstOrFail();
                $poItem->increment('quantity_received', $rec['quantity_received']);

                if ($poItem->quantity_received < $poItem->quantity_ordered) {
                    $fullyReceived = false;
                }

                // Create or find inventory batch
                $batch = InventoryBatch::updateOrCreate(
                    [
                        'clinic_id' => $po->clinic_id,
                        'branch_id' => $po->branch_id,
                        'inventory_item_id' => $poItem->inventory_item_id,
                        'batch_number' => $rec['batch_number'],
                    ],
                    [
                        'manufacturing_date' => $rec['manufacturing_date'] ?? null,
                        'expiry_date' => $rec['expiry_date'] ?? null,
                        'purchase_cost' => $poItem->unit_cost,
                    ]
                );

                // Add transaction history
                $this->txService->recordTransaction(
                    $po->branch,
                    $poItem->item,
                    $batch,
                    InventoryTransactionType::Purchase,
                    $rec['quantity_received'],
                    'Received from PO: '.$po->po_number,
                    $po,
                    $receiver
                );
            }

            $status = $fullyReceived ? PurchaseOrderStatus::Received : PurchaseOrderStatus::PartiallyReceived;
            $po->update(['status' => $status]);
        });
    }
}
