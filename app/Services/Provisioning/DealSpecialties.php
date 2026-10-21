<?php

namespace App\Services\Provisioning;

use App\Models\Branch;
use App\Models\Clinic;
use App\Models\Lead;
use Illuminate\Support\Str;

/**
 * The specialties a deal sold, carried onto the clinic it provisions.
 *
 * The deal's `healthcare_specialties` (chosen in CRM, required on every lead)
 * is the one record of what the clinic practises. Deal-Won and trial
 * provisioning both read it here, so a trial and a sale of the same deal get
 * the same specialty set, and so the same onboarding template. The plan then
 * decides which of them the clinic may use (`SpecialtyEntitlements`).
 */
class DealSpecialties
{
    /**
     * The deal's specialty keys, as the clinic stores them. `other` and
     * blanks name no specialty.
     *
     * @return list<string>
     */
    public function fromLead(Lead $lead): array
    {
        return collect((array) ($lead->healthcare_specialties ?? []))
            ->push($lead->healthcare_specialty instanceof \BackedEnum ? $lead->healthcare_specialty->value : $lead->healthcare_specialty)
            ->filter(fn ($value) => is_string($value) && $value !== '' && $value !== 'other')
            ->map(fn (string $value) => Str::snake(Str::lower(trim($value))))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Write the deal's specialties to the clinic and its primary branch. A
     * deal that names no specialty leaves both as they are.
     */
    public function apply(Lead $lead, Clinic $clinic, ?Branch $branch): void
    {
        $specialties = $this->fromLead($lead);

        if ($specialties === []) {
            return;
        }

        $clinic->update(['specialties' => $specialties]);

        if ($branch) {
            Branch::withoutGlobalScopes()->whereKey($branch->id)->update(['specialties' => json_encode($specialties)]);
        }
    }
}
