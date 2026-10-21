<?php

namespace App\Services\Patient;

use App\Models\Clinic;
use App\Models\Patient;
use App\Models\PatientSpecialty;
use App\Models\PatientTimelineEvent;
use App\Models\User;
use App\Services\Security\SecurityAudit;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;

/**
 * The only writer of `patient_specialties`.
 *
 * An enrolment is made by an explicit act — registration (the specialties
 * chosen, or the clinic's only one), a clinician starting care in a specialty,
 * staff adding it, a treatment starting (later phase) or the foundation
 * backfill — never inferred when something is read. Ordinary activity in a
 * specialty only moves `last_activity_at` (`touch()`).
 */
class PatientSpecialties
{
    public function __construct(
        protected ClinicSpecialties $clinicSpecialties,
        protected SecurityAudit $audit,
    ) {}

    /**
     * @return Collection<int, PatientSpecialty>
     */
    public function for(Patient $patient): Collection
    {
        return PatientSpecialty::query()->withoutGlobalScopes()->where('clinic_id', $patient->clinic_id)
            ->where('patient_id', $patient->id)->with('clinicSpecialty')->orderBy('id')->get();
    }

    /**
     * Enrol the patient in one of the clinic's active specialties, or
     * reactivate a past enrolment. Idempotent.
     */
    public function enroll(Patient $patient, string $key, string $source, ?User $by = null, ?CarbonInterface $at = null): PatientSpecialty
    {
        if (! in_array($source, PatientSpecialty::SOURCES, true)) {
            throw new DomainException("Unknown enrolment source “{$source}”.");
        }
        $clinic = Clinic::query()->findOrFail($patient->clinic_id);
        $specialty = $this->clinicSpecialties->active($clinic, $key)
            ?? throw new DomainException("“{$key}” is not an active specialty of this clinic.");
        $at ??= now();

        $existing = $this->find($patient, $specialty->id);
        if ($existing) {
            if ($existing->status !== PatientSpecialty::ACTIVE) {
                $existing->update(['status' => PatientSpecialty::ACTIVE, 'ended_at' => null, 'last_activity_at' => $at]);
                $this->recorded($patient, $existing, 'patient.specialty.reactivated', $source, $by);
            }

            return $existing;
        }

        try {
            $enrolment = PatientSpecialty::query()->withoutGlobalScopes()->create([
                'clinic_id' => $patient->clinic_id,
                'patient_id' => $patient->id,
                'clinic_specialty_id' => $specialty->id,
                'status' => PatientSpecialty::ACTIVE,
                'source' => $source,
                'enrolled_at' => $at,
                'enrolled_by' => $by?->id,
                'last_activity_at' => $at,
            ]);
        } catch (UniqueConstraintViolationException) {
            // A concurrent enrolment of the same patient/specialty won.
            return $this->find($patient, $specialty->id);
        }

        $this->recorded($patient, $enrolment, 'patient.specialty.enrolled', $source, $by);

        return $enrolment;
    }

    /**
     * Enrol in the clinic's specialty if it is active; a no-op otherwise
     * (registration in a clinic that has not configured one yet).
     */
    public function enrollIfActive(Patient $patient, string $key, string $source, ?User $by = null, ?CarbonInterface $at = null): ?PatientSpecialty
    {
        $clinic = Clinic::query()->findOrFail($patient->clinic_id);

        return $this->clinicSpecialties->active($clinic, $key) ? $this->enroll($patient, $key, $source, $by, $at) : null;
    }

    /** Activity in a specialty the patient is enrolled in: only moves `last_activity_at` forward. */
    public function touch(int $clinicId, int $patientId, string $key, CarbonInterface $at): void
    {
        PatientSpecialty::query()->withoutGlobalScopes()
            ->where('clinic_id', $clinicId)->where('patient_id', $patientId)
            ->whereHas('clinicSpecialty', fn ($query) => $query->withoutGlobalScopes()->where('specialty_key', $key))
            ->where(fn ($query) => $query->whereNull('last_activity_at')->orWhere('last_activity_at', '<', $at))
            ->update(['last_activity_at' => $at]);
    }

    /** End an enrolment (kept as history). */
    public function end(PatientSpecialty $enrolment, string $status, ?User $by = null): void
    {
        if (! in_array($status, [PatientSpecialty::INACTIVE, PatientSpecialty::DISCHARGED], true)) {
            throw new DomainException("An enrolment ends as inactive or discharged, not “{$status}”.");
        }
        $enrolment->update(['status' => $status, 'ended_at' => now()]);
        $this->audit->record('patient.specialty.ended', $enrolment, ['status' => $status, 'patient_id' => $enrolment->patient_id]);
    }

    private function find(Patient $patient, int $clinicSpecialtyId): ?PatientSpecialty
    {
        return PatientSpecialty::query()->withoutGlobalScopes()->where('clinic_id', $patient->clinic_id)
            ->where('patient_id', $patient->id)->where('clinic_specialty_id', $clinicSpecialtyId)->first();
    }

    private function recorded(Patient $patient, PatientSpecialty $enrolment, string $event, string $source, ?User $by): void
    {
        $key = $enrolment->clinicSpecialty()->withoutGlobalScopes()->value('specialty_key');
        $this->audit->record($event, $enrolment, ['patient_id' => $patient->id, 'specialty' => $key, 'source' => $source]);

        if ($source !== 'backfill') {
            PatientTimelineEvent::query()->withoutGlobalScopes()->create([
                'organization_id' => $patient->organization_id,
                'clinic_id' => $patient->clinic_id,
                'patient_id' => $patient->id,
                'actor_id' => $by?->id,
                'event_type' => 'specialty_enrolled',
                'context' => ['specialty' => $key, 'source' => $source],
            ]);
        }
    }
}
