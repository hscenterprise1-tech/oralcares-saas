<?php

namespace App\Http\Controllers\Clinical;

use App\Http\Controllers\Controller;
use App\Models\ClinicalEncounter;
use App\Models\Invoice;
use App\Services\BillingService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class BillingController extends Controller
{
    public function index(ClinicalEncounter $encounter)
    {
        abort_if($encounter->organization_id !== auth()->user()->organization_id, 403);

        $invoices = Invoice::where('encounter_id', $encounter->id)
            ->with(['items.chargeable'])
            ->latest()
            ->get();

        return Inertia::render('Clinical/Encounters/Billing/Index', [
            'encounter' => $encounter,
            'invoices' => $invoices,
        ]);
    }

    public function generateDraft(ClinicalEncounter $encounter, BillingService $service)
    {
        abort_if($encounter->organization_id !== auth()->user()->organization_id, 403);

        $service->generateDraftInvoice($encounter->patient, $encounter);

        return redirect()->back()->with('success', 'Draft invoice generated.');
    }

    public function addItem(Request $request, ClinicalEncounter $encounter, Invoice $invoice, BillingService $service)
    {
        abort_if($encounter->organization_id !== auth()->user()->organization_id, 403);
        abort_if($invoice->encounter_id !== $encounter->id, 403);
        abort_if($invoice->status !== 'draft', 400, 'Cannot add items to a non-draft invoice.');

        $validated = $request->validate([
            'description' => 'required|string|max:255',
            'quantity' => 'required|numeric|min:1',
            'unit_price' => 'required|numeric|min:0',
            'discount_amount' => 'nullable|numeric|min:0',
            'tax_amount' => 'nullable|numeric|min:0',
        ]);

        $service->addChargeItem($invoice, $validated);

        return redirect()->back()->with('success', 'Charge item added.');
    }

    public function issue(ClinicalEncounter $encounter, Invoice $invoice, BillingService $service)
    {
        abort_if($encounter->organization_id !== auth()->user()->organization_id, 403);
        abort_if($invoice->encounter_id !== $encounter->id, 403);
        abort_if($invoice->status !== 'draft', 400, 'Only draft invoices can be issued.');
        abort_if($invoice->items()->count() === 0, 400, 'Cannot issue an empty invoice.');

        $service->issueInvoice($invoice);

        return redirect()->back()->with('success', 'Invoice issued.');
    }
}
