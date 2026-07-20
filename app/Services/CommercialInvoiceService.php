<?php

namespace App\Services;

use App\Events\CommercialInvoiceGenerated;
use App\Events\CommercialInvoicePaid;
use App\Models\CommercialInvoice;
use App\Models\CommercialPayment;
use App\Models\CompanySubscription;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CommercialInvoiceService
{
    /**
     * Generate an invoice for a subscription.
     */
    public function generateInvoice(CompanySubscription $subscription, ?string $dueDate = null): CommercialInvoice
    {
        return DB::transaction(function () use ($subscription, $dueDate) {
            $plan = $subscription->plan;
            $amount = $plan ? (float) $plan->price : 0.00;

            // Calculate addons amount
            $addonsAmount = 0.00;
            foreach ($subscription->addons as $addon) {
                if ($addon->is_active) {
                    $addonsAmount += ($addon->price * $addon->quantity);
                }
            }

            $subtotal = $amount + $addonsAmount;
            $taxRate = 0.18; // 18% standard tax
            $tax = round($subtotal * $taxRate, 2);
            $discount = 0.00; // Customizable discounts could go here
            $finalAmount = $subtotal + $tax - $discount;

            $invoice = CommercialInvoice::create([
                'clinic_id' => $subscription->clinic_id,
                'company_subscription_id' => $subscription->id,
                'invoice_number' => 'INV-SaaS-'.strtoupper(Str::random(10)),
                'status' => 'draft',
                'amount' => $subtotal,
                'tax_amount' => $tax,
                'discount_amount' => $discount,
                'final_amount' => $finalAmount,
                'due_date' => $dueDate ?? now()->addDays(30)->toDateString(),
            ]);

            event(new CommercialInvoiceGenerated($invoice));

            return $invoice;
        });
    }

    /**
     * Issue an invoice (transition status from draft to issued).
     */
    public function issueInvoice(CommercialInvoice $invoice): void
    {
        if ($invoice->status === 'draft') {
            $invoice->update([
                'status' => 'issued',
                'issued_at' => now(),
            ]);
        }
    }

    /**
     * Record a manual/gateway payment for an invoice.
     */
    public function recordPayment(
        CommercialInvoice $invoice,
        float $amount,
        string $method,
        ?string $reference = null
    ): CommercialPayment {
        return DB::transaction(function () use ($invoice, $amount, $method, $reference) {
            // Create payment record
            $payment = CommercialPayment::create([
                'clinic_id' => $invoice->clinic_id,
                'commercial_invoice_id' => $invoice->id,
                'amount' => $amount,
                'payment_method' => $method,
                'payment_type' => 'charge',
                'transaction_reference' => $reference,
                'paid_at' => now(),
            ]);

            // Calculate total paid so far
            $totalPaid = $invoice->payments()->where('payment_type', 'charge')->sum('amount')
                - $invoice->payments()->where('payment_type', 'refund')->sum('amount');

            if ($totalPaid >= $invoice->final_amount) {
                $invoice->update([
                    'status' => 'paid',
                    'paid_at' => now(),
                ]);

                event(new CommercialInvoicePaid($invoice));
            }

            return $payment;
        });
    }

    /**
     * Record a refund against an invoice.
     */
    public function recordRefund(
        CommercialInvoice $invoice,
        float $amount,
        ?string $reference = null
    ): CommercialPayment {
        return DB::transaction(function () use ($invoice, $amount, $reference) {
            $payment = CommercialPayment::create([
                'clinic_id' => $invoice->clinic_id,
                'commercial_invoice_id' => $invoice->id,
                'amount' => $amount,
                'payment_method' => 'manually_recorded',
                'payment_type' => 'refund',
                'transaction_reference' => $reference,
                'paid_at' => now(),
            ]);

            return $payment;
        });
    }

    /**
     * Record a credit note against an invoice.
     */
    public function recordCreditNote(
        CommercialInvoice $invoice,
        float $amount,
        ?string $reference = null
    ): CommercialPayment {
        return DB::transaction(function () use ($invoice, $amount, $reference) {
            $payment = CommercialPayment::create([
                'clinic_id' => $invoice->clinic_id,
                'commercial_invoice_id' => $invoice->id,
                'amount' => $amount,
                'payment_method' => 'credit_note',
                'payment_type' => 'credit_note',
                'transaction_reference' => $reference,
                'paid_at' => now(),
            ]);

            return $payment;
        });
    }
}
