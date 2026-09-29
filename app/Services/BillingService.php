<?php

namespace App\Services;

use App\Models\ClinicalEncounter;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Patient;
use App\Models\PatientTimelineEvent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BillingService
{
    public function generateDraftInvoice(Patient $patient, ?ClinicalEncounter $encounter = null): Invoice
    {
        return Invoice::create([
            'organization_id' => $patient->organization_id,
            'patient_id' => $patient->id,
            'encounter_id' => $encounter?->id,
            'invoice_number' => 'INV-'.date('Y').'-'.strtoupper(Str::random(6)),
            'status' => 'draft',
        ]);
    }

    public function addChargeItem(Invoice $invoice, array $itemData, ?Model $chargeable = null): InvoiceItem
    {
        return DB::transaction(function () use ($invoice, $itemData, $chargeable) {
            $quantity = $itemData['quantity'] ?? 1;
            $unitPrice = $itemData['unit_price'] ?? 0;
            $discount = $itemData['discount_amount'] ?? 0;
            $tax = $itemData['tax_amount'] ?? 0;

            $netAmount = ($quantity * $unitPrice) - $discount + $tax;

            $item = $invoice->items()->create([
                'chargeable_type' => $chargeable ? get_class($chargeable) : null,
                'chargeable_id' => $chargeable ? $chargeable->getKey() : null,
                'item_code' => $itemData['item_code'] ?? null,
                'description' => $itemData['description'],
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'tax_amount' => $tax,
                'discount_amount' => $discount,
                'net_amount' => $netAmount,
                'details' => $itemData['details'] ?? [],
            ]);

            $invoice->recalculateTotals();

            return $item;
        });
    }

    public function issueInvoice(Invoice $invoice): Invoice
    {
        return DB::transaction(function () use ($invoice) {
            $invoice->update([
                'status' => 'issued',
                'issued_at' => now(),
            ]);

            PatientTimelineEvent::create([
                'organization_id' => $invoice->organization_id,
                'patient_id' => $invoice->patient_id,
                'event_type' => 'invoice_issued',
                'context' => [
                    'invoice_id' => $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                    'total_amount' => $invoice->total_amount,
                ],
                'actor_id' => auth()->id(),
            ]);

            return $invoice;
        });
    }
}
