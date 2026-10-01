<?php

namespace App;

/**
 * The one appointment status set. "Waiting" is not a status of its own: it is
 * a checked-in appointment whose queue item is waiting, read from the queue.
 * "Rescheduled" is likewise not a status: a moved appointment keeps its
 * status and records `rescheduled_at` / `reschedule_count`.
 */
enum AppointmentStatus: string
{
    case Scheduled = 'scheduled';
    case Confirmed = 'confirmed';
    case Arrived = 'arrived';
    case CheckedIn = 'checked_in';
    case InConsultation = 'in_consultation';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case NoShow = 'no_show';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Booked and not yet here: what closing a day turns into no-shows.
     *
     * @return list<self>
     */
    public static function awaitingArrival(): array
    {
        return [self::Scheduled, self::Confirmed];
    }

    /**
     * What Reception may check in.
     *
     * @return list<self>
     */
    public static function checkable(): array
    {
        return [self::Scheduled, self::Confirmed, self::Arrived];
    }

    public function label(): string
    {
        return match ($this) {
            self::Scheduled => 'Scheduled',
            self::Confirmed => 'Confirmed',
            self::Arrived => 'Arrived',
            self::CheckedIn => 'Checked in',
            self::InConsultation => 'In consultation',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
            self::NoShow => 'No-show',
        };
    }

    /**
     * Still on the books: it holds its time slot and can be moved, confirmed,
     * cancelled or checked in.
     */
    public function isUpcoming(): bool
    {
        return in_array($this, self::checkable(), true);
    }

    /**
     * Frees its slot: other bookings may take the time.
     */
    public function releasesSlot(): bool
    {
        return in_array($this, [self::Cancelled, self::NoShow], true);
    }

    /**
     * The statuses the calendar may set directly. Checked in, in consultation
     * and completed are set by Reception check-in and the queue, never here.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Scheduled => [self::Confirmed, self::Arrived, self::NoShow],
            self::Confirmed => [self::Scheduled, self::Arrived, self::NoShow],
            self::Arrived => [self::Confirmed, self::NoShow],
            self::NoShow => [self::Scheduled],
            default => [],
        };
    }
}
