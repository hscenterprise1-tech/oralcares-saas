<?php

namespace App\Services;

use App\Models\ClinicalEncounter;
use App\Models\ClinicalOrder;
use App\Models\ClinicalOrderItem;
use App\Models\PatientTimelineEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ClinicalOrderService
{
    public function placeOrder(ClinicalEncounter $encounter, array $orderData, array $items): ClinicalOrder
    {
        return DB::transaction(function () use ($encounter, $orderData, $items) {
            $order = ClinicalOrder::create([
                'organization_id' => $encounter->organization_id,
                'encounter_id' => $encounter->id,
                'patient_id' => $encounter->patient_id,
                'ordered_by' => auth()->id(),
                'order_number' => 'ORD-'.strtoupper(Str::random(8)),
                'order_type' => $orderData['order_type'],
                'status' => 'active',
                'priority' => $orderData['priority'] ?? 'routine',
                'clinical_instructions' => $orderData['clinical_instructions'] ?? null,
                'details' => $orderData['details'] ?? [],
            ]);

            foreach ($items as $item) {
                $order->items()->create([
                    'item_code' => $item['item_code'] ?? null,
                    'item_name' => $item['item_name'],
                    'status' => 'pending',
                    'details' => $item['details'] ?? [],
                ]);
            }

            PatientTimelineEvent::create([
                'organization_id' => $order->organization_id,
                'patient_id' => $order->patient_id,
                'event_type' => 'clinical_order_placed',
                'context' => [
                    'order_id' => $order->id,
                    'encounter_id' => $order->encounter_id,
                    'order_number' => $order->order_number,
                    'order_type' => $order->order_type,
                    'item_count' => count($items),
                ],
                'actor_id' => auth()->id(),
            ]);

            return $order;
        });
    }

    public function updateItemStatus(ClinicalOrderItem $item, string $status): ClinicalOrderItem
    {
        $item->update(['status' => $status]);

        // Check if all items in the order are completed to auto-complete the order
        $order = $item->order;
        $allCompleted = $order->items()->where('status', '!=', 'completed')->count() === 0;

        if ($allCompleted && $order->status !== 'completed') {
            $order->update(['status' => 'completed']);

            PatientTimelineEvent::create([
                'organization_id' => $order->organization_id,
                'patient_id' => $order->patient_id,
                'event_type' => 'clinical_order_completed',
                'context' => [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'order_type' => $order->order_type,
                ],
                'actor_id' => auth()->id(),
            ]);
        }

        return $item;
    }
}
