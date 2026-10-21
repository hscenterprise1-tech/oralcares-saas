<?php

namespace App\Services\Governance\Actions;

use App\ClinicPermission;
use App\Models\ClinicalEncounter;
use App\Models\DeletionRequest;
use App\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Model;

/**
 * Voiding a dental specialty record (root canal, implant, orthodontic case)
 * entered in error: it stays readable, marked void with the reason, and
 * leaves every summary and contextual view. Subclasses name the model.
 */
abstract class VoidDentalRecord extends BaseGovernedAction
{
    /** @return class-string<Model> */
    abstract protected function model(): string;

    abstract protected function noun(Model $target): string;

    public function requestPermission(): ClinicPermission
    {
        return ClinicPermission::ClinicalEdit;
    }

    public function find(int $clinicId, int $targetId): ?Model
    {
        return ($this->model())::query()->withoutGlobalScopes()->where('clinic_id', $clinicId)->whereKey($targetId)->first();
    }

    public function describe(Model $target): array
    {
        return [
            'label' => $this->noun($target).', started '.$target->created_at?->format('d M Y'),
            'patient_id' => $target->patient_id,
            'branch_id' => ClinicalEncounter::query()->withoutGlobalScopes()->whereKey($target->encounter_id)->value('branch_id'),
        ];
    }

    public function fingerprint(Model $target): string
    {
        return $this->hash([$target->getKey(), $target->status, $target->updated_at?->toIso8601String(), $target->voided_at?->toIso8601String()]);
    }

    public function assertActionable(Model $target): void
    {
        if ($target->voided_at !== null) {
            throw new DomainException('This record is already void.');
        }
    }

    public function execute(Model $target, DeletionRequest $request, User $actor): void
    {
        $target->forceFill(['voided_at' => now(), 'voided_by' => $actor->id, 'void_reason' => mb_substr($request->reason, 0, 500)])->saveQuietly();
        $this->timeline($request, 'dental_record_voided');
    }
}
