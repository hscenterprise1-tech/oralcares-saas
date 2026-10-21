<?php

namespace App\Services\Physio;

use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicalEncounter;
use App\Models\ClinicEmployee;
use App\Models\ClinicRoom;
use App\Models\Patient;
use App\Models\PatientSessionPackage;
use App\Models\PatientTimelineEvent;
use App\Models\PatientTreatment;
use App\Models\PhysioAssessment;
use App\Models\PhysioCarePlan;
use App\Models\PhysioExercise;
use App\Models\PhysioSession;
use App\Models\PhysioSessionExercise;
use App\Models\User;
use App\Services\Security\SecurityAudit;
use App\Services\Treatment\LegacyTreatmentMirror;
use App\Services\Treatment\PatientTreatments;
use App\Services\Treatment\TreatmentAccess;
use App\Support\Specialty\SpecialtyRegistry;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The physiotherapy writer. The patient treatment is the care context: every
 * plan, session and package below carries an explicit `patient_treatment_id`,
 * and a patient may hold any number of treatments at once (Knee, Back,
 * Shoulder…), each with its own plan, sessions and packages.
 *
 * - Assessment: on its consultation (unchanged).
 * - Plan of care: revisions per treatment. One active revision per treatment
 *   (also enforced by a unique index); a new revision supersedes the old,
 *   which is never altered.
 * - Session: one per treatment per consultation, so one visit can carry
 *   several treatments' sessions. Draft → completed (numbered within its
 *   treatment; the only moment a package is consumed) → reversed (the
 *   consumption given back in the ledger). What was done is recorded per
 *   exercise against the plan, never by changing the plan.
 * - Packages: `SessionPackages` (sold per treatment, ledger-backed).
 *
 * Treatment work is authorised here through `TreatmentAccess` (permission,
 * specialty practised, branch, patient access), not left to the screen.
 */
class PhysioRecord
{
    public function __construct(
        protected LegacyTreatmentMirror $mirror,
        protected PatientTreatments $treatments,
        protected SessionPackages $packages,
        protected TreatmentAccess $access,
        protected SpecialtyRegistry $specialties,
        protected SecurityAudit $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function saveAssessment(ClinicalEncounter $encounter, array $data, ClinicEmployee $therapist): PhysioAssessment
    {
        return DB::transaction(function () use ($encounter, $data, $therapist) {
            $encounter = $this->lockOpen($encounter);

            $assessment = PhysioAssessment::query()->withoutGlobalScopes()->where('encounter_id', $encounter->id)->first()
                ?? new PhysioAssessment(['clinic_id' => $encounter->clinic_id, 'patient_id' => $encounter->patient_id, 'encounter_id' => $encounter->id]);

            $first = ! $assessment->exists;
            $earlier = PhysioAssessment::query()->withoutGlobalScopes()->where('clinic_id', $encounter->clinic_id)
                ->where('patient_id', $encounter->patient_id)->where('encounter_id', '!=', $encounter->id)->exists();

            $assessment->fill([
                // The first assessment of a patient is the initial one; later ones are reassessments.
                'kind' => $assessment->kind ?? ($earlier ? PhysioAssessment::REASSESSMENT : PhysioAssessment::INITIAL),
                'pain_location' => $this->text($data['pain_location'] ?? null, 255),
                'onset' => $this->text($data['onset'] ?? null, 255),
                'aggravating_factors' => $this->text($data['aggravating_factors'] ?? null),
                'relieving_factors' => $this->text($data['relieving_factors'] ?? null),
                'objective_findings' => $this->text($data['objective_findings'] ?? null),
                'gait' => $this->text($data['gait'] ?? null),
                'special_tests' => $this->rows($data['special_tests'] ?? [], ['test', 'side', 'result', 'note']),
                'clinical_impression' => $this->text($data['clinical_impression'] ?? null),
                'diagnosis' => $this->text($data['diagnosis'] ?? null, 500),
                'presenting_problem' => $this->text($data['presenting_problem'] ?? null),
                'body_chart' => $this->rows($data['body_chart'] ?? [], ['region', 'side', 'pain_type', 'note']),
                'pain_at_rest' => $this->pain($data['pain_at_rest'] ?? null),
                'pain_on_activity' => $this->pain($data['pain_on_activity'] ?? null),
                'range_of_motion' => $this->rows($data['range_of_motion'] ?? [], ['joint', 'movement', 'side', 'active', 'passive', 'normal']),
                'strength' => $this->strength($data['strength'] ?? []),
                'posture' => $this->text($data['posture'] ?? null),
                'functional_measures' => $this->measures($data['functional_measures'] ?? []),
                'functional_notes' => $this->text($data['functional_notes'] ?? null),
                'goals' => $this->text($data['goals'] ?? null),
                'assessed_by' => $therapist->id,
            ])->save();

            if ($first) {
                $this->timeline($assessment->clinic_id, $assessment->patient_id, 'physio_assessment_recorded', [
                    'kind' => $assessment->kind, 'encounter_number' => $encounter->encounter_number,
                    'pain_on_activity' => $assessment->pain_on_activity,
                ]);
            }

            return $assessment;
        });
    }

    // ---------------------------------------------------------------- Plans of care

    /**
     * The first plan of care of a treatment (revision 1).
     *
     * @param  array{title?: ?string, goals?: ?string, interventions?: list<string>, planned_sessions?: ?int, frequency?: ?string, starts_on?: ?string, effective_from?: ?string}  $data
     * @param  list<array<string, mixed>>  $exercises
     */
    public function startPlan(PatientTreatment $treatment, array $data, array $exercises, ClinicEmployee $therapist, User $by, ?ClinicalEncounter $encounter = null): PhysioCarePlan
    {
        $this->assertTreatmentWork($treatment, 'exercise_plan', $therapist, $by);
        if ($encounter && ($encounter->patient_id !== $treatment->patient_id || $encounter->clinic_id !== $treatment->clinic_id)) {
            throw new InvalidArgumentException('The consultation is for a different patient.');
        }

        return DB::transaction(function () use ($treatment, $data, $exercises, $therapist, $encounter) {
            $treatment = $this->lockOpenTreatment($treatment);
            if ($this->activePlanQuery($treatment)->exists()) {
                throw new DomainException('This treatment already has an active plan of care. Revise it instead.');
            }

            $plan = PhysioCarePlan::query()->withoutGlobalScopes()->create([
                'clinic_id' => $treatment->clinic_id,
                'patient_id' => $treatment->patient_id,
                'patient_treatment_id' => $treatment->id,
                'revision' => 1,
                'encounter_id' => $encounter?->id,
                'assessment_id' => $encounter ? PhysioAssessment::query()->withoutGlobalScopes()->where('encounter_id', $encounter->id)->value('id') : null,
                ...$this->planFields($data, null),
                'status' => PhysioCarePlan::ACTIVE,
                'created_by' => $therapist->id,
            ]);
            $this->writeExercises($plan, $exercises);
            $this->timeline($plan->clinic_id, $plan->patient_id, 'physio_plan_started', ['care_plan_id' => $plan->id, 'patient_treatment_id' => $treatment->id, 'title' => $plan->title]);

            return $plan->refresh();
        });
    }

    /**
     * A new revision of a treatment's plan. The current revision becomes
     * `superseded` and is otherwise left exactly as it was, exercises and
     * all; the new one takes over from its effective date.
     *
     * @param  array{title?: ?string, goals?: ?string, interventions?: list<string>, planned_sessions?: ?int, frequency?: ?string, effective_from?: ?string}  $data
     * @param  list<array<string, mixed>>  $exercises
     */
    public function revisePlan(PhysioCarePlan $current, array $data, array $exercises, ClinicEmployee $therapist, User $by): PhysioCarePlan
    {
        $treatment = $this->treatmentOf($current);
        $this->assertTreatmentWork($treatment, 'exercise_plan', $therapist, $by);

        return DB::transaction(function () use ($current, $data, $exercises, $therapist, $treatment) {
            $treatment = $this->lockOpenTreatment($treatment);
            $current = $this->lockActivePlan($current);

            $current->update(['status' => PhysioCarePlan::SUPERSEDED, 'closed_at' => now()]);
            $next = PhysioCarePlan::query()->withoutGlobalScopes()->create([
                'clinic_id' => $current->clinic_id,
                'patient_id' => $current->patient_id,
                'patient_treatment_id' => $treatment->id,
                'revision' => $current->revision + 1,
                'supersedes_id' => $current->id,
                'encounter_id' => $current->encounter_id,
                'assessment_id' => $current->assessment_id,
                ...$this->planFields($data, $current),
                'status' => PhysioCarePlan::ACTIVE,
                'created_by' => $therapist->id,
            ]);
            $this->writeExercises($next, $exercises);
            $this->timeline($next->clinic_id, $next->patient_id, 'physio_plan_revised', ['care_plan_id' => $next->id, 'patient_treatment_id' => $treatment->id, 'revision' => $next->revision]);

            return $next->refresh();
        });
    }

    public function closeCarePlan(PhysioCarePlan $plan, string $status, ?string $note): PhysioCarePlan
    {
        if (! in_array($status, [PhysioCarePlan::COMPLETED, PhysioCarePlan::DISCONTINUED], true)) {
            throw new InvalidArgumentException('A plan of care is closed as completed or discontinued.');
        }

        return DB::transaction(function () use ($plan, $status, $note) {
            $plan = $this->lockActivePlan($plan);
            $plan->update(['status' => $status, 'closed_at' => now(), 'closing_note' => $this->text($note)]);
            // Only a plan from before treatments were the care context drives its treatment's status.
            $this->mirror->mirrorPhysioCarePlan($plan);
            $this->timeline($plan->clinic_id, $plan->patient_id, "physio_plan_{$status}", ['care_plan_id' => $plan->id, 'title' => $plan->title]);

            return $plan;
        });
    }

    /**
     * The consultation screen's "start a plan of care": a new ad-hoc
     * physiotherapy treatment with this as its first plan, or (with
     * `patient_treatment_id`) the first plan of an existing treatment.
     *
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $exercises
     */
    public function startCarePlan(ClinicalEncounter $encounter, array $data, array $exercises, ClinicEmployee $therapist, ?User $by = null): PhysioCarePlan
    {
        $by ??= auth()->user() ?? throw new DomainException('No signed-in user.');
        $treatment = ! empty($data['patient_treatment_id'])
            ? $this->findTreatment((int) $data['patient_treatment_id'], $encounter->clinic_id, $encounter->patient_id)
            : $this->treatments->startAdHoc(
                Patient::query()->withoutGlobalScopes()->findOrFail($encounter->patient_id),
                $this->physioSpecialtyFor($encounter),
                trim((string) ($data['title'] ?? '')) ?: 'Plan of care',
                $therapist,
                Branch::query()->withoutGlobalScopes()->findOrFail($encounter->branch_id),
                $by,
                ['encounter' => $encounter, 'goal' => $data['goals'] ?? null, 'sessions_planned' => $data['planned_sessions'] ?? null],
            );

        return $this->startPlan($treatment, $data, $exercises, $therapist, $by, $encounter);
    }

    /**
     * The consultation screen's exercise edit: a new revision, never an
     * in-place change.
     *
     * @param  list<array<string, mixed>>  $exercises
     */
    public function replaceExercises(PhysioCarePlan $plan, array $exercises, ?ClinicEmployee $therapist = null, ?User $by = null): PhysioCarePlan
    {
        $by ??= auth()->user() ?? throw new DomainException('No signed-in user.');
        $therapist ??= ClinicEmployee::query()->withoutGlobalScopes()->findOrFail($plan->created_by);

        return $this->revisePlan($plan, [], $exercises, $therapist, $by);
    }

    // ---------------------------------------------------------------- Sessions

    /**
     * Today's session of one treatment, within one consultation: the draft
     * already open for that pair, or a new one. One consultation may hold
     * sessions of several treatments.
     */
    public function openSession(PatientTreatment $treatment, ClinicalEncounter $encounter, ClinicEmployee $therapist, User $by): PhysioSession
    {
        $this->assertTreatmentWork($treatment, 'soap_notes', $therapist, $by);
        if ($encounter->patient_id !== $treatment->patient_id || $encounter->clinic_id !== $treatment->clinic_id) {
            throw new InvalidArgumentException('The consultation is for a different patient.');
        }
        $this->access->assertCanUseConsultation($encounter);

        return DB::transaction(function () use ($treatment, $encounter, $therapist) {
            $encounter = $this->lockOpen($encounter);
            $treatment = $this->lockOpenTreatment($treatment);

            $existing = PhysioSession::query()->withoutGlobalScopes()->where('clinic_id', $treatment->clinic_id)
                ->where('encounter_id', $encounter->id)->where('patient_treatment_id', $treatment->id)->first();
            if ($existing) {
                return $existing;
            }

            return PhysioSession::query()->withoutGlobalScopes()->create([
                'clinic_id' => $treatment->clinic_id,
                'patient_id' => $treatment->patient_id,
                'encounter_id' => $encounter->id,
                'patient_treatment_id' => $treatment->id,
                'care_plan_id' => $this->activePlanQuery($treatment)->value('id'),
                'therapist_id' => $therapist->id,
                'status' => PhysioSession::DRAFT,
            ]);
        });
    }

    /**
     * Write a draft session: the SOAP note, pain, modalities, room, and each
     * exercise as it went (performed or skipped, against the plan, or added
     * on the day). The plan itself is never touched.
     *
     * @param  array<string, mixed>  $data
     */
    public function saveSession(PhysioSession $session, array $data, ClinicEmployee $therapist, User $by): PhysioSession
    {
        $treatment = $this->sessionTreatment($session);
        $this->assertTreatmentWork($treatment, 'soap_notes', $therapist, $by);

        return DB::transaction(function () use ($session, $data, $therapist) {
            $session = $this->lockDraft($session);
            $encounter = $this->lockOpen(ClinicalEncounter::query()->withoutGlobalScopes()->findOrFail($session->encounter_id));

            $roomId = $data['room_id'] ?? null;
            if ($roomId !== null && $roomId !== '' && ! ClinicRoom::query()->withoutGlobalScopes()->whereKey($roomId)->where('branch_id', $encounter->branch_id)->exists()) {
                throw new InvalidArgumentException('That bed or room is not at this branch.');
            }

            // Only the fields sent change: completing a draft never wipes what was written.
            $fields = [
                'subjective' => fn ($v) => $this->text($v),
                'objective' => fn ($v) => $this->text($v),
                'assessment' => fn ($v) => $this->text($v),
                'plan' => fn ($v) => $this->text($v),
                'pain_before' => fn ($v) => $this->pain($v),
                'pain_after' => fn ($v) => $this->pain($v),
                'modalities' => fn ($v) => array_values(array_intersect(array_keys(config('clinic_physio.modalities')), (array) $v)),
                'room_id' => fn ($v) => $v ?: null,
                'treatment_performed' => fn ($v) => $this->text($v),
                // The older form's rows exactly as entered; the canonical record is `physio_session_exercises`.
                'exercises_performed' => fn ($v) => $this->rows(is_array($v) ? $v : [], ['name', 'sets', 'reps', 'duration', 'intensity']),
                'patient_response' => fn ($v) => $this->text($v),
                'home_program' => fn ($v) => $this->text($v),
                'next_step' => fn ($v) => $this->text($v, 500),
            ];
            foreach ($fields as $field => $clean) {
                if (array_key_exists($field, $data)) {
                    $session->{$field} = $clean($data[$field]);
                }
            }
            $session->therapist_id = $therapist->id;
            $session->save();

            if (array_key_exists('exercises', $data)) {
                $this->writePerformed($session, (array) $data['exercises']);
            }

            return $session->refresh();
        });
    }

    /**
     * Complete a session, in one transaction: it is numbered within its
     * treatment and, when a package is named, one session is consumed from
     * that package (which must be this treatment's). Retrying a completed
     * session changes nothing. Package choice is always explicit: with no
     * `packageId` nothing is consumed, even when one package would fit.
     */
    public function completeSession(PhysioSession $session, ?int $packageId, ClinicEmployee $therapist, User $by): PhysioSession
    {
        $treatment = $this->sessionTreatment($session);
        $this->assertTreatmentWork($treatment, 'soap_notes', $therapist, $by);

        return DB::transaction(function () use ($session, $packageId, $therapist, $by) {
            $locked = PhysioSession::query()->withoutGlobalScopes()->whereKey($session->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === PhysioSession::COMPLETED) {
                return $locked; // idempotent retry
            }
            if ($locked->status !== PhysioSession::DRAFT) {
                throw new DomainException('A reversed session cannot be completed again.');
            }
            $this->lockOpen(ClinicalEncounter::query()->withoutGlobalScopes()->findOrFail($locked->encounter_id));
            $treatment = $this->lockOpenTreatment(PatientTreatment::query()->withoutGlobalScopes()->findOrFail($locked->patient_treatment_id));

            if ($packageId !== null) {
                $this->packages->consume($locked, $packageId, $therapist, $by);
            }
            $number = (int) PhysioSession::query()->withoutGlobalScopes()->where('clinic_id', $treatment->clinic_id)
                ->where('patient_treatment_id', $treatment->id)->max('session_number') + 1;
            $locked->forceFill([
                'status' => PhysioSession::COMPLETED,
                'session_number' => $number,
                'package_id' => $packageId,
                'completed_at' => now(),
                'completed_by' => $therapist->id,
            ])->save();
            $this->treatments->refreshSessionCount($treatment);

            $this->timeline($locked->clinic_id, $locked->patient_id, 'physio_session_completed', [
                'patient_treatment_id' => $treatment->id, 'title' => $treatment->title, 'session_number' => $number, 'package_id' => $packageId,
            ]);

            return $locked;
        });
    }

    /**
     * Undo a completed session (recorded in error, wrong patient's notes…).
     * Its package consumption is given back by a ledger reversal; the note
     * itself is kept and marked reversed. Needs a reason; audited.
     */
    public function reverseSession(PhysioSession $session, string $reason, ClinicEmployee $therapist, User $by): PhysioSession
    {
        $treatment = $this->sessionTreatment($session);
        $this->assertTreatmentWork($treatment, 'soap_notes', $therapist, $by);
        $reason = trim($reason);
        if (mb_strlen($reason) < 3) {
            throw new InvalidArgumentException('Give a reason for reversing the session.');
        }

        return DB::transaction(function () use ($session, $reason, $therapist, $by, $treatment) {
            $locked = PhysioSession::query()->withoutGlobalScopes()->whereKey($session->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== PhysioSession::COMPLETED) {
                throw new DomainException('Only a completed session can be reversed.');
            }
            $this->packages->reverseFor($locked, $reason, $therapist, $by);
            $locked->forceFill(['status' => PhysioSession::REVERSED, 'reversed_at' => now(), 'reversal_reason' => mb_substr($reason, 0, 500)])->save();
            $this->treatments->refreshSessionCount($treatment);

            $this->audit->record('physio.session.reversed', $locked, ['patient_treatment_id' => $treatment->id, 'reason' => $reason], ['clinic_id' => $locked->clinic_id]);
            $this->timeline($locked->clinic_id, $locked->patient_id, 'physio_session_reversed', ['patient_treatment_id' => $treatment->id, 'session_number' => $locked->session_number, 'reason' => $reason]);

            return $locked;
        });
    }

    /**
     * The consultation screen's session form: open (or reopen) the session of
     * the named treatment in this consultation, save it, and complete it when
     * asked. The treatment is required; it is never guessed.
     *
     * @param  array<string, mixed>  $data
     */
    public function recordSession(ClinicalEncounter $encounter, array $data, ClinicEmployee $therapist, ?User $by = null): PhysioSession
    {
        $by ??= auth()->user() ?? throw new DomainException('No signed-in user.');
        if (empty($data['patient_treatment_id'])) {
            throw new InvalidArgumentException('Choose the treatment this session is for.');
        }
        $treatment = $this->findTreatment((int) $data['patient_treatment_id'], $encounter->clinic_id, $encounter->patient_id);

        // The older form's free rows become ad-hoc performed exercises (the canonical record).
        // Its duration is free text with no unit ("10 min", "30", "2 x 30s"), so it is never
        // converted to `duration_seconds`; it is kept verbatim as a labelled note.
        if (! array_key_exists('exercises', $data) && is_array($data['exercises_performed'] ?? null)) {
            $data['exercises'] = collect($data['exercises_performed'])->filter(fn ($row) => is_array($row) && trim((string) ($row['name'] ?? '')) !== '')
                ->map(fn (array $row) => [
                    'name' => $row['name'],
                    'status' => PhysioSessionExercise::PERFORMED,
                    'sets' => $row['sets'] ?? null,
                    'reps' => $row['reps'] ?? null,
                    'duration_seconds' => null,
                    'intensity' => $row['intensity'] ?? null,
                    'notes' => trim((string) ($row['duration'] ?? '')) !== '' ? 'Duration: '.trim((string) $row['duration']) : null,
                ])
                ->values()->all();
        }

        $session = $this->openSession($treatment, $encounter, $therapist, $by);
        if ($session->status === PhysioSession::DRAFT) {
            $session = $this->saveSession($session, $data, $therapist, $by);
        }
        if (! empty($data['complete'])) {
            $session = $this->completeSession($session, isset($data['package_id']) && $data['package_id'] !== '' ? (int) $data['package_id'] : null, $therapist, $by);
        }

        return $session;
    }

    // ---------------------------------------------------------------- Packages

    /**
     * Sell a package for one of the patient's treatments (see `SessionPackages::sell`).
     *
     * @param  array{name: string, sessions_total: int, patient_treatment_id: int, valid_until?: ?string, clinical_procedure_id?: ?int, price?: ?float, renews_package_id?: ?int}  $data
     */
    public function sellPackage(Patient $patient, Branch $branch, array $data, ClinicEmployee $seller): PatientSessionPackage
    {
        if ($patient->clinic_id !== $branch->clinic_id) {
            throw new InvalidArgumentException('The patient and branch must belong to one clinic.');
        }
        if (empty($data['patient_treatment_id'])) {
            throw new InvalidArgumentException('Choose the treatment the package is for.');
        }

        return $this->packages->sell($this->findTreatment((int) $data['patient_treatment_id'], $patient->clinic_id, $patient->id), $branch, $data, $seller);
    }

    public function cancelPackage(PatientSessionPackage $package): PatientSessionPackage
    {
        return $this->packages->cancel($package);
    }

    /**
     * Pain over the patient's completed sessions and assessments, oldest first.
     *
     * @return array{sessions: list<array<string, mixed>>, assessments: list<array<string, mixed>>}
     */
    public function progress(Patient $patient): array
    {
        return [
            'sessions' => PhysioSession::query()->withoutGlobalScopes()->where('clinic_id', $patient->clinic_id)->where('patient_id', $patient->id)
                ->where('status', '!=', PhysioSession::REVERSED)
                ->orderBy('created_at')->limit(100)->get(['id', 'patient_treatment_id', 'session_number', 'pain_before', 'pain_after', 'created_at'])
                ->map(fn (PhysioSession $s) => ['patient_treatment_id' => $s->patient_treatment_id, 'session_number' => $s->session_number, 'pain_before' => $s->pain_before, 'pain_after' => $s->pain_after, 'date' => $s->created_at?->toDateString()])->all(),
            'assessments' => PhysioAssessment::query()->withoutGlobalScopes()->where('clinic_id', $patient->clinic_id)->where('patient_id', $patient->id)
                ->orderBy('created_at')->limit(50)->get(['id', 'pain_at_rest', 'pain_on_activity', 'functional_measures', 'created_at'])
                ->map(fn (PhysioAssessment $a) => ['pain_at_rest' => $a->pain_at_rest, 'pain_on_activity' => $a->pain_on_activity, 'functional_measures' => $a->functional_measures ?? [], 'date' => $a->created_at?->toDateString()])->all(),
        ];
    }

    // ---------------------------------------------------------------- Internals

    /**
     * The treatment's specialty must bring this physio extension, and the
     * user may do treatment work on it (TreatmentAccess).
     */
    private function assertTreatmentWork(PatientTreatment $treatment, string $extension, ClinicEmployee $therapist, User $by): void
    {
        $extensions = $this->specialties->get((string) $treatment->specialty_key)['extensions'] ?? [];
        if (! in_array($extension, $extensions, true)) {
            throw new DomainException('This treatment\'s specialty does not use physiotherapy records.');
        }
        $this->access->assertCanExecute($by, $treatment, $therapist);
    }

    private function findTreatment(int $id, int $clinicId, int $patientId): PatientTreatment
    {
        $treatment = PatientTreatment::query()->withoutGlobalScopes()->where('clinic_id', $clinicId)->find($id);
        if (! $treatment || $treatment->patient_id !== $patientId) {
            throw new InvalidArgumentException('That treatment is not this patient\'s.');
        }

        return $treatment;
    }

    private function treatmentOf(PhysioCarePlan $plan): PatientTreatment
    {
        if ($plan->patient_treatment_id === null) {
            // A plan from before treatments were the care context: give it its treatment first.
            return $this->mirror->mirrorPhysioCarePlan($plan);
        }

        return PatientTreatment::query()->withoutGlobalScopes()->where('clinic_id', $plan->clinic_id)->findOrFail($plan->patient_treatment_id);
    }

    private function sessionTreatment(PhysioSession $session): PatientTreatment
    {
        if ($session->patient_treatment_id === null) {
            throw new DomainException('This session has no treatment; it was recorded before treatments were the care context.');
        }

        return PatientTreatment::query()->withoutGlobalScopes()->where('clinic_id', $session->clinic_id)->findOrFail($session->patient_treatment_id);
    }

    private function activePlanQuery(PatientTreatment $treatment): Builder
    {
        return PhysioCarePlan::query()->withoutGlobalScopes()->where('clinic_id', $treatment->clinic_id)
            ->where('patient_treatment_id', $treatment->id)->where('status', PhysioCarePlan::ACTIVE);
    }

    private function lockOpenTreatment(PatientTreatment $treatment): PatientTreatment
    {
        $locked = PatientTreatment::query()->withoutGlobalScopes()->whereKey($treatment->id)->lockForUpdate()->firstOrFail();
        if (! $locked->isOpen()) {
            throw new DomainException($locked->status === PatientTreatment::PAUSED ? 'This treatment is paused. Resume it first.' : 'This treatment is closed.');
        }

        return $locked;
    }

    private function lockDraft(PhysioSession $session): PhysioSession
    {
        $locked = PhysioSession::query()->withoutGlobalScopes()->whereKey($session->id)->lockForUpdate()->firstOrFail();
        if ($locked->status !== PhysioSession::DRAFT) {
            throw new DomainException('A completed session is fixed; reverse it to correct it.');
        }

        return $locked;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function planFields(array $data, ?PhysioCarePlan $base): array
    {
        $pick = fn (string $key) => array_key_exists($key, $data) ? $data[$key] : $base?->{$key};

        return [
            'title' => trim((string) ($pick('title') ?? '')) ?: ($base?->title ?? 'Plan of care'),
            'goals' => $this->text($pick('goals')),
            'interventions' => array_values(array_intersect(array_keys(config('clinic_physio.modalities')), (array) ($pick('interventions') ?? []))),
            'planned_sessions' => $pick('planned_sessions') !== null ? max(1, (int) $pick('planned_sessions')) : null,
            'frequency' => $this->text($pick('frequency')),
            'starts_on' => $base?->starts_on ?? ($data['starts_on'] ?? now()->toDateString()),
            'effective_from' => $data['effective_from'] ?? now()->toDateString(),
        ];
    }

    /**
     * Replace a draft session's exercise rows. A row naming a prescribed
     * exercise must be one of the session's plan; its name is snapshotted.
     *
     * @param  array<int, mixed>  $rows
     */
    private function writePerformed(PhysioSession $session, array $rows): void
    {
        $prescribed = $session->care_plan_id
            ? PhysioExercise::query()->withoutGlobalScopes()->where('clinic_id', $session->clinic_id)->where('care_plan_id', $session->care_plan_id)->get()->keyBy('id')
            : collect();
        PhysioSessionExercise::query()->withoutGlobalScopes()->where('physio_session_id', $session->id)->delete();

        $seen = [];
        foreach (array_values(array_slice($rows, 0, 60)) as $index => $row) {
            if (! is_array($row)) {
                continue;
            }
            $exerciseId = isset($row['physio_exercise_id']) && $row['physio_exercise_id'] !== '' ? (int) $row['physio_exercise_id'] : null;
            if ($exerciseId !== null && ! $prescribed->has($exerciseId)) {
                throw new InvalidArgumentException('That exercise is not on this session\'s plan.');
            }
            if ($exerciseId !== null && isset($seen[$exerciseId])) {
                throw new InvalidArgumentException('Each prescribed exercise is recorded once per session.');
            }
            $seen[$exerciseId ?? 'adhoc'.$index] = true;
            $name = trim((string) ($row['name'] ?? '')) ?: ($exerciseId ? $prescribed[$exerciseId]->name : '');
            if ($name === '') {
                throw new InvalidArgumentException('Name the exercise.');
            }
            $status = ($row['status'] ?? PhysioSessionExercise::PERFORMED) === PhysioSessionExercise::SKIPPED ? PhysioSessionExercise::SKIPPED : PhysioSessionExercise::PERFORMED;
            $number = fn (string $key, int $max) => isset($row[$key]) && $row[$key] !== '' ? min($max, max(0, (int) $row[$key])) : null;

            PhysioSessionExercise::query()->withoutGlobalScopes()->create([
                'clinic_id' => $session->clinic_id,
                'physio_session_id' => $session->id,
                'physio_exercise_id' => $exerciseId,
                'name' => mb_substr($name, 0, 150),
                'status' => $status,
                'sets' => $status === PhysioSessionExercise::PERFORMED ? $number('sets', 50) : null,
                'reps' => $status === PhysioSessionExercise::PERFORMED ? $number('reps', 500) : null,
                'duration_seconds' => $status === PhysioSessionExercise::PERFORMED ? $number('duration_seconds', 14400) : null,
                'intensity' => $this->text($row['intensity'] ?? null, 80),
                'notes' => $this->text($row['notes'] ?? null),
                'position' => $index + 1,
            ]);
        }
    }

    /** The specialty an ad-hoc physio treatment started from a consultation belongs to. */
    private function physioSpecialtyFor(ClinicalEncounter $encounter): string
    {
        $key = (string) ($encounter->specialty ?: 'physiotherapy');
        $extensions = $this->specialties->get($key)['extensions'] ?? [];

        return in_array('exercise_plan', $extensions, true) ? $key : 'physiotherapy';
    }

    /**
     * @param  list<array<string, mixed>>  $exercises
     */
    private function writeExercises(PhysioCarePlan $plan, array $exercises): void
    {
        foreach (array_values($exercises) as $index => $exercise) {
            $name = trim((string) ($exercise['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $number = fn (string $key, int $max) => isset($exercise[$key]) && $exercise[$key] !== '' ? min($max, max(0, (int) $exercise[$key])) : null;

            $plan->exercises()->create([
                'patient_treatment_id' => $plan->patient_treatment_id,
                'name' => mb_substr($name, 0, 150),
                'sets' => $number('sets', 50),
                'reps' => $number('reps', 500),
                'hold_seconds' => $number('hold_seconds', 600),
                'frequency' => $this->text($exercise['frequency'] ?? null),
                'instructions' => $this->text($exercise['instructions'] ?? null),
                'is_home' => (bool) ($exercise['is_home'] ?? true),
                'sort_order' => $index + 1,
            ]);
        }
    }

    private function lockOpen(ClinicalEncounter $encounter): ClinicalEncounter
    {
        /** @var ClinicalEncounter $locked */
        $locked = ClinicalEncounter::query()->withoutGlobalScopes()->whereKey($encounter->id)->lockForUpdate()->firstOrFail();
        if (! $locked->isEditable()) {
            throw new DomainException('This session is signed and can no longer be changed.');
        }

        return $locked;
    }

    private function lockActivePlan(PhysioCarePlan $plan): PhysioCarePlan
    {
        $locked = PhysioCarePlan::query()->withoutGlobalScopes()->whereKey($plan->id)->lockForUpdate()->firstOrFail();
        if ($locked->status !== PhysioCarePlan::ACTIVE) {
            throw new DomainException('This plan of care is closed.');
        }

        return $locked;
    }

    private function pain(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $value = (int) $value;
        if ($value < 0 || $value > 10) {
            throw new InvalidArgumentException('Pain is scored 0 to 10.');
        }

        return $value;
    }

    private function text(mixed $value, ?int $max = null): ?string
    {
        $value = is_string($value) ? trim($value) : null;
        if ($value === '' || $value === null) {
            return null;
        }

        return $max ? mb_substr($value, 0, $max) : $value;
    }

    /**
     * @param  array<int, mixed>  $rows
     * @param  list<string>  $keys
     * @return list<array<string, mixed>>|null
     */
    private function rows(array $rows, array $keys): ?array
    {
        $clean = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $kept = array_filter(array_intersect_key($row, array_flip($keys)), fn ($v) => $v !== null && $v !== '');
            if ($kept !== []) {
                $clean[] = array_map(fn ($v) => is_string($v) ? mb_substr(trim($v), 0, 200) : $v, $kept);
            }
        }

        return $clean === [] ? null : array_slice($clean, 0, 60);
    }

    /**
     * @param  array<int, mixed>  $rows
     * @return list<array<string, mixed>>|null
     */
    private function strength(array $rows): ?array
    {
        $rows = $this->rows($rows, ['muscle_group', 'side', 'grade']);
        foreach ($rows ?? [] as $row) {
            if (isset($row['grade']) && ! in_array((int) $row['grade'], config('clinic_physio.strength_grades'), true)) {
                throw new InvalidArgumentException('Strength is graded 0 to 5.');
            }
        }

        return $rows;
    }

    /**
     * @param  array<int, mixed>  $rows
     * @return list<array<string, mixed>>|null
     */
    private function measures(array $rows): ?array
    {
        $rows = $this->rows($rows, ['measure', 'score']);
        foreach ($rows ?? [] as $row) {
            $definition = config('clinic_physio.functional_measures.'.($row['measure'] ?? '')) ?? throw new InvalidArgumentException('Unknown outcome measure.');
            if (! isset($row['score']) || (float) $row['score'] < 0 || (float) $row['score'] > $definition['max']) {
                throw new InvalidArgumentException("{$definition['label']} is scored 0 to {$definition['max']}.");
            }
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function timeline(int $clinicId, int $patientId, string $event, array $context): void
    {
        PatientTimelineEvent::create([
            'organization_id' => Clinic::query()->whereKey($clinicId)->value('organization_id'),
            'clinic_id' => $clinicId,
            'patient_id' => $patientId,
            'actor_id' => auth()->id(),
            'event_type' => $event,
            'context' => $context,
        ]);
    }
}
