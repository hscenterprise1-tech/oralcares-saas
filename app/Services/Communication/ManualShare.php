<?php

namespace App\Services\Communication;

use App\ClinicPermission;
use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\ClinicalOrderItem;
use App\Models\Notification;
use App\Models\Patient;
use App\Models\PatientBillingPayment;
use App\Models\PatientFollowUp;
use App\Models\Prescription;
use App\Models\User;
use App\Services\Patient\Patient360\Patient360Access;
use App\Services\Patient\Patient360\Patient360Summary;
use App\Services\WhatsApp\ClinicWhatsApp;
use App\Support\ClinicClock;
use DomainException;
use Illuminate\Database\Eloquent\Builder;

/**
 * A member sending a patient a WhatsApp message by hand, from Patient 360:
 * a prescription, bill, receipt or reviewed report is ready, or an upcoming
 * appointment or follow-up — each through the clinic's approved template for
 * it, never free text, never a file or a link to one.
 *
 * - Who: each kind follows the permission for its subject (prescriptions and
 *   reports: clinical view; bills and receipts: billing view; appointments:
 *   appointment booking; follow-ups: follow-up management) and the member's
 *   branches (`Patient360Summary` scopes).
 * - What: only this patient's own records, of this clinic, in a state that
 *   makes the message true (a finalised prescription, a reviewed report, an
 *   upcoming appointment, an open follow-up).
 * - How: `PatientMessaging::sendManual` — eligibility (deceased, merged),
 *   consent, the clinic's own sender (no fallback to another number), the
 *   approved template, queued and tracked like every other message.
 * - Once an hour per record: the event key carries the hour, so a double
 *   click or a retried request does not send twice.
 */
class ManualShare
{
    /** @var array<string, array{code: string, label: string}> */
    public const KINDS = [
        'prescription' => ['code' => 'prescription_ready', 'label' => 'Prescription ready'],
        'bill' => ['code' => 'bill_invoice', 'label' => 'Bill'],
        'receipt' => ['code' => 'payment_receipt', 'label' => 'Payment receipt'],
        'report' => ['code' => 'report_ready', 'label' => 'Report ready'],
        'appointment' => ['code' => 'appointment_confirmed', 'label' => 'Appointment details'],
        'follow_up' => ['code' => 'follow_up_reminder', 'label' => 'Follow-up reminder'],
    ];

    public function __construct(
        protected Patient360Summary $summary,
        protected Patient360Access $access,
        protected PatientMessaging $messaging,
        protected ClinicWhatsApp $whatsApp,
    ) {}

    /**
     * What this member may send this patient now, each with the text the
     * patient would receive, and why WhatsApp cannot send it when it cannot.
     *
     * @return list<array{kind: string, id: int, label: string, detail: string, preview: string, blocked: ?string}>
     */
    public function options(Patient $patient, User $user): array
    {
        $clinic = Clinic::query()->findOrFail($patient->clinic_id);
        $items = [];
        foreach ($this->candidates($patient, $user) as [$kind, $id, $detail, $placeholders]) {
            $code = self::KINDS[$kind]['code'];
            $items[] = [
                'kind' => $kind,
                'id' => $id,
                'label' => self::KINDS[$kind]['label'],
                'detail' => $detail,
                'preview' => $this->whatsApp->preview($clinic, $code, $placeholders),
                'blocked' => $this->whatsApp->blockedReason($clinic, $patient, $code),
            ];
        }

        return $items;
    }

    /**
     * Send one of them. Refused (with the reason) when the member may not,
     * the record is not this patient's or not in a sendable state, or
     * WhatsApp cannot carry it.
     *
     * @throws DomainException
     */
    public function share(Patient $patient, User $user, string $kind, int $id): Notification
    {
        $candidate = collect($this->candidates($patient, $user))->first(fn (array $row) => $row[0] === $kind && $row[1] === $id)
            ?? throw new DomainException('That cannot be sent to this patient.');
        $clinic = Clinic::query()->findOrFail($patient->clinic_id);
        $code = self::KINDS[$kind]['code'];
        if (($reason = $this->whatsApp->blockedReason($clinic, $patient, $code)) !== null) {
            throw new DomainException($reason);
        }

        $eventKey = "manual:{$kind}:{$id}:".ClinicClock::now()->format('YmdH');
        if (Notification::query()->where('clinic_id', $clinic->id)->where('event_key', $eventKey)->exists()) {
            throw new DomainException('This was already sent in the last hour.');
        }

        return $this->messaging->sendManual($clinic->id, $candidate[4], $patient->id, $code, $eventKey, $candidate[3])
            ?? throw new DomainException('The message was not queued. The patient may not be contacted.');
    }

    /**
     * The records this member may send, newest first, at most a few of each.
     *
     * @return list<array{0: string, 1: int, 2: string, 3: array<string, string>, 4: ?int}> kind, id, detail, placeholders, branch
     */
    private function candidates(Patient $patient, User $user): array
    {
        $base = $this->messaging->basePlaceholders($patient->clinic_id, $patient->id);
        $rows = [];

        if ($this->access->canViewClinical($user)) {
            foreach ($this->summary->prescriptions($patient)->where('status', Prescription::FINALIZED)->latest('id')->limit(3)->get() as $rx) {
                $rows[] = ['prescription', $rx->id, "Prescription {$rx->prescription_number}", [...$base, 'number' => (string) $rx->prescription_number], null];
            }
            foreach ($this->summary->orderItems($patient)->where('clinical_order_items.status', ClinicalOrderItem::REVIEWED)->latest('clinical_order_items.id')->limit(3)->get(['clinical_order_items.*']) as $item) {
                $rows[] = ['report', $item->id, (string) $item->item_name, $base, null];
            }
        }
        if ($this->access->canViewBilling($user) && $user->hasClinicPermission(ClinicPermission::BillingView)) {
            foreach ($this->summary->bills($patient)->where('status', '!=', 'cancelled')->latest('id')->limit(3)->get() as $bill) {
                $rows[] = ['bill', $bill->id, "Bill {$bill->billing_number}", [...$base, 'number' => (string) $bill->billing_number, 'amount' => PatientMessaging::money((float) $bill->final_amount)], $bill->branch_id];
            }
            $billIds = $this->summary->bills($patient)->select('id');
            foreach (PatientBillingPayment::query()->whereIn('patient_billing_id', $billIds)->latest('id')->limit(3)->get() as $payment) {
                $rows[] = ['receipt', $payment->id, "Receipt {$payment->receipt_number}", [...$base, 'number' => (string) $payment->receipt_number, 'amount' => PatientMessaging::money((float) $payment->amount)], $payment->branch_id ?? null];
            }
        }
        if ($this->access->canViewAppointments($user) && $user->hasClinicPermission(ClinicPermission::AppointmentCreate)) {
            $next = Appointment::query()->where('clinic_id', $patient->clinic_id)->where('patient_id', $patient->id)
                ->tap(fn (Builder $query) => $this->summary->inBranches($query))
                ->where('scheduled_at', '>', now())->whereIn('status', ['scheduled', 'confirmed'])->orderBy('scheduled_at')->first();
            if ($next) {
                $when = ClinicClock::fromUtc($next->scheduled_at)->format('d M Y, h:i A');
                $rows[] = ['appointment', $next->id, "Appointment on {$when}", [...$base, 'when' => $when], $next->branch_id];
            }
        }
        if ($this->access->canManageFollowUps($user)) {
            $followUp = PatientFollowUp::query()->where('clinic_id', $patient->clinic_id)->where('patient_id', $patient->id)
                ->whereIn('status', [PatientFollowUp::DUE])->orderBy('due_on')->first();
            if ($followUp) {
                $when = $followUp->due_on->format('d M Y');
                $rows[] = ['follow_up', $followUp->id, "Follow-up due {$when}", [...$base, 'when' => $when], $followUp->branch_id ?? null];
            }
        }

        return $rows;
    }
}
