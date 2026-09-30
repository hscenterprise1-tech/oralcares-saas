<?php

namespace App\Services\Reception;

use App\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicEmployee;
use App\Models\ClinicQueue;
use App\Models\ClinicQueueItem;
use App\Models\Patient;
use App\Models\PatientTimelineEvent;
use App\Models\PatientVisit;
use App\QueueItemStatus;
use App\Services\Patient\CaseNumberGenerator;
use App\Services\QueueStateMachine;
use App\Support\ClinicClock;
use App\VisitPriority;
use App\VisitRegistrationType;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * One-tap check-in: puts a patient into today's queue at a branch.
 *
 * Check-ins at one branch are serialised on the branch row, so the day's
 * queue is created once and positions never collide. A patient already in
 * today's queue is refused, not queued twice. The case number for the
 * period is attached to the visit (issued if this is the first visit of the
 * period).
 */
class ReceptionCheckIn
{
    public function __construct(
        protected QueueStateMachine $machine,
        protected CaseNumberGenerator $caseNumbers,
        protected DoctorRoster $roster,
    ) {}

    public function checkIn(
        Clinic $clinic,
        Branch $branch,
        Patient $patient,
        ?Appointment $appointment = null,
        ?ClinicEmployee $doctor = null,
        VisitPriority $priority = VisitPriority::Normal,
        ?ClinicEmployee $performer = null,
    ): ClinicQueueItem {
        return DB::transaction(function () use ($clinic, $branch, $patient, $appointment, $doctor, $priority, $performer) {
            Branch::withoutGlobalScopes()->whereKey($branch->id)->lockForUpdate()->first();

            $now = ClinicClock::now();
            $queue = $this->todaysQueue($clinic, $branch, $now->toDateString());

            $existing = ClinicQueueItem::where('clinic_queue_id', $queue->id)
                ->whereHas('patientVisit', fn ($q) => $q->withoutGlobalScopes()->where('patient_id', $patient->id))
                ->whereIn('status', QueueStateMachine::ACTIVE)
                ->exists();

            if ($existing) {
                throw ValidationException::withMessages(['patient_id' => "{$patient->short_name} is already in today's queue."]);
            }

            if ($appointment) {
                $this->assertCheckable($appointment, $patient, $now->toDateString());
                $appointment->update(['status' => AppointmentStatus::CheckedIn, 'updated_by' => auth()->id()]);
                $doctor ??= ClinicEmployee::withoutGlobalScopes()->find($appointment->practitioner_id);
            }

            $doctor ??= $this->defaultDoctor($clinic, $branch, $patient, $queue);
            $visit = $this->todaysVisit($clinic, $branch, $patient, $doctor, $now->toDateString());
            $caseNumber = $this->caseNumbers->forVisit($clinic, $visit);

            $item = ClinicQueueItem::create([
                'clinic_queue_id' => $queue->id,
                'patient_visit_id' => $visit->id,
                'doctor_id' => $doctor?->id,
                'appointment_id' => $appointment?->id,
                'position' => (ClinicQueueItem::where('clinic_queue_id', $queue->id)->max('position') ?? 0) + 1,
                'priority' => $priority,
                'status' => QueueItemStatus::Waiting,
                'checked_in_at' => $now,
            ]);

            PatientTimelineEvent::create([
                'organization_id' => $clinic->organization_id,
                'clinic_id' => $clinic->id,
                'patient_id' => $patient->id,
                'actor_id' => auth()->id(),
                'event_type' => $appointment ? 'appointment_checked_in' : 'walk_in_registered',
                'context' => [
                    'case_number' => $caseNumber->case_number,
                    'doctor' => $doctor?->name,
                    'priority' => $priority->value,
                    'checked_in_by' => auth()->user()?->name ?? 'Reception',
                ],
            ]);

            $this->machine->admitted($item, $performer, $priority === VisitPriority::Emergency ? 'Checked in as an emergency' : null);

            return $item->load(['patientVisit.caseNumber', 'doctor']);
        });
    }

    /**
     * One reception queue per branch per clinic-local day. A day whose queue
     * `queue:close-day` has already closed takes no more check-ins.
     */
    private function todaysQueue(Clinic $clinic, Branch $branch, string $today): ClinicQueue
    {
        // `whereDate`, not firstOrCreate: a date cast is stored with a time
        // part on some drivers, so an equality match on "Y-m-d" can miss.
        $queue = ClinicQueue::withoutGlobalScopes()
            ->where('clinic_id', $clinic->id)
            ->where('branch_id', $branch->id)
            ->whereDate('queue_date', $today)
            ->first()
            ?? ClinicQueue::withoutGlobalScopes()->create([
                'clinic_id' => $clinic->id,
                'branch_id' => $branch->id,
                'queue_date' => $today,
                'name' => "Reception queue {$today}",
            ]);

        if ($queue->closed_at) {
            throw ValidationException::withMessages(['patient_id' => "Today's queue at {$branch->name} has closed. Check-ins open again tomorrow."]);
        }

        return $queue;
    }

    private function assertCheckable(Appointment $appointment, Patient $patient, string $today): void
    {
        $problem = match (true) {
            $appointment->patient_id !== $patient->id => 'This appointment belongs to a different patient.',
            $appointment->scheduled_at?->toDateString() !== $today => 'Only today\'s appointments can be checked in.',
            ! in_array($appointment->status, [AppointmentStatus::Scheduled, AppointmentStatus::Arrived], true) => 'This appointment has already been checked in, completed or cancelled.',
            default => null,
        };

        if ($problem) {
            throw ValidationException::withMessages(['appointment_id' => $problem]);
        }
    }

    /**
     * The patient's own assigned doctor if they are working today; otherwise
     * the working doctor with the fewest patients still waiting; otherwise
     * nobody ("any doctor").
     */
    private function defaultDoctor(Clinic $clinic, Branch $branch, Patient $patient, ClinicQueue $queue): ?ClinicEmployee
    {
        $working = $this->roster->forBranch($clinic, $branch)
            ->filter(fn (array $entry) => in_array($entry['shift'], ['on_shift', 'not_set'], true))
            ->pluck('employee');

        $assigned = $working->firstWhere('id', $patient->current_practitioner_id);
        if ($assigned) {
            return $assigned;
        }

        $load = ClinicQueueItem::where('clinic_queue_id', $queue->id)
            ->whereIn('status', [QueueItemStatus::Waiting, QueueItemStatus::Called])
            ->selectRaw('doctor_id, COUNT(*) as waiting')
            ->groupBy('doctor_id')
            ->pluck('waiting', 'doctor_id');

        return $working->sortBy(fn (ClinicEmployee $doctor) => [$load[$doctor->id] ?? 0, $doctor->name])->first();
    }

    private function todaysVisit(Clinic $clinic, Branch $branch, Patient $patient, ?ClinicEmployee $doctor, string $today): PatientVisit
    {
        $visit = PatientVisit::withoutGlobalScopes()
            ->where('clinic_id', $clinic->id)
            ->where('patient_id', $patient->id)
            ->whereDate('visit_date', $today)
            ->first();

        if ($visit) {
            $visit->update(['doctor_id' => $doctor?->id ?? $visit->doctor_id, 'queue_status' => QueueItemStatus::Waiting->value, 'visit_status' => 'checked_in']);

            return $visit;
        }

        return PatientVisit::withoutGlobalScopes()->create([
            'clinic_id' => $clinic->id,
            'patient_id' => $patient->id,
            'visit_date' => $today,
            'branch_id' => $branch->id,
            'doctor_id' => $doctor?->id,
            'registration_type' => PatientVisit::withoutGlobalScopes()->where('patient_id', $patient->id)->exists()
                ? VisitRegistrationType::Repeat->value
                : VisitRegistrationType::New->value,
            'queue_status' => QueueItemStatus::Waiting->value,
            'visit_status' => 'checked_in',
        ]);
    }
}
