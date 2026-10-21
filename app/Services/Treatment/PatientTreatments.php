<?php

namespace App\Services\Treatment;

use App\Models\Branch;
use App\Models\CatalogStage;
use App\Models\CatalogStageProcedure;
use App\Models\CatalogTreatment;
use App\Models\CatalogTreatmentVersion;
use App\Models\ClinicalEncounter;
use App\Models\ClinicalProcedure;
use App\Models\ClinicEmployee;
use App\Models\Patient;
use App\Models\PatientProcedure;
use App\Models\PatientTimelineEvent;
use App\Models\PatientTreatment;
use App\Models\PatientTreatmentStage;
use App\Models\PhysioSession;
use App\Models\TreatmentPlan;
use App\Models\User;
use App\Services\Clinical\EncounterWorkspace;
use App\Services\Patient\PatientBranchActivityRecorder;
use App\Services\Patient\PatientSpecialties;
use App\Services\Security\SecurityAudit;
use App\Services\Treatment\Targets\TreatmentTargets;
use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The only writer of patient treatments started from the catalog.
 *
 * Starting pins everything the treatment will charge: the published version,
 * and its prices at that moment — the version price (upfront), each stage's
 * price (per stage), or each allowed procedure's price (per procedure, kept
 * in `price_snapshot`). Nothing later reads today's catalog price for it.
 *
 * Work is recorded as `PatientProcedure` rows in an encounter, linked to the
 * treatment and stage; for a session-based treatment each one is a session.
 */
class PatientTreatments
{
    public function __construct(
        protected TreatmentAccess $access,
        protected CatalogPricing $pricing,
        protected EncounterWorkspace $workspace,
        protected PatientSpecialties $patientSpecialties,
        protected PatientBranchActivityRecorder $branchActivity,
        protected TreatmentTargets $targets,
        protected SecurityAudit $audit,
    ) {}

    /**
     * @param  array{encounter?: ?ClinicalEncounter, treatment_plan?: ?TreatmentPlan, targets?: array<string, list<array<string, mixed>>>}  $options  targets keyed by the target key a specialty declares, e.g. ['tooth' => [['tooth_number' => 16]]]
     */
    public function start(Patient $patient, CatalogTreatmentVersion $version, ClinicEmployee $practitioner, Branch $branch, User $by, array $options = []): PatientTreatment
    {
        $this->access->assertCanStart($by, $patient, $version, $practitioner, $branch);
        $treatment = CatalogTreatment::query()->withoutGlobalScopes()->findOrFail($version->catalog_treatment_id);
        if ($version->status !== CatalogTreatmentVersion::PUBLISHED || $treatment->current_version_id !== $version->id) {
            throw new DomainException('Start treatment from the current published version.');
        }
        if (! $treatment->is_active) {
            throw new DomainException('This treatment is no longer offered.');
        }
        $targets = $this->resolveTargets($version, $treatment->specialty_key, (array) ($options['targets'] ?? []));
        $encounter = $options['encounter'] ?? null;
        if ($encounter && ($encounter->patient_id !== $patient->id || $encounter->clinic_id !== $patient->clinic_id)) {
            throw new InvalidArgumentException('The consultation is for a different patient.');
        }
        $this->access->assertCanUseConsultation($encounter);
        $plan = $options['treatment_plan'] ?? null;
        if ($plan && ($plan->patient_id !== $patient->id || $plan->clinic_id !== $patient->clinic_id)) {
            throw new InvalidArgumentException('The proposal is for a different patient.');
        }

        $diagnosisId = $this->checkedDiagnosis($patient->clinic_id, $patient->id, $options['primary_diagnosis_id'] ?? null);
        $goal = $this->goal($options['goal'] ?? null);

        return DB::transaction(function () use ($patient, $version, $treatment, $practitioner, $branch, $by, $targets, $encounter, $plan, $diagnosisId, $goal) {
            $now = now();
            $stages = CatalogStage::query()->withoutGlobalScopes()->where('catalog_treatment_version_id', $version->id)->orderBy('position')->get();
            $stageProcedures = CatalogStageProcedure::query()->withoutGlobalScopes()->whereIn('catalog_stage_id', $stages->pluck('id'))->get();

            // Pin the prices in force now.
            $versionPrice = $version->charging_model === 'upfront' ? $this->pricing->current($patient->clinic_id, 'catalog_treatment_version_id', $version->id, $branch->id, $now) : null;
            $stagePrices = $version->charging_model === 'per_stage'
                ? $stages->mapWithKeys(fn (CatalogStage $stage) => [$stage->id => $this->pricing->current($patient->clinic_id, 'catalog_stage_id', $stage->id, $branch->id, $now)])
                : collect();
            $snapshot = $version->charging_model === 'per_procedure'
                ? $stageProcedures->pluck('clinical_procedure_id')->unique()->mapWithKeys(function (int $id) use ($patient, $branch, $now) {
                    $price = $this->pricing->current($patient->clinic_id, 'clinical_procedure_id', $id, $branch->id, $now);

                    return [(string) $id => ['price_version_id' => $price?->id, 'amount' => $price ? (float) $price->amount : null]];
                })->all()
                : null;

            $agreed = match ($version->charging_model) {
                'upfront' => $versionPrice?->amount,
                'per_stage' => $stagePrices->filter()->isEmpty() ? null : $stagePrices->filter()->sum(fn ($price) => (float) $price->amount),
                'per_procedure' => collect($snapshot)->every(fn ($p) => $p['amount'] === null) ? null
                    : $stageProcedures->where('is_required', true)->sum(fn (CatalogStageProcedure $sp) => ($snapshot[(string) $sp->clinical_procedure_id]['amount'] ?? 0) * $sp->quantity),
                default => null,
            };

            $sessions = $version->planned_sessions ?? ($stages->sum('planned_sessions') ?: null);
            $enrolment = $this->patientSpecialties->enroll($patient, $treatment->specialty_key, 'treatment', $by, $now);

            $row = PatientTreatment::query()->withoutGlobalScopes()->create([
                'clinic_id' => $patient->clinic_id,
                'branch_id' => $branch->id,
                'patient_id' => $patient->id,
                'patient_specialty_id' => $enrolment->id,
                'specialty_key' => $treatment->specialty_key,
                'source' => PatientTreatment::SOURCE_CATALOG,
                'catalog_treatment_id' => $treatment->id,
                'catalog_treatment_version_id' => $version->id,
                'treatment_plan_id' => $plan?->id,
                'primary_diagnosis_id' => $diagnosisId,
                'goal' => $goal,
                'encounter_id' => $encounter?->id,
                'practitioner_id' => $practitioner->id,
                'title' => $treatment->name,
                'code' => $treatment->code,
                'treatment_type' => $version->treatment_type,
                'charging_model' => $version->charging_model,
                'status' => PatientTreatment::IN_PROGRESS,
                'price_version_id' => $versionPrice?->id,
                'agreed_amount' => $agreed,
                'price_snapshot' => $snapshot,
                'stages_total' => $stages->count(),
                'sessions_planned' => $sessions,
                'valid_until' => $version->validity_days ? $now->copy()->addDays($version->validity_days)->toDateString() : null,
                'started_at' => $now,
                'created_by' => $by->id,
            ]);

            foreach ($stages as $stage) {
                $price = $stagePrices->get($stage->id);
                PatientTreatmentStage::query()->withoutGlobalScopes()->create([
                    'clinic_id' => $row->clinic_id,
                    'patient_treatment_id' => $row->id,
                    'catalog_stage_id' => $stage->id,
                    'position' => $stage->position,
                    'name' => $stage->name,
                    'is_optional' => $stage->is_optional,
                    'status' => PatientTreatmentStage::PENDING,
                    'price_version_id' => $price?->id,
                    'agreed_amount' => $price?->amount,
                    'sessions_planned' => $stage->planned_sessions,
                ]);
            }
            foreach ($targets as $key => $items) {
                $this->targets->handler($key)->store($row, $items);
            }

            $this->branchActivity->record($row->clinic_id, $patient->id, $branch->id, $now, 'encounter');
            $this->recorded($row, 'treatment_started', 'treatment.started', ['title' => $row->title, 'version' => $version->version, 'targets' => $targets], $by);

            return $row->load('stages');
        });
    }

    public function startStage(PatientTreatmentStage $stage, User $by, ClinicEmployee $performer, ?ClinicalEncounter $encounter = null): PatientTreatmentStage
    {
        return $this->onStage($stage, $by, $performer, function (PatientTreatment $treatment, PatientTreatmentStage $stage) use ($encounter) {
            $this->assertEncounterOf($treatment, $encounter);
            if ($stage->status !== PatientTreatmentStage::PENDING) {
                throw new DomainException('This stage has already started.');
            }
            $this->assertPreviousClosed($treatment, $stage);
            $stage->update(['status' => PatientTreatmentStage::IN_PROGRESS, 'started_at' => now(), 'encounter_id' => $encounter?->id ?? $stage->encounter_id]);

            return $stage;
        }, 'treatment_stage_started');
    }

    public function completeStage(PatientTreatmentStage $stage, User $by, ClinicEmployee $performer, ?ClinicalEncounter $encounter = null): PatientTreatmentStage
    {
        return $this->onStage($stage, $by, $performer, function (PatientTreatment $treatment, PatientTreatmentStage $stage) use ($encounter) {
            $this->assertEncounterOf($treatment, $encounter);
            if ($stage->isClosed()) {
                throw new DomainException('This stage is already closed.');
            }
            $this->assertPreviousClosed($treatment, $stage);
            $stage->update(['status' => PatientTreatmentStage::COMPLETED, 'completed_at' => now(), 'started_at' => $stage->started_at ?? now(), 'encounter_id' => $encounter?->id ?? $stage->encounter_id]);

            return $stage;
        }, 'treatment_stage_completed');
    }

    /** Only an optional stage can be skipped. */
    public function skipStage(PatientTreatmentStage $stage, User $by, ClinicEmployee $performer): PatientTreatmentStage
    {
        return $this->onStage($stage, $by, $performer, function (PatientTreatment $treatment, PatientTreatmentStage $stage) {
            if (! $stage->is_optional || $stage->isClosed()) {
                throw new DomainException('Only an open optional stage can be skipped.');
            }
            $stage->update(['status' => PatientTreatmentStage::SKIPPED, 'completed_at' => now()]);

            return $stage;
        }, 'treatment_stage_skipped');
    }

    /**
     * Record a procedure (or, for a session-based treatment, a session) done
     * for this treatment in an open consultation. Its charge is fixed now,
     * from the price pinned when the treatment started.
     */
    public function recordProcedure(PatientTreatmentStage $stage, ClinicalProcedure $procedure, ClinicalEncounter $encounter, ClinicEmployee $performer, User $by, int $quantity = 1, ?string $notes = null): PatientProcedure
    {
        $treatment = PatientTreatment::query()->withoutGlobalScopes()->findOrFail($stage->patient_treatment_id);
        $this->access->assertCanExecute($by, $treatment, $performer);
        $this->assertEncounterOf($treatment, $encounter);

        return DB::transaction(function () use ($treatment, $stage, $procedure, $encounter, $by, $quantity, $notes) {
            $treatment = $this->lockOpen($treatment);
            $stage = PatientTreatmentStage::query()->withoutGlobalScopes()->whereKey($stage->id)->lockForUpdate()->firstOrFail();
            if ($stage->isClosed()) {
                throw new DomainException('This stage is already closed.');
            }
            $this->assertPreviousClosed($treatment, $stage);

            $allowed = CatalogStageProcedure::query()->withoutGlobalScopes()
                ->where('catalog_stage_id', $stage->catalog_stage_id)->where('clinical_procedure_id', $procedure->id)->exists();
            if (! $allowed) {
                throw new DomainException("“{$procedure->name}” is not part of this stage.");
            }

            $performed = $this->workspace->addProcedure($encounter, [
                'clinical_procedure_id' => $procedure->id, 'quantity' => $quantity, 'notes' => $notes,
                'details' => $this->targets->procedureDetails($treatment),
            ]);
            $pinned = $treatment->charging_model === 'per_procedure' ? ($treatment->price_snapshot[(string) $procedure->id] ?? null) : null;
            $performed->update([
                'patient_treatment_id' => $treatment->id,
                'patient_treatment_stage_id' => $stage->id,
                'price_version_id' => $pinned['price_version_id'] ?? null,
                'charged_amount' => $pinned && $pinned['amount'] !== null ? round($pinned['amount'] * max(1, $quantity), 2) : null,
            ]);

            $stageUpdate = ['status' => PatientTreatmentStage::IN_PROGRESS, 'started_at' => $stage->started_at ?? now(), 'encounter_id' => $encounter->id];
            if ($treatment->treatment_type === 'session_based') {
                $stageUpdate['sessions_completed'] = $stage->sessions_completed + 1;
                $treatment->update(['sessions_completed' => $treatment->sessions_completed + 1]);
                // The stage closes itself when its planned sessions are done.
                if ($stage->sessions_planned !== null && $stageUpdate['sessions_completed'] >= $stage->sessions_planned) {
                    $stageUpdate['status'] = PatientTreatmentStage::COMPLETED;
                    $stageUpdate['completed_at'] = now();
                }
            }
            $stage->update($stageUpdate);
            $this->refreshProgress($treatment);
            $this->recorded($treatment, $treatment->treatment_type === 'session_based' ? 'treatment_session_recorded' : 'treatment_procedure_recorded', 'treatment.procedure.recorded',
                ['stage' => $stage->name, 'procedure' => $procedure->name, 'charged' => $performed->charged_amount], $by);

            return $performed;
        });
    }

    /**
     * A physiotherapy SOAP session documents the session recorded for a
     * session-based treatment: link it to that procedure and its treatment.
     */
    public function attachPhysioSession(PhysioSession $session, PatientProcedure $procedure, User $by, ClinicEmployee $performer): PhysioSession
    {
        $treatment = PatientTreatment::query()->withoutGlobalScopes()->findOrFail($procedure->patient_treatment_id
            ?? throw new InvalidArgumentException('That procedure is not part of a treatment.'));
        $this->access->assertCanExecute($by, $treatment, $performer);
        if ($session->clinic_id !== $procedure->clinic_id || $session->patient_id !== $procedure->patient_id || $session->encounter_id !== $procedure->encounter_id) {
            throw new InvalidArgumentException('The session and the procedure must be the same visit of the same patient.');
        }

        $session->forceFill(['patient_treatment_id' => $treatment->id, 'patient_procedure_id' => $procedure->id])->save();

        return $session;
    }

    public function pause(PatientTreatment $treatment, User $by, ClinicEmployee $performer, string $reason): PatientTreatment
    {
        return $this->transition($treatment, $by, $performer, [PatientTreatment::IN_PROGRESS, PatientTreatment::PLANNED], PatientTreatment::PAUSED, $reason);
    }

    public function resume(PatientTreatment $treatment, User $by, ClinicEmployee $performer): PatientTreatment
    {
        return $this->transition($treatment, $by, $performer, [PatientTreatment::PAUSED], PatientTreatment::IN_PROGRESS, null);
    }

    public function cancel(PatientTreatment $treatment, User $by, ClinicEmployee $performer, string $reason): PatientTreatment
    {
        return $this->transition($treatment, $by, $performer, [PatientTreatment::PLANNED, PatientTreatment::IN_PROGRESS, PatientTreatment::PAUSED], PatientTreatment::CANCELLED, $reason);
    }

    /**
     * A care programme started without a catalogue entry: the same canonical
     * patient treatment, same rules (permission, specialty practised by the
     * practitioner, branch, patient access), no stages, no pinned price.
     * A patient may hold any number of these at once.
     *
     * @param  array{goal?: ?string, primary_diagnosis_id?: ?int, sessions_planned?: ?int, encounter?: ?ClinicalEncounter}  $options
     */
    public function startAdHoc(Patient $patient, string $specialtyKey, string $title, ClinicEmployee $practitioner, Branch $branch, User $by, array $options = []): PatientTreatment
    {
        $this->access->assertCanStartAdHoc($by, $patient, $specialtyKey, $practitioner, $branch);
        $title = trim($title);
        if ($title === '' || mb_strlen($title) > 150) {
            throw new InvalidArgumentException('Name the treatment (up to 150 characters).');
        }
        $encounter = $options['encounter'] ?? null;
        if ($encounter && ($encounter->patient_id !== $patient->id || $encounter->clinic_id !== $patient->clinic_id)) {
            throw new InvalidArgumentException('The consultation is for a different patient.');
        }
        $this->access->assertCanUseConsultation($encounter);
        $diagnosisId = $this->checkedDiagnosis($patient->clinic_id, $patient->id, $options['primary_diagnosis_id'] ?? null);
        $sessions = isset($options['sessions_planned']) ? max(1, min(500, (int) $options['sessions_planned'])) : null;

        return DB::transaction(function () use ($patient, $specialtyKey, $title, $practitioner, $branch, $by, $options, $encounter, $diagnosisId, $sessions) {
            $now = now();
            $enrolment = $this->patientSpecialties->enroll($patient, $specialtyKey, 'treatment', $by, $now);

            $row = PatientTreatment::query()->withoutGlobalScopes()->create([
                'clinic_id' => $patient->clinic_id,
                'branch_id' => $branch->id,
                'patient_id' => $patient->id,
                'patient_specialty_id' => $enrolment->id,
                'specialty_key' => $specialtyKey,
                'source' => PatientTreatment::SOURCE_ADHOC,
                'encounter_id' => $encounter?->id,
                'primary_diagnosis_id' => $diagnosisId,
                'practitioner_id' => $practitioner->id,
                'title' => $title,
                'goal' => $this->goal($options['goal'] ?? null),
                'treatment_type' => 'session_based',
                'status' => PatientTreatment::IN_PROGRESS,
                'stages_total' => 0,
                'sessions_planned' => $sessions,
                'started_at' => $now,
                'created_by' => $by->id,
            ]);

            $this->branchActivity->record($row->clinic_id, $patient->id, $branch->id, $now, 'encounter');
            $this->recorded($row, 'treatment_started', 'treatment.started', ['title' => $row->title, 'source' => PatientTreatment::SOURCE_ADHOC], $by);

            return $row;
        });
    }

    /**
     * What the treatment is for: a reference to the diagnosis recorded on a
     * consultation of this patient (the diagnosis stays where it is) and the
     * goal. Either may be cleared; neither is required.
     */
    public function setClinicalContext(PatientTreatment $treatment, ?int $diagnosisId, ?string $goal, User $by, ClinicEmployee $performer): PatientTreatment
    {
        $this->access->assertCanExecute($by, $treatment, $performer);
        $diagnosisId = $this->checkedDiagnosis($treatment->clinic_id, $treatment->patient_id, $diagnosisId);

        return DB::transaction(function () use ($treatment, $diagnosisId, $goal, $by) {
            $locked = PatientTreatment::query()->withoutGlobalScopes()->whereKey($treatment->id)->lockForUpdate()->firstOrFail();
            $locked->update(['primary_diagnosis_id' => $diagnosisId, 'goal' => $this->goal($goal)]);
            if ($locked->wasChanged()) {
                $this->recorded($locked, 'treatment_context_updated', 'treatment.context_updated', ['primary_diagnosis_id' => $diagnosisId], $by);
            }

            return $locked;
        });
    }

    /** Finish a treatment that has no stages to complete it (an ad-hoc or session-based programme). */
    public function complete(PatientTreatment $treatment, User $by, ClinicEmployee $performer, ?string $note = null): PatientTreatment
    {
        $this->access->assertCanExecute($by, $treatment, $performer);
        if ($treatment->stages_total > 0) {
            throw new DomainException('A staged treatment completes when its stages do.');
        }

        return DB::transaction(function () use ($treatment, $note, $by) {
            $locked = $this->lockOpen($treatment);
            $locked->update(['status' => PatientTreatment::COMPLETED, 'completed_at' => now(), 'status_reason' => $note ? mb_substr(trim($note), 0, 500) : $locked->status_reason]);
            $this->recorded($locked, 'treatment_completed', 'treatment.completed', [], $by);

            return $locked;
        });
    }

    /**
     * Keep a stage-less treatment's session count in step with its completed
     * sessions (a staged treatment counts the procedures recorded on its stages).
     */
    public function refreshSessionCount(PatientTreatment $treatment): void
    {
        if ($treatment->stages_total > 0) {
            return;
        }
        $completed = PhysioSession::query()->withoutGlobalScopes()->where('clinic_id', $treatment->clinic_id)
            ->where('patient_treatment_id', $treatment->id)->where('status', PhysioSession::COMPLETED)->count();
        PatientTreatment::query()->withoutGlobalScopes()->whereKey($treatment->id)->update(['sessions_completed' => $completed, 'updated_at' => now()]);
    }

    private function checkedDiagnosis(int $clinicId, int $patientId, ?int $diagnosisId): ?int
    {
        if ($diagnosisId === null) {
            return null;
        }
        $owned = DB::table('clinical_diagnoses')->where('id', $diagnosisId)->where('clinic_id', $clinicId)->where('patient_id', $patientId)->exists();
        if (! $owned) {
            throw new InvalidArgumentException('That diagnosis is not one recorded for this patient.');
        }

        return $diagnosisId;
    }

    private function goal(?string $goal): ?string
    {
        $goal = trim((string) $goal);

        return $goal === '' ? null : mb_substr($goal, 0, 2000);
    }

    /**
     * @param  callable(PatientTreatment, PatientTreatmentStage): PatientTreatmentStage  $change
     */
    private function onStage(PatientTreatmentStage $stage, User $by, ClinicEmployee $performer, callable $change, string $event): PatientTreatmentStage
    {
        $treatment = PatientTreatment::query()->withoutGlobalScopes()->findOrFail($stage->patient_treatment_id);
        $this->access->assertCanExecute($by, $treatment, $performer);

        return DB::transaction(function () use ($treatment, $stage, $change, $event, $by) {
            $treatment = $this->lockOpen($treatment);
            $stage = PatientTreatmentStage::query()->withoutGlobalScopes()->whereKey($stage->id)->lockForUpdate()->firstOrFail();
            $stage = $change($treatment, $stage);
            $this->refreshProgress($treatment);
            $this->recorded($treatment, $event, "treatment.stage.{$stage->status}", ['stage' => $stage->name], $by);

            return $stage;
        });
    }

    /** A consultation named for treatment work must be this patient's, in this clinic, at a branch the user works at. */
    private function assertEncounterOf(PatientTreatment $treatment, ?ClinicalEncounter $encounter): void
    {
        if ($encounter && ($encounter->patient_id !== $treatment->patient_id || $encounter->clinic_id !== $treatment->clinic_id)) {
            throw new InvalidArgumentException('The consultation is for a different patient.');
        }
        $this->access->assertCanUseConsultation($encounter);
    }

    /** Earlier required stages must be closed before a later one moves. */
    private function assertPreviousClosed(PatientTreatment $treatment, PatientTreatmentStage $stage): void
    {
        $open = PatientTreatmentStage::query()->withoutGlobalScopes()->where('patient_treatment_id', $treatment->id)
            ->where('position', '<', $stage->position)->where('is_optional', false)
            ->whereNotIn('status', PatientTreatmentStage::CLOSED)->exists();
        if ($open) {
            throw new DomainException('Finish the earlier stages first.');
        }
    }

    private function refreshProgress(PatientTreatment $treatment): void
    {
        $stages = PatientTreatmentStage::query()->withoutGlobalScopes()->where('patient_treatment_id', $treatment->id)->get(['status', 'is_optional']);
        $closed = $stages->filter(fn ($stage) => in_array($stage->status, PatientTreatmentStage::CLOSED, true))->count();
        $update = ['stages_completed' => $closed];
        if ($stages->isNotEmpty() && $closed === $stages->count()) {
            $update += ['status' => PatientTreatment::COMPLETED, 'completed_at' => now()];
        } elseif ($treatment->status === PatientTreatment::PLANNED) {
            $update['status'] = PatientTreatment::IN_PROGRESS;
        }
        $treatment->update($update);
    }

    /**
     * @param  list<string>  $from
     */
    private function transition(PatientTreatment $treatment, User $by, ClinicEmployee $performer, array $from, string $to, ?string $reason): PatientTreatment
    {
        $this->access->assertCanExecute($by, $treatment, $performer);
        if ($to !== PatientTreatment::IN_PROGRESS && trim((string) $reason) === '') {
            throw new InvalidArgumentException('Give a reason.');
        }

        return DB::transaction(function () use ($treatment, $from, $to, $reason, $by) {
            $locked = PatientTreatment::query()->withoutGlobalScopes()->whereKey($treatment->id)->lockForUpdate()->firstOrFail();
            if (! in_array($locked->status, $from, true)) {
                throw new DomainException("A treatment that is {$locked->status} cannot become {$to}.");
            }
            $locked->update(['status' => $to, 'status_reason' => $reason ? mb_substr(trim($reason), 0, 500) : $locked->status_reason, 'cancelled_at' => $to === PatientTreatment::CANCELLED ? now() : $locked->cancelled_at]);
            $this->recorded($locked, "treatment_{$to}", "treatment.{$to}", ['reason' => $reason], $by);

            return $locked;
        });
    }

    private function lockOpen(PatientTreatment $treatment): PatientTreatment
    {
        $locked = PatientTreatment::query()->withoutGlobalScopes()->whereKey($treatment->id)->lockForUpdate()->firstOrFail();
        if (! $locked->isOpen()) {
            throw new DomainException($locked->status === PatientTreatment::PAUSED ? 'This treatment is paused. Resume it first.' : 'This treatment is closed.');
        }

        return $locked;
    }

    /**
     * The targets a version requires (declared by its specialty extension),
     * validated and normalised by their handlers.
     *
     * @param  array<string, list<array<string, mixed>>>  $given
     * @return array<string, list<array<string, mixed>>>
     */
    private function resolveTargets(CatalogTreatmentVersion $version, ?string $specialtyKey, array $given): array
    {
        $declared = $this->targets->forSpecialty($specialtyKey);
        $resolved = [];
        foreach ($given as $key => $items) {
            if (! in_array($key, $declared, true)) {
                throw new InvalidArgumentException("This treatment cannot be aimed at a “{$key}”.");
            }
            $resolved[$key] = $this->targets->handler($key)->normalize((array) $items);
        }
        foreach ($version->targets() as $required) {
            if (($resolved[$required] ?? []) === []) {
                $label = mb_strtolower($this->targets->label($required));
                throw new InvalidArgumentException("Which {$label} is this treatment for?");
            }
        }

        return array_filter($resolved);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function recorded(PatientTreatment $treatment, string $timelineEvent, string $auditEvent, array $context, User $by): void
    {
        PatientTimelineEvent::query()->withoutGlobalScopes()->create([
            'organization_id' => Patient::query()->withoutGlobalScopes()->whereKey($treatment->patient_id)->value('organization_id'),
            'clinic_id' => $treatment->clinic_id,
            'patient_id' => $treatment->patient_id,
            'actor_id' => $by->id,
            'event_type' => $timelineEvent,
            'context' => ['patient_treatment_id' => $treatment->id, 'title' => $treatment->title, ...$context],
        ]);
        $this->audit->record($auditEvent, $treatment, ['patient_id' => $treatment->patient_id, ...$context], ['clinic_id' => $treatment->clinic_id]);
    }
}
