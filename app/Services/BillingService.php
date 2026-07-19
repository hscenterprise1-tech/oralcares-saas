<?php

namespace App\Services;

use App\BillingStatus;
use App\Events\BillingCreated;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicalEncounter;
use App\Models\ClinicEmployee;
use App\Models\Patient;
use App\Models\PatientBilling;
use App\Models\PatientBillingItem;
use Illuminate\Support\Facades\DB;

class BillingService
{
    public function __construct(
        protected ReceiptNumberService $receiptService,
        protected BillableItemService $itemService,
        protected DiscountService $discountService
    ) {}

    /**
     * Create a patient billing invoice.
     */
    public function createBill(
        Clinic $clinic,
        Patient $patient,
        Branch $branch,
        array $itemsData,
        ?ClinicalEncounter $encounter = null,
        float $billDiscountValue = 0.00,
        string $billDiscountType = 'amount',
        ?ClinicEmployee $creator = null
    ): PatientBilling {
        return DB::transaction(function () use ($clinic, $patient, $branch, $itemsData, $encounter, $billDiscountValue, $billDiscountType, $creator) {
            $totalAmount = 0.00;
            $itemsDiscountTotal = 0.00;

            // 1. Process items first to compute subtotals
            $processedItems = [];
            foreach ($itemsData as $item) {
                $qty = $item['quantity'] ?? 1;
                $price = (float) ($item['unit_price'] ?? 0.00);
                $disc = (float) ($item['discount_amount'] ?? 0.00);

                $final = $this->itemService->calculateItemFinalAmount($qty, $price, $disc);

                $totalAmount += ($price * $qty);
                $itemsDiscountTotal += $disc;

                $processedItems[] = [
                    'clinical_procedure_id' => $item['clinical_procedure_id'],
                    'treatment_record_id' => $item['treatment_record_id'] ?? null,
                    'quantity' => $qty,
                    'unit_price' => $price,
                    'discount_amount' => $disc,
                    'final_amount' => $final,
                ];
            }

            // 2. Compute bill-level discount
            $billDiscount = $this->discountService->calculateBillLevelDiscount($totalAmount, $billDiscountValue, $billDiscountType);
            $totalDiscount = $itemsDiscountTotal + $billDiscount;
            $finalAmount = max(0.00, $totalAmount - $totalDiscount);

            // 3. Generate receipt number
            $billingNumber = $this->receiptService->generateReceiptNumber($clinic);

            // 4. Create Billing record
            $billing = PatientBilling::create([
                'clinic_id' => $clinic->id,
                'patient_id' => $patient->id,
                'encounter_id' => $encounter?->id,
                'branch_id' => $branch->id,
                'doctor_id' => $encounter?->doctor_id,
                'billing_number' => $billingNumber,
                'status' => BillingStatus::Pending,
                'total_amount' => $totalAmount,
                'discount_amount' => $totalDiscount,
                'final_amount' => $finalAmount,
                'outstanding_balance' => $finalAmount,
                'created_by' => $creator?->id,
            ]);

            // 5. Create Billing items
            foreach ($processedItems as $pi) {
                PatientBillingItem::create(array_merge($pi, [
                    'patient_billing_id' => $billing->id,
                ]));
            }

            event(new BillingCreated($billing));

            return $billing;
        });
    }

    /**
     * Cancel a billing invoice.
     */
    public function cancelBill(PatientBilling $billing, ?ClinicEmployee $updater = null): void
    {
        $billing->update([
            'status' => BillingStatus::Cancelled,
            'updated_by' => $updater?->id,
        ]);
    }
}
