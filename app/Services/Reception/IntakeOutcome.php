<?php

namespace App\Services\Reception;

use App\ClinicPermission;
use App\Models\Clinic;
use App\Models\ClinicQueueItem;
use App\Models\Patient;
use App\Models\User;
use App\Services\Clinical\ClinicalContext;
use App\Services\Patient\CaseNumberFormatter;
use App\Support\ClinicFrontend;

/**
 * What the desk shows after New Case or Repeat Case: who was registered or
 * checked in, for whom and what, where they are in the queue (only when the
 * clinic shows queue positions), and the next actions this member may take.
 * One shape for both flows.
 */
class IntakeOutcome
{
    public function __construct(protected PatientFlowConfigService $flow) {}

    /**
     * @return array<string, mixed>
     */
    public function present(Clinic $clinic, User $user, Patient $patient, ?ClinicQueueItem $item, ?ClinicalContext $context, ?VisitContext $visit, string $message, ?array $billing = null): array
    {
        $queue = $this->flow->queueShown($clinic);
        $sequence = $item?->patientVisit?->caseNumber?->sequence;
        $isTheDoctor = $item?->doctor && $item->doctor->user_id === $user->id;
        $profile = ClinicFrontend::available('clinic.patients.show') ? route('clinic.patients.show', $patient->id) : null;

        return [
            'message' => $message,
            'patient' => ['id' => $patient->id, 'name' => $patient->full_name, 'uhid' => $patient->uhid, 'url' => $profile],
            'check_in' => $item ? [
                'case_number' => $item->patientVisit?->caseNumber?->case_number,
                'case_short' => $sequence !== null ? CaseNumberFormatter::shortLabel($sequence) : null,
                'doctor' => $item->doctor?->name,
                'queue' => $queue,
                'position' => $queue ? $item->position : null,
                'priority' => ['value' => $item->priority->value, 'label' => $item->priority->label()],
                'reason' => $item->patientVisit?->visit_reason,
                'context' => $context?->withPractitioner($item->doctor)->present(),
            ] : null,
            // The consultation charge raised at check-in, and the receipt when money was taken. Null otherwise: no fake receipt.
            'billing' => $billing,
            // What the desk should tell the patient about paying.
            'payment_timing' => $this->flow->checkIn($clinic)['payment'],
            // Only to pages that are built (`ClinicFrontend`): an unbuilt page is never offered.
            'links' => [
                'patient' => $user->hasClinicPermission(ClinicPermission::PatientView) ? $profile : null,
                // The doctor checking a patient in for themselves goes straight to Today.
                'consultation' => $isTheDoctor && ClinicFrontend::available('clinic.consultations.index') ? route('clinic.consultations.index') : null,
            ],
        ];
    }
}
