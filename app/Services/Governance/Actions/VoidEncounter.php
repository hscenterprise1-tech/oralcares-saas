<?php

namespace App\Services\Governance\Actions;

use App\ClinicPermission;
use App\Models\ClinicalEncounter;
use App\Models\DeletionRequest;
use App\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Model;

/**
 * A signed consultation is never deleted. Voiding marks it "entered in
 * error": it stays readable with its signature and addenda, and it leaves
 * the patient's clinical summaries. A draft is simply cancelled in the
 * consultation instead.
 */
class VoidEncounter extends BaseGovernedAction
{
    public function key(): string
    {
        return 'encounter.void';
    }

    public function label(): string
    {
        return 'Void consultation';
    }

    public function targetType(): string
    {
        return 'encounter';
    }

    public function requestPermission(): ClinicPermission
    {
        return ClinicPermission::ClinicalEdit;
    }

    public function find(int $clinicId, int $targetId): ?Model
    {
        return ClinicalEncounter::query()->withoutGlobalScopes()->where('clinic_id', $clinicId)->whereKey($targetId)->first();
    }

    public function describe(Model $target): array
    {
        /** @var ClinicalEncounter $target */
        return [
            'label' => "Consultation {$target->encounter_number}",
            'patient_id' => $target->patient_id,
            'branch_id' => $target->branch_id,
        ];
    }

    public function fingerprint(Model $target): string
    {
        /** @var ClinicalEncounter $target */
        return $this->hash([$target->id, $target->signature_hash, $target->voided_at?->toIso8601String()]);
    }

    public function assertActionable(Model $target): void
    {
        /** @var ClinicalEncounter $target */
        if ($target->isVoided()) {
            throw new DomainException('This consultation is already voided.');
        }
        if (! $target->isFinalized()) {
            throw new DomainException('Only a signed consultation is voided. Cancel a draft in the consultation itself.');
        }
    }

    public function execute(Model $target, DeletionRequest $request, User $actor): void
    {
        /** @var ClinicalEncounter $target */
        $target->forceFill(['voided_at' => now(), 'voided_by' => $actor->id, 'void_reason' => mb_substr($request->reason, 0, 500)])->saveQuietly();

        $this->timeline($request, 'encounter_voided', ['encounter_number' => $target->encounter_number]);
    }
}
