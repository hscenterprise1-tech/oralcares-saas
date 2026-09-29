<?php

namespace App\Services\Patient;

use App\Models\CaseNumber;
use App\Models\CaseNumberHistory;
use App\Models\Clinic;
use App\Models\Patient;
use Illuminate\Database\Eloquent\Collection;

class PatientSearchService
{
    /**
     * Unified reception search.
     *
     * @param  string  $searchBy  One of: 'mobile', 'name', 'uhid', 'case_number'
     * @return Collection<int, Patient>
     */
    public function search(Clinic $clinic, string $query, string $searchBy): Collection
    {
        $query = trim($query);

        return match ($searchBy) {
            'mobile' => $this->byMobile($clinic, $query),
            'uhid' => $this->byUhid($clinic, $query),
            'case_number' => $this->byCaseNumber($clinic, $query),
            default => $this->byName($clinic, $query),
        };
    }

    /**
     * Search by mobile or alternate mobile number.
     *
     * @return Collection<int, Patient>
     */
    private function byMobile(Clinic $clinic, string $mobile): Collection
    {
        return Patient::withoutGlobalScopes()
            ->where('clinic_id', $clinic->id)
            ->searchByPhone($mobile)
            ->with(['profile', 'caseNumbers' => fn ($q) => $q->latest()])
            ->limit(20)
            ->get();
    }

    /**
     * Search by patient name (partial, case-insensitive).
     *
     * @return Collection<int, Patient>
     */
    private function byName(Clinic $clinic, string $name): Collection
    {
        return Patient::withoutGlobalScopes()
            ->where('clinic_id', $clinic->id)
            ->searchByName($name)
            ->with(['profile', 'caseNumbers' => fn ($q) => $q->latest()])
            ->limit(20)
            ->get();
    }

    /**
     * Search by exact UHID.
     *
     * @return Collection<int, Patient>
     */
    private function byUhid(Clinic $clinic, string $uhid): Collection
    {
        return Patient::withoutGlobalScopes()
            ->where('clinic_id', $clinic->id)
            ->searchByUhid($uhid)
            ->with(['profile', 'caseNumbers' => fn ($q) => $q->latest()])
            ->limit(5)
            ->get();
    }

    /**
     * Search by the full case number, in any spelling of its separators
     * ("DC/26-27/000124" and "dc2627000124" match the same number). Numbers
     * merged into `case_number_history` still resolve to their patient.
     * Deliberately exact: the last-four-digits label shown in the queue is
     * for display only and never searched on.
     *
     * @return Collection<int, Patient>
     */
    private function byCaseNumber(Clinic $clinic, string $caseNumber): Collection
    {
        $searchKey = CaseNumberFormatter::searchKey($caseNumber);

        if ($searchKey === '') {
            return new Collection;
        }

        $patientIds = CaseNumber::withoutGlobalScopes()
            ->where('clinic_id', $clinic->id)
            ->where('search_key', $searchKey)
            ->pluck('patient_id')
            ->merge(CaseNumberHistory::where('clinic_id', $clinic->id)->where('search_key', $searchKey)->pluck('patient_id'));

        return Patient::withoutGlobalScopes()
            ->where('clinic_id', $clinic->id)
            ->whereIn('id', $patientIds)
            ->with(['profile', 'caseNumbers' => fn ($q) => $q->latest()])
            ->limit(5)
            ->get();
    }
}
