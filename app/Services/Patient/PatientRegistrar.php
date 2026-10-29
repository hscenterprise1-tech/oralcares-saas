<?php

namespace App\Services\Patient;

use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicEmployee;
use App\Models\ClinicQueueItem;
use App\Models\Patient;
use App\Models\User;
use App\Services\MedicalHistoryService;
use App\Services\PatientDuplicateDetectionService;
use App\Services\Reception\ReceptionCheckIn;
use App\Services\Reception\VisitContext;
use App\Support\Clinical\DefaultClinicalCatalogue;
use App\VisitPriority;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Registering a patient: the one domain workflow behind every way in — the
 * full New Patient page and Reception's New Case dialog alike.
 *
 * 1. `gate()`: possible existing patients block registration unless every
 *    match is acknowledged with a reason (the audited override).
 * 2. `prepareClinical()`: conditions, allergies and medications checked
 *    against what the clinic has enabled, before anything is written.
 * 3. `register()`: in one transaction, the patient, profile, specialties,
 *    branch activity, consent, clinical entries, safety alerts, the override
 *    audit, and (when asked) today's check-in through `ReceptionCheckIn`, so
 *    a new patient is never half-registered or registered but not queued.
 */
class PatientRegistrar
{
    public function __construct(
        private PatientDuplicateDetectionService $duplicates,
        private PatientRegistrationService $registration,
        private PatientClinicalEntries $clinicalEntries,
        private MedicalHistoryService $medicalHistory,
        private ReceptionCheckIn $checkIn,
    ) {}

    /**
     * Why registration cannot go ahead yet, or null when it can.
     *
     * @param  array<string, ?string>  $criteria
     * @param  list<int>  $acknowledgedIds
     * @return array{reason: 'duplicates'|'reason_required'|'overridden', matches: Collection<int, Patient>}|null
     */
    public function gate(Clinic $clinic, array $criteria, array $acknowledgedIds, ?string $overrideReason): ?array
    {
        $matches = $this->duplicates->detectDuplicates($clinic, $criteria);
        if ($matches->isEmpty()) {
            return null;
        }
        if ($matches->pluck('id')->diff(array_map('intval', $acknowledgedIds))->isNotEmpty()) {
            return ['reason' => 'duplicates', 'matches' => $matches];
        }
        if (mb_strlen(trim((string) $overrideReason)) < 3) {
            return ['reason' => 'reason_required', 'matches' => $matches];
        }

        return ['reason' => 'overridden', 'matches' => $matches];
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $input  kind field => entries
     * @param  list<string>  $specialtyKeys
     * @return array<string, list<array<string, mixed>>> kind => prepared entries
     */
    public function prepareClinical(Clinic $clinic, array $input, array $specialtyKeys): array
    {
        $fields = [
            DefaultClinicalCatalogue::KIND_CONDITION => 'medical_conditions',
            DefaultClinicalCatalogue::KIND_ALLERGY => 'allergies',
            DefaultClinicalCatalogue::KIND_MEDICATION => 'medications',
            DefaultClinicalCatalogue::KIND_SURGERY => 'surgical_history',
            DefaultClinicalCatalogue::KIND_FAMILY_HISTORY => 'family_history',
        ];

        $prepared = [];
        foreach ($fields as $kind => $field) {
            if (! empty($input[$field])) {
                $prepared[$kind] = $this->clinicalEntries->prepare($clinic->id, $kind, $input[$field], $field, $specialtyKeys);
            }
        }

        return $prepared;
    }

    /**
     * @param  array<string, mixed>  $patientData
     * @param  array<string, mixed>  $profileData
     * @param  array<string, mixed>  $visitData  department/doctor/fee/notes for a first visit without check-in
     * @param  list<string>  $specialtyKeys
     * @param  array<string, list<array<string, mixed>>>  $clinical  from `prepareClinical()`
     * @param  list<string>  $manualAlerts
     * @param  list<int>  $overriddenMatchIds
     * @param  array{doctor: ?ClinicEmployee, priority: VisitPriority, context: VisitContext, performer?: ?ClinicEmployee, appointment?: ?Appointment}|null  $checkIn  with a prospect booking (`appointment`), the booking becomes this patient's and is checked in
     * @param  list<array{label: string, severity: string}>  $alertFlags  operational/safety flags chosen from the catalogue
     * @param  ?string  $allergyStatus  "none_known" when reception confirmed no known allergies
     * @return array{patient: Patient, queue_item: ?ClinicQueueItem}
     */
    public function register(
        Clinic $clinic,
        ?Branch $branch,
        ?User $by,
        array $patientData,
        array $profileData,
        array $visitData,
        array $specialtyKeys,
        array $clinical,
        array $manualAlerts,
        bool $communicationConsent,
        ?string $consentMethod,
        array $overriddenMatchIds,
        ?string $overrideReason,
        ?array $checkIn = null,
        array $alertFlags = [],
        ?string $allergyStatus = null,
    ): array {
        return DB::transaction(function () use ($clinic, $branch, $by, $patientData, $profileData, $visitData, $specialtyKeys, $clinical, $manualAlerts, $communicationConsent, $consentMethod, $overriddenMatchIds, $overrideReason, $checkIn, $alertFlags, $allergyStatus) {
            // A doctor chosen at registration without checking in still
            // means a first visit (the assignment lives on the visit).
            if ($checkIn === null && ! empty($visitData['doctor_id']) && $branch) {
                $patient = $this->registration->registerNewPatient(patientData: $patientData, visitData: $visitData, clinic: $clinic, branch: $branch)->patient;
                $profileData = array_filter($profileData);
                if ($profileData !== []) {
                    $patient->profile()->create($profileData + ['organization_id' => $clinic->organization_id]);
                }
            } else {
                $patient = $this->registration->createPatientOnly(patientData: $patientData, profileData: $profileData, clinic: $clinic);
            }

            foreach ($clinical as $kind => $entries) {
                $this->clinicalEntries->add($patient, $kind, $entries, 'registration', $by);
            }
            $this->medicalHistory->syncManualAlerts($patient, array_values(array_filter($manualAlerts)));
            foreach ($alertFlags as $flag) {
                $patient->alerts()->create(['clinic_id' => $patient->clinic_id, 'type' => 'flag', 'description' => $flag['label'], 'severity' => $flag['severity'], 'created_by' => $by?->id]);
            }
            if ($allergyStatus !== null || isset($clinical[DefaultClinicalCatalogue::KIND_ALLERGY])) {
                $patient->profile()->updateOrCreate([], ['clinic_id' => $patient->clinic_id, 'organization_id' => $clinic->organization_id, 'allergy_status' => isset($clinical[DefaultClinicalCatalogue::KIND_ALLERGY]) ? 'recorded' : $allergyStatus]);
            }

            $this->registration->completeRegistration(
                patient: $patient,
                clinic: $clinic,
                branch: $branch,
                by: $by,
                specialtyKeys: $specialtyKeys,
                communicationConsent: $communicationConsent,
                consentMethod: $consentMethod,
                overriddenMatchIds: $overriddenMatchIds,
                overrideReason: $overrideReason,
            );

            $queueItem = null;
            if ($checkIn !== null && $branch) {
                $appointment = $checkIn['appointment'] ?? null;
                if ($appointment) {
                    // A booking made before the patient existed: theirs now, once.
                    $locked = Appointment::query()->withoutGlobalScopes()->whereKey($appointment->id)->lockForUpdate()->firstOrFail();
                    if ($locked->patient_id !== null) {
                        throw ValidationException::withMessages(['appointment_id' => 'That booking has already been registered to a patient.']);
                    }
                    $locked->update(['patient_id' => $patient->id]);
                    $appointment = $locked;
                }
                $queueItem = $this->checkIn->checkIn(
                    $clinic,
                    $branch,
                    $patient,
                    appointment: $appointment,
                    doctor: $checkIn['doctor'],
                    priority: $checkIn['priority'],
                    performer: $checkIn['performer'] ?? null,
                    context: $checkIn['context'],
                );
            }

            return ['patient' => $patient, 'queue_item' => $queueItem];
        });
    }
}
