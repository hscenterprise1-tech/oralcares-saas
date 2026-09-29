<?php

namespace App\Http\Controllers\Clinical;

use App\Http\Controllers\Controller;
use App\Models\ClinicalEncounter;
use App\Models\ClinicalOrder;
use App\Models\ClinicalOrderItem;
use App\Services\ClinicalOrderService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ClinicalOrderController extends Controller
{
    public function index(ClinicalEncounter $encounter)
    {
        abort_if($encounter->organization_id !== auth()->user()->organization_id, 403);

        $orders = $encounter->orders()->with(['items', 'orderer'])->latest()->get();

        return Inertia::render('Clinical/Encounters/Orders/Index', [
            'encounter' => $encounter,
            'orders' => $orders,
        ]);
    }

    public function store(Request $request, ClinicalEncounter $encounter, ClinicalOrderService $service)
    {
        abort_if($encounter->organization_id !== auth()->user()->organization_id, 403);

        $validated = $request->validate([
            'order_type' => 'required|string|in:laboratory,imaging,pharmacy,procedure,referral',
            'priority' => 'nullable|string|in:routine,urgent,stat',
            'clinical_instructions' => 'nullable|string',
            'details' => 'nullable|array',
            'items' => 'required|array|min:1',
            'items.*.item_code' => 'nullable|string',
            'items.*.item_name' => 'required|string|max:255',
            'items.*.details' => 'nullable|array',
        ]);

        $service->placeOrder($encounter, [
            'order_type' => $validated['order_type'],
            'priority' => $validated['priority'] ?? 'routine',
            'clinical_instructions' => $validated['clinical_instructions'] ?? null,
            'details' => $validated['details'] ?? [],
        ], $validated['items']);

        return redirect()->back()->with('success', 'Order placed successfully.');
    }

    public function updateItemStatus(Request $request, ClinicalEncounter $encounter, ClinicalOrder $order, ClinicalOrderItem $item, ClinicalOrderService $service)
    {
        abort_if($encounter->organization_id !== auth()->user()->organization_id, 403);
        abort_if($order->encounter_id !== $encounter->id, 403);
        abort_if($item->clinical_order_id !== $order->id, 403);

        $validated = $request->validate([
            'status' => 'required|string|in:pending,in_progress,completed,cancelled',
        ]);

        $service->updateItemStatus($item, $validated['status']);

        return redirect()->back()->with('success', 'Order item status updated.');
    }
}
