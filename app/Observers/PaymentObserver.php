<?php

namespace App\Observers;

use App\Models\Payment;
use App\Services\CommercialLedgerService;

class PaymentObserver
{
    /**
     * Handle the Payment "created" event.
     */
    public function created(Payment $payment): void
    {
        $this->projectToLedger($payment);

        $status = ucfirst($payment->status);
        $amount = $payment->currency.' '.$payment->amount;

        $desc = "Payment of {$amount} recorded. Status: {$status}.";
        if ($payment->commercial_document_id) {
            $desc = "Payment of {$amount} recorded for ".ucfirst($payment->document->type).' '.$payment->document->document_number.". Status: {$status}.";
        }

        $payment->lead->activities()->create([
            'user_id' => $payment->recorded_by ?? auth()->id() ?? 1,
            'type' => 'system_update',
            'description' => $desc,
        ]);
    }

    /**
     * Handle the Payment "updated" event.
     */
    public function updated(Payment $payment): void
    {
        if ($payment->isDirty('status')) {
            $this->projectToLedger($payment);

            $status = ucfirst($payment->status);
            $amount = $payment->currency.' '.$payment->amount;

            $desc = "Payment status of {$amount} updated to {$status}.";
            if ($payment->commercial_document_id) {
                $desc = "Payment status of {$amount} for ".ucfirst($payment->document->type).' '.$payment->document->document_number." updated to {$status}.";
            }

            $payment->lead->activities()->create([
                'user_id' => auth()->id() ?? $payment->recorded_by ?? 1,
                'type' => 'system_update',
                'description' => $desc,
            ]);
        }
    }

    /**
     * Book a settled payment into the Finance ledger.
     *
     * Money recorded against a deal used to live only in `payments`, which
     * Finance never reads. The service ignores pending and failed attempts and
     * keys on the payment id, so a status flip to `successful` books revenue
     * exactly once.
     */
    protected function projectToLedger(Payment $payment): void
    {
        app(CommercialLedgerService::class)->recordPayment($payment);
    }
}
