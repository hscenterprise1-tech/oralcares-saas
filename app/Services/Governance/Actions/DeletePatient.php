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

/**
 * Deleting a patient — a soft delete. Staff who edit patient details may ask;
 * only the owner decides (or deletes directly in Owner Mode), with a reason —
 * the governance request holds who asked, who decided, when and why.
 *
 * Carrying it out:
 * - the patient's status becomes `deleted`, outside `PatientStatus::working()`,
 *   so the record leaves the directory, search, the desk lookup and every
 *   working screen, and Patient 360 no longer opens it;
 * - bookings still to come are cancelled ("Patient deleted"), open follow-ups
 *   are closed and messages still waiting are skipped.
 * Nothing is erased: visits, consultations, bills and documents stay in the
 * database, linked to the record, as the clinic's medical and legal history.
 */
class DeletePatient extends BaseGovernedAction
{
    public const CANCELLATION_REASON = 'Patient deleted';

    public function key(): string
    {
        return 'patient.delete';
    }

    public function label(): string
    {
        return 'Delete patient';
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
        return $this->hash([$target->id, $target->status?->value]);
    }

    public function assertActionable(Model $target): void
    {
        /** @var Patient $target */
        // A branch-limited member acts only on patients seen at one of their branches.
        if (! app(PatientBranchAccess::class)->allows($target)) {
            throw new AuthorizationException('That patient is at a branch you cannot open.');
        }
        if ($target->status === PatientStatus::Deleted) {
            throw new DomainException('This patient is already deleted.');
        }
        if ($target->status === PatientStatus::Merged) {
            throw new DomainException('This record was merged into another; it cannot be deleted on its own.');
        }
    }

    public function execute(Model $target, DeletionRequest $request, User $actor): void
    {
        /** @var Patient $target */
        $previous = $target->status?->value;
        $target->forceFill(['status' => PatientStatus::Deleted])->save();

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

        app(PatientMessaging::class)->skipPendingFor($target, PatientMessageEligibility::DELETED_REASON);

        $this->timeline($request, 'patient_deleted', [
            'previous_status' => $previous,
            'appointments_cancelled' => $cancelled,
            'follow_ups_closed' => $closed,
        ]);
    }
}
