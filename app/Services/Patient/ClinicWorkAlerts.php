<?php

namespace App\Services\Patient;

use App\LabCaseStatus;
use App\Models\Clinic;
use App\Models\ClinicalEncounter;
use App\Models\ClinicalOrderItem;
use App\Models\ClinicEmployee;
use App\Models\LabOrder;
use App\Models\PatientCase;
use App\Models\PatientVisit;
use App\Models\TreatmentPlan;
use App\Models\User;
use App\Services\Communication\PatientMessaging;
use App\Shared\Notification\DTO\NotificationActionDTO;
use App\Shared\Notification\DTO\SendNotificationDTO;
use App\Shared\Notification\Enums\NotificationCategory;
use App\Shared\Notification\Enums\NotificationPriority;
use App\Shared\Notification\Models\EnterpriseNotification;
use App\Shared\Notification\Services\NotificationEngineService;
use App\Support\ClinicClock;
use Carbon\CarbonImmutable;

/**
 * The clinic's own work that should not slip, told to the staff who own it
 * (in-app notifications, `NotificationEngineService`):
 *
 * - consultations left unsigned, to their doctor;
 * - results in but not reviewed for a day, to who ordered them;
 * - lab cases past their due date, to the requesting doctor and whoever sent them;
 * - dental treatment plans with no work for a month, to their doctor, with a
 *   "book your next visit" message to the patient (`dental_treatment_followup`);
 * - active cases with no visit for 45 days, to the lead practitioner, as a
 *   suggestion to recommend completion or book a follow-up. Nothing is ever
 *   closed by itself.
 *
 * Each alert carries an event key and is sent once per key, so the daily run
 * can repeat safely. Clinics turn it off with `communication_config.staff_alerts`.
 */
class ClinicWorkAlerts
{
    public const STALLED_PLAN_DAYS = 30;

    public const QUIET_CASE_DAYS = 45;

    private const PER_KIND = 300;

    public function __construct(
        protected NotificationEngineService $engine,
        protected PatientMessaging $messages,
    ) {}

    public function enabled(Clinic $clinic): bool
    {
        return (bool) ($clinic->communication_config['staff_alerts'] ?? true);
    }

    /**
     * Every alert for one clinic. Returns how many were sent, by kind.
     *
     * @return array{unsigned: int, results: int, lab: int, plans: int, cases: int}
     */
    public function run(Clinic $clinic): array
    {
        if (! $this->enabled($clinic)) {
            return ['unsigned' => 0, 'results' => 0, 'lab' => 0, 'plans' => 0, 'cases' => 0];
        }

        return [
            'unsigned' => $this->unsignedConsultations($clinic),
            'results' => $this->resultsAwaitingReview($clinic),
            'lab' => $this->overdueLabCases($clinic),
            'plans' => $this->stalledPlans($clinic),
            'cases' => $this->quietCases($clinic),
        ];
    }

    /** Consultations still open two hours after they started, one alert per doctor per day. */
    public function unsignedConsultations(Clinic $clinic): int
    {
        $today = ClinicClock::now()->toDateString();
        $open = ClinicalEncounter::query()->withoutGlobalScopes()->where('clinic_id', $clinic->id)
            ->whereNull('finalized_at')->whereNull('voided_at')->whereNotNull('doctor_id')
            ->where('started_at', '<=', now()->subHours(2))
            ->limit(self::PER_KIND * 5)->get(['id', 'doctor_id']);

        $sent = 0;
        foreach ($open->groupBy('doctor_id') as $doctorId => $encounters) {
            $user = $this->userOf((int) $doctorId);
            $count = $encounters->count();
            $sent += (int) $this->notify($user, $clinic, "unsigned:{$doctorId}:{$today}", new SendNotificationDTO(
                title: $count === 1 ? '1 consultation is not signed' : "{$count} consultations are not signed",
                message: 'Sign them so the visit, the bill and the patient record are complete.',
                category: NotificationCategory::DOCTOR,
                priority: NotificationPriority::MEDIUM,
                clinicId: $clinic->id,
                actions: [new NotificationActionDTO('Open my day', route('clinic.consultations.index'))],
            ));
        }

        return $sent;
    }

    /** Results entered more than a day ago and still not reviewed, to whoever ordered them. */
    public function resultsAwaitingReview(Clinic $clinic): int
    {
        $items = ClinicalOrderItem::query()->withoutGlobalScopes()->where('clinic_id', $clinic->id)
            ->where('status', ClinicalOrderItem::RESULTED)->where('resulted_at', '<=', now()->subDay())
            ->with(['order' => fn ($query) => $query->withoutGlobalScopes()->select(['id', 'ordered_by', 'patient_id', 'order_number'])])
            ->limit(self::PER_KIND)->get(['id', 'clinical_order_id', 'item_name']);

        $sent = 0;
        foreach ($items as $item) {
            $user = $item->order?->ordered_by ? User::query()->find($item->order->ordered_by) : null;
            $sent += (int) $this->notify($user, $clinic, "result_review:{$item->id}", new SendNotificationDTO(
                title: 'A result is waiting for your review',
                message: "{$item->item_name} ({$item->order?->order_number}) came in more than a day ago.",
                category: NotificationCategory::LAB,
                priority: NotificationPriority::HIGH,
                clinicId: $clinic->id,
                actions: [new NotificationActionDTO('Open the order', route('clinic.orders.show', $item->clinical_order_id))],
            ));
        }

        return $sent;
    }

    /** Lab cases past their due date and not back yet, to the requesting doctor and to whoever sent them. */
    public function overdueLabCases(Clinic $clinic): int
    {
        $waiting = [LabCaseStatus::Requested, LabCaseStatus::Accepted, LabCaseStatus::InProduction, LabCaseStatus::QualityCheck, LabCaseStatus::ReadyForDelivery];
        $cases = LabOrder::query()->withoutGlobalScopes()->where('clinic_id', $clinic->id)
            ->whereIn('status', array_map(fn (LabCaseStatus $status) => $status->value, $waiting))
            ->whereNotNull('delivery_due_date')->where('delivery_due_date', '<', ClinicClock::now()->toDateString())
            ->limit(self::PER_KIND)->get();

        $sent = 0;
        foreach ($cases as $case) {
            $due = CarbonImmutable::parse($case->delivery_due_date)->toDateString();
            $recipients = collect([$this->userOf((int) $case->requesting_doctor_id), $case->created_by ? User::query()->find($case->created_by) : null])->filter()->unique('id');
            foreach ($recipients as $user) {
                $sent += (int) $this->notify($user, $clinic, "lab_overdue:{$case->id}:{$due}", new SendNotificationDTO(
                    title: 'A lab case is overdue',
                    message: ($case->lab_order_number ?? 'Lab case')." was due on {$due} and is not back yet.",
                    category: NotificationCategory::LAB,
                    priority: NotificationPriority::MEDIUM,
                    clinicId: $clinic->id,
                    actions: [new NotificationActionDTO('Open the patient', route('clinic.patients.show', ['patient' => $case->patient_id, 'zone' => 'clinical']))],
                ));
            }
        }

        return $sent;
    }

    /** Dental plans under way with no work for a month: the doctor is nudged, the patient asked to book. Once a month per plan. */
    public function stalledPlans(Clinic $clinic): int
    {
        $since = now()->subDays(self::STALLED_PLAN_DAYS);
        $plans = TreatmentPlan::query()->withoutGlobalScopes()->where('clinic_id', $clinic->id)
            ->whereIn('status', [TreatmentPlan::ACCEPTED, TreatmentPlan::ACTIVE])
            ->where(fn ($query) => $query->whereNull('accepted_at')->orWhere('accepted_at', '<=', $since))
            ->whereDoesntHave('records', fn ($query) => $query->where('updated_at', '>', $since))
            ->limit(self::PER_KIND)->get();

        $month = ClinicClock::now()->format('Ym');
        $sent = 0;
        foreach ($plans as $plan) {
            $encounter = $plan->encounter_id ? ClinicalEncounter::query()->withoutGlobalScopes()->find($plan->encounter_id) : null;
            $user = $encounter?->doctor_id ? $this->userOf((int) $encounter->doctor_id) : null;
            $notified = $this->notify($user, $clinic, "plan_stalled:{$plan->id}:{$month}", new SendNotificationDTO(
                title: 'A treatment plan has stalled',
                message: "\"{$plan->title}\" has had no work for ".self::STALLED_PLAN_DAYS.' days. The patient has been asked to book their next visit.',
                category: NotificationCategory::DOCTOR,
                priority: NotificationPriority::NORMAL,
                clinicId: $clinic->id,
                actions: [new NotificationActionDTO('Open the patient', route('clinic.patients.show', ['patient' => $plan->patient_id, 'zone' => 'clinical']))],
            ));
            $this->messages->planStalled($plan, $month);
            $sent += (int) $notified;
        }

        return $sent;
    }

    /** Active cases with no visit for 45 days: the lead practitioner decides whether the case is complete. Never closed by itself. */
    public function quietCases(Clinic $clinic): int
    {
        $cutoff = ClinicClock::now()->subDays(self::QUIET_CASE_DAYS)->toDateString();
        $cases = PatientCase::query()->withoutGlobalScopes()->where('clinic_id', $clinic->id)->where('status', PatientCase::ACTIVE)
            ->whereNotNull('lead_practitioner_id')->where('opened_at', '<=', $cutoff)
            ->whereDoesntHave('visits', fn ($query) => $query->withoutGlobalScopes()->where('visit_date', '>', $cutoff))
            ->limit(self::PER_KIND)->get();

        $sent = 0;
        foreach ($cases as $case) {
            $last = PatientVisit::query()->withoutGlobalScopes()->where('case_id', $case->id)->max('visit_date');
            $sent += (int) $this->notify($this->userOf((int) $case->lead_practitioner_id), $clinic, "case_quiet:{$case->id}:".($last ?? 'none'), new SendNotificationDTO(
                title: 'Is this case complete?',
                message: "\"{$case->title}\" has had no visit for ".self::QUIET_CASE_DAYS.' days. Recommend completion, or book a follow-up.',
                category: NotificationCategory::DOCTOR,
                priority: NotificationPriority::NORMAL,
                clinicId: $clinic->id,
                actions: [new NotificationActionDTO('Open the case', route('clinic.patients.show', ['patient' => $case->patient_id, 'zone' => 'cases', 'case' => $case->id]))],
            ));
        }

        return $sent;
    }

    /** The signed-in user behind a clinic employee. */
    private function userOf(int $employeeId): ?User
    {
        $userId = $employeeId ? ClinicEmployee::query()->withoutGlobalScopes()->whereKey($employeeId)->value('user_id') : null;

        return $userId ? User::query()->find($userId) : null;
    }

    /** Send once per event key and person; false when there is nobody to tell or it was already sent. */
    public function notify(?User $user, Clinic $clinic, string $key, SendNotificationDTO $dto): bool
    {
        if (! $user) {
            return false;
        }
        $already = EnterpriseNotification::query()->where('notifiable_type', $user->getMorphClass())->where('notifiable_id', $user->getKey())
            ->where('clinic_id', $clinic->id)->where('data->metadata->event_key', $key)->exists();
        if ($already) {
            return false;
        }

        $this->engine->send($user, new SendNotificationDTO(
            title: $dto->title, message: $dto->message, category: $dto->category, priority: $dto->priority,
            clinicId: $dto->clinicId, actions: $dto->actions, metadata: ['event_key' => $key, 'type' => 'clinic_work_alert'],
        ));

        return true;
    }
}
