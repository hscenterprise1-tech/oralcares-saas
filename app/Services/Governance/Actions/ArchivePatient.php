<?php

namespace App\Services\Governance\Actions;

use App\ClinicPermission;
use App\Models\DeletionRequest;
use App\Models\Patient;
use App\Models\User;
use App\PatientStatus;
use DomainException;
use Illuminate\Database\Eloquent\Model;

/**
 * "Delete patient" is archiving: the patient leaves the working directory
 * and search, and every record stays. A patient's history is never erased.
 */
class ArchivePatient extends BaseGovernedAction
{
    public function key(): string
    {
        return 'patient.archive';
    }

    public function label(): string
    {
        return 'Archive patient';
    }

    public function targetType(): string
    {
        return 'patient';
    }

    public function requestPermission(): ClinicPermission
    {
        return ClinicPermission::PatientDelete;
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
        return $this->hash([$target->id, $target->status?->value, $target->archived_at?->toIso8601String()]);
    }

    public function assertActionable(Model $target): void
    {
        /** @var Patient $target */
        if ($target->status === PatientStatus::Archived) {
            throw new DomainException('This patient is already archived.');
        }
        if ($target->status === PatientStatus::Merged) {
            throw new DomainException('This patient was merged into another record.');
        }
    }

    public function execute(Model $target, DeletionRequest $request, User $actor): void
    {
        /** @var Patient $target */
        $target->forceFill([
            'status' => PatientStatus::Archived,
            'archived_at' => now(),
            'archived_by' => $actor->id,
            'archive_reason' => mb_substr($request->reason, 0, 500),
        ])->save();

        $this->timeline($request, 'patient_archived');
    }
}
