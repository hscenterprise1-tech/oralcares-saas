<?php

namespace App\Services\Governance\Actions;

use App\AppointmentStatus;
use App\ClinicPermission;
use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\DeletionRequest;
use App\Models\Patient;
use App\Models\PatientFollowUp;
use App\Models\User;
use App\PatientStatus;
use App\Services\Appointments\AppointmentBookingService;
use App\Services\Communication\PatientMessageEligibility;
use App\Services\Communication\PatientMessaging;
use App\Services\Patient\FollowUps;
use App\Services\Patient\PatientBranchAccess;
use App\Support\ClinicClock;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Recording a patient's death. Staff who edit patient details may ask; only
 * the owner decides (or records it directly in Owner Mode), with a reason
 * and an explicit date — the governance request holds who asked, who
 * decided, when and why. Nothing is deleted.
 *
 * Carrying it out:
 * - the patient is marked deceased with the date and who recorded it;
 * - bookings still to come are cancelled ("Patient recorded as deceased") and
 *   open follow-ups are closed, so the patient is not called back;
 * - messages still waiting are skipped with the reason.
 * From then on `PatientCareGuard` refuses new care and charges.
 */
class RecordDeath extends BaseGovernedAction
{
    public const CANCELLATION_REASON = 'Patient recorded as deceased';

    public function key(): string
    {
        return 'patient.record_death';
    }

    public function label(): string
    {
        return 'Record death';
    }

    public function targetType(): string
    {
        return 'patient';
    }

    public function requestPermission(): ClinicPermission
    {
        return ClinicPermission::PatientEdit;
    }

    public function find(int $clinicId, int $targetId): ?Model
    {
        return Patient::query()->withoutGlobalScopes()->where('clinic_id', $clinicId)->whereKey($targetId)->first();
    }

    public function describe(Model $target): array
    {
        /** @var Patient $target */
        return [
            'label' => trim("Patient {$target->full_name}".($target->uhid ? " ({$target->uhid})" : '')),
            'patient_id' => $target->id,
            'branch_id' => null,
        ];
    }

    public function fingerprint(Model $target): string
    {
        /** @var Patient $target */
        return $this->hash([$target->id, $target->status?->value, $target->date_of_death?->toDateString()]);
    }

    public function assertActionable(Model $target): void
    {
        /** @var Patient $target */
        // A branch-limited member acts only on patients seen at one of their branches.
        if (! app(PatientBranchAccess::class)->allows($target)) {
            throw new AuthorizationException('That patient is at a branch you cannot open.');
        }
        if ($target->status === PatientStatus::Deceased) {
            throw new DomainException('This patient is already recorded as deceased.');
        }
        if ($target->status === PatientStatus::Merged) {
            throw new DomainException('This record was merged into another; record the death on the patient kept.');
        }
    }

    /**
     * The date of death: required, a real date, not in the future and not
     * before the patient's date of birth.
     *
     * @return array{date_of_death: string}
     */
    public function payload(Model $target, array $input, int $clinicId): array
    {
        /** @var Patient $target */
        $raw = (string) ($input['date_of_death'] ?? '');
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) || ! ($date = Carbon::createFromFormat('Y-m-d', $raw)) || $date->format('Y-m-d') !== $raw) {
            throw new DomainException('Give the date of death.');
        }
        if ($raw > ClinicClock::now()->toDateString()) {
            throw new DomainException('The date of death cannot be in the future.');
        }
        if ($target->date_of_birth && $raw < $target->date_of_birth->toDateString()) {
            throw new DomainException('The date of death cannot be before the date of birth.');
        }

        return ['date_of_death' => $raw];
    }

    public function execute(Model $target, DeletionRequest $request, User $actor): void
    {
        /** @var Patient $target */
        $target->forceFill([
            'status' => PatientStatus::Deceased,
            'date_of_death' => $request->payload['date_of_death'],
            'death_recorded_at' => now(),
            'death_recorded_by' => $actor->id,
        ])->save();

        $clinic = Clinic::query()->findOrFail($target->clinic_id);
        $bookings = app(AppointmentBookingService::class);
        $cancelled = 0;
        Appointment::query()->withoutGlobalScopes()->where('clinic_id', $target->clinic_id)->where('patient_id', $target->id)
            ->where('scheduled_at', '>=', ClinicClock::now())
            ->whereIn('status', [AppointmentStatus::Scheduled->value, AppointmentStatus::Confirmed->value])
            ->orderBy('scheduled_at')->get()
            ->each(function (Appointment $appointment) use ($bookings, $clinic, $actor, &$cancelled) {
                $cancelled += $bookings->cancel($clinic, $appointment, self::CANCELLATION_REASON, 'this', $actor);
            });

        $followUps = app(FollowUps::class);
        $closed = 0;
        PatientFollowUp::query()->withoutGlobalScopes()->where('clinic_id', $target->clinic_id)->where('patient_id', $target->id)
            ->whereIn('status', [PatientFollowUp::DUE, PatientFollowUp::BOOKED])->get()
            ->each(function (PatientFollowUp $followUp) use ($followUps, &$closed) {
                $followUps->cancel($followUp);
                $closed++;
            });

        app(PatientMessaging::class)->skipPendingFor($target, PatientMessageEligibility::DECEASED_REASON);

        $this->timeline($request, 'patient_death_recorded', [
            'date_of_death' => $request->payload['date_of_death'],
            'appointments_cancelled' => $cancelled,
            'follow_ups_closed' => $closed,
        ]);
    }
}
