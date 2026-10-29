<?php

namespace App\Services\Patient;

use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\ClinicalEncounter;
use App\Models\ClinicEmployee;
use App\Models\Patient;
use App\Models\PatientFollowUp;
use App\Models\PatientTimelineEvent;
use App\Services\Communication\PatientMessaging;
use App\Support\ClinicClock;
use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The one writer of patient follow-ups. A signed consultation with a
 * follow-up date creates one; Patient 360 adds, reschedules, links to a
 * booking, completes or cancels them. The patient is reminded through
 * `PatientMessaging`; a rescheduled follow-up is reminded afresh.
 */
class FollowUps
{
    public function __construct(protected PatientMessaging $messages) {}

    public function fromEncounter(ClinicalEncounter $encounter): ?PatientFollowUp
    {
        if (! $encounter->follow_up_on) {
            return null;
        }

        $followUp = PatientFollowUp::query()->withoutGlobalScopes()->firstOrCreate(
            ['encounter_id' => $encounter->id],
            [
                'clinic_id' => $encounter->clinic_id,
                'branch_id' => $encounter->branch_id,
                'patient_id' => $encounter->patient_id,
                'practitioner_id' => $encounter->doctor_id,
                'due_on' => $encounter->follow_up_on,
                'reason' => $encounter->follow_up_note ? mb_substr($encounter->follow_up_note, 0, 500) : null,
                'status' => PatientFollowUp::DUE,
                'created_by' => auth()->id(),
            ],
        );

        if ($followUp->wasRecentlyCreated) {
            $this->timeline($followUp, 'follow_up_added');
        }
        DB::afterCommit(fn () => $this->messages->followUpDue($followUp));

        return $followUp;
    }

    public function add(Patient $patient, string $dueOn, ?string $reason, ?ClinicEmployee $practitioner, ?int $branchId): PatientFollowUp
    {
        app(PatientCareGuard::class)->assertCanReceiveCare($patient);
        if ($practitioner && $practitioner->clinic_id !== $patient->clinic_id) {
            throw new InvalidArgumentException('Choose a practitioner of this clinic.');
        }
        $this->assertFuture($dueOn);

        return DB::transaction(function () use ($patient, $dueOn, $reason, $practitioner, $branchId) {
            $followUp = PatientFollowUp::query()->create([
                'clinic_id' => $patient->clinic_id,
                'branch_id' => $branchId,
                'patient_id' => $patient->id,
                'practitioner_id' => $practitioner?->id,
                'due_on' => $dueOn,
                'reason' => $reason ? mb_substr(trim($reason), 0, 500) : null,
                'status' => PatientFollowUp::DUE,
                'created_by' => auth()->id(),
            ]);
            $this->timeline($followUp, 'follow_up_added');
            DB::afterCommit(fn () => $this->messages->followUpDue($followUp));

            return $followUp;
        });
    }

    public function reschedule(PatientFollowUp $followUp, string $dueOn): PatientFollowUp
    {
        $this->assertFuture($dueOn);

        return DB::transaction(function () use ($followUp, $dueOn) {
            $followUp = $this->lockOpen($followUp);
            $followUp->update(['due_on' => $dueOn]);
            $this->timeline($followUp, 'follow_up_rescheduled');
            DB::afterCommit(fn () => $this->messages->followUpDue($followUp->fresh()));

            return $followUp;
        });
    }

    /** The follow-up has a booking. */
    public function book(PatientFollowUp $followUp, Appointment $appointment): PatientFollowUp
    {
        return DB::transaction(function () use ($followUp, $appointment) {
            $followUp = $this->lockOpen($followUp);
            if ($appointment->clinic_id !== $followUp->clinic_id || $appointment->patient_id !== $followUp->patient_id) {
                throw new InvalidArgumentException('That appointment is not this patient\'s.');
            }
            $followUp->update(['status' => PatientFollowUp::BOOKED, 'appointment_id' => $appointment->id]);

            return $followUp;
        });
    }

    /** The team's priority and notes on an open follow-up. */
    public function annotate(PatientFollowUp $followUp, string $priority, ?string $notes): PatientFollowUp
    {
        if (! array_key_exists($priority, PatientFollowUp::PRIORITIES)) {
            throw new InvalidArgumentException('Choose normal, high or urgent.');
        }

        return DB::transaction(function () use ($followUp, $priority, $notes) {
            $followUp = $this->lockOpen($followUp);
            $followUp->update(['priority' => PatientFollowUp::PRIORITIES[$priority], 'notes' => $notes !== null && trim($notes) !== '' ? mb_substr(trim($notes), 0, 2000) : null]);

            return $followUp;
        });
    }

    public function complete(PatientFollowUp $followUp): PatientFollowUp
    {
        return DB::transaction(function () use ($followUp) {
            $followUp = $this->lockOpen($followUp);
            $followUp->update(['status' => PatientFollowUp::COMPLETED, 'completed_at' => now()]);
            $this->timeline($followUp, 'follow_up_completed');
            $this->messages->withdrawFollowUp($followUp);

            return $followUp;
        });
    }

    public function cancel(PatientFollowUp $followUp): PatientFollowUp
    {
        return DB::transaction(function () use ($followUp) {
            $followUp = $this->lockOpen($followUp);
            $followUp->update(['status' => PatientFollowUp::CANCELLED]);
            $this->timeline($followUp, 'follow_up_cancelled');
            $this->messages->withdrawFollowUp($followUp);

            return $followUp;
        });
    }

    private function timeline(PatientFollowUp $followUp, string $event): void
    {
        PatientTimelineEvent::create([
            'organization_id' => Clinic::query()->whereKey($followUp->clinic_id)->value('organization_id'),
            'clinic_id' => $followUp->clinic_id,
            'patient_id' => $followUp->patient_id,
            'actor_id' => auth()->id(),
            'event_type' => $event,
            'context' => ['follow_up_id' => $followUp->id, 'due_on' => $followUp->due_on?->format('d M Y'), 'reason' => $followUp->reason],
        ]);
    }

    private function lockOpen(PatientFollowUp $followUp): PatientFollowUp
    {
        $locked = PatientFollowUp::query()->withoutGlobalScopes()->whereKey($followUp->id)->lockForUpdate()->firstOrFail();
        if (! $locked->isOpen()) {
            throw new DomainException('This follow-up is already closed.');
        }

        return $locked;
    }

    private function assertFuture(string $dueOn): void
    {
        if ($dueOn < ClinicClock::today()->toDateString()) {
            throw new InvalidArgumentException('A follow-up cannot be due in the past.');
        }
    }
}
