<?php

namespace App\Services\Governance\Actions;

use App\ClinicPermission;
use App\Models\AttachedDocument;
use App\Models\ClinicalEncounter;
use App\Models\DeletionRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Removing a document takes it off the patient's record (soft delete). The
 * file itself is kept on the private disk for retention.
 */
class RemoveDocument extends BaseGovernedAction
{
    public function key(): string
    {
        return 'document.remove';
    }

    public function label(): string
    {
        return 'Remove document';
    }

    public function targetType(): string
    {
        return 'document';
    }

    public function requestPermission(): ClinicPermission
    {
        return ClinicPermission::DocumentsManage;
    }

    public function find(int $clinicId, int $targetId): ?Model
    {
        return AttachedDocument::query()->withoutGlobalScopes()->where('clinic_id', $clinicId)->whereKey($targetId)->whereNull('deleted_at')->first();
    }

    public function describe(Model $target): array
    {
        /** @var AttachedDocument $target */
        return [
            'label' => "Document “{$target->file_name}”",
            'patient_id' => $target->patient_id,
            'branch_id' => $target->encounter_id
                ? ClinicalEncounter::query()->withoutGlobalScopes()->whereKey($target->encounter_id)->value('branch_id')
                : null,
        ];
    }

    public function fingerprint(Model $target): string
    {
        /** @var AttachedDocument $target */
        return $this->hash([$target->id, $target->file_sha256, $target->file_path]);
    }

    public function assertActionable(Model $target): void {}

    public function execute(Model $target, DeletionRequest $request, User $actor): void
    {
        /** @var AttachedDocument $target */
        $target->forceFill(['removed_by' => $actor->id, 'removal_reason' => mb_substr($request->reason, 0, 500)])->save();
        $target->delete();

        $this->timeline($request, 'document_removed');
    }
}
