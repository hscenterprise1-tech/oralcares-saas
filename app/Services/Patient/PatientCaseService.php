<?php

namespace App\Services\Patient;

use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicEmployee;
use App\Models\Patient;
use App\Models\PatientCase;
use App\Models\PatientVisit;
use App\Services\Reception\VisitContext;
use App\Support\ClinicClock;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Service orchestrating PatientCase lifecycle, case number generation,
 * and linking to PatientVisit records during New Case and Repeat Case reception workflows.
 */
class PatientCaseService
{
    /**
     * Generate the next unique case number for a clinic and year.
     * Guaranteed collision-free via transactional row locking.
     */
    public function generateCaseNumber(Clinic $clinic): string
    {
        $year = ClinicClock::now()->format('Y');
        $prefix = "CASE-{$year}-";

        // Lock existing cases for this clinic to prevent race conditions during sequence allocation
        $maxCase = PatientCase::withoutGlobalScopes()
            ->where('clinic_id', $clinic->id)
            ->where('case_number', 'like', "{$prefix}%")
            ->orderByDesc('id')
            ->lockForUpdate()
            ->value('case_number');

        $nextSeq = 1;
        if ($maxCase && preg_match('/CASE-\d{4}-(\d+)/', $maxCase, $matches)) {
            $nextSeq = ((int) $matches[1]) + 1;
        }

        $candidate = sprintf('CASE-%s-%06d', $year, $nextSeq);

        while (PatientCase::withoutGlobalScopes()->where('clinic_id', $clinic->id)->where('case_number', $candidate)->exists()) {
            $nextSeq++;
            $candidate = sprintf('CASE-%s-%06d', $year, $nextSeq);
        }

        return $candidate;
    }

    /**
     * Create a new PatientCase.
     */
    public function createCase(
        Clinic $clinic,
        Branch $branch,
        Patient $patient,
        ClinicEmployee $doctor,
        string $specialty,
        string $title,
        ?string $chiefComplaint = null,
        ?string $caseNumber = null,
    ): PatientCase {
        $caseNumber ??= $this->generateCaseNumber($clinic);
        $chiefComplaint = trim((string) ($chiefComplaint ?: $title));

        return PatientCase::create([
            'clinic_id' => $clinic->id,
            'branch_id' => $branch->id,
            'patient_id' => $patient->id,
            'case_number' => $caseNumber,
            'specialty' => mb_substr($specialty, 0, 32),
            'title' => mb_substr($title, 0, 150),
            'chief_complaint' => $chiefComplaint !== '' ? $chiefComplaint : 'Initial Consultation',
            'lead_practitioner_id' => $doctor->id,
            'status' => PatientCase::ACTIVE,
            'opened_at' => ClinicClock::now(),
        ]);
    }

    /**
     * Retrieve all active cases for a patient.
     *
     * @return Collection<int, PatientCase>
     */
    public function getActiveCases(Clinic $clinic, Patient $patient): Collection
    {
        return PatientCase::withoutGlobalScopes()
            ->where('clinic_id', $clinic->id)
            ->where('patient_id', $patient->id)
            ->whereIn('status', PatientCase::OPEN)
            ->with(['leadPractitioner', 'branch'])
            ->orderByDesc('opened_at')
            ->get();
    }

    /**
     * Resolve or create the PatientCase appropriate for a visit.
     *
     * An existing case keeps its lead practitioner. A new case is opened only
     * when the visit's practitioner may lead it (`eligibleLead`); otherwise no
     * case is opened and the visit stays unlinked (`patient_visits.case_id`
     * null) rather than naming someone who is not the patient's doctor.
     *
     * @throws ValidationException when the chosen case is not this patient's
     */
    public function resolveCaseForVisit(
        Clinic $clinic,
        Branch $branch,
        Patient $patient,
        PatientVisit $visit,
        ?ClinicEmployee $doctor,
        ?VisitContext $context = null,
        ?int $caseId = null,
        bool $isNewCase = false,
    ): ?PatientCase {
        // 1. Explicit case ID provided (from Repeat Case selector)
        if ($caseId !== null) {
            $case = PatientCase::withoutGlobalScopes()
                ->where('clinic_id', $clinic->id)
                ->where('patient_id', $patient->id)
                ->find($caseId);

            if (! $case) {
                throw ValidationException::withMessages(['case_id' => 'That case is not one of this patient\'s cases.']);
            }

            return $case;
        }

        $specialty = $context?->specialtyKey ?: ($visit->clinic_specialty_id ? (string) $visit->clinic_specialty_id : 'general');
        $title = $context?->reason ?: ($context?->specialtyKey ? ucfirst(str_replace('_', ' ', $context->specialtyKey)).' Consultation' : 'General Care Episode');
        $complaint = $context?->reason ?: 'Consultation';

        // 2. If not explicitly requesting a new case, search for an existing active case matching the specialty
        if (! $isNewCase) {
            $activeCase = PatientCase::withoutGlobalScopes()
                ->where('clinic_id', $clinic->id)
                ->where('patient_id', $patient->id)
                ->whereIn('status', PatientCase::OPEN)
                ->when($context?->specialtyKey, fn ($q, $spec) => $q->where('specialty', $spec))
                ->latest('id')
                ->first();

            if ($activeCase) {
                return $activeCase;
            }
        }

        // 3. Otherwise, open a new PatientCase led by the visit's own practitioner.
        // `lead_practitioner_id` cannot be empty, so with no eligible lead no case is opened.
        $leadDoctor = $this->eligibleLead($clinic, $branch, $doctor, $context?->specialtyKey);
        if ($leadDoctor === null) {
            return null;
        }

        return $this->createCase(
            clinic: $clinic,
            branch: $branch,
            patient: $patient,
            doctor: $leadDoctor,
            specialty: $specialty,
            title: $title,
            chiefComplaint: $complaint,
        );
    }

    /**
     * The visit's practitioner, when they may lead a case here: an active
     * practitioner of this clinic, working at this branch (placed here, or
     * not tied to a branch, as `DoctorRoster` reads it), who practises the
     * visit's specialty when one is known. No one is ever put in their place.
     */
    public function eligibleLead(Clinic $clinic, Branch $branch, ?ClinicEmployee $doctor, ?string $specialty = null): ?ClinicEmployee
    {
        if ($doctor === null) {
            return null;
        }

        $employee = ClinicEmployee::withoutGlobalScopes()
            ->where('clinic_id', $clinic->id)
            ->where('status', 'active')
            ->with(['practitionerProfile' => fn ($query) => $query->withoutGlobalScopes(), 'branches:id'])
            ->find($doctor->id);

        if ($employee?->practitionerProfile === null || (int) $branch->clinic_id !== (int) $clinic->id) {
            return null;
        }
        if ($employee->branches->isNotEmpty() && ! $employee->branches->contains('id', $branch->id)) {
            return null;
        }

        $practised = $employee->practitionerProfile->specialtyKeys();
        if ($specialty !== null && $practised !== [] && ! in_array($specialty, $practised, true)) {
            return null;
        }

        return $employee;
    }
}
