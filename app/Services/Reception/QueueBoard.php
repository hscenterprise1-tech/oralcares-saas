<?php

namespace App\Services\Reception;

use App\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicQueue;
use App\Models\ClinicQueueItem;
use App\Models\QueueHistory;
use App\QueueItemStatus;
use App\Services\Patient\CaseNumberFormatter;
use App\Support\ClinicClock;
use App\VisitPriority;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Everything the Live Queue shows, read from real rows only: today's queue
 * items, today's appointments, `queue_histories`, leave and shift data.
 *
 * Order is automatic: emergencies first, then by "effective time" — a booked
 * patient who arrived by their slot (plus the late threshold) keeps their
 * slot time, anyone else queues from when they checked in. There is no
 * manual reordering.
 */
class QueueBoard
{
    public const DATETIME = 'Y-m-d\TH:i:s';

    public function __construct(
        protected DoctorRoster $roster,
        protected PatientFlowConfigService $flowConfig,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forBranch(Clinic $clinic, Branch $branch, ?CarbonImmutable $now = null): array
    {
        $now ??= ClinicClock::now();
        $roomsEnabled = $this->flowConfig->roomsEnabled($clinic);

        $items = $this->todaysItems($clinic, $branch, $now);
        $appointments = Appointment::withoutGlobalScopes()
            ->where('clinic_id', $clinic->id)
            ->where('branch_id', $branch->id)
            ->whereDate('scheduled_at', $now->toDateString())
            ->with(['patient', 'practitioner', ...($roomsEnabled ? ['room'] : [])])
            ->orderBy('scheduled_at')
            ->get();

        $roster = $this->roster->forBranch($clinic, $branch, $now);
        $doctorRooms = $roster->mapWithKeys(fn (array $entry) => [$entry['employee']->id => $entry['room']]);
        $active = self::order($items->filter(fn (ClinicQueueItem $item) => in_array($item->status, [
            QueueItemStatus::Waiting, QueueItemStatus::Called, QueueItemStatus::InConsultation, QueueItemStatus::Skipped,
        ], true)), $now);

        return [
            'now' => $now->format(self::DATETIME),
            'rooms_enabled' => $roomsEnabled,
            'thresholds' => config('reception.thresholds'),
            'summary' => $this->summary($items, $appointments),
            'attention' => $this->attention($active, $appointments, $roster, $now),
            'queue' => $active->map(fn (ClinicQueueItem $item) => $this->present($item, $now, $roomsEnabled, $doctorRooms))->values()->all(),
            'doctors' => $this->doctors($roster, $items),
            'booking_status' => $this->bookingStatus($appointments),
            'wait_times' => $this->waitTimes($items),
            'activity' => $this->activity($items),
        ];
    }

    /**
     * @return Collection<int, ClinicQueueItem>
     */
    public function todaysItems(Clinic $clinic, Branch $branch, CarbonImmutable $now): Collection
    {
        $queueIds = ClinicQueue::withoutGlobalScopes()
            ->where('clinic_id', $clinic->id)
            ->where('branch_id', $branch->id)
            ->whereDate('queue_date', $now->toDateString())
            ->pluck('id');

        return ClinicQueueItem::whereIn('clinic_queue_id', $queueIds)
            ->with(['patientVisit' => fn ($q) => $q->withoutGlobalScopes(), 'patientVisit.patient' => fn ($q) => $q->withoutGlobalScopes(), 'patientVisit.caseNumber', 'doctor', 'appointment' => fn ($q) => $q->withoutGlobalScopes()])
            ->get();
    }

    /**
     * Emergencies first, then effective time, then check-in order.
     *
     * @param  Collection<int, ClinicQueueItem>  $items
     * @return Collection<int, ClinicQueueItem>
     */
    public static function order(Collection $items, CarbonImmutable $now): Collection
    {
        $grace = (int) config('reception.thresholds.late_arrival_minutes', 15);

        return $items->sortBy(fn (ClinicQueueItem $item) => [
            $item->priority === VisitPriority::Emergency ? 0 : 1,
            self::effectiveTime($item, $grace)?->getTimestamp() ?? PHP_INT_MAX,
            $item->position,
        ])->values();
    }

    public static function effectiveTime(ClinicQueueItem $item, int $graceMinutes): ?CarbonInterface
    {
        $slot = $item->appointment?->scheduled_at;

        if ($slot && $item->checked_in_at && $item->checked_in_at->lte($slot->copy()->addMinutes($graceMinutes))) {
            return $slot;
        }

        return $item->checked_in_at;
    }

    /**
     * @param  Collection<int, string|null>  $doctorRooms
     * @return array<string, mixed>
     */
    public function present(ClinicQueueItem $item, CarbonImmutable $now, bool $roomsEnabled, Collection $doctorRooms): array
    {
        $patient = $item->patientVisit?->patient;
        $sequence = $item->patientVisit?->caseNumber?->sequence;
        $room = $roomsEnabled ? ($item->appointment?->room?->name ?? $doctorRooms->get($item->doctor_id)) : null;

        return [
            'id' => $item->id,
            'status' => $item->status->value,
            'priority' => ($item->priority ?? VisitPriority::Normal)->value,
            'patient' => [
                'id' => $patient?->id,
                'short_name' => $patient?->short_name ?? 'Patient',
            ],
            'case_short' => $sequence ? CaseNumberFormatter::shortLabel($sequence) : null,
            'doctor' => $item->doctor ? ['id' => $item->doctor->id, 'name' => $item->doctor->name] : null,
            'room' => $room,
            'booked_for' => $item->appointment?->scheduled_at?->format(self::DATETIME),
            'checked_in_at' => $item->checked_in_at?->format(self::DATETIME),
            'called_at' => $item->called_at?->format(self::DATETIME),
            'started_at' => $item->started_at?->format(self::DATETIME),
            'wait_minutes' => $item->checked_in_at ? (int) $item->checked_in_at->diffInMinutes($item->started_at ?? $now) : null,
        ];
    }

    /**
     * @param  Collection<int, ClinicQueueItem>  $items
     * @param  Collection<int, Appointment>  $appointments
     * @return array<string, int>
     */
    private function summary(Collection $items, Collection $appointments): array
    {
        $count = fn (QueueItemStatus ...$statuses) => $items->filter(fn (ClinicQueueItem $item) => in_array($item->status, $statuses, true))->count();

        return [
            'booked' => $appointments->reject(fn (Appointment $a) => $a->status === AppointmentStatus::Cancelled)->count(),
            'checked_in' => $items->count(),
            'waiting' => $count(QueueItemStatus::Waiting, QueueItemStatus::Called),
            'in_consultation' => $count(QueueItemStatus::InConsultation),
            'completed' => $count(QueueItemStatus::Completed),
        ];
    }

    /**
     * What needs a person to act on it now, most urgent first.
     *
     * @param  Collection<int, ClinicQueueItem>  $active
     * @param  Collection<int, Appointment>  $appointments
     * @param  Collection<int, array{employee: mixed, shift: string}>  $roster
     * @return array<int, array<string, mixed>>
     */
    private function attention(Collection $active, Collection $appointments, Collection $roster, CarbonImmutable $now): array
    {
        $limits = config('reception.thresholds');
        $entries = collect();
        $who = fn (ClinicQueueItem $item) => trim(($item->patientVisit?->patient?->short_name ?? 'Patient').' · '.($item->patientVisit?->caseNumber ? CaseNumberFormatter::shortLabel($item->patientVisit->caseNumber->sequence) : ''), ' ·');

        foreach ($active as $item) {
            $doctor = $item->doctor?->name ?? 'any doctor';

            if ($item->priority === VisitPriority::Emergency && $item->status !== QueueItemStatus::InConsultation) {
                $entries->push(['kind' => 'emergency', 'rank' => 0, 'item_id' => $item->id, 'title' => $who($item),
                    'detail' => 'Emergency, waiting for '.$doctor, 'minutes' => (int) $item->checked_in_at?->diffInMinutes($now)]);
            }

            if ($item->status === QueueItemStatus::Called && $item->called_at && $item->called_at->diffInMinutes($now) >= $limits['called_not_seen_minutes']) {
                $entries->push(['kind' => 'called_not_seen', 'rank' => 1, 'item_id' => $item->id, 'title' => $who($item),
                    'detail' => 'Called '.(int) $item->called_at->diffInMinutes($now).' min ago, not seen yet', 'minutes' => (int) $item->called_at->diffInMinutes($now)]);
            }

            if ($item->status === QueueItemStatus::Waiting && $item->checked_in_at && $item->checked_in_at->diffInMinutes($now) >= $limits['long_wait_minutes']) {
                $entries->push(['kind' => 'long_wait', 'rank' => 2, 'item_id' => $item->id, 'title' => $who($item),
                    'detail' => 'Waiting '.(int) $item->checked_in_at->diffInMinutes($now).' min for '.$doctor, 'minutes' => (int) $item->checked_in_at->diffInMinutes($now)]);
            }
        }

        $onLeave = $roster->where('shift', 'on_leave')->pluck('employee.id')->all();

        foreach ($appointments as $appointment) {
            if (! in_array($appointment->status, [AppointmentStatus::Scheduled, AppointmentStatus::Arrived], true)) {
                continue;
            }

            $name = $appointment->patient?->short_name ?? 'Patient';
            $time = $appointment->scheduled_at->format('H:i');

            if (in_array($appointment->practitioner_id, $onLeave, true)) {
                $entries->push(['kind' => 'doctor_on_leave', 'rank' => 3, 'appointment_id' => $appointment->id, 'title' => $name,
                    'detail' => "Booked {$time} with ".($appointment->practitioner?->name ?? 'a doctor').', who is on leave today', 'minutes' => 0]);
            } elseif ($now->gt($appointment->scheduled_at->copy()->addMinutes($limits['late_arrival_minutes']))) {
                $late = (int) $appointment->scheduled_at->diffInMinutes($now);
                $entries->push(['kind' => 'late', 'rank' => 4, 'appointment_id' => $appointment->id, 'title' => $name,
                    'detail' => "Booked {$time}, {$late} min late, not checked in", 'minutes' => $late]);
            }
        }

        return $entries->sortBy([['rank', 'asc'], ['minutes', 'desc']])
            ->map(fn (array $entry) => ['id' => $entry['kind'].'-'.($entry['item_id'] ?? 'a'.$entry['appointment_id'])] + $entry)
            ->values()->all();
    }

    /**
     * @param  Collection<int, array{employee: mixed, shift: string, shift_label: string, room: string|null}>  $roster
     * @param  Collection<int, ClinicQueueItem>  $items
     * @return array<int, array<string, mixed>>
     */
    private function doctors(Collection $roster, Collection $items): array
    {
        return $roster->map(function (array $entry) use ($items) {
            $doctorId = $entry['employee']->id;
            $withPatient = $items->first(fn (ClinicQueueItem $item) => $item->doctor_id === $doctorId && $item->status === QueueItemStatus::InConsultation);
            $waiting = $items->filter(fn (ClinicQueueItem $item) => $item->doctor_id === $doctorId && in_array($item->status, [QueueItemStatus::Waiting, QueueItemStatus::Called], true))->count();

            $state = match (true) {
                $entry['shift'] === 'on_leave' => 'on_leave',
                $withPatient !== null => 'busy',
                $entry['shift'] === 'off_shift' => 'off_shift',
                default => 'available',
            };

            return [
                'id' => $doctorId,
                'name' => $entry['employee']->name,
                'state' => $state,
                'shift_label' => $entry['shift_label'],
                'room' => $entry['room'],
                'waiting' => $waiting,
                'with_patient' => $withPatient?->patientVisit?->patient?->short_name,
                'seen_today' => $items->filter(fn (ClinicQueueItem $item) => $item->doctor_id === $doctorId && $item->status === QueueItemStatus::Completed)->count(),
            ];
        })->values()->all();
    }

    /**
     * @param  Collection<int, Appointment>  $appointments
     * @return array{total: int, segments: array<int, array{status: string, count: int}>}
     */
    private function bookingStatus(Collection $appointments): array
    {
        $counts = $appointments->countBy(fn (Appointment $a) => $a->status?->value ?? 'scheduled');

        return [
            'total' => $appointments->count(),
            'segments' => collect(AppointmentStatus::cases())
                ->map(fn (AppointmentStatus $status) => ['status' => $status->value, 'count' => (int) ($counts[$status->value] ?? 0)])
                ->filter(fn (array $segment) => $segment['count'] > 0)
                ->values()->all(),
        ];
    }

    /**
     * Minutes from check-in to being called (or taken straight in), by the
     * hour the patient checked in.
     *
     * @param  Collection<int, ClinicQueueItem>  $items
     * @return array{average: int|null, longest: int|null, seen: int, by_hour: array<int, array{hour: int, average: int, longest: int, count: int}>}
     */
    private function waitTimes(Collection $items): array
    {
        $waits = $items->filter(fn (ClinicQueueItem $item) => $item->checked_in_at && ($item->called_at || $item->started_at))
            ->map(fn (ClinicQueueItem $item) => [
                'hour' => (int) $item->checked_in_at->format('G'),
                'minutes' => (int) $item->checked_in_at->diffInMinutes($item->called_at ?? $item->started_at),
            ]);

        return [
            'average' => $waits->isEmpty() ? null : (int) round($waits->avg('minutes')),
            'longest' => $waits->max('minutes'),
            'seen' => $waits->count(),
            'by_hour' => $waits->groupBy('hour')->map(fn (Collection $group, int $hour) => [
                'hour' => $hour,
                'average' => (int) round($group->avg('minutes')),
                'longest' => (int) $group->max('minutes'),
                'count' => $group->count(),
            ])->sortKeys()->values()->all(),
        ];
    }

    /**
     * @param  Collection<int, ClinicQueueItem>  $items
     * @return array<int, array<string, mixed>>
     */
    private function activity(Collection $items): array
    {
        $byId = $items->keyBy('id');

        return QueueHistory::whereIn('clinic_queue_item_id', $byId->keys())
            ->with('performer')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(15)
            ->get()
            ->map(function (QueueHistory $entry) use ($byId) {
                $item = $byId->get($entry->clinic_queue_item_id);
                $sequence = $item?->patientVisit?->caseNumber?->sequence;

                return [
                    'id' => $entry->id,
                    'at' => $entry->created_at?->format(self::DATETIME),
                    'action' => $entry->action,
                    'patient' => $item?->patientVisit?->patient?->short_name ?? 'Patient',
                    'case_short' => $sequence ? CaseNumberFormatter::shortLabel($sequence) : null,
                    'doctor' => $item?->doctor?->name,
                    'by' => $entry->performer?->name,
                    'notes' => $entry->notes,
                ];
            })->values()->all();
    }
}
