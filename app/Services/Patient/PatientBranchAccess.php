<?php

namespace App\Services\Patient;

use App\Models\Patient;
use App\Services\ClinicContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Branch scope for patients. The patient record is clinic-wide; a member
 * limited to some branches sees the patients who have activity at one of
 * them (`patient_branch_activity`), and nobody else's directory entry or
 * Patient 360.
 *
 * Identity lookups — the reception check-in search, duplicate detection —
 * deliberately stay clinic-wide: a returning patient from another branch
 * must be found, not registered twice. Checking them in here records
 * activity at this branch, which is what then opens their record.
 */
class PatientBranchAccess
{
    /** @var array<string, list<int>|null> */
    private array $memo = [];

    public function __construct(protected ClinicContext $clinicContext) {}

    /**
     * Branch ids the current member is limited to, or null when unlimited
     * (owner, all-branch access, no membership context such as a job).
     * Remembered per request: Laravel reuses the instances that hold this.
     *
     * @return list<int>|null
     */
    public function branchIds(): ?array
    {
        $membership = $this->clinicContext->membership();
        $key = spl_object_id(request()).':'.($membership?->id ?? 0);
        if (array_key_exists($key, $this->memo)) {
            return $this->memo[$key];
        }

        $ids = (! $membership || $membership->has_all_branches_access || $membership->is_primary_owner)
            ? null
            : $membership->accessibleActiveBranches()->pluck('branches.id')->map(fn ($id) => (int) $id)->values()->all();

        // Only this request's answer is kept, so the memo cannot grow.
        $this->memo = [$key => $ids];

        return $ids;
    }

    /** Limit a patient query to the current member's branches. */
    public function restrict(Builder $patients): Builder
    {
        $ids = $this->branchIds();
        if ($ids === null) {
            return $patients;
        }

        $table = $patients->getModel()->getTable();

        return $patients->whereExists(fn (QueryBuilder $activity) => $activity->selectRaw('1')
            ->from('patient_branch_activity')
            ->whereColumn('patient_branch_activity.patient_id', "{$table}.id")
            ->whereColumn('patient_branch_activity.clinic_id', "{$table}.clinic_id")
            ->whereIn('patient_branch_activity.branch_id', $ids === [] ? [0] : $ids));
    }

    public function allows(Patient $patient): bool
    {
        $ids = $this->branchIds();
        if ($ids === null) {
            return true;
        }

        return $ids !== [] && $patient->branchActivity()->withoutGlobalScopes()
            ->where('clinic_id', $patient->clinic_id)->whereIn('branch_id', $ids)->exists();
    }
}
