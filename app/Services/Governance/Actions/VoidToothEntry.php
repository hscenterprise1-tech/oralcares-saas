<?php

namespace App\Services\Governance\Actions;

use App\ClinicPermission;
use App\Models\ClinicalEncounter;
use App\Models\DeletionRequest;
use App\Models\PatientDentalChart;
use App\Models\PatientToothStatusHistory;
use App\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Model;

/**
 * The odontogram's destructive correction. An ordinary correction is a new
 * finding on top (history keeps both). Voiding is for an entry that should
 * never have been made: it is marked void, kept in the tooth's history, and
 * the tooth's current state falls back to its latest entry still standing.
 */
class VoidToothEntry extends BaseGovernedAction
{
    public function key(): string
    {
        return 'tooth_entry.void';
    }

    public function label(): string
    {
        return 'Void chart entry';
    }

    public function targetType(): string
    {
        return 'tooth_entry';
    }

    public function requestPermission(): ClinicPermission
    {
        return ClinicPermission::ClinicalEdit;
    }

    public function find(int $clinicId, int $targetId): ?Model
    {
        return PatientToothStatusHistory::query()->withoutGlobalScopes()->where('clinic_id', $clinicId)->whereKey($targetId)->first();
    }

    public function describe(Model $target): array
    {
        /** @var PatientToothStatusHistory $target */
        $condition = config("clinic_dental.conditions.{$target->status}.label") ?? $target->status;
        $surfaces = $target->surfaces ? ' ('.implode('', $target->surfaces).')' : '';

        return [
            'label' => "Tooth {$target->tooth_number}: {$condition}{$surfaces}, charted {$target->created_at?->format('d M Y')}",
            'patient_id' => $target->patient_id,
            'branch_id' => $target->encounter_id
                ? ClinicalEncounter::query()->withoutGlobalScopes()->whereKey($target->encounter_id)->value('branch_id')
                : null,
        ];
    }

    public function fingerprint(Model $target): string
    {
        /** @var PatientToothStatusHistory $target */
        return $this->hash([$target->id, $target->status, $target->surfaces, $target->voided_at?->toIso8601String()]);
    }

    public function assertActionable(Model $target): void
    {
        /** @var PatientToothStatusHistory $target */
        if ($target->voided_at !== null) {
            throw new DomainException('This chart entry is already void.');
        }
        if ($target->kind !== PatientToothStatusHistory::FINDING) {
            throw new DomainException('A treatment entry is tied to a recorded procedure. Void the consultation it was done in instead.');
        }
    }

    public function execute(Model $target, DeletionRequest $request, User $actor): void
    {
        /** @var PatientToothStatusHistory $target */
        $chart = PatientDentalChart::query()->withoutGlobalScopes()
            ->where('clinic_id', $target->clinic_id)->where('patient_id', $target->patient_id)->where('tooth_number', $target->tooth_number)
            ->lockForUpdate()->first();

        $target->forceFill(['voided_at' => now(), 'voided_by' => $actor->id, 'void_reason' => mb_substr($request->reason, 0, 500)])->saveQuietly();

        $standing = PatientToothStatusHistory::query()->withoutGlobalScopes()
            ->where('clinic_id', $target->clinic_id)->where('patient_id', $target->patient_id)->where('tooth_number', $target->tooth_number)
            ->whereNull('voided_at')->latest('id')->first();

        if ($standing) {
            $state = ['status' => $standing->status, 'surfaces' => $standing->surfaces, 'notes' => $standing->notes, 'encounter_id' => $standing->encounter_id, 'updated_by_doctor_id' => $standing->updated_by_doctor_id];
            $chart
                ? $chart->forceFill($state)->save()
                : PatientDentalChart::query()->create(['clinic_id' => $target->clinic_id, 'patient_id' => $target->patient_id, 'tooth_number' => $target->tooth_number, ...$state]);
        } else {
            // Nothing left charted on this tooth: its current state is "not charted".
            $chart?->delete();
        }

        $this->timeline($request, 'tooth_entry_voided', ['tooth_number' => $target->tooth_number]);
    }
}
