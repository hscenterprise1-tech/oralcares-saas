<?php

namespace App\Services\Clinical;

use App\ClinicDocument;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicalDiagnosis;
use App\Models\ClinicalEncounter;
use App\Models\ClinicalEncounterAddendum;
use App\Models\ClinicalNote;
use App\Models\ClinicalProcedure;
use App\Models\ClinicEmployee;
use App\Models\ClinicQueueItem;
use App\Models\ClinicSpecialty;
use App\Models\Patient;
use App\Models\PatientProcedure;
use App\Models\PatientTimelineEvent;
use App\Models\PatientVisit;
use App\QueueItemStatus;
use App\Services\ClinicalPrescriptionService;
use App\Services\Numbering\ClinicDocumentNumbers;
use App\Services\Patient\FollowUps;
use App\Services\Patient\PatientCareGuard;
use App\Services\Patient\PatientSpecialties;
use App\Services\QueueStateMachine;
use App\Services\Security\SecurityAudit;
use App\Support\Specialty\SpecialtyEntitlements;
use App\Support\Specialty\SpecialtyRegistry;
use App\VisitStatus;
use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The consultation workspace's one writer, on the canonical clinical anchor
 * `clinical_encounters` (ADR-001 §E).
 *
 * Lifecycle: started (from the doctor's queue, or directly) → drafted and
 * autosaved → finalised and signed → only addenda and amendments after that.
 * Every child record inherits the encounter's clinic. Authorisation is the
 * caller's (`ClinicalEncounterPolicy`); this class enforces the record's own
 * rules — ownership, state and order.
 */
class EncounterWorkspace
{
    public function __construct(
        protected ClinicDocumentNumbers $numbers,
        protected QueueStateMachine $queue,
        protected SpecialtyRegistry $specialties,
        protected SecurityAudit $audit,
        protected ClinicalPrescriptionService $prescriptions,
        protected FollowUps $followUps,
        protected PatientSpecialties $patientSpecialties,
        protected SpecialtyEntitlements $entitlements,
    ) {}

    /**
     * Start (or re-open) the consultation for a patient the doctor has
     * called from the queue.
     */
    public function startFromQueue(ClinicQueueItem $item, ClinicEmployee $doctor, ?string $specialty = null): ClinicalEncounter
    {
        return DB::transaction(function () use ($item, $doctor, $specialty) {
            /** @var ClinicQueueItem $item */
            $item = ClinicQueueItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();

            // A walk-in for "any doctor" is taken by whoever starts it; once taken, it is theirs.
            if ($item->doctor_id !== null && $item->doctor_id !== $doctor->id) {
                throw new DomainException('Another doctor has already taken this patient in.');
            }

            if ($item->encounter_id) {
                return ClinicalEncounter::query()->withoutGlobalScopes()->findOrFail($item->encounter_id);
            }

            $queue = $item->queue()->withoutGlobalScopes()->firstOrFail();
            $visit = $item->patientVisit()->withoutGlobalScopes()->first()
                ?? throw new DomainException('This queue entry has no registered visit to consult on.');
            $branch = Branch::query()->withoutGlobalScopes()->findOrFail($queue->branch_id);

            if (! in_array($item->status, [QueueItemStatus::Called, QueueItemStatus::Waiting, QueueItemStatus::InConsultation], true)) {
                throw new DomainException('This patient is no longer waiting to be seen.');
            }

            // The specialty Reception checked the patient in for is the consultation's, unless one is named.
            if ($specialty === null && $visit->clinic_specialty_id) {
                $checkedInFor = $this->specialties->normalize([ClinicSpecialty::query()->withoutGlobalScopes()->whereKey($visit->clinic_specialty_id)->value('specialty_key')])[0] ?? null;
                $specialty = in_array($checkedInFor, $this->specialties->forBranch($branch), true) ? $checkedInFor : null;
            }
            $encounter = $this->open($visit->patient()->withoutGlobalScopes()->firstOrFail(), $branch, $doctor, $visit, $specialty);

            $item->update(['encounter_id' => $encounter->id, 'doctor_id' => $doctor->id]);
            if ($item->status !== QueueItemStatus::InConsultation) {
                $this->queue->start($item, $doctor);
            }

            return $encounter;
        });
    }

    /**
     * Start a consultation without the queue (a review, a physiotherapy
     * session booked straight to the therapist).
     */
    public function startForPatient(Patient $patient, Branch $branch, ClinicEmployee $doctor, ?PatientVisit $visit = null, ?string $specialty = null): ClinicalEncounter
    {
        app(PatientCareGuard::class)->assertCanReceiveCare($patient);

        return DB::transaction(fn () => $this->open($patient, $branch, $doctor, $visit, $specialty));
    }

    /**
     * Save the narrative while the consultation runs. `$version` is the
     * `lock_version` the screen last saw: if the record moved on since (a
     * second tab, a colleague), nothing is written and the caller gets the
     * current record to reconcile.
     *
     * @param  array{chief_complaint?: ?string, history?: ?string, examination?: ?string, follow_up_note?: ?string, follow_up_on?: ?string}  $data
     */
    public function autosave(ClinicalEncounter $encounter, array $data, int $version): ClinicalEncounter
    {
        return DB::transaction(function () use ($encounter, $data, $version) {
            $locked = $this->lockEditable($encounter);

            if ($locked->lock_version !== $version) {
                throw new StaleEncounterException($locked);
            }

            $changes = [];
            foreach (ClinicalEncounter::NARRATIVE as $field) {
                if (array_key_exists($field, $data)) {
                    $changes[$field] = $this->clean($data[$field]);
                }
            }
            if (array_key_exists('follow_up_on', $data)) {
                $changes['follow_up_on'] = $data['follow_up_on'] ?: null;
            }

            // A draft is not the record yet: saved quietly, without an audit
            // entry per keystroke. Signing is what is audited.
            $locked->forceFill([...$changes, 'lock_version' => $locked->lock_version + 1, 'last_autosaved_at' => now()])->saveQuietly();

            return $locked;
        });
    }

    /**
     * @param  array{description: string, code?: ?string, coding_system?: ?string, status?: string, is_primary?: bool, diagnosis_master_id?: ?int}  $data
     */
    public function addDiagnosis(ClinicalEncounter $encounter, array $data): ClinicalDiagnosis
    {
        return DB::transaction(function () use ($encounter, $data) {
            $locked = $this->lockEditable($encounter);
            $primary = (bool) ($data['is_primary'] ?? ! $locked->diagnoses()->exists());

            if ($primary) {
                $locked->diagnoses()->update(['is_primary' => false]);
            }

            return $locked->diagnoses()->create([
                'clinic_id' => $locked->clinic_id,
                'organization_id' => $locked->organization_id,
                'patient_id' => $locked->patient_id,
                'diagnosis_master_id' => $data['diagnosis_master_id'] ?? null,
                'coding_system' => $data['coding_system'] ?? (! empty($data['code']) ? 'ICD-10' : null),
                'code' => $data['code'] ?? null,
                'description' => trim($data['description']),
                'status' => $data['status'] ?? 'provisional',
                'is_primary' => $primary,
                'diagnosed_by' => auth()->id(),
            ]);
        });
    }

    public function removeDiagnosis(ClinicalEncounter $encounter, ClinicalDiagnosis $diagnosis): void
    {
        DB::transaction(function () use ($encounter, $diagnosis) {
            $locked = $this->lockEditable($encounter);
            $this->assertChild($locked, $diagnosis->encounter_id);
            $diagnosis->delete();

            if ($diagnosis->is_primary) {
                $locked->diagnoses()->oldest('id')->limit(1)->update(['is_primary' => true]);
            }
        });
    }

    /**
     * Record a procedure done in this consultation, from the clinic's own
     * service catalogue (which is what billing reads).
     *
     * @param  array{clinical_procedure_id: int, quantity?: int, notes?: ?string, details?: array<string, mixed>}  $data
     */
    public function addProcedure(ClinicalEncounter $encounter, array $data): PatientProcedure
    {
        return DB::transaction(function () use ($encounter, $data) {
            $locked = $this->lockEditable($encounter);

            $service = ClinicalProcedure::query()->withoutGlobalScopes()
                ->where('clinic_id', $locked->clinic_id)
                ->whereKey($data['clinical_procedure_id'])
                ->first() ?? throw new InvalidArgumentException('That service is not in this clinic\'s catalogue.');
            // A specialty's service is billable only while the clinic's plan sells that specialty.
            if ($service->specialty_key !== null && ! $this->entitlements->allows($locked->clinic()->withoutGlobalScopes()->firstOrFail(), $service->specialty_key)) {
                throw new InvalidArgumentException("“{$service->name}” belongs to a specialty that is not part of this clinic's plan.");
            }

            return $locked->procedures()->create([
                'clinic_id' => $locked->clinic_id,
                'organization_id' => $locked->organization_id,
                'patient_id' => $locked->patient_id,
                'clinical_procedure_id' => $service->id,
                'code' => $service->code,
                'name' => $service->name,
                'quantity' => max(1, (int) ($data['quantity'] ?? 1)),
                'description' => $this->clean($data['notes'] ?? null),
                'details' => $data['details'] ?? [],
                'status' => 'performed',
                'performed_by' => auth()->id(),
                'performed_at' => now(),
            ]);
        });
    }

    public function removeProcedure(ClinicalEncounter $encounter, PatientProcedure $procedure): void
    {
        DB::transaction(function () use ($encounter, $procedure) {
            $locked = $this->lockEditable($encounter);
            $this->assertChild($locked, $procedure->encounter_id);
            // Treatment work is part of a treatment's progress and charges; it is
            // never silently deleted from the consultation.
            if ($procedure->patient_treatment_id !== null) {
                throw new DomainException('This procedure is part of a treatment and cannot be removed here.');
            }
            $procedure->delete();
        });
    }

    public function addNote(ClinicalEncounter $encounter, string $type, string $text): ClinicalNote
    {
        return DB::transaction(function () use ($encounter, $type, $text) {
            $locked = $this->lockEditable($encounter);

            return $locked->clinicalNotes()->create([
                'clinic_id' => $locked->clinic_id,
                'organization_id' => $locked->organization_id,
                'patient_id' => $locked->patient_id,
                'author_id' => auth()->id(),
                'note_type' => $type,
                'content' => ['text' => trim($text)],
                'status' => 'draft',
            ]);
        });
    }

    /**
     * Sign the consultation. Its content is fixed from here (a hash of it is
     * kept), the doctor's consultation and the queue entry end, and any draft
     * notes are signed with it.
     */
    public function finalize(ClinicalEncounter $encounter, ClinicEmployee $doctor): ClinicalEncounter
    {
        return DB::transaction(function () use ($encounter, $doctor) {
            $locked = $this->lockEditable($encounter);

            if (trim((string) $locked->chief_complaint) === '' && ! $locked->diagnoses()->exists()) {
                throw new DomainException('Record the chief complaint or a diagnosis before signing.');
            }

            $now = now();
            $locked->clinicalNotes()->where('status', 'draft')->update(['status' => 'signed', 'signed_at' => $now]);
            $this->prescriptions->settleDraftsOf($locked, $doctor);

            $locked->fill([
                'doctor_id' => $locked->doctor_id ?? $doctor->id,
                'status' => VisitStatus::Completed,
                'ended_at' => $now,
                'finalized_at' => $now,
                'finalized_by' => $doctor->id,
            ]);
            $locked->signature_hash = hash('sha256', json_encode($this->signedContent($locked)));
            $locked->lock_version += 1;
            $locked->save();

            $item = ClinicQueueItem::query()->where('encounter_id', $locked->id)->lockForUpdate()->first();
            if ($item && $item->status === QueueItemStatus::InConsultation) {
                $this->queue->complete($item, $doctor, 'Consultation signed');
            }

            $this->timeline($locked, 'encounter_finalized', ['encounter_number' => $locked->encounter_number]);
            $this->followUps->fromEncounter($locked);
            $this->audit->record('encounter.finalized', $locked, ['encounter_number' => $locked->encounter_number], ['clinic_id' => $locked->clinic_id]);

            return $locked;
        });
    }

    /**
     * Add to a signed encounter. An amendment corrects one narrative section
     * and must give a reason; only the doctor who signed may amend.
     */
    public function addAddendum(ClinicalEncounter $encounter, ClinicEmployee $author, string $content, string $kind = ClinicalEncounterAddendum::ADDENDUM, ?string $section = null, ?string $reason = null): ClinicalEncounterAddendum
    {
        return DB::transaction(function () use ($encounter, $author, $content, $kind, $section, $reason) {
            /** @var ClinicalEncounter $locked */
            $locked = ClinicalEncounter::query()->withoutGlobalScopes()->whereKey($encounter->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isFinalized()) {
                throw new DomainException('An unsigned consultation is edited directly, not amended.');
            }

            $previous = null;
            if ($kind === ClinicalEncounterAddendum::AMENDMENT) {
                if (! in_array($section, ClinicalEncounter::NARRATIVE, true)) {
                    throw new InvalidArgumentException('Choose the section the amendment corrects.');
                }
                if (trim((string) $reason) === '') {
                    throw new InvalidArgumentException('An amendment needs a reason.');
                }
                if ($author->id !== $locked->finalized_by) {
                    throw new DomainException('Only the doctor who signed this consultation can amend it.');
                }
                $previous = $locked->addenda()->where('kind', ClinicalEncounterAddendum::AMENDMENT)->where('section', $section)
                    ->latest('id')->value('content') ?? $locked->getAttribute($section);
            } elseif ($kind !== ClinicalEncounterAddendum::ADDENDUM) {
                throw new InvalidArgumentException('Unknown addendum kind.');
            }

            $signedAt = now();
            $addendum = $locked->addenda()->create([
                'clinic_id' => $locked->clinic_id,
                'kind' => $kind,
                'section' => $kind === ClinicalEncounterAddendum::AMENDMENT ? $section : null,
                'content' => trim($content),
                'previous_content' => $previous,
                'reason' => $reason ? trim($reason) : null,
                'author_id' => $author->id,
                'signed_at' => $signedAt,
                'content_hash' => hash('sha256', json_encode([$locked->signature_hash, $kind, $section, trim($content), $author->id, $signedAt->toIso8601String()])),
            ]);

            $this->timeline($locked, "encounter_{$kind}", ['encounter_number' => $locked->encounter_number, 'section' => $section]);
            $this->audit->record("encounter.{$kind}", $locked, ['addendum_id' => $addendum->id, 'section' => $section, 'reason' => $reason], ['clinic_id' => $locked->clinic_id]);

            return $addendum;
        });
    }

    /**
     * The narrative as it reads now: each section's latest amendment, else
     * what was signed.
     *
     * @return array<string, ?string>
     */
    public function effectiveNarrative(ClinicalEncounter $encounter): array
    {
        $amended = $encounter->addenda()->where('kind', ClinicalEncounterAddendum::AMENDMENT)->orderBy('id')->get(['section', 'content'])
            ->pluck('content', 'section');

        $sections = [];
        foreach (ClinicalEncounter::NARRATIVE as $field) {
            $sections[$field] = $amended[$field] ?? $encounter->getAttribute($field);
        }

        return $sections;
    }

    /**
     * Whether a signed encounter still matches the hash taken when it was
     * signed (nothing changed it outside the workspace).
     */
    public function signatureIsIntact(ClinicalEncounter $encounter): bool
    {
        return $encounter->isFinalized()
            && hash_equals((string) $encounter->signature_hash, hash('sha256', json_encode($this->signedContent($encounter))));
    }

    private function open(Patient $patient, Branch $branch, ClinicEmployee $doctor, ?PatientVisit $visit, ?string $specialty): ClinicalEncounter
    {
        if ($patient->clinic_id !== $branch->clinic_id || $doctor->clinic_id !== $branch->clinic_id) {
            throw new InvalidArgumentException('The patient, branch and doctor must belong to one clinic.');
        }
        if ($visit && ($visit->patient_id !== $patient->id || $visit->clinic_id !== $branch->clinic_id)) {
            throw new InvalidArgumentException('The visit belongs to a different patient.');
        }

        if ($visit) {
            $existing = ClinicalEncounter::query()->withoutGlobalScopes()
                ->where('clinic_id', $branch->clinic_id)->where('patient_visit_id', $visit->id)
                ->where('status', VisitStatus::InProgress->value)->first();
            if ($existing) {
                return $existing;
            }
        }

        $clinic = Clinic::query()->findOrFail($branch->clinic_id);
        $practised = $this->specialties->forBranch($branch, $clinic);
        $specialty = $specialty !== null ? ($this->specialties->normalize([$specialty])[0] ?? null) : null;
        if ($specialty !== null && ! in_array($specialty, $practised, true)) {
            throw new InvalidArgumentException('This branch does not practise that specialty.');
        }

        $now = now();
        $encounter = ClinicalEncounter::query()->create([
            'organization_id' => $clinic->organization_id,
            'clinic_id' => $clinic->id,
            'branch_id' => $branch->id,
            'patient_id' => $patient->id,
            'patient_visit_id' => $visit?->id,
            'doctor_id' => $doctor->id,
            'encounter_number' => $this->numbers->next(ClinicDocument::Encounter, $clinic),
            'encounter_type' => 'consultation',
            'specialty' => $specialty ?? (count($practised) === 1 ? $practised[0] : null),
            'status' => VisitStatus::InProgress,
            'started_at' => $now,
        ]);

        $visit?->update(['visit_status' => 'in_progress']);

        // Starting care in a specialty is an explicit clinical act: it enrols
        // the patient there if they are not already (the observer only moves
        // `last_activity_at` for ordinary activity).
        if ($encounter->specialty) {
            $this->patientSpecialties->enrollIfActive($patient, $encounter->specialty, 'encounter', $doctor->user, $now);
        }

        $this->timeline($encounter, 'encounter_started', ['encounter_number' => $encounter->encounter_number, 'doctor' => $doctor->name]);

        return $encounter;
    }

    private function lockEditable(ClinicalEncounter $encounter): ClinicalEncounter
    {
        /** @var ClinicalEncounter $locked */
        $locked = ClinicalEncounter::query()->withoutGlobalScopes()->whereKey($encounter->id)->lockForUpdate()->firstOrFail();

        if (! $locked->isEditable()) {
            throw new DomainException('This consultation is signed. Add an addendum instead.');
        }

        return $locked;
    }

    private function assertChild(ClinicalEncounter $encounter, ?int $encounterId): void
    {
        if ($encounterId !== $encounter->id) {
            throw new InvalidArgumentException('That record belongs to a different consultation.');
        }
    }

    private function clean(mixed $text): ?string
    {
        $text = is_string($text) ? trim($text) : null;

        return $text === '' ? null : $text;
    }

    /**
     * What a signature covers: the narrative, findings and every clinical
     * child as they stand at signing.
     *
     * @return array<string, mixed>
     */
    private function signedContent(ClinicalEncounter $encounter): array
    {
        return [
            'encounter' => [$encounter->id, $encounter->encounter_number, $encounter->clinic_id, $encounter->patient_id, $encounter->doctor_id],
            'narrative' => array_map(fn (string $field) => $encounter->getAttribute($field), ClinicalEncounter::NARRATIVE),
            // Vitals are no longer recorded (null on every new encounter); kept here so signatures made before still verify.
            'vitals' => $encounter->vitals,
            'follow_up_on' => $encounter->follow_up_on?->toDateString(),
            'diagnoses' => $encounter->diagnoses()->orderBy('id')->get(['id', 'code', 'description', 'status', 'is_primary'])->toArray(),
            'procedures' => $encounter->procedures()->orderBy('id')->get(['id', 'clinical_procedure_id', 'name', 'quantity'])->toArray(),
            'notes' => $encounter->clinicalNotes()->orderBy('id')->get(['id', 'note_type', 'content'])->toArray(),
            'prescriptions' => $encounter->prescriptions()->orderBy('id')->pluck('prescription_number')->all(),
            'finalized' => [$encounter->finalized_by, $encounter->finalized_at?->toIso8601String()],
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function timeline(ClinicalEncounter $encounter, string $event, array $context): void
    {
        PatientTimelineEvent::create([
            'organization_id' => $encounter->organization_id,
            'clinic_id' => $encounter->clinic_id,
            'patient_id' => $encounter->patient_id,
            'actor_id' => auth()->id(),
            'event_type' => $event,
            'context' => ['encounter_id' => $encounter->id, ...$context],
        ]);
    }
}
