<?php

namespace App\Services\Governance\Actions;

use App\ClinicPermission;
use App\Models\DeletionRequest;
use App\Models\Patient;
use App\Models\User;
use App\PatientStatus;
use App\Services\Patient\PatientBranchAccess;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;

/**
 * Correcting a death recorded in error — the only way back. The same
 * governance as recording it (staff ask with a reason, the owner decides),
 * never automatic. The patient becomes a current patient again; what was
 * recorded (the date, who recorded it) stays on the timeline and the
 * governance requests. Bookings and follow-ups cancelled when the death was
 * recorded are not restored: the desk books again.
 */
class CorrectDeath extends BaseGovernedAction
{
    public function key(): string
    {
        return 'patient.correct_death';
    }

    public function label(): string
    {
        return 'Correct a death recorded in error';
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
        if ($target->status !== PatientStatus::Deceased) {
            throw new DomainException('This patient is not recorded as deceased.');
        }
    }

    public function execute(Model $target, DeletionRequest $request, User $actor): void
    {
        /** @var Patient $target */
        $was = $target->date_of_death?->toDateString();
        $recordedBy = $target->death_recorded_by;
        $target->forceFill([
            'status' => PatientStatus::Active,
            'date_of_death' => null,
            'death_recorded_at' => null,
            'death_recorded_by' => null,
        ])->save();

        $this->timeline($request, 'patient_death_corrected', ['previous_date_of_death' => $was, 'previously_recorded_by' => $recordedBy]);
    }
}
