<?php

namespace App\Services\Patient;

use App\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\ClinicalEncounter;
use App\Models\ClinicEmployee;
use App\Models\ClinicQueueItem;
use App\Models\Patient;
use App\Models\PatientDoctorAssignment;
use App\Models\PatientFollowUp;
use App\Models\PatientTimelineEvent;
use App\Models\PatientVisit;
use App\Models\TreatmentPlan;
use App\PatientStatus;
use App\Services\Appointments\AppointmentBookingService;
use App\Services\Appointments\AppointmentSettingsService;
use App\Support\ClinicClock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The patient-care steps the clinic no longer has to remember:
 *
 * - **Missed appointments.** A booking still waiting for the patient
 *   (`AppointmentStatus::awaitingArrival`) once the clinic's
 *   `no_show_after_minutes` has passed, with no check-in for it, is marked a
 *   no-show through `AppointmentBookingService::setStatus` (the same step as
 *   the calendar's, with its timeline entry). The missed-appointment message
 *   follows from that change (`PatientMessageObserver`). Clinics turn this
 *   off with `appointment_config.auto_no_show`.
 * - **Dental recall.** When a dental treatment plan is completed, a recall
 *   follow-up is booked `recall_months` later (`communication_config`,
 *   default 6; 0 turns it off), unless the patient already has an open
 *   follow-up after today. Its reminders are the follow-up's own.
 *
 * Every step is idempotent and leaves care alone for patients who may not
 * receive it (merged, deleted).
 */
class PatientAutomation
{
    public const RECALL_REASON = 'Routine dental check-up (recall)';

    public const ASSIGN_REASON = 'Assigned automatically: doctor of the first consultation';

    /** How far back a missed booking is still marked (older ones were the desk's to handle). */
    private const MISSED_LOOKBACK_DAYS = 7;

    private const PER_RUN = 500;

    public function __construct(
        protected AppointmentSettingsService $appointmentSettings,
        protected AppointmentBookingService $bookings,
        protected FollowUps $followUps,
    ) {}

    /**
     * Mark this clinic's missed bookings as no-shows. Returns how many.
     */
    public function markMissedAppointments(Clinic $clinic): int
    {
        $settings = $this->appointmentSettings->resolve($clinic);
        if (! $settings['auto_no_show']) {
            return 0;
        }

        $now = ClinicClock::now();
        $cutoff = $now->subMinutes($settings['no_show_after_minutes']);
        $checkedIn = fn ($query) => $query->select('appointment_id')->from((new ClinicQueueItem)->getTable())->whereNotNull('appointment_id');
        $visited = fn ($query) => $query->select('appointment_id')->from((new PatientVisit)->getTable())->whereNotNull('appointment_id');

        $missed = Appointment::query()->withoutGlobalScopes()
            ->where('clinic_id', $clinic->id)
            ->whereNotNull('patient_id')
            ->whereIn('status', array_map(fn (AppointmentStatus $status) => $status->value, AppointmentStatus::awaitingArrival()))
            ->where('scheduled_at', '<=', $cutoff)
            ->where('scheduled_at', '>=', $now->subDays(self::MISSED_LOOKBACK_DAYS)->startOfDay())
            ->whereNotIn('id', $checkedIn)
            ->whereNotIn('id', $visited)
            ->orderBy('scheduled_at')
            ->limit(self::PER_RUN)
            ->get();

        $marked = 0;
        foreach ($missed as $appointment) {
            try {
                $this->bookings->setStatus($clinic, $appointment, AppointmentStatus::NoShow, null);
                $marked++;
            } catch (Throwable $exception) {
                // One booking that cannot move (changed meanwhile) never stops the rest.
                Log::info('Missed appointment not marked.', ['clinic_id' => $clinic->id, 'appointment_id' => $appointment->id, 'reason' => $exception->getMessage()]);
            }
        }

        return $marked;
    }

    /**
     * A patient with no assigned doctor is assigned the doctor of their first
     * consultation, kept in the assignment history and on the timeline like
     * a Transfer (`PatientController::transfer`). Returns whether it assigned.
     */
    public function assignFirstDoctor(ClinicalEncounter $encounter): bool
    {
        $clinic = Clinic::query()->find($encounter->clinic_id);
        if (! $clinic || ! $this->assignsFirstDoctor($clinic) || ! $encounter->doctor_id) {
            return false;
        }

        return (bool) DB::transaction(function () use ($clinic, $encounter) {
            $patient = Patient::query()->withoutGlobalScopes()->where('clinic_id', $clinic->id)->lockForUpdate()->find($encounter->patient_id);
            if (! $patient || $patient->current_practitioner_id !== null || ! in_array($patient->status?->value, PatientStatus::working(), true)) {
                return false;
            }
            $doctor = ClinicEmployee::query()->withoutGlobalScopes()->where('clinic_id', $clinic->id)->find($encounter->doctor_id);
            // The history needs who assigned: the person who opened the consultation, else the doctor.
            $assignedBy = auth()->id() ?? $doctor?->user_id;
            if (! $doctor || ! $assignedBy) {
                return false;
            }

            $patient->forceFill(['current_practitioner_id' => $doctor->id])->save();
            PatientDoctorAssignment::create([
                'patient_id' => $patient->id, 'clinic_id' => $clinic->id, 'from_practitioner_id' => null,
                'to_practitioner_id' => $doctor->id, 'assigned_by' => $assignedBy, 'reason' => self::ASSIGN_REASON,
            ]);
            PatientTimelineEvent::create([
                'organization_id' => $clinic->organization_id, 'clinic_id' => $clinic->id, 'patient_id' => $patient->id, 'actor_id' => $assignedBy,
                'event_type' => 'doctor_transferred',
                'context' => ['from_practitioner' => null, 'to_practitioner' => $doctor->name, 'reason' => self::ASSIGN_REASON, 'transferred_by' => 'OralCares'],
            ]);

            return true;
        });
    }

    /**
     * Off unless the clinic turns it on (`communication_config.auto_assign_doctor`):
     * an assigned doctor is the only practitioner who may write for the
     * patient (`PatientPolicy::writeClinicalData`), so other doctors would
     * lose that.
     */
    public function assignsFirstDoctor(Clinic $clinic): bool
    {
        return (bool) ($clinic->communication_config['auto_assign_doctor'] ?? false);
    }

    /** Months after a completed dental plan that the recall falls due; 0 when the clinic turned recalls off. */
    public function recallMonths(Clinic $clinic): int
    {
        $months = $clinic->communication_config['recall_months'] ?? 6;

        return is_numeric($months) ? max(0, min(36, (int) $months)) : 6;
    }

    /**
     * Book the recall for a completed dental plan. Returns the follow-up, or
     * null when none was needed.
     */
    public function scheduleRecall(TreatmentPlan $plan): ?PatientFollowUp
    {
        $clinic = Clinic::query()->find($plan->clinic_id);
        $patient = Patient::query()->withoutGlobalScopes()->where('clinic_id', $plan->clinic_id)->find($plan->patient_id);
        if (! $clinic || ! $patient || ($months = $this->recallMonths($clinic)) === 0) {
            return null;
        }

        $today = ClinicClock::now()->toDateString();
        $alreadyComing = PatientFollowUp::query()->withoutGlobalScopes()->where('clinic_id', $plan->clinic_id)->where('patient_id', $plan->patient_id)
            ->whereIn('status', [PatientFollowUp::DUE, PatientFollowUp::BOOKED])->where('due_on', '>', $today)->exists();
        if ($alreadyComing) {
            return null;
        }

        try {
            // The doctor and branch of the consultation the plan was made in.
            $encounter = $plan->encounter_id ? ClinicalEncounter::query()->withoutGlobalScopes()->where('clinic_id', $plan->clinic_id)->find($plan->encounter_id) : null;
            $practitioner = $encounter?->doctor_id ? ClinicEmployee::query()->withoutGlobalScopes()->where('clinic_id', $plan->clinic_id)->find($encounter->doctor_id) : null;

            return $this->followUps->add($patient, ClinicClock::now()->addMonthsNoOverflow($months)->toDateString(), self::RECALL_REASON, $practitioner, $encounter?->branch_id);
        } catch (Throwable $exception) {
            // A patient who may not receive care (deleted) gets no recall.
            Log::info('Dental recall not booked.', ['clinic_id' => $plan->clinic_id, 'plan_id' => $plan->id, 'reason' => $exception->getMessage()]);

            return null;
        }
    }
}
