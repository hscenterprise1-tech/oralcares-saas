<?php

namespace App\Services;

use App\BillingStatus;
use App\Events\BillCompleted;
use App\Events\PaymentRecorded;
use App\Events\RefundInitiated;
use App\Models\ClinicEmployee;
use App\Models\PatientBilling;
use App\Models\PatientBillingPayment;
use App\Models\PatientBillingRefund;
use App\PaymentMethod;
use Illuminate\Support\Facades\DB;

class PaymentService
{
    /**
     * Record a payment against an outstanding billing invoice.
     */
    public function recordPayment(
        PatientBilling $billing,
        PaymentMethod $method,
        float $amount,
        ?string $reference = null,
        ?ClinicEmployee $recorder = null
    ): PatientBillingPayment {
        return DB::transaction(function () use ($billing, $method, $amount, $reference, $recorder) {
            $payment = PatientBillingPayment::create([
                'patient_billing_id' => $billing->id,
                'payment_method' => $method,
                'amount' => $amount,
                'transaction_reference' => $reference,
                'recorded_by' => $recorder?->id,
            ]);

            // Adjust outstanding balance
            $newBalance = max(0.00, $billing->outstanding_balance - $amount);
            $newStatus = $newBalance <= 0.00 ? BillingStatus::Paid : BillingStatus::PartiallyPaid;

            $billing->update([
                'outstanding_balance' => $newBalance,
                'status' => $newStatus,
            ]);

            event(new PaymentRecorded($payment));

            if ($newStatus === BillingStatus::Paid) {
                event(new BillCompleted($billing));
            }

            return $payment;
        });
    }

    /**
     * Record a refund against a paid invoice.
     */
    public function recordRefund(
        PatientBilling $billing,
        float $amount,
        ?string $reason = null,
        ?ClinicEmployee $refunder = null
    ): PatientBillingRefund {
        return DB::transaction(function () use ($billing, $amount, $reason, $refunder) {
            $refund = PatientBillingRefund::create([
                'patient_billing_id' => $billing->id,
                'amount' => $amount,
                'reason' => $reason,
                'refunded_by' => $refunder?->id,
            ]);

            $billing->update([
                'status' => BillingStatus::Refunded,
            ]);

            event(new RefundInitiated($refund));

            return $refund;
        });
    }
}
