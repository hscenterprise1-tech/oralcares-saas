<?php

namespace App\Services\Clinical;

use App\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicalEncounter;
use App\Models\ClinicalOrderItem;
use App\Models\ClinicEmployee;
use App\Models\ClinicQueue;
use App\Models\ClinicQueueItem;
use App\Models\Patient;
use App\Models\User;
use App\QueueItemStatus;
use App\Services\Appointments\AppointmentPresenter;
use App\Services\Patient\CaseNumberFormatter;
use App\Services\Patient\PatientBranchAccess;
use App\Services\Patient\PatientClinicalEntries;
use App\Services\Reception\QueueBoard;
use App\Services\Reception\VisitLabels;
use App\Services\Treatment\TreatmentReadModel;
use App\Support\ClinicClock;
use App\Support\Specialty\SpecialtyRegistry;
use App\VisitPriority;
use App\VisitStatus;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * The doctor's Today workspace: who is waiting for them, who is with them and
 * who they have finished with, at the working branch, from the one queue
 * Reception checks patients into (no second queue).
 *
 * A practitioner sees their own patients plus walk-ins waiting for any doctor
 * (the same rule as `DoctorQueue`); a clinician who is not a practitioner sees
 * the branch's day. Each row carries only what the day needs: identity,
 * today's visit, safety flags and a short summary of open treatments. Deep
 * history stays in Patient 360.
 *
 * Bounded and batched: one query for the day's queues, one for the items (with
 * their visit, patient, case number and doctor), one for the consultations,
 * two for safety flags, one for open treatments, one for specialties. The
 * number of queries does not grow with the number of patients.
 */
class DoctorToday
{
    /** A branch's day never shows more than this many rows per list. */
    public const LIMIT = 200;

    /** Open treatments summarised on a row; the rest are counted. */
    private const TREATMENTS_PER_ROW = 3;

    public function __construct(
        protected VisitLabels $visitLabels,
        protected PatientClinicalEntries $entries,
        protected TreatmentReadModel $treatments,
        protected PatientBranchAccess $branchAccess,
        protected SpecialtyRegistry $specialties,
    ) {}

    /**
     * @return array{waiting: list<array<string, mixed>>, in_consultation: list<array<string, mixed>>, completed: list<array<string, mixed>>}
     */
    public function build(Clinic $clinic, Branch $branch, ?ClinicEmployee $doctor): array
    {
        $now = ClinicClock::now();
        $queueIds = ClinicQueue::query()->withoutGlobalScopes()
            ->where('clinic_id', $clinic->id)->where('branch_id', $branch->id)
            ->whereOnDate('queue_date', $now)->pluck('id');

        $items = $queueIds->isEmpty() ? collect() : ClinicQueueItem::query()->withoutGlobalScopes()
            ->whereIn('clinic_queue_id', $queueIds)
            ->whereIn('status', [QueueItemStatus::Waiting, QueueItemStatus::Called, QueueItemStatus::InConsultation, QueueItemStatus::Completed])
            ->when($doctor, fn ($query) => $query->where(fn ($mine) => $mine->where('doctor_id', $doctor->id)
                ->orWhere(fn ($any) => $any->whereNull('doctor_id')->where('status', QueueItemStatus::Waiting))))
            ->with([
                'patientVisit' => fn ($q) => $q->withoutGlobalScopes(),
                'patientVisit.patient' => fn ($q) => $q->withoutGlobalScopes(),
                'patientVisit.caseNumber',
                'doctor:id,name',
                'appointment' => fn ($q) => $q->withoutGlobalScopes(),
            ])
            ->limit(self::LIMIT * 2)
            ->get();

        // Consultations are stamped in real UTC; the clinic's day starts at its own midnight.
        $today = CarbonImmutable::now(ClinicClock::timezone())->startOfDay()->utc();
        $encounters = ClinicalEncounter::query()->withoutGlobalScopes()
            ->where('clinic_id', $clinic->id)->where('branch_id', $branch->id)
            ->when($doctor, fn ($query) => $query->where('doctor_id', $doctor->id))
            ->where(fn ($query) => $query->where('status', VisitStatus::InProgress->value)->orWhere('started_at', '>=', $today))
            ->with(['patient' => fn ($q) => $q->withoutGlobalScopes(), 'doctor:id,name'])
            ->latest('started_at')->limit(self::LIMIT)->get()->keyBy('id');

        $patients = $items->map(fn (ClinicQueueItem $item) => $item->patientVisit?->patient)->filter()
            ->merge($encounters->pluck('patient')->filter())->keyBy('id');
        $patientIds = $patients->keys()->all();
        $safety = $this->entries->safetyFlags($clinic->id, $patientIds);
        $care = $this->treatments->activeCareFor($clinic->id, $patientIds, $this->branchAccess->branchIds());

        $row = fn (?ClinicQueueItem $item, ?ClinicalEncounter $encounter) => $this->row($clinic, $item, $encounter, $doctor, $safety, $care, $now);

        $linked = $items->pluck('encounter_id')->filter()->flip();
        $loose = $encounters->reject(fn (ClinicalEncounter $e) => $linked->has($e->id));

        $waiting = QueueBoard::order($items->whereIn('status', [QueueItemStatus::Waiting, QueueItemStatus::Called]), $now)
            ->take(self::LIMIT)->map(fn (ClinicQueueItem $item) => $row($item, null));

        $inConsultation = $items->where('status', QueueItemStatus::InConsultation)->sortBy('started_at')
            ->map(fn (ClinicQueueItem $item) => $row($item, $encounters->get($item->encounter_id)))
            ->concat($loose->filter(fn (ClinicalEncounter $e) => $e->isEditable())->map(fn (ClinicalEncounter $e) => $row(null, $e)));

        $completed = $items->where('status', QueueItemStatus::Completed)->sortByDesc('completed_at')
            ->map(fn (ClinicQueueItem $item) => $row($item, $encounters->get($item->encounter_id)))
            ->concat($loose->filter(fn (ClinicalEncounter $e) => $e->isFinalized())->map(fn (ClinicalEncounter $e) => $row(null, $e)));

        return [
            'waiting' => $waiting->values()->all(),
            'in_consultation' => $inConsultation->take(self::LIMIT)->values()->all(),
            'completed' => $completed->take(self::LIMIT)->values()->all(),
        ];
    }

    /**
     * Results back from the lab or imaging for orders this member placed,
     * still waiting for their review (`ClinicalOrderItem::RESULTED`), oldest
     * first: the first ten, and how many there are.
     *
     * @return array{total: int, rows: list<array<string, mixed>>}
     */
    public function resultsToReview(Clinic $clinic, User $user): array
    {
        $query = ClinicalOrderItem::query()->withoutGlobalScopes()->where('clinic_id', $clinic->id)->where('status', ClinicalOrderItem::RESULTED)
            ->whereHas('order', fn ($order) => $order->withoutGlobalScopes()->where('clinic_id', $clinic->id)->where('ordered_by', $user->id));

        $rows = (clone $query)->with(['order' => fn ($order) => $order->withoutGlobalScopes()->select(['id', 'patient_id'])->with(['patient' => fn ($patient) => $patient->withoutGlobalScopes()->select(['id', 'first_name', 'last_name', 'uhid'])])])
            ->orderBy('resulted_at')->limit(10)->get(['id', 'clinical_order_id', 'item_name', 'category', 'resulted_at']);

        return [
            'total' => $query->count(),
            'rows' => $rows->map(fn (ClinicalOrderItem $item) => [
                'id' => $item->id,
                'test' => $item->item_name,
                'category' => $item->category,
                'patient' => $item->order?->patient ? ['name' => $item->order->patient->full_name, 'uhid' => $item->order->patient->uhid] : null,
                'resulted_at' => $item->resulted_at?->toIso8601String(),
                'waiting_days' => $item->resulted_at ? (int) $item->resulted_at->diffInDays(now()) : null,
                'url' => route('clinic.orders.show', $item->clinical_order_id),
            ])->values()->all(),
        ];
    }

    /**
     * The practitioner's booked day at this branch, for the dashboard's
     * appointments panel: the same appointments and statuses the calendar
     * shows (`AppointmentPresenter`), in time order, with the safety flags
     * of the patients on it. Cancelled bookings are left out; no-shows stay.
     *
     * @return list<array<string, mixed>>
     */
    public function appointments(Clinic $clinic, Branch $branch, ClinicEmployee $doctor, CarbonImmutable $date): array
    {
        $appointments = Appointment::query()->withoutGlobalScopes()
            ->where('clinic_id', $clinic->id)->where('branch_id', $branch->id)
            ->where('practitioner_id', $doctor->id)
            ->whereBetween('scheduled_at', [$date->startOfDay(), $date->endOfDay()])
            ->where('status', '!=', AppointmentStatus::Cancelled->value)
            ->with(['patient' => fn ($q) => $q->withoutGlobalScopes()->select(['id', 'clinic_id', 'first_name', 'middle_name', 'last_name', 'name', 'uhid', 'phone']), 'practitioner:id,name', 'service:id,name', 'queueItem'])
            ->orderBy('scheduled_at')->limit(self::LIMIT)->get();
        $safety = $this->entries->safetyFlags($clinic->id, $appointments->pluck('patient_id')->filter()->unique()->values()->all());

        return $appointments->map(function (Appointment $appointment) use ($safety) {
            $row = AppointmentPresenter::toArray($appointment, false);

            return [
                'id' => $row['id'],
                'start' => $row['start'],
                'end' => $row['end'],
                'patient' => $row['patient'] ? collect($row['patient'])->only(['id', 'name', 'uhid'])->all() : null,
                'new_case' => $row['new_case'] ? ['name' => $row['new_case']['name']] : null,
                'service' => $row['service']['name'] ?? null,
                'status' => $row['display_status'],
                'safety' => $appointment->patient_id ? array_slice($safety[$appointment->patient_id] ?? [], 0, 2) : [],
                'patient_url' => $appointment->patient_id ? route('clinic.patients.show', $appointment->patient_id) : null,
            ];
        })->values()->all();
    }

    /**
     * @param  array<int, list<array<string, mixed>>>  $safety
     * @param  array<int, list<array<string, mixed>>>  $care
     * @return array<string, mixed>
     */
    private function row(Clinic $clinic, ?ClinicQueueItem $item, ?ClinicalEncounter $encounter, ?ClinicEmployee $doctor, array $safety, array $care, DateTimeInterface $now): array
    {
        $visit = $item?->patientVisit;
        /** @var Patient|null $patient */
        $patient = $visit?->patient ?? $encounter?->patient;
        $treatments = $patient ? ($care[$patient->id] ?? []) : [];
        $status = $item?->status->value ?? ($encounter?->isFinalized() ? 'completed' : 'in_consultation');
        $mineToStart = $item && $doctor && in_array($item->status, [QueueItemStatus::Waiting, QueueItemStatus::Called, QueueItemStatus::InConsultation], true)
            && ($item->doctor_id === null || $item->doctor_id === $doctor->id);

        return [
            'key' => $item ? "q{$item->id}" : "e{$encounter?->id}",
            'queue_item_id' => $item?->id,
            'status' => $status,
            'priority' => ($item?->priority ?? VisitPriority::Normal)->value,
            'patient' => $patient ? [
                'id' => $patient->id,
                'name' => $patient->full_name,
                'uhid' => $patient->uhid,
                'age' => $patient->age,
                'gender' => $patient->gender,
            ] : null,
            'case' => $visit?->caseNumber ? CaseNumberFormatter::shortLabel($visit->caseNumber->sequence) : null,
            'visit' => collect($this->visitLabels->for($clinic, $visit, $encounter?->specialty, $encounter?->chief_complaint))->except('type_key')->all(),
            'doctor' => ($item?->doctor ?? $encounter?->doctor)?->name,
            // Queue times are the clinic's wall clock (see ClinicClock): sent without an offset, shown as they are.
            'booked_for' => $item?->appointment?->scheduled_at?->format(QueueBoard::DATETIME),
            'checked_in_at' => $item?->checked_in_at?->format(QueueBoard::DATETIME),
            'started_at' => $item?->started_at?->format(QueueBoard::DATETIME) ?? $encounter?->started_at?->toIso8601String(),
            'completed_at' => $item?->completed_at?->format(QueueBoard::DATETIME) ?? $encounter?->finalized_at?->toIso8601String(),
            'wait_minutes' => $item?->checked_in_at && in_array($item->status, [QueueItemStatus::Waiting, QueueItemStatus::Called], true)
                ? (int) $item->checked_in_at->diffInMinutes($now) : null,
            'safety' => $patient ? array_slice($safety[$patient->id] ?? [], 0, 3) : [],
            'safety_more' => $patient ? max(0, count($safety[$patient->id] ?? []) - 3) : 0,
            'treatments' => array_slice($treatments, 0, self::TREATMENTS_PER_ROW),
            'treatments_more' => max(0, count($treatments) - self::TREATMENTS_PER_ROW),
            'encounter' => $encounter ? [
                'id' => $encounter->id,
                'number' => $encounter->encounter_number,
                'status' => $encounter->isFinalized() ? 'signed' : 'draft',
                'url' => route('clinic.consultations.show', $encounter),
            ] : null,
            'links' => [
                'start' => $mineToStart ? route('clinic.consultations.start-from-queue', $item) : null,
                'patient' => $patient ? route('clinic.patients.show', $patient->id) : null,
            ],
        ];
    }
}
