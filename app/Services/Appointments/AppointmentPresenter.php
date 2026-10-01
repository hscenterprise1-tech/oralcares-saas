<?php

namespace App\Services\Appointments;

use App\AppointmentStatus;
use App\Models\Appointment;
use App\QueueItemStatus;

/**
 * An appointment as the calendar receives it.
 *
 * Times are clinic wall-clock digits with no offset: appointments store the
 * clinic's local time, and an ISO string with "+00:00" would make the
 * browser shift it by its own offset.
 */
class AppointmentPresenter
{
    /**
     * The status the calendar shows. A checked-in appointment whose queue
     * item is still waiting (or being called) shows as Waiting; the stored
     * status is unchanged.
     */
    public static function displayStatus(Appointment $appointment): string
    {
        $status = $appointment->status ?? AppointmentStatus::Scheduled;
        $queueStatus = $appointment->relationLoaded('queueItem') ? $appointment->queueItem?->status : null;

        if ($status === AppointmentStatus::CheckedIn && in_array($queueStatus, [QueueItemStatus::Waiting, QueueItemStatus::Calling, QueueItemStatus::Called, QueueItemStatus::Recalled], true)) {
            return 'waiting';
        }

        return $status->value;
    }

    /**
     * @param  array<string, true>  $leaveDays  "providerId|Y-m-d" keys of approved leave
     * @return array<string, mixed>
     */
    public static function toArray(Appointment $appointment, bool $roomsEnabled, array $leaveDays = []): array
    {
        $patient = $appointment->patient;
        $status = $appointment->status ?? AppointmentStatus::Scheduled;

        return [
            'id' => $appointment->id,
            'reference' => self::reference($appointment->id),
            'patient' => $patient ? [
                'id' => $patient->id,
                'name' => $patient->full_name,
                'uhid' => $patient->uhid,
                'phone' => $patient->phone,
            ] : null,
            // A new case booked before registration: just who to expect.
            'new_case' => $patient ? null : [
                'name' => $appointment->prospect_name ?? 'New patient',
                'phone' => $appointment->prospect_phone,
            ],
            'provider_id' => $appointment->practitioner_id,
            'provider_name' => $appointment->practitioner?->name,
            'provider_on_leave' => isset($leaveDays[$appointment->practitioner_id.'|'.$appointment->scheduled_at?->toDateString()]),
            'service' => $appointment->service ? ['id' => $appointment->service->id, 'name' => $appointment->service->name] : null,
            'resource' => $roomsEnabled && $appointment->room ? ['name' => $appointment->room->name, 'type' => $appointment->room->type] : null,
            'start' => $appointment->scheduled_at?->format('Y-m-d\TH:i:s'),
            'end' => $appointment->endsAt()?->format('Y-m-d\TH:i:s'),
            'duration_minutes' => $appointment->duration_minutes ?? 15,
            'status' => $status->value,
            'display_status' => self::displayStatus($appointment),
            'source' => $appointment->source?->label() ?? 'Reception',
            'notes' => $appointment->notes,
            'series_id' => $appointment->appointment_series_id,
            'rescheduled' => ($appointment->reschedule_count ?? 0) > 0,
            'cancellation_reason' => $appointment->cancellation_reason,
            'allowed_statuses' => array_map(fn (AppointmentStatus $next) => $next->value, $status->allowedTransitions()),
            'upcoming' => $status->isUpcoming(),
        ];
    }

    /**
     * The reference staff read out and search by.
     */
    public static function reference(int $id): string
    {
        return 'APT-'.str_pad((string) $id, 5, '0', STR_PAD_LEFT);
    }
}
