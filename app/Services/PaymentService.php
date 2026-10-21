<?php

namespace App\Services;

use App\BillingStatus;
use App\ClinicDocument;
use App\Events\BillCompleted;
use App\Events\PaymentRecorded;
use App\Events\RefundInitiated;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicEmployee;
use App\Models\PatientBilling;
use App\Models\PatientBillingAdjustment;
use App\Models\PatientBillingPayment;
use App\Models\PatientBillingRefund;
use App\Models\PatientTimelineEvent;
use App\Models\User;
use App\PaymentMethod;
use App\Services\Numbering\ClinicDocumentNumbers;
use App\Services\Security\SecurityAudit;
use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The one writer of payments and refunds on clinic bills.
 *
 * Each runs on the bill row locked, so two cashiers can never both settle
 * the same balance: a payment never exceeds what is outstanding, a refund
 * never exceeds what was collected. Receipts and refunds are numbered from
 * the bill's branch series.
 */
class PaymentService
{
    public function __construct(protected ClinicDocumentNumbers $numbers) {}

    /**
     * The methods a payment may be recorded by at this clinic: those it
     * accepts (every method when it has not narrowed them). At the desk a
     * gateway or insurance settlement is never typed in by hand.
     *
     * @return list<PaymentMethod>
     */
    public function acceptedMethods(Clinic $clinic, bool $atDesk = false): array
    {
        $accepted = (array) (($clinic->billing_config ?? [])['payment_methods'] ?? []);

        return array_values(array_filter(PaymentMethod::cases(), fn (PaymentMethod $method) => ($accepted === [] || in_array($method->value, $accepted, true))
            && ! ($atDesk && in_array($method, [PaymentMethod::Gateway, PaymentMethod::Insurance], true))));
    }

    /**
     * `acceptedMethods` as options for a payment form.
     *
     * @return list<array{value: string, label: string}>
     */
    public function methodOptions(Clinic $clinic, bool $atDesk = false): array
    {
        return array_map(fn (PaymentMethod $method) => ['value' => $method->value, 'label' => $method->label()], $this->acceptedMethods($clinic, $atDesk));
    }

    public function recordPayment(
        PatientBilling $billing,
        PaymentMethod $method,
        float $amount,
        ?string $reference = null,
        ?ClinicEmployee $recorder = null,
        ?string $notes = null,
    ): PatientBillingPayment {
        $paise = (int) round($amount * 100);
        if ($paise <= 0) {
            throw new InvalidArgumentException('A payment must be more than zero.');
        }

        return DB::transaction(function () use ($billing, $method, $paise, $reference, $recorder, $notes) {
            [$bill, $clinic, $branch] = $this->lock($billing);

            if ($bill->isCancelled()) {
                throw new DomainException('This bill is cancelled.');
            }

            if (! in_array($method, $this->acceptedMethods($clinic), true)) {
                throw new DomainException("{$method->label()} is not a payment method this clinic accepts.");
            }

            $outstanding = (int) round((float) $bill->outstanding_balance * 100);
            if ($paise > $outstanding) {
                throw new DomainException('The payment is more than the amount due.');
            }

            $payment = PatientBillingPayment::query()->create([
                'patient_billing_id' => $bill->id,
                'clinic_id' => $bill->clinic_id,
                'branch_id' => $bill->branch_id,
                'receipt_number' => $this->numbers->next(ClinicDocument::Receipt, $clinic, $branch),
                'payment_method' => $method,
                'amount' => $paise / 100,
                'transaction_reference' => $reference,
                'paid_at' => now(),
                'notes' => $notes,
                'recorded_by' => $recorder?->id,
            ]);

            $remaining = $outstanding - $paise;
            $bill->update([
                'paid_amount' => ((int) round((float) $bill->paid_amount * 100) + $paise) / 100,
                'outstanding_balance' => $remaining / 100,
                'status' => $remaining === 0 ? BillingStatus::Paid : BillingStatus::PartiallyPaid,
                'updated_by' => $recorder?->id,
            ]);

            $this->timeline($bill, $clinic, 'payment_recorded', ['receipt_number' => $payment->receipt_number, 'amount' => $payment->amount, 'method' => $method->value]);

            event(new PaymentRecorded($payment));

            if ($remaining === 0) {
                event(new BillCompleted($bill));
            }

            return $payment;
        });
    }

    public function recordRefund(
        PatientBilling $billing,
        float $amount,
        ?string $reason = null,
        ?ClinicEmployee $refunder = null,
        ?PatientBillingPayment $payment = null,
        ?PaymentMethod $method = null,
    ): PatientBillingRefund {
        $paise = (int) round($amount * 100);
        if ($paise <= 0) {
            throw new InvalidArgumentException('A refund must be more than zero.');
        }

        return DB::transaction(function () use ($billing, $paise, $reason, $refunder, $payment, $method) {
            [$bill, $clinic, $branch] = $this->lock($billing);

            if ($payment && $payment->patient_billing_id !== $bill->id) {
                throw new InvalidArgumentException('That payment belongs to a different bill.');
            }

            $paid = (int) round((float) $bill->paid_amount * 100);
            $refunded = (int) round((float) $bill->refunded_amount * 100);
            if ($paise > $paid - $refunded) {
                throw new DomainException('The refund is more than the amount collected on this bill.');
            }

            $refund = PatientBillingRefund::query()->create([
                'patient_billing_id' => $bill->id,
                'patient_billing_payment_id' => $payment?->id,
                'clinic_id' => $bill->clinic_id,
                'branch_id' => $bill->branch_id,
                'refund_number' => $this->numbers->next(ClinicDocument::Refund, $clinic, $branch),
                'amount' => $paise / 100,
                'payment_method' => $method ?? $payment?->payment_method,
                'reason' => $reason,
                'refunded_by' => $refunder?->id,
            ]);

            $totalRefunded = $refunded + $paise;
            $bill->update([
                'refunded_amount' => $totalRefunded / 100,
                'status' => $totalRefunded === $paid ? BillingStatus::Refunded : $bill->status,
                'updated_by' => $refunder?->id,
            ]);

            $this->timeline($bill, $clinic, 'refund_recorded', ['refund_number' => $refund->refund_number, 'amount' => $refund->amount]);

            event(new RefundInitiated($refund));

            return $refund;
        });
    }

    /**
     * Reduce what is still due on a bill — a concession or a write-off —
     * with its reason. The bill's lines are never edited; the adjustment is
     * its own record and the bill keeps their total in `adjusted_amount`.
     */
    public function recordAdjustment(PatientBilling $billing, float $amount, string $reason, ?User $by = null): PatientBillingAdjustment
    {
        $paise = (int) round($amount * 100);
        if ($paise <= 0) {
            throw new InvalidArgumentException('An adjustment must be more than zero.');
        }
        if (trim($reason) === '') {
            throw new InvalidArgumentException('Say why the balance is adjusted.');
        }

        return DB::transaction(function () use ($billing, $paise, $reason, $by) {
            [$bill, $clinic] = $this->lock($billing);
            if ($bill->isCancelled()) {
                throw new DomainException('This bill is cancelled.');
            }
            $outstanding = (int) round((float) $bill->outstanding_balance * 100);
            if ($paise > $outstanding) {
                throw new DomainException('The adjustment is more than the amount due.');
            }

            $adjustment = PatientBillingAdjustment::query()->withoutGlobalScopes()->create([
                'patient_billing_id' => $bill->id, 'clinic_id' => $bill->clinic_id, 'branch_id' => $bill->branch_id,
                'amount' => $paise / 100, 'reason' => mb_substr(trim($reason), 0, 500), 'adjusted_by' => $by?->id,
            ]);
            $remaining = $outstanding - $paise;
            $bill->update([
                'adjusted_amount' => ((int) round((float) $bill->adjusted_amount * 100) + $paise) / 100,
                'outstanding_balance' => $remaining / 100,
                'status' => $remaining === 0 ? BillingStatus::Paid : ((float) $bill->paid_amount > 0 ? BillingStatus::PartiallyPaid : $bill->status),
            ]);
            $this->timeline($bill, $clinic, 'bill_adjusted', ['amount' => $adjustment->amount, 'reason' => $adjustment->reason]);
            app(SecurityAudit::class)->record('billing.adjusted', $bill, ['amount' => (float) $adjustment->amount, 'reason' => $adjustment->reason], ['clinic_id' => $bill->clinic_id]);

            return $adjustment;
        });
    }

    /**
     * @return array{0: PatientBilling, 1: Clinic, 2: Branch}
     */
    private function lock(PatientBilling $billing): array
    {
        /** @var PatientBilling $bill */
        $bill = PatientBilling::query()->withoutGlobalScopes()->whereKey($billing->id)->lockForUpdate()->firstOrFail();
        $clinic = Clinic::query()->findOrFail($bill->clinic_id);
        $branch = Branch::query()->withoutGlobalScopes()->where('clinic_id', $bill->clinic_id)->findOrFail($bill->branch_id);

        return [$bill, $clinic, $branch];
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function timeline(PatientBilling $bill, Clinic $clinic, string $event, array $context): void
    {
        PatientTimelineEvent::create([
            'organization_id' => $clinic->organization_id,
            'clinic_id' => $clinic->id,
            'patient_id' => $bill->patient_id,
            'actor_id' => auth()->id(),
            'event_type' => $event,
            'context' => ['billing_id' => $bill->id, 'billing_number' => $bill->billing_number, ...$context],
        ]);
    }
}
