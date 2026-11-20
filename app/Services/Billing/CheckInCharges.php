<?php

namespace App\Services\Billing;

use App\ClinicPermission;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicEmployee;
use App\Models\ClinicSpecialty;
use App\Models\Patient;
use App\Models\PatientBilling;
use App\Models\PatientBillingItem;
use App\Models\PatientBillingPayment;
use App\Models\PatientVisit;
use App\Models\User;
use App\PaymentMethod;
use App\Services\BillingService;
use App\Services\PaymentService;
use App\Services\Reception\PatientFlowConfigService;
use App\Support\Specialty\PracticeAreas;
use DomainException;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * The consultation fee when a clinic collects it at check-in.
 *
 * Collected at check-in or after the visit, it is the same one charge: a
 * consultation line on a `PatientBilling` bill (written by `BillingService`)
 * whose source is the visit, so `EncounterCheckout` sees the consultation as
 * already billed and never adds a second consultation line. Payments go
 * through `PaymentService` (one payment, one receipt, the bill's outstanding
 * balance the only balance). The fee is the practitioner's own; nothing the
 * desk sends sets an amount to charge.
 *
 * Callers run this inside the registration/check-in transaction: payment
 * comes first, so a payment that fails undoes the visit, the queue entry and
 * the charge with it — no orphan bill, no unpaid patient in the queue.
 */
class CheckInCharges
{
    public function __construct(
        protected BillingService $billing,
        protected PaymentService $payments,
        protected PatientFlowConfigService $flow,
    ) {}

    /** The practitioner's consultation fee, or null when none is set. */
    public function feeFor(?ClinicEmployee $doctor): ?float
    {
        $fee = (float) ($doctor?->practitionerProfile()->withoutGlobalScopes()->value('consultation_fee') ?? 0);

        return $fee > 0 ? $fee : null;
    }

    /** Whether this clinic takes the consultation fee at check-in (else after the visit). */
    public function collectsAtCheckIn(Clinic $clinic): bool
    {
        return $this->flow->checkIn($clinic)['payment'] === PatientFlowConfigService::PAY_AT_CHECK_IN;
    }

    /**
     * What checking this visit in charges: the doctor's consultation fee
     * when the clinic collects at check-in and this member may raise bills;
     * nothing when the clinic bills after the visit, the doctor has no fee,
     * or the visit's module bills at its own stage. A session package never
     * waives a check-in charge: which treatment the visit is for is not known
     * until the session, and a package pays only for its own treatment's
     * completed session (`SessionPackages::paidFor`, at checkout).
     * Registering a patient on its own never charges anything.
     */
    public function dueAtCheckIn(Clinic $clinic, Patient $patient, PatientVisit $visit, ?ClinicEmployee $doctor, User $user): ?float
    {
        $fee = $this->feeFor($doctor);
        if (! $this->collectsAtCheckIn($clinic) || $fee === null || ! $user->hasClinicPermission(ClinicPermission::BillingCreate)) {
            return null;
        }
        // A module that bills at its own stage (Physiotherapy: sessions and packages) charges nothing at check-in.
        if (! $this->moduleChargesAtCheckIn($visit)) {
            return null;
        }

        return $fee;
    }

    /** Whether the visit's module takes a consultation charge at check-in (`clinic_specialties.modules.*.charges_at_check_in`, default yes). */
    public function moduleChargesAtCheckIn(PatientVisit $visit): bool
    {
        $specialty = $this->specialtyOf($visit);
        $module = $specialty !== null ? app(PracticeAreas::class)->moduleOfSpecialty($specialty) : null;

        return $module === null || (bool) config("clinic_specialties.modules.{$module}.charges_at_check_in", true);
    }

    private function specialtyOf(PatientVisit $visit): ?string
    {
        return $visit->clinic_specialty_id
            ? ClinicSpecialty::query()->withoutGlobalScopes()->whereKey($visit->clinic_specialty_id)->value('specialty_key')
            : null;
    }

    /**
     * Charge the consultation for a visit (and take a payment against it
     * when one is given) — exactly what `dueAtCheckIn` says, nothing else.
     *
     * `$verify` with `$expected`: the amount the desk showed before saving
     * (null for "nothing charged"). When the server's own answer differs —
     * the fee changed, a package covers the visit, the policy changed — the
     * check-in is refused with the real amount, so nobody is charged a sum
     * they were not shown. A desk that showed a fee also says how it is
     * settled: a payment (full or part), or `$payLater` — an explicit unpaid
     * check-in that leaves the bill owing. `$payLater` null is an older
     * caller that never states it: the bill is left owing, as before.
     *
     * The payment is taken by a member who may collect money at this
     * branch, by a method the desk accepts; any refusal throws, so the
     * caller's transaction undoes the check-in with it.
     *
     * @param  array{method: string, amount: float|int|string, reference?: ?string}|null  $payment
     * @return array{bill: PatientBilling, payment: ?PatientBillingPayment}|null
     */
    public function chargeVisit(Clinic $clinic, Branch $branch, Patient $patient, PatientVisit $visit, ?ClinicEmployee $doctor, User $user, ?ClinicEmployee $performer, ?array $payment, bool $verify = false, ?float $expected = null, ?bool $payLater = null): ?array
    {
        $fee = $this->dueAtCheckIn($clinic, $patient, $visit, $doctor, $user);
        if ($verify && round((float) $expected, 2) !== round((float) $fee, 2)) {
            throw ValidationException::withMessages(['expected_charge' => $fee === null
                ? 'Nothing is charged at check-in for this visit. Check the visit and submit again.'
                : 'Checking in charges ₹'.number_format($fee, 2).' for this visit. Check the amount and submit again.']);
        }
        if ($fee === null) {
            if ($payment !== null) {
                throw ValidationException::withMessages(['payment' => 'Nothing is collected at check-in for this visit.']);
            }

            return null;
        }
        if ($payment !== null && $payLater === true) {
            throw ValidationException::withMessages(['payment' => 'Take the payment or choose Pay later, not both.']);
        }
        if ($payment === null && $payLater !== true && ($verify || $payLater === false)) {
            throw ValidationException::withMessages(['payment' => 'Take the payment, or choose Pay later to check the patient in unpaid.']);
        }
        if ($payment !== null) {
            if (! $user->hasClinicPermissionAt(ClinicPermission::PaymentCollect, $branch)) {
                throw ValidationException::withMessages(['payment' => 'You cannot take payments.']);
            }
            $method = PaymentMethod::tryFrom((string) ($payment['method'] ?? ''));
            if ($method === null || ! in_array($method, $this->payments->acceptedMethods($clinic, atDesk: true), true)) {
                throw ValidationException::withMessages(['payment.method' => 'Choose how the patient is paying.']);
            }
        }
        if ($this->visitCharged($visit)) {
            throw ValidationException::withMessages(['payment' => 'This visit has already been charged.']);
        }

        $bill = $this->billing->createBill(
            clinic: $clinic,
            patient: $patient,
            branch: $branch,
            items: [[
                'item_type' => PatientBillingItem::TYPE_CONSULTATION,
                'description' => 'Consultation',
                'quantity' => 1,
                'unit_price' => $fee,
                'source' => $visit,
            ]],
            doctor: $doctor,
            creator: $performer,
        );

        $paid = null;
        if ($payment !== null) {
            try {
                $paid = $this->payments->recordPayment($bill, $method, (float) $payment['amount'], $payment['reference'] ?? null, $performer);
            } catch (DomainException|InvalidArgumentException $e) {
                throw ValidationException::withMessages(['payment.amount' => $e->getMessage()]);
            }
        }

        return ['bill' => $bill->fresh(), 'payment' => $paid];
    }

    /** Whether a live bill already charges this visit. */
    public function visitCharged(PatientVisit $visit): bool
    {
        return PatientBillingItem::query()->withoutGlobalScopes()
            ->where('source_type', $visit->getMorphClass())->where('source_id', $visit->id)
            ->whereHas('billing', fn ($bill) => $bill->withoutGlobalScopes()->where('status', '!=', 'cancelled'))
            ->exists();
    }

    /**
     * The payment part of the desk's success screen: the bill, what was paid
     * and what is still owed, and the receipt when money was taken.
     *
     * @param  array{bill: PatientBilling, payment: ?PatientBillingPayment}|null  $charge
     * @return array<string, mixed>|null
     */
    public function present(?array $charge, User $user): ?array
    {
        if ($charge === null) {
            return null;
        }
        $bill = $charge['bill'];
        $payment = $charge['payment'];

        $bill->loadMissing('items');
        // Printing this bill, its receipt and following its messages: the desk that raised it may, as may the ledger's readers.
        $mayView = $user->hasClinicPermission(ClinicPermission::BillingView) || $user->hasClinicPermission(ClinicPermission::BillingCreate) || $user->hasClinicPermission(ClinicPermission::PaymentCollect);
        $mayFollowMessages = $user->hasClinicPermission(ClinicPermission::PaymentCollect);

        return [
            'id' => $bill->id,
            'billing_number' => $bill->billing_number,
            // What is charged and why: each line of the bill, as the server raised it.
            'lines' => $bill->items->map(fn (PatientBillingItem $item) => [
                'description' => $item->description,
                'quantity' => (int) $item->quantity,
                'amount' => (float) $item->final_amount,
            ])->values()->all(),
            'reason' => 'Consultation fee'.($bill->doctor?->name ? ' for '.$bill->doctor->name : '').', charged when the patient checked in.',
            // Where to take a payment, for a member who may (the existing billing payment endpoint).
            'pay_url' => (float) $bill->outstanding_balance > 0 && $user->hasClinicPermission(ClinicPermission::PaymentCollect) ? route('clinic.billing.payments.store', $bill) : null,
            'total' => (float) $bill->final_amount,
            'paid' => (float) $bill->paid_amount,
            'outstanding' => (float) $bill->outstanding_balance,
            'status' => $bill->status->value,
            'receipt' => $payment ? [
                'id' => $payment->id,
                'number' => $payment->receipt_number,
                'amount' => (float) $payment->amount,
                'method' => $payment->payment_method->label(),
                // The existing bill print route as a thermal roll receipt for this payment.
                'print_url' => $mayView ? route('clinic.billing.bills.print', ['bill' => $bill, 'layout' => 'receipt', 'payment' => $payment->id]) : null,
                // Where the patient's messages for this payment stand (queued after the payment commits).
                'messages_url' => $mayFollowMessages ? route('clinic.billing.payments.messages', $payment->id) : null,
                'retry_url' => $user->hasClinicPermission(ClinicPermission::PaymentCollect) ? route('clinic.billing.payments.messages.retry', $payment->id) : null,
            ] : null,
            'print_url' => $mayView ? route('clinic.billing.bills.print', $bill) : null,
        ];
    }
}
