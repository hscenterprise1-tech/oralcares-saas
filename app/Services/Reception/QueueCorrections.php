<?php

namespace App\Services\Reception;

use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicEmployee;
use App\Models\ClinicQueueItem;
use App\Models\ClinicSpecialty;
use App\Models\PatientVisit;
use App\Models\QueueHistory;
use App\QueueItemStatus;
use App\Services\Clinical\ClinicalContext;
use App\Services\Clinical\ClinicalContextResolver;
use App\Services\Security\SecurityAudit;
use App\Support\ClinicClock;
use App\Support\Specialty\SpecialtyRegistry;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Reception's corrections to a patient who has not been seen yet: which
 * doctor will see them, and why they came (specialty, visit type, reason).
 *
 * Only before the consultation: the queue entry is re-read under a row lock
 * and must still be waiting or called with no consultation opened, so a
 * doctor who has just started the visit always wins. The doctor must work at
 * this branch and practise the visit's specialty. Every change is written to
 * the queue history and the security audit; clinical records are never
 * touched.
 */
class QueueCorrections
{
    public function __construct(
        protected DoctorRoster $roster,
        protected SpecialtyRegistry $specialties,
        protected SecurityAudit $audit,
        protected ClinicalContextResolver $resolver,
    ) {}

    public function reassign(Clinic $clinic, Branch $branch, ClinicQueueItem $item, ClinicEmployee $doctor, ?ClinicEmployee $performer): ClinicQueueItem
    {
        if ($doctor->clinic_id !== $clinic->id || ! $this->roster->forBranch($clinic, $branch)->contains(fn (array $entry) => $entry['employee']->id === $doctor->id)) {
            throw new InvalidArgumentException('That doctor does not see patients at this branch.');
        }

        return DB::transaction(function () use ($clinic, $branch, $item, $doctor, $performer) {
            $item = $this->lockBeforeConsultation($item);
            if ($item->doctor_id === $doctor->id) {
                return $item;
            }
            $visit = $item->patientVisit()->withoutGlobalScopes()->first();
            $this->assertPractises($doctor, $visit);
            $area = $this->areaFor($clinic, $branch, $doctor, $visit, ClinicalContext::SELECTED);

            $from = $item->doctor()->withoutGlobalScopes()->value('name');
            // A patient called for the old doctor waits again for the new one.
            $item->update(['doctor_id' => $doctor->id, 'status' => QueueItemStatus::Waiting, 'called_at' => null]);
            $visit?->update(['doctor_id' => $doctor->id, 'practice_area_key' => $area]);

            $this->history($item, 'reassign', $performer, ($from ? "From {$from} to " : 'To ').$doctor->name);
            $this->audit->record('queue.doctor_changed', $item, ['from_doctor' => $from, 'to_doctor_id' => $doctor->id]);

            return $item->fresh(['doctor']);
        });
    }

    /**
     * @param  array{visit_specialty?: ?string, visit_type?: ?string, visit_reason?: ?string}  $input
     */
    public function correctVisit(Clinic $clinic, ClinicQueueItem $item, array $input, ?ClinicEmployee $performer): PatientVisit
    {
        $context = VisitContext::fromInput($clinic, $input);

        return DB::transaction(function () use ($clinic, $item, $context, $performer) {
            $item = $this->lockBeforeConsultation($item);
            $visit = $item->patientVisit()->withoutGlobalScopes()->lockForUpdate()->first()
                ?? throw new DomainException('This queue entry has no visit to correct.');

            $doctor = $item->doctor_id ? ClinicEmployee::query()->withoutGlobalScopes()->find($item->doctor_id) : null;
            $after = [
                'clinic_specialty_id' => $context->clinicSpecialtyId,
                'visit_type_key' => $context->visitTypeKey,
                'visit_reason' => $context->reason,
            ];
            if ($doctor && $context->specialtyKey !== null) {
                $this->assertPractises($doctor, new PatientVisit($after));
            }
            if ($doctor) {
                $branch = Branch::query()->withoutGlobalScopes()->find($visit->branch_id);
                $after['practice_area_key'] = $this->areaFor($clinic, $branch, $doctor, new PatientVisit($after), ClinicalContext::QUEUE);
            }

            $before = $visit->only(array_keys($after));
            $visit->update($after);

            $this->history($item, 'visit_corrected', $performer, null);
            $this->audit->record('visit.context_corrected', $visit, ['before' => $before, 'after' => $after]);

            return $visit;
        });
    }

    /** The entry, locked, only while nobody has started seeing the patient. */
    private function lockBeforeConsultation(ClinicQueueItem $item): ClinicQueueItem
    {
        $locked = ClinicQueueItem::query()->withoutGlobalScopes()->whereKey($item->id)->lockForUpdate()->firstOrFail();
        if (! in_array($locked->status, [QueueItemStatus::Waiting, QueueItemStatus::Called], true) || $locked->encounter_id !== null) {
            throw new DomainException('The doctor has already started this visit; it can no longer be changed here.');
        }

        return $locked;
    }

    private function assertPractises(ClinicEmployee $doctor, ?PatientVisit $visit): void
    {
        if (! $visit?->clinic_specialty_id) {
            return;
        }
        $key = ClinicSpecialty::query()->withoutGlobalScopes()->whereKey($visit->clinic_specialty_id)->value('specialty_key');
        $key = $key !== null ? ($this->specialties->normalize([$key])[0] ?? $key) : null;
        $practised = $doctor->practitionerProfile()->withoutGlobalScopes()->first()?->specialtyKeys() ?? [];
        if ($key !== null && ! in_array($key, $practised, true)) {
            $label = $this->specialties->get($key)['label'] ?? $key;
            throw new InvalidArgumentException("{$doctor->name} does not practise {$label}. Choose another doctor or change the visit's specialty.");
        }
    }

    /**
     * The practice area the doctor sees this visit under, worked out by the
     * same resolver as check-in; a doctor who cannot take it is refused.
     */
    private function areaFor(Clinic $clinic, ?Branch $branch, ClinicEmployee $doctor, ?PatientVisit $visit, string $source): ?string
    {
        $specialty = $visit?->clinic_specialty_id
            ? ClinicSpecialty::query()->withoutGlobalScopes()->whereKey($visit->clinic_specialty_id)->value('specialty_key')
            : null;

        try {
            return $this->resolver->resolve($clinic, $branch, [
                'practitioner' => $doctor->id,
                'specialty' => $specialty,
                'allow_on_leave' => $source === ClinicalContext::QUEUE,
            ])->practiceArea;
        } catch (ValidationException $e) {
            throw new InvalidArgumentException(collect($e->errors())->flatten()->first() ?? 'That doctor cannot take this visit.');
        }
    }

    private function history(ClinicQueueItem $item, string $action, ?ClinicEmployee $performer, ?string $notes): void
    {
        $queue = $item->queue()->withoutGlobalScopes()->first();
        QueueHistory::create([
            'clinic_id' => $queue->clinic_id,
            'branch_id' => $queue->branch_id,
            'clinic_queue_item_id' => $item->id,
            'action' => $action,
            'performed_by' => $performer?->id,
            'notes' => $notes,
            'created_at' => ClinicClock::now(),
        ]);
    }
}
