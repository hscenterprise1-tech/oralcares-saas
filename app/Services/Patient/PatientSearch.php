<?php

namespace App\Services\Patient;

use App\Models\Clinic;
use App\Models\Patient;
use App\PatientStatus;
use Illuminate\Support\Collection;

/**
 * The one way the portal finds a patient by what a person types: a mobile
 * number, a UHID or a name. Every search surface (the desk's Repeat Case,
 * the calendar's patient picker, the command palette) asks this, so they
 * agree on what matches, how short a query may be and how much comes back.
 *
 * Identity search covers the branches the member may open
 * (`PatientBranchAccess`): a member limited to some branches finds only
 * the patients with activity at one of them, so typing a name or number
 * never reveals another branch's patients. Registration's duplicate check
 * is the one clinic-wide look, and it shows other-branch matches masked
 * (`PatientDuplicateDetectionService::present`). Results are bounded; what
 * each surface then shows about a match is that surface's own read model.
 * Throttling is on the routes (`throttle:clinic-patient-lookup`).
 */
class PatientSearch
{
    public function __construct(protected PatientBranchAccess $branchAccess) {}

    /** Shorter queries return nothing: too broad to be useful, too cheap to abuse. */
    public const MIN_LENGTH = 2;

    public const MAX_RESULTS = 20;

    /**
     * @param  list<string>  $columns
     * @return Collection<int, Patient>
     */
    public function find(Clinic $clinic, string $query, int $limit = 10, bool $workingOnly = true, array $columns = ['*']): Collection
    {
        $query = $this->normalise($query);
        if (mb_strlen($query) < self::MIN_LENGTH) {
            return collect();
        }

        return $this->branchAccess->restrict(Patient::withoutGlobalScopes()->where('clinic_id', $clinic->id))
            ->matchingSearch($query)
            ->when($workingOnly, fn ($builder) => $builder->whereIn('status', PatientStatus::working()))
            ->limit(min(max($limit, 1), self::MAX_RESULTS))
            ->get($columns);
    }

    /**
     * Trims, and reads a typed number ("98111 00124", "98111-00124") without
     * its separators, the way numbers are stored. A leading `+` is kept.
     */
    public function normalise(string $query): string
    {
        $query = trim($query);

        if (preg_match('/^[\d\s+()\-]+$/', $query) === 1) {
            return (str_starts_with($query, '+') ? '+' : '').(preg_replace('/\D/', '', $query) ?? '');
        }

        return $query;
    }
}
