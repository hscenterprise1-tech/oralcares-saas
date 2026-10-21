<?php

namespace App\Services\Governance\Actions;

use App\ClinicPermission;
use App\Models\DeletionRequest;
use App\Models\Patient;
use App\Models\User;
use App\PatientStatus;
use App\Services\Patient\PatientMergeService;
use DomainException;
use Illuminate\Database\Eloquent\Model;

/**
 * Merging a duplicate patient into the one kept. Staff with `patient.merge`
 * may ask; only the owner decides (or acts directly in Owner Mode). The
 * target is the duplicate; the patient kept is the request's payload.
 */
class MergePatient extends BaseGovernedAction
{
    public function __construct(protected PatientMergeService $merges) {}

    public function key(): string
    {
        return 'patient.merge';
    }

    public function label(): string
    {
        return 'Merge duplicate patient';
    }

    public function targetType(): string
    {
        return 'patient';
    }

    public function requestPermission(): ClinicPermission
    {
        return ClinicPermission::PatientMerge;
    }

    public function find(int $clinicId, int $targetId): ?Model
    {
        return Patient::query()->withoutGlobalScopes()->where('clinic_id', $clinicId)->whereKey($targetId)->first();
    }

    public function describe(Model $target): array
    {
        /** @var Patient $target */
        return [
            'label' => trim("Duplicate {$target->full_name}".($target->uhid ? " ({$target->uhid})" : '')),
            'patient_id' => $target->id,
            'branch_id' => null,
        ];
    }

    public function fingerprint(Model $target): string
    {
        /** @var Patient $target */
        return $this->hash([$target->id, $target->status?->value, $target->updated_at?->toIso8601String()]);
    }

    public function assertActionable(Model $target): void
    {
        /** @var Patient $target */
        if (! in_array($target->status?->value, PatientStatus::working(), true)) {
            throw new DomainException('This patient cannot be merged now.');
        }
    }

    /**
     * @return array{survivor_patient_id: int}
     */
    public function payload(Model $target, array $input, int $clinicId): array
    {
        /** @var Patient $target */
        $survivor = $this->find($clinicId, (int) ($input['survivor_patient_id'] ?? 0))
            ?? throw new DomainException('Choose the patient to keep.');
        /** @var Patient $survivor */
        $this->merges->assertMergeable($target, $survivor);

        return ['survivor_patient_id' => $survivor->id];
    }

    public function execute(Model $target, DeletionRequest $request, User $actor): void
    {
        /** @var Patient $target */
        $survivor = $this->find($target->clinic_id, (int) ($request->payload['survivor_patient_id'] ?? 0))
            ?? throw new DomainException('The patient to keep no longer exists.');
        $this->merges->merge($target, $survivor, $request, $actor);
    }
}
