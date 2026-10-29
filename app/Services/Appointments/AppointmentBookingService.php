<?php

namespace App\Services\Appointments;

use App\AppointmentSource;
use App\AppointmentStatus;
use App\Models\Appointment;
use App\Models\AppointmentSeries;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicEmployee;
use App\Models\Patient;
use App\Models\PatientTimelineEvent;
use App\Models\User;
use App\Services\AppointmentConflictService;
use App\Services\Communication\PatientMessaging;
use App\Services\Patient\PatientCareGuard;
use App\Support\ClinicClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Every change to the appointment book: booking (single or recurring),
 * moving, cancelling, linking a new case to their registration, and the
 * calendar's own status steps. Each booking is conflict-checked on the
 * server inside a transaction that locks the provider, so two desks booking
 * the same slot cannot both succeed; the room or chair is chosen here, never
 * by the person booking.
 *
 * Check-in is not here: it belongs to Reception (`ReceptionCheckIn`), which
 * also opens the visit and queue item.
 */
class AppointmentBookingService
{
    public const SCOPE_THIS = 'this';

    public const SCOPE_FOLLOWING = 'following';

    public const SCOPE_SERIES = 'series';

    public function __construct(
        protected AppointmentConflictService $conflicts,
        protected AppointmentSettingsService $settings,
        protected AppointmentResourceResolver $resources,
        protected AppointmentAvailabilityService $availability,
        protected PatientMessaging $messages,
    ) {}

    /**
     * Book an existing patient, or a new case by name and mobile only (no
     * registration until they arrive).
     *
     * @param  array{scheduled_at: CarbonImmutable, duration_minutes: int, service_id?: int|null, appointment_type?: string|null, source: AppointmentSource, notes?: string|null, recurrence?: array{frequency: string, occurrences: int}|null, prospect?: array{name: string, phone: string}|null}  $data
     */
    public function book(Clinic $clinic, Branch $branch, ?Patient $patient, ClinicEmployee $provider, array $data, ?User $actor): Appointment
    {
        if ($patient) {
            app(PatientCareGuard::class)->assertCanReceiveCare($patient);
        }
        $recurrence = $data['recurrence'] ?? null;
        $starts = $recurrence
            ? $this->occurrences($data['scheduled_at'], $recurrence['frequency'], $recurrence['occurrences'])
            : collect([$data['scheduled_at']]);

        return DB::transaction(function () use ($clinic, $branch, $patient, $provider, $data, $recurrence, $starts, $actor) {
            $this->lockProvider($provider);

            $rooms = $starts->map(fn (CarbonImmutable $start) => $this->assertFree($clinic, $branch, $provider, $start, $data['duration_minutes'], null, $recurrence !== null));

            $series = $recurrence ? AppointmentSeries::create([
                'clinic_id' => $clinic->id,
                'branch_id' => $branch->id,
                'patient_id' => $patient?->id,
                'practitioner_id' => $provider->id,
                'service_id' => $data['service_id'] ?? null,
                'frequency' => $recurrence['frequency'],
                'occurrences' => $starts->count(),
                'starts_at' => $data['scheduled_at'],
                'created_by' => $actor?->id,
            ]) : null;

            $appointments = $starts->map(fn (CarbonImmutable $start, int $index) => Appointment::create([
                'organization_id' => $clinic->organization_id,
                'clinic_id' => $clinic->id,
                'branch_id' => $branch->id,
                'room_id' => $rooms[$index],
                'service_id' => $data['service_id'] ?? null,
                'appointment_series_id' => $series?->id,
                'patient_id' => $patient?->id,
                'prospect_name' => $patient ? null : ($data['prospect']['name'] ?? null),
                'prospect_phone' => $patient ? null : ($data['prospect']['phone'] ?? null),
                'practitioner_id' => $provider->id,
                'scheduled_at' => $start,
                'duration_minutes' => $data['duration_minutes'],
                'status' => AppointmentStatus::Scheduled,
                'appointment_type' => $data['appointment_type'] ?? 'consultation',
                'source' => $series ? AppointmentSource::Recurring : $data['source'],
                'notes' => $data['notes'] ?? null,
                'created_by' => $actor?->id,
                'updated_by' => $actor?->id,
            ]));

            $first = $appointments->first();

            $this->timeline($clinic, $first, 'appointment_scheduled', $actor, [
                'practitioner' => $provider->name,
                'scheduled_at' => $first->scheduled_at->format('d M Y \a\t h:i A'),
                'booked_by' => $actor?->name ?? 'Reception',
                'source' => $first->source->label(),
                'occurrences' => $appointments->count(),
            ]);

            // Told only once the booking is committed.
            DB::afterCommit(fn () => $this->messages->appointmentsBooked($appointments));

            return $first;
        });
    }

    /**
     * Move an appointment (and, for a series, the occurrences in scope) to a
     * new time or provider. Every moved occurrence is checked again and gets
     * its room chosen again.
     *
     * @param  array{scheduled_at: CarbonImmutable, duration_minutes: int, practitioner: ClinicEmployee}  $data
     * @return int how many appointments moved
     */
    public function reschedule(Clinic $clinic, Branch $branch, Appointment $appointment, array $data, string $scope, ?User $actor): int
    {
        $this->assertUpcoming($appointment, 'moved');
        // Moving a booking is a new time with the patient: not for a patient recorded as deceased.
        if ($appointment->patient_id && ($patient = Patient::query()->withoutGlobalScopes()->where('clinic_id', $clinic->id)->find($appointment->patient_id))) {
            app(PatientCareGuard::class)->assertCanReceiveCare($patient);
        }

        $shift = (int) $appointment->scheduled_at->diffInMinutes($data['scheduled_at'], false);
        $targets = $this->inScope($appointment, $scope);

        return DB::transaction(function () use ($clinic, $branch, $appointment, $data, $targets, $shift, $actor) {
            $this->lockProvider($data['practitioner']);
            $moves = $targets->map(fn (Appointment $target) => [
                $target,
                $target->is($appointment) ? $data['scheduled_at'] : CarbonImmutable::instance($target->scheduled_at)->addMinutes($shift),
            ]);

            $rooms = $moves->map(fn (array $move) => $this->assertFree($clinic, $branch, $data['practitioner'], $move[1], $data['duration_minutes'], $move[0]->id, $moves->count() > 1));

            foreach ($moves as $index => [$target, $start]) {
                $timeChanged = ! $target->scheduled_at->equalTo($start) || (int) $target->practitioner_id !== $data['practitioner']->id;
                $target->update([
                    'scheduled_at' => $start,
                    'duration_minutes' => $data['duration_minutes'],
                    'practitioner_id' => $data['practitioner']->id,
                    'room_id' => $rooms[$index],
                    'rescheduled_at' => $timeChanged ? now() : $target->rescheduled_at,
                    'reschedule_count' => $target->reschedule_count + ($timeChanged ? 1 : 0),
                    'updated_by' => $actor?->id,
                ]);
            }

            $this->timeline($clinic, $appointment, 'appointment_rescheduled', $actor, [
                'practitioner' => $data['practitioner']->name,
                'scheduled_at' => $data['scheduled_at']->format('d M Y \a\t h:i A'),
                'occurrences' => $moves->count(),
            ]);

            DB::afterCommit(fn () => $moves->each(fn (array $move) => $this->messages->appointmentRescheduled($move[0]->fresh())));

            return $moves->count();
        });
    }

    /**
     * @return int how many appointments were cancelled
     */
    public function cancel(Clinic $clinic, Appointment $appointment, ?string $reason, string $scope, ?User $actor): int
    {
        $this->assertUpcoming($appointment, 'cancelled');

        $settings = $this->settings->resolve($clinic);
        $late = $settings['cancellation_notice_hours'] > 0
            && ClinicClock::now()->diffInMinutes($appointment->scheduled_at, false) < $settings['cancellation_notice_hours'] * 60;

        return DB::transaction(function () use ($clinic, $appointment, $reason, $scope, $actor, $late) {
            $targets = $this->inScope($appointment, $scope);

            foreach ($targets as $target) {
                $target->update([
                    'status' => AppointmentStatus::Cancelled,
                    'cancelled_at' => now(),
                    'cancelled_by' => $actor?->id,
                    'cancellation_reason' => $reason,
                    'updated_by' => $actor?->id,
                ]);
            }

            $this->timeline($clinic, $appointment, 'appointment_cancelled', $actor, [
                'scheduled_at' => $appointment->scheduled_at->format('d M Y \a\t h:i A'),
                'reason' => $reason,
                'late_cancellation' => $late,
                'occurrences' => $targets->count(),
            ]);

            DB::afterCommit(fn () => $targets->each(fn (Appointment $target) => $this->messages->appointmentCancelled($target)));

            return $targets->count();
        });
    }

    /**
     * A new case has arrived and been registered: attach their patient record
     * to the booking (and the rest of its series), so it can be checked in.
     */
    public function linkPatient(Clinic $clinic, Appointment $appointment, Patient $patient, ?User $actor): void
    {
        app(PatientCareGuard::class)->assertCanReceiveCare($patient);
        if ($appointment->patient_id !== null) {
            throw ValidationException::withMessages(['patient_id' => 'This appointment already belongs to a registered patient.']);
        }

        DB::transaction(function () use ($clinic, $appointment, $patient, $actor) {
            Appointment::withoutGlobalScopes()
                ->where('clinic_id', $clinic->id)
                ->whereNull('patient_id')
                ->where(fn ($q) => $q->whereKey($appointment->id)->when(
                    $appointment->appointment_series_id,
                    fn ($series, $id) => $series->orWhere('appointment_series_id', $id),
                ))
                ->update(['patient_id' => $patient->id, 'updated_by' => $actor?->id]);

            $appointment->refresh();
            $this->timeline($clinic, $appointment, 'appointment_patient_linked', $actor, [
                'scheduled_at' => $appointment->scheduled_at->format('d M Y \a\t h:i A'),
                'booked_as' => $appointment->prospect_name,
            ]);
        });
    }

    /**
     * The calendar's own status steps: confirm, back to scheduled, arrived,
     * no-show, and reverting a no-show.
     */
    public function setStatus(Clinic $clinic, Appointment $appointment, AppointmentStatus $status, ?User $actor): void
    {
        if (! in_array($status, $appointment->status->allowedTransitions(), true)) {
            throw ValidationException::withMessages([
                'status' => "A {$appointment->status->label()} appointment cannot be marked {$status->label()}.",
            ]);
        }

        $now = ClinicClock::now();

        if ($status === AppointmentStatus::NoShow && $now->lt($appointment->scheduled_at)) {
            throw ValidationException::withMessages(['status' => 'An appointment can only be marked a no-show once its time has started.']);
        }

        if ($status === AppointmentStatus::Arrived && $appointment->scheduled_at->toDateString() !== $now->toDateString()) {
            throw ValidationException::withMessages(['status' => 'Only today\'s appointments can be marked arrived.']);
        }

        if ($appointment->status === AppointmentStatus::NoShow && $now->toDateString() > $appointment->scheduled_at->toDateString()) {
            throw ValidationException::withMessages(['status' => 'A past no-show cannot be reopened. Book a new appointment instead.']);
        }

        $appointment->update([
            'status' => $status,
            'confirmed_at' => $status === AppointmentStatus::Confirmed ? now() : ($status === AppointmentStatus::Scheduled ? null : $appointment->confirmed_at),
            'updated_by' => $actor?->id,
        ]);

        if (in_array($status, [AppointmentStatus::NoShow, AppointmentStatus::Confirmed], true)) {
            $this->timeline($clinic, $appointment, 'appointment_'.$status->value, $actor, [
                'scheduled_at' => $appointment->scheduled_at->format('d M Y \a\t h:i A'),
            ]);
        }
    }

    /**
     * The start of each occurrence of a recurring booking.
     *
     * @return Collection<int, CarbonImmutable>
     */
    public function occurrences(CarbonImmutable $first, string $frequency, int $count): Collection
    {
        return collect(range(0, $count - 1))->map(fn (int $index) => match ($frequency) {
            'daily' => $first->addDays($index),
            'fortnightly' => $first->addWeeks($index * 2),
            'monthly' => $first->addMonthsNoOverflow($index),
            default => $first->addWeeks($index),
        });
    }

    /**
     * The appointment, or with a series the upcoming occurrences in scope.
     *
     * @return Collection<int, Appointment>
     */
    private function inScope(Appointment $appointment, string $scope): Collection
    {
        if ($scope === self::SCOPE_THIS || ! $appointment->appointment_series_id) {
            return collect([$appointment]);
        }

        return Appointment::withoutGlobalScopes()
            ->where('clinic_id', $appointment->clinic_id)
            ->where('appointment_series_id', $appointment->appointment_series_id)
            ->whereIn('status', array_map(fn (AppointmentStatus $status) => $status->value, AppointmentStatus::checkable()))
            ->when($scope === self::SCOPE_FOLLOWING, fn ($q) => $q->where('scheduled_at', '>=', $appointment->scheduled_at))
            ->orderBy('scheduled_at')
            ->limit(60)
            ->get();
    }

    private function assertUpcoming(Appointment $appointment, string $verb): void
    {
        if (! $appointment->status->isUpcoming()) {
            throw ValidationException::withMessages([
                'appointment' => "A {$appointment->status->label()} appointment cannot be {$verb}.",
            ]);
        }
    }

    /**
     * Checks the time and chooses the room. Throws, naming the clash and the
     * provider's next free time, when it cannot be booked.
     */
    private function assertFree(Clinic $clinic, Branch $branch, ClinicEmployee $provider, CarbonImmutable $start, int $duration, ?int $ignoreId, bool $nameTheDate): ?int
    {
        if ($start->lt(ClinicClock::now())) {
            $this->refuse($clinic, $branch, $provider, $start, $duration, $ignoreId, 'This time has already passed.', $nameTheDate);
        }

        $reason = $this->conflicts->checkConflict($branch, $provider->id, $start, $duration, $ignoreId);
        if ($reason) {
            $this->refuse($clinic, $branch, $provider, $start, $duration, $ignoreId, $reason, $nameTheDate);
        }

        $resource = $this->resources->resolve($clinic, $branch, $provider->id, $start, $duration, $ignoreId);
        if (! $resource['available']) {
            $this->refuse($clinic, $branch, $provider, $start, $duration, $ignoreId, "Every room or chair assigned to {$provider->name} is booked at that time.", $nameTheDate);
        }

        return $resource['room_id'];
    }

    private function refuse(Clinic $clinic, Branch $branch, ClinicEmployee $provider, CarbonImmutable $start, int $duration, ?int $ignoreId, string $reason, bool $nameTheDate): never
    {
        $from = $start->lt(ClinicClock::today()) ? ClinicClock::today() : $start->startOfDay();
        // The first free time after the one asked for, else the first that day.
        $slots = $this->availability->freeSlots($clinic, $branch, [$provider->id], $from, 14, $duration, 60, ignoreAppointmentId: $ignoreId);
        $wanted = $start->format('Y-m-d\TH:i:s');
        $next = collect($slots)->first(fn (array $slot) => $slot['start'] > $wanted) ?? ($slots[0] ?? null);
        $suggestion = $next ? ' Next free with '.$provider->name.': '.CarbonImmutable::parse($next['start'])->format('D j M, g:i A').'.' : '';

        throw ValidationException::withMessages([
            'scheduled_at' => ($nameTheDate ? $start->format('D j M').': ' : '').$reason.$suggestion,
        ]);
    }

    /**
     * Serialises bookings for one provider: a second desk waits here until
     * the first one's transaction commits, then sees its appointment.
     */
    private function lockProvider(ClinicEmployee $provider): void
    {
        ClinicEmployee::withoutGlobalScopes()->whereKey($provider->id)->lockForUpdate()->first();
    }

    /**
     * Written to the patient's timeline; a new case has none until linked.
     *
     * @param  array<string, mixed>  $context
     */
    private function timeline(Clinic $clinic, Appointment $appointment, string $type, ?User $actor, array $context): void
    {
        if (! $appointment->patient_id) {
            return;
        }

        PatientTimelineEvent::create([
            'organization_id' => $clinic->organization_id,
            'clinic_id' => $clinic->id,
            'patient_id' => $appointment->patient_id,
            'actor_id' => $actor?->id,
            'event_type' => $type,
            'context' => ['appointment_id' => $appointment->id] + $context,
        ]);
    }
}
