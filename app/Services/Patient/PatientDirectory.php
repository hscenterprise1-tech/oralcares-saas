<?php

namespace App\Services\Patient;

use App\Models\Branch;
use App\Models\Patient;
use App\Models\PatientAlert;
use App\Models\PatientBranchActivity;
use App\Models\PatientSpecialty;
use App\PatientStatus;
use App\Services\Treatment\TreatmentReadModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\CursorPaginator;

/**
 * The patient directory for any clinic size.
 *
 * - Every query leads with `clinic_id`; search is prefix-only (indexed);
 *   a branch-limited member is limited with one EXISTS on
 *   `patient_branch_activity`; specialty uses `patient_specialties`.
 * - Cursor pagination on (created_at, id): no COUNT(*), constant cost at any
 *   depth.
 * - Each page's extra columns — active care, specialties, last activity,
 *   alerts — are one batched query each, never per row.
 */
class PatientDirectory
{
    public const PER_PAGE = 25;

    public function __construct(
        protected PatientBranchAccess $branchAccess,
        protected TreatmentReadModel $treatments,
    ) {}

    /**
     * @param  array{q?: ?string, status?: ?string, specialty?: ?string, branch_id?: ?int, practitioner_id?: ?int, active_care?: ?bool, registered_from?: ?string, registered_to?: ?string}  $filters
     * @return array{data: list<array<string, mixed>>, next_cursor: ?string, prev_cursor: ?string, per_page: int}
     */
    public function page(int $clinicId, array $filters, ?string $cursor = null, int $perPage = self::PER_PAGE): array
    {
        $perPage = max(1, min($perPage, 100));
        $branchIds = $this->branchAccess->branchIds();

        /** @var CursorPaginator $page */
        $page = $this->query($clinicId, $filters, $branchIds)
            ->orderByDesc('created_at')->orderByDesc('id')
            ->cursorPaginate($perPage, ['id', 'clinic_id', 'uhid', 'first_name', 'middle_name', 'last_name', 'name', 'phone', 'email', 'gender', 'date_of_birth', 'age', 'status', 'current_practitioner_id', 'created_at'], 'cursor', $cursor);

        $ids = collect($page->items())->pluck('id')->map(fn ($id) => (int) $id)->all();
        $activeCare = $this->treatments->activeCareFor($clinicId, $ids, $branchIds);
        $specialties = $ids === [] ? collect() : PatientSpecialty::query()->withoutGlobalScopes()->where('patient_specialties.clinic_id', $clinicId)->whereIn('patient_specialties.patient_id', $ids)
            ->where('patient_specialties.status', PatientSpecialty::ACTIVE)->join('clinic_specialties', 'clinic_specialties.id', '=', 'patient_specialties.clinic_specialty_id')
            ->get(['patient_specialties.patient_id', 'clinic_specialties.specialty_key'])->groupBy('patient_id');
        // The most recent branch each patient was seen at (among the member's own branches):
        // one query for the page's (patient, branch) rows, one for the branch names.
        $lastSeen = $ids === [] ? collect() : PatientBranchActivity::query()->withoutGlobalScopes()->where('clinic_id', $clinicId)->whereIn('patient_id', $ids)
            ->when($branchIds !== null, fn ($q) => $q->whereIn('branch_id', $branchIds ?: [0]))
            ->get(['patient_id', 'branch_id', 'last_seen_at'])
            ->groupBy('patient_id')->map(fn ($rows) => $rows->sortByDesc('last_seen_at')->first());
        $branchNames = $lastSeen->isEmpty() ? collect() : Branch::query()->withoutGlobalScopes()->where('clinic_id', $clinicId)
            ->whereIn('id', $lastSeen->pluck('branch_id')->unique())->pluck('name', 'id');
        $alerts = $ids === [] ? collect() : PatientAlert::query()->withoutGlobalScopes()->where('clinic_id', $clinicId)->whereIn('patient_id', $ids)
            ->whereIn('severity', ['critical', 'high'])->groupBy('patient_id')->selectRaw('patient_id, count(*) as n')->pluck('n', 'patient_id');

        return [
            'data' => collect($page->items())->map(fn (Patient $p) => [
                'id' => $p->id,
                'uhid' => $p->uhid,
                'name' => $p->full_name,
                'phone' => $p->phone,
                'email' => $p->email,
                'gender' => $p->gender,
                'age' => $p->age,
                'status' => $p->status?->value ?? 'active',
                'specialties' => ($specialties[$p->id] ?? collect())->pluck('specialty_key')->values()->all(),
                'active_care' => $activeCare[$p->id] ?? [],
                'last_activity_at' => isset($lastSeen[$p->id]) ? (string) $lastSeen[$p->id]->last_seen_at : null,
                'last_branch' => isset($lastSeen[$p->id]) ? ($branchNames[$lastSeen[$p->id]->branch_id] ?? null) : null,
                'critical_alerts' => (int) ($alerts[$p->id] ?? 0),
                'registered_on' => $p->created_at?->toDateString(),
            ])->values()->all(),
            'next_cursor' => $page->nextCursor()?->encode(),
            'prev_cursor' => $page->previousCursor()?->encode(),
            'per_page' => $perPage,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  list<int>|null  $branchIds
     */
    public function query(int $clinicId, array $filters, ?array $branchIds): Builder
    {
        $q = trim((string) ($filters['q'] ?? ''));
        $branchId = isset($filters['branch_id']) ? (int) $filters['branch_id'] : null;
        // A branch filter can only narrow what the member may see.
        if ($branchId !== null && $branchIds !== null && ! in_array($branchId, $branchIds, true)) {
            $branchId = 0;
        }

        return $this->branchAccess->restrict(Patient::query()->withoutGlobalScopes()->where('patients.clinic_id', $clinicId))
            ->when(filled($filters['status'] ?? null), fn (Builder $b) => $b->where('status', $filters['status']), fn (Builder $b) => $b->whereIn('status', PatientStatus::working()))
            ->when($q !== '', fn (Builder $b) => $b->matchingSearch($q))
            ->when(filled($filters['practitioner_id'] ?? null), fn (Builder $b) => $b->where('current_practitioner_id', (int) $filters['practitioner_id']))
            ->when(filled($filters['registered_from'] ?? null), fn (Builder $b) => $b->where('created_at', '>=', $filters['registered_from']))
            ->when(filled($filters['registered_to'] ?? null), fn (Builder $b) => $b->where('created_at', '<', now()->parse($filters['registered_to'])->addDay()))
            ->when($branchId !== null, fn (Builder $b) => $b->whereExists(fn ($s) => $s->selectRaw('1')->from('patient_branch_activity as fba')
                ->whereColumn('fba.patient_id', 'patients.id')->whereColumn('fba.clinic_id', 'patients.clinic_id')->where('fba.branch_id', $branchId)))
            ->when(filled($filters['specialty'] ?? null), fn (Builder $b) => $b->whereExists(fn ($s) => $s->selectRaw('1')->from('patient_specialties as ps')
                ->join('clinic_specialties as cs', 'cs.id', '=', 'ps.clinic_specialty_id')
                ->whereColumn('ps.patient_id', 'patients.id')->where('ps.clinic_id', $clinicId)->where('ps.status', PatientSpecialty::ACTIVE)->where('cs.specialty_key', $filters['specialty'])))
            ->when(($filters['active_care'] ?? null) === true, fn (Builder $b) => $b->whereExists(fn ($s) => $s->selectRaw('1')->from('patient_treatments as pt')
                ->whereColumn('pt.patient_id', 'patients.id')->where('pt.clinic_id', $clinicId)->whereIn('pt.status', TreatmentReadModel::OPEN)));
    }
}
