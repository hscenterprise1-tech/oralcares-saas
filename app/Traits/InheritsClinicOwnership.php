<?php

namespace App\Traits;

use App\Models\Branch;
use App\Models\ClinicalEncounter;
use App\Models\Patient;
use App\Services\ClinicContext;
use Illuminate\Database\Eloquent\Builder;
use LogicException;

/**
 * For clinical records whose `clinic_id` is required (encounter family).
 *
 * If a record is created without `clinic_id`, it takes the clinic of the
 * record it hangs off: its encounter, else its patient, else its branch.
 * Ownership then always comes from the data, never from a guess. A record
 * whose parents disagree about the clinic, or whose clinic is not the clinic
 * of the current tenant context, is refused. That stops a user in one clinic
 * from attaching a record to another clinic's patient.
 *
 * Runs in `performInsert`, not a `creating` listener, so it holds even when
 * model events are faked in tests.
 */
trait InheritsClinicOwnership
{
    protected function performInsert(Builder $query)
    {
        $this->resolveClinicOwnership();

        return parent::performInsert($query);
    }

    protected function resolveClinicOwnership(): void
    {
        $candidates = array_values(array_unique(array_filter([
            $this->getAttribute('clinic_id') ? (int) $this->getAttribute('clinic_id') : null,
            $this->ownerClinicFromEncounter(),
            $this->ownerClinicFromPatient(),
            $this->ownerClinicFromBranch(),
        ])));

        if (count($candidates) > 1) {
            throw new LogicException(class_basename($this).' links records that belong to different clinics.');
        }

        if ($candidates === []) {
            return; // The NOT NULL constraint reports it.
        }

        $context = app()->bound(ClinicContext::class) ? app(ClinicContext::class) : null;

        if ($context?->hasClinic() && $context->clinic()->id !== $candidates[0]) {
            throw new LogicException(class_basename($this).' cannot be created for a clinic other than the active one.');
        }

        $this->setAttribute('clinic_id', $candidates[0]);
    }

    private function ownerClinicFromEncounter(): ?int
    {
        $encounterId = $this->getAttribute('encounter_id');

        return $encounterId ? ClinicalEncounter::query()->withoutGlobalScopes()->whereKey($encounterId)->value('clinic_id') : null;
    }

    private function ownerClinicFromPatient(): ?int
    {
        $patientId = $this->getAttribute('patient_id');

        return $patientId ? Patient::query()->withoutGlobalScopes()->whereKey($patientId)->value('clinic_id') : null;
    }

    private function ownerClinicFromBranch(): ?int
    {
        $branchId = $this->getAttribute('branch_id');

        return $branchId ? Branch::query()->withoutGlobalScopes()->whereKey($branchId)->value('clinic_id') : null;
    }
}
