<?php

namespace App\Services\Communication;

use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicalEncounter;
use App\Models\ClinicalOrderItem;
use App\Models\ClinicEmployee;
use App\Models\ClinicQueue;
use App\Models\ClinicQueueItem;
use App\Models\ClinicWhatsAppMessage;
use App\Models\Notification;
use App\Models\NotificationTemplate;
use App\Models\Patient;
use App\Models\PatientBilling;
use App\Models\PatientBillingPayment;
use App\Models\PatientFollowUp;
use App\Models\PatientProcedure;
use App\Models\PatientSessionPackage;
use App\Models\PatientTimelineEvent;
use App\Models\PatientTreatment;
use App\Models\PatientTreatmentStage;
use App\Models\PatientVisit;
use App\Models\PhysioSession;
use App\Models\Prescription;
use App\Models\TreatmentPlan;
use App\Services\ChannelResolverService;
use App\Services\NotificationService;
use App\Services\WhatsApp\ClinicWhatsApp;
use App\Support\ClinicClock;
use App\Support\Specialty\SpecialtyRegistry;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * What the clinic tells its patients, through the existing patient
 * notification queue (`notifications`, sent by `NotificationService`):
 * appointment confirmation, reminder, reschedule and cancellation; the
 * follow-up reminder the doctor asked for; prescription issued; report ready.
 *
 * Every message carries an event key, so the same event never queues two
 * messages (a retried request, a re-saved record). A reminder for an
 * appointment that moves or is cancelled is withdrawn. Schedules are clinic
 * wall-clock digits, like appointments (see `ClinicClock`).
 *
 * Messages are only queued here; `DispatchDuePatientMessages` sends them.
 * Nothing is queued for a patient who may not be contacted at all
 * (`PatientMessageEligibility`: merged, deleted).
 */
class PatientMessaging
{
    public function __construct(
        protected NotificationService $notifications,
        protected ChannelResolverService $channels,
        protected ClinicWhatsApp $whatsApp,
        protected PatientMessageEligibility $eligibility,
    ) {}

    /**
     * One confirmation for the booking (the first of a series), and a
     * reminder before each appointment.
     *
     * @param  iterable<Appointment>  $appointments
     */
    public function appointmentsBooked(iterable $appointments): void
    {
        $first = true;
        foreach ($appointments as $appointment) {
            if (! $appointment->patient_id) {
                return;
            }
            if ($first) {
                $this->send($appointment->clinic_id, $appointment->branch_id, $appointment->patient_id, 'appointment_confirmed', "appointment_confirmed:{$appointment->id}", $this->appointmentPlaceholders($appointment));
                $first = false;
            }
            $this->scheduleReminder($appointment);
        }
    }

    public function appointmentRescheduled(Appointment $appointment): void
    {
        if (! $appointment->patient_id) {
            return;
        }

        $this->withdraw($appointment->clinic_id, "appointment_reminder:{$appointment->id}:");
        $stamp = $appointment->scheduled_at->format('YmdHi');
        $this->send($appointment->clinic_id, $appointment->branch_id, $appointment->patient_id, 'appointment_rescheduled', "appointment_rescheduled:{$appointment->id}:{$stamp}", $this->appointmentPlaceholders($appointment));
        $this->scheduleReminder($appointment);
    }

    public function appointmentCancelled(Appointment $appointment): void
    {
        if (! $appointment->patient_id) {
            return;
        }

        $this->withdraw($appointment->clinic_id, "appointment_reminder:{$appointment->id}:");
        $this->send($appointment->clinic_id, $appointment->branch_id, $appointment->patient_id, 'appointment_cancelled', "appointment_cancelled:{$appointment->id}", $this->appointmentPlaceholders($appointment));
    }

    /** The review the doctor asked for, reminded the day before. */
    /**
     * Remind the patient of a follow-up the day before it is due. A follow-up
     * moved to a new date withdraws the old reminder and sets a new one.
     */
    public function followUpDue(PatientFollowUp $followUp): void
    {
        $this->withdrawFollowUp($followUp);
        if (! $followUp->isOpen()) {
            return;
        }

        $review = CarbonImmutable::parse($followUp->due_on->toDateString(), config('app.timezone'));
        $sendAt = $review->subDay()->setTime((int) config('clinic_messages.follow_up_hour', 9), 0);
        if ($sendAt->lessThan(ClinicClock::now())) {
            $sendAt = null;
        }

        $this->send($followUp->clinic_id, $followUp->branch_id, $followUp->patient_id, 'follow_up_reminder', "follow_up:{$followUp->id}:".$review->format('Ymd'), [
            'patient' => $this->patientName($followUp->patient_id),
            'clinic' => $this->clinicName($followUp->clinic_id),
            'doctor' => ClinicEmployee::query()->withoutGlobalScopes()->whereKey($followUp->practitioner_id)->value('name') ?? 'your doctor',
            'when' => $review->format('d M Y'),
        ], $sendAt);
    }

    /** Withdraw a follow-up's reminders that have not gone out yet. */
    public function withdrawFollowUp(PatientFollowUp $followUp): void
    {
        $this->withdraw($followUp->clinic_id, "follow_up:{$followUp->id}:");
    }

    public function prescriptionIssued(Prescription $prescription): void
    {
        $this->send($prescription->clinic_id, $this->branchOfEncounter($prescription->encounter_id), $prescription->patient_id, 'prescription_ready', "prescription:{$prescription->id}", [
            'patient' => $this->patientName($prescription->patient_id),
            'clinic' => $this->clinicName($prescription->clinic_id),
            'doctor' => ClinicEmployee::query()->withoutGlobalScopes()->whereKey($prescription->prescriber_id)->value('name') ?? 'your doctor',
            'number' => (string) $prescription->prescription_number,
        ]);
    }

    public function reportReviewed(ClinicalOrderItem $item): void
    {
        $order = $item->order()->withoutGlobalScopes()->firstOrFail();

        $this->send($order->clinic_id, $order->branch_id, $order->patient_id, 'report_ready', "report:{$item->id}", [
            'patient' => $this->patientName($order->patient_id),
            'clinic' => $this->clinicName($order->clinic_id),
            'test' => $item->item_name,
        ]);
    }

    private function scheduleReminder(Appointment $appointment): void
    {
        $sendAt = CarbonImmutable::instance($appointment->scheduled_at)->subHours((int) config('clinic_messages.reminder_hours_before', 24));
        if ($sendAt->lessThanOrEqualTo(ClinicClock::now())) {
            return;
        }

        $this->send($appointment->clinic_id, $appointment->branch_id, $appointment->patient_id, 'appointment_reminder',
            "appointment_reminder:{$appointment->id}:".$appointment->scheduled_at->format('YmdHi'), $this->appointmentPlaceholders($appointment), $sendAt);
    }

    /**
     * A message a member chose to send now (`ManualShare`), through the same
     * pipeline as the automatic ones: eligibility (merged, deleted),
     * consent, the clinic's own sender, an approved template, and the event
     * key for duplicate protection. WhatsApp only: when WhatsApp cannot carry
     * it, nothing is queued on another channel.
     *
     * @param  array<string, string>  $placeholders
     */
    public function sendManual(int $clinicId, ?int $branchId, int $patientId, string $code, string $eventKey, array $placeholders): ?Notification
    {
        $clinic = Clinic::query()->findOrFail($clinicId);
        if (! $this->whatsApp->carries($clinic, $code)) {
            return null;
        }

        return $this->send($clinicId, $branchId, $patientId, $code, $eventKey, $placeholders);
    }

    /**
     * The placeholders a message uses, as the automatic sends build them.
     *
     * @return array{patient: string, clinic: string}
     */
    public function basePlaceholders(int $clinicId, int $patientId): array
    {
        return ['patient' => $this->patientName($patientId), 'clinic' => $this->clinicName($clinicId)];
    }

    /**
     * @param  array<string, string>  $placeholders
     */
    private function send(int $clinicId, ?int $branchId, int $patientId, string $code, string $eventKey, array $placeholders, ?CarbonInterface $sendAt = null): ?Notification
    {
        if (Notification::query()->where('clinic_id', $clinicId)->where('event_key', $eventKey)->exists()) {
            return null;
        }

        // A patient who may not be contacted gets nothing queued. Nothing is
        // stored under the event key, so the same event can still message the
        // patient if the record is restored. The log carries identifiers only.
        if (($reason = $this->eligibility->blockReasonFor($clinicId, $patientId)) !== null) {
            Log::info('Patient message not queued: patient may not be contacted.', [
                'clinic_id' => $clinicId,
                'patient_id' => $patientId,
                'event_key' => $eventKey,
                'reason' => $reason,
            ]);

            return null;
        }

        $clinic = Clinic::query()->findOrFail($clinicId);

        try {
            // A savepoint, so losing a race to the unique key leaves the
            // caller's transaction intact.
            return DB::transaction(function () use ($clinic, $branchId, $patientId, $code, $eventKey, $placeholders, $sendAt) {
                $notification = $this->notifications->queueNotification(
                    $clinic,
                    $branchId ? Branch::query()->withoutGlobalScopes()->find($branchId) : null,
                    'patient',
                    $patientId,
                    $this->channelFor($clinic, $code, $patientId),
                    str_starts_with($code, 'appointment') ? 'high' : 'medium',
                    $this->template($clinic, $code),
                    $placeholders,
                    $sendAt?->format('Y-m-d H:i:s'),
                );
                $notification->forceFill(['event_key' => $eventKey])->save();

                // The patient's timeline notes that a message was queued — its
                // kind and channel only, never its text.
                PatientTimelineEvent::create([
                    'organization_id' => $clinic->organization_id,
                    'clinic_id' => $clinic->id,
                    'patient_id' => $patientId,
                    'actor_id' => auth()->id(),
                    'event_type' => 'message_queued',
                    'context' => ['kind' => Str::headline($code), 'channel' => $notification->channel, 'notification_id' => $notification->id],
                ]);

                return $notification;
            });
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }

    public function appointmentMissed(Appointment $appointment): void
    {
        if (! $appointment->patient_id) {
            return;
        }

        $this->withdraw($appointment->clinic_id, "appointment_reminder:{$appointment->id}:");
        $this->send($appointment->clinic_id, $appointment->branch_id, $appointment->patient_id, 'appointment_missed', "appointment_missed:{$appointment->id}", $this->appointmentPlaceholders($appointment));
    }

    /** The patient is checked in: their token. */
    public function checkedIn(ClinicQueueItem $item): void
    {
        $patientId = $this->patientOfQueueItem($item);
        if ($patientId === null) {
            return;
        }

        [$clinicId, $branchId] = $this->queueOf($item);
        $this->send($clinicId, $branchId, $patientId, 'checkin_confirmation', "checkin:{$item->id}", [
            'patient' => $this->patientName($patientId),
            'clinic' => $this->clinicName($clinicId),
            'token' => (string) ($item->case_number ?: $item->position),
        ]);
    }

    /** The patient has been called in. A recall is a new call. */
    public function calledIn(ClinicQueueItem $item): void
    {
        $patientId = $this->patientOfQueueItem($item);
        if ($patientId === null) {
            return;
        }

        [$clinicId, $branchId] = $this->queueOf($item);
        $this->send($clinicId, $branchId, $patientId, 'queue_turn', "queue_turn:{$item->id}:".($item->called_at?->format('YmdHis') ?? now()->format('YmdHis')), [
            'patient' => $this->patientName($patientId),
            'clinic' => $this->clinicName($clinicId),
        ]);
    }

    /**
     * The doctor has signed the visit: a feedback request two hours later
     * and, after a dental visit, a check on the patient the next morning.
     */
    public function visitCompleted(ClinicalEncounter $encounter): void
    {
        $placeholders = ['patient' => $this->patientName($encounter->patient_id), 'clinic' => $this->clinicName($encounter->clinic_id)];
        $now = ClinicClock::now();

        $this->send($encounter->clinic_id, $encounter->branch_id, $encounter->patient_id, 'feedback_request', "feedback:{$encounter->id}", $placeholders, $now->addHours(2));
        if ($this->specialtyHas($encounter->specialty, 'odontogram')) {
            $this->send($encounter->clinic_id, $encounter->branch_id, $encounter->patient_id, 'dental_procedure_followup', "dental_after_visit:{$encounter->id}", $placeholders,
                $now->addDay()->setTime((int) config('clinic_messages.follow_up_hour', 9), 0));
        }
    }

    /**
     * Care advice after a recorded procedure (`clinic_messages.aftercare`):
     * the first kind whose pattern matches its name, once per consultation
     * and kind. A procedure with no matching kind sends nothing.
     */
    public function procedureAftercare(PatientProcedure $procedure): void
    {
        $name = (string) ($procedure->name ?? '');
        foreach ((array) config('clinic_messages.aftercare', []) as $kind => $advice) {
            if (! @preg_match($advice['pattern'], $name)) {
                continue;
            }
            $this->send($procedure->clinic_id, $this->branchOfEncounter($procedure->encounter_id), $procedure->patient_id, 'procedure_aftercare',
                'aftercare:'.($procedure->encounter_id ?? "procedure-{$procedure->id}").":{$kind}", [
                    'patient' => $this->patientName($procedure->patient_id),
                    'clinic' => $this->clinicName($procedure->clinic_id),
                    'procedure' => mb_strtolower($name),
                    'care' => $advice['care'],
                ]);

            return;
        }
    }

    /** A dental plan with no work for a month (`ClinicWorkAlerts`): ask the patient to book. Once a month per plan. */
    public function planStalled(TreatmentPlan $plan, string $month): void
    {
        $this->send($plan->clinic_id, $this->branchOfEncounter($plan->encounter_id), $plan->patient_id, 'dental_plan_waiting', "dental_plan_waiting:{$plan->id}:{$month}", [
            'patient' => $this->patientName($plan->patient_id),
            'clinic' => $this->clinicName($plan->clinic_id),
        ]);
    }

    /** A step of a dental treatment is done: book the next visit. */
    public function treatmentStageCompleted(PatientTreatmentStage $stage): void
    {
        $treatment = PatientTreatment::query()->withoutGlobalScopes()->find($stage->patient_treatment_id);
        if (! $treatment || ! $this->specialtyHas($treatment->specialty_key, 'odontogram')) {
            return;
        }

        $this->send($treatment->clinic_id, $treatment->branch_id, $treatment->patient_id, 'dental_treatment_followup', "dental_stage:{$stage->id}", [
            'patient' => $this->patientName($treatment->patient_id),
            'clinic' => $this->clinicName($treatment->clinic_id),
        ]);
    }

    /**
     * A physiotherapy session is complete: the next session (an upcoming
     * appointment, or a prompt to book one) and, when the package is nearly
     * used up, its balance.
     */
    public function physioSessionCompleted(PhysioSession $session, ?PatientSessionPackage $package, ?int $remaining): void
    {
        $next = Appointment::query()->withoutGlobalScopes()->where('clinic_id', $session->clinic_id)->where('patient_id', $session->patient_id)
            ->where('scheduled_at', '>', ClinicClock::now())->whereNotIn('status', ['cancelled', 'no_show'])->orderBy('scheduled_at')->first();
        $placeholders = ['patient' => $this->patientName($session->patient_id), 'clinic' => $this->clinicName($session->clinic_id)];
        $branchId = PatientTreatment::query()->withoutGlobalScopes()->whereKey($session->patient_treatment_id)->value('branch_id');

        $this->send($session->clinic_id, $branchId, $session->patient_id, 'physio_next_session', "physio_next:{$session->id}", [
            ...$placeholders, 'next' => $next ? $next->scheduled_at->format('d M Y, h:i A') : 'please book your next session with us',
        ]);
        if ($package && $remaining !== null && $remaining <= (int) config('clinic_whatsapp.package_low_balance', 2)) {
            $this->send($session->clinic_id, $branchId, $session->patient_id, 'physio_package_balance', "physio_balance:{$package->id}:{$remaining}", [
                ...$placeholders, 'remaining' => (string) $remaining,
            ]);
        }
    }

    public function packageExpiring(PatientSessionPackage $package): void
    {
        $this->send($package->clinic_id, $package->branch_id, $package->patient_id, 'physio_package_expiry', "physio_expiry:{$package->id}:".$package->valid_until?->format('Ymd'), [
            'patient' => $this->patientName($package->patient_id),
            'clinic' => $this->clinicName($package->clinic_id),
            'date' => $package->valid_until?->format('d M Y') ?? '',
        ]);
    }

    public function billIssued(PatientBilling $bill): void
    {
        $this->send($bill->clinic_id, $bill->branch_id, $bill->patient_id, 'bill_invoice', "bill:{$bill->id}", [
            'patient' => $this->patientName($bill->patient_id),
            'clinic' => $this->clinicName($bill->clinic_id),
            'number' => (string) $bill->billing_number,
            'amount' => self::money((float) $bill->final_amount),
        ]);
    }

    /** A payment: the acknowledgement with the balance, and the receipt. */
    public function paymentRecorded(PatientBillingPayment $payment): void
    {
        $bill = PatientBilling::query()->withoutGlobalScopes()->findOrFail($payment->patient_billing_id);
        $placeholders = ['patient' => $this->patientName($bill->patient_id), 'clinic' => $this->clinicName($bill->clinic_id), 'amount' => self::money((float) $payment->amount)];

        $this->send($bill->clinic_id, $bill->branch_id, $bill->patient_id, 'payment_received', "payment:{$payment->id}", [...$placeholders, 'balance' => self::money((float) $bill->outstanding_balance)]);
        $this->send($bill->clinic_id, $bill->branch_id, $bill->patient_id, 'payment_receipt', "receipt:{$payment->id}", [...$placeholders, 'number' => (string) $payment->receipt_number]);
    }

    /** A balance still open: due, then overdue (once each per bill). */
    /**
     * A bill still owing. An overdue bill is reminded again at each later
     * stage (`QueueScheduledPatientMessages::OVERDUE_STAGES`); the first
     * stage keeps the original event key, so no earlier reminder repeats.
     */
    public function balanceOpen(PatientBilling $bill, bool $overdue, ?int $stage = null): void
    {
        $code = $overdue ? 'payment_overdue' : 'payment_due';
        $key = "{$code}:{$bill->id}".($overdue && $stage !== null && $stage > (int) config('clinic_whatsapp.overdue_after_days', 7) ? ":{$stage}" : '');
        $this->send($bill->clinic_id, $bill->branch_id, $bill->patient_id, $code, $key, [
            'patient' => $this->patientName($bill->patient_id),
            'clinic' => $this->clinicName($bill->clinic_id),
            'amount' => self::money((float) $bill->outstanding_balance),
            'number' => (string) $bill->billing_number,
        ]);
    }

    /** The follow-up's own day. */
    public function followUpToday(PatientFollowUp $followUp): void
    {
        $this->send($followUp->clinic_id, $followUp->branch_id, $followUp->patient_id, 'follow_up_due', "follow_up_today:{$followUp->id}:".$followUp->due_on->format('Ymd'), [
            'patient' => $this->patientName($followUp->patient_id),
            'clinic' => $this->clinicName($followUp->clinic_id),
            'doctor' => ClinicEmployee::query()->withoutGlobalScopes()->whereKey($followUp->practitioner_id)->value('name') ?? 'your doctor',
            'when' => $followUp->due_on->format('d M Y'),
        ]);
    }

    public static function money(float $amount): string
    {
        return '₹'.number_format($amount, fmod($amount, 1.0) === 0.0 ? 0 : 2);
    }

    private function specialtyHas(?string $specialty, string $extension): bool
    {
        return $specialty !== null && in_array($extension, app(SpecialtyRegistry::class)->extensionsFor([$specialty]), true);
    }

    private function patientOfQueueItem(ClinicQueueItem $item): ?int
    {
        return $item->patient_visit_id ? PatientVisit::query()->withoutGlobalScopes()->whereKey($item->patient_visit_id)->value('patient_id') : null;
    }

    /** @return array{0: int, 1: ?int} the queue item's clinic and branch */
    private function queueOf(ClinicQueueItem $item): array
    {
        $queue = ClinicQueue::query()->withoutGlobalScopes()->findOrFail($item->clinic_queue_id, ['clinic_id', 'branch_id']);

        return [(int) $queue->clinic_id, $queue->branch_id];
    }

    /**
     * WhatsApp when the clinic's WhatsApp carries this message, otherwise the
     * clinic's preferred other channel. Decided again when the message is
     * sent (`NotificationService`), so a later settings change applies.
     */
    public function channelFor(Clinic $clinic, string $code, int $patientId): string
    {
        return $this->whatsApp->carries($clinic, $code)
            ? 'whatsapp'
            : $this->channels->resolvePreferredChannel($clinic, 'patient', $patientId, except: ['whatsapp']);
    }

    /**
     * Stop everything still waiting to go to a patient who may no longer be
     * contacted (merged into another record): pending messages become `skipped` with the
     * reason, and WhatsApp deliveries not yet handed to the provider likewise.
     * A message already being sent is left to the dispatch-time check.
     * Returns how many patient messages were stopped.
     */
    public function skipPendingFor(Patient $patient, string $reason): int
    {
        $skipped = Notification::query()->where('clinic_id', $patient->clinic_id)
            ->where('recipient_type', 'patient')
            ->where('recipient_id', $patient->id)
            ->where('status', 'pending')
            ->update(['status' => NotificationService::SKIPPED, 'failed_reason' => $reason]);

        // A WhatsApp delivery waiting for its send job: its patient message is
        // already `processing`, so both are stopped together.
        $queued = ClinicWhatsAppMessage::query()->withoutGlobalScopes()->where('clinic_id', $patient->clinic_id)
            ->where('patient_id', $patient->id)
            ->where('status', ClinicWhatsAppMessage::QUEUED);
        $notificationIds = (clone $queued)->whereNotNull('notification_id')->pluck('notification_id')->all();
        $queued->update(['status' => ClinicWhatsAppMessage::SKIPPED, 'failure_reason' => $reason]);
        if ($notificationIds !== []) {
            $skipped += Notification::query()->where('clinic_id', $patient->clinic_id)->whereIn('id', $notificationIds)
                ->where('status', 'processing')
                ->update(['status' => NotificationService::SKIPPED, 'failed_reason' => $reason]);
        }

        return $skipped;
    }

    /** Withdraw pending messages whose key starts with the prefix. */
    private function withdraw(int $clinicId, string $eventKeyPrefix): void
    {
        Notification::query()->where('clinic_id', $clinicId)
            ->where('status', 'pending')
            ->where('event_key', 'like', $eventKeyPrefix.'%')
            ->update(['status' => 'cancelled']);
    }

    /** The clinic's own template for the code; copied from config the first time. */
    private function template(Clinic $clinic, string $code): NotificationTemplate
    {
        $default = config("clinic_messages.templates.{$code}");

        return NotificationTemplate::query()->withoutGlobalScopes()->where('clinic_id', $clinic->id)->where('code', $code)->latest('id')->first()
            ?? NotificationTemplate::query()->create([
                'clinic_id' => $clinic->id,
                'code' => $code,
                'name' => $default['name'],
                'subject' => $default['subject'],
                'content' => $default['content'],
            ]);
    }

    /**
     * @return array<string, string>
     */
    private function appointmentPlaceholders(Appointment $appointment): array
    {
        return [
            'patient' => $this->patientName($appointment->patient_id),
            'clinic' => $this->clinicName($appointment->clinic_id),
            'doctor' => ClinicEmployee::query()->withoutGlobalScopes()->whereKey($appointment->practitioner_id)->value('name') ?? 'your doctor',
            'when' => $appointment->scheduled_at->format('d M Y, h:i A'),
        ];
    }

    private function patientName(int $patientId): string
    {
        return Patient::query()->withoutGlobalScopes()->find($patientId)?->first_name ?: 'there';
    }

    private function clinicName(int $clinicId): string
    {
        return (string) Clinic::query()->whereKey($clinicId)->value('name');
    }

    private function branchOfEncounter(?int $encounterId): ?int
    {
        return $encounterId ? ClinicalEncounter::query()->withoutGlobalScopes()->whereKey($encounterId)->value('branch_id') : null;
    }
}
