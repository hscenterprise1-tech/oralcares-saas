<?php

namespace App\Services\Patient;

use App\BillingStatus;
use App\EmploymentStatus;
use App\Models\Branch;
use App\Models\ClinicEmployee;
use App\Models\Patient;
use App\Models\PatientAlert;
use App\Models\PatientBilling;
use App\Models\PatientBranchActivity;
use App\Models\PatientCase;
use App\Models\PatientSpecialty;
use App\Models\PatientTreatment;
use App\Models\PatientVisit;
use App\PatientStatus;
use App\Services\Treatment\TreatmentReadModel;
use App\Support\ClinicClock;
use App\VisitRegistrationType;
use Carbon\Carbon;
use Carbon\CarbonInterface;
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
 *   alerts, balances — are one batched query each, never per row.
 */
class PatientDirectory
{
    public const PER_PAGE = 25;

    /**
     * A visit the patient actually came for: checked in, with the doctor or
     * done. A registration that was never checked in (`registered`) and a
     * cancelled visit are not attendance.
     */
    public const ATTENDED = ['checked_in', 'in_queue', 'waiting', 'with_doctor', 'in_progress', 'completed'];

    public function __construct(
        protected PatientBranchAccess $branchAccess,
        protected TreatmentReadModel $treatments,
    ) {}

    /**
     * @param  array{q?: ?string, specialty?: ?string, branch_id?: ?int, practitioner_id?: int|string|null, active_care?: ?bool, registered_from?: ?string, registered_to?: ?string, view?: ?string, today_doctor_id?: ?int}  $filters
     * @param  bool  $withContact  whether the member may see the full mobile number and email
     * @param  bool  $withBilling  whether the member may see the outstanding balance
     * @return array{data: list<array<string, mixed>>, next_cursor: ?string, prev_cursor: ?string, per_page: int}
     */
    public function page(int $clinicId, array $filters, ?string $cursor = null, int $perPage = self::PER_PAGE, bool $withContact = false, bool $withBilling = false): array
    {
        $perPage = max(1, min($perPage, 100));
        $branchIds = $this->branchAccess->branchIds();
        $clinicToday = ClinicClock::today();
        $today = $clinicToday->toDateString();

        /** @var CursorPaginator $page */
        $page = $this->query($clinicId, $filters, $branchIds)
            ->orderByDesc('created_at')->orderByDesc('id')
            ->cursorPaginate($perPage, ['id', 'clinic_id', 'uhid', 'first_name', 'middle_name', 'last_name', 'name', 'phone', 'email', 'gender', 'date_of_birth', 'age', 'status', 'current_practitioner_id', 'created_at'], 'cursor', $cursor);

        $ids = collect($page->items())->pluck('id')->map(fn ($id) => (int) $id)->all();
        $activeCare = $this->treatments->activeCareFor($clinicId, $ids, $branchIds);
        // Treatment progress (`patient_treatments`, the one record of Dental plans and Physio care plans alike):
        // ongoing while any treatment is open, complete once one is completed and none is open. Never the queue or billing.
        $completedCare = $ids === [] ? collect() : $this->treatments->query($clinicId, $branchIds)->whereIn('patient_id', $ids)
            ->where('status', PatientTreatment::COMPLETED)->distinct()->pluck('patient_id')->flip();
        // New or Repeat is the visit's own classification (`patient_visits.registration_type`, set at check-in by the
        // Reception rule): the latest attended visit's, so Today shows today's case. One query for the page.
        $latestVisits = $ids === [] ? collect() : PatientVisit::query()->withoutGlobalScopes()
            ->whereIn('id', PatientVisit::query()->withoutGlobalScopes()->selectRaw('max(id)')->where('clinic_id', $clinicId)->whereIn('patient_id', $ids)
                ->whereIn('visit_status', self::ATTENDED)->when($branchIds !== null, fn ($q) => $q->whereIn('branch_id', $branchIds ?: [0]))->groupBy('patient_id'))
            ->get(['patient_id', 'registration_type'])->keyBy('patient_id');
        $specialties = $ids === [] ? collect() : PatientSpecialty::query()->withoutGlobalScopes()->where('patient_specialties.clinic_id', $clinicId)->whereIn('patient_specialties.patient_id', $ids)
            ->where('patient_specialties.status', PatientSpecialty::ACTIVE)->join('clinic_specialties', 'clinic_specialties.id', '=', 'patient_specialties.clinic_specialty_id')
            ->get(['patient_specialties.patient_id', 'clinic_specialties.specialty_key'])->groupBy('patient_id');
        // The most recent branch each patient was seen at (among the member's own branches):
        // one query for the page's (patient, branch) rows, one for the branch names.
        $lastSeen = $ids === [] ? collect() : PatientBranchActivity::query()->withoutGlobalScopes()->where('clinic_id', $clinicId)->whereIn('patient_id', $ids)
            ->when($branchIds !== null, fn ($q) => $q->whereIn('branch_id', $branchIds ?: [0]))
            ->get(['patient_id', 'branch_id', 'last_seen_at'])
            ->groupBy('patient_id');
        $branchCounts = $lastSeen->map(fn ($rows) => $rows->pluck('branch_id')->unique()->count());
        $lastSeen = $lastSeen->map(fn ($rows) => $rows->sortByDesc('last_seen_at')->first());
        $branchNames = $lastSeen->isEmpty() ? collect() : Branch::query()->withoutGlobalScopes()->where('clinic_id', $clinicId)
            ->whereIn('id', $lastSeen->pluck('branch_id')->unique())->pluck('name', 'id');
        $alerts = $ids === [] ? collect() : PatientAlert::query()->withoutGlobalScopes()->where('clinic_id', $clinicId)->whereIn('patient_id', $ids)
            ->whereNull('resolved_at')->whereIn('severity', ['critical', 'high'])->groupBy('patient_id')->selectRaw('patient_id, count(*) as n')->pluck('n', 'patient_id');
        // The assigned doctor (`current_practitioner_id`, set by Transfer): one query for the page.
        $doctorIds = collect($page->items())->pluck('current_practitioner_id')->filter()->unique()->values();
        $doctors = $doctorIds->isEmpty() ? collect() : ClinicEmployee::query()->withoutGlobalScopes()->where('clinic_id', $clinicId)
            ->whereIn('id', $doctorIds)->get(['id', 'name', 'status'])->keyBy('id');
        // Today's visit and its branch, for the Today view: one query for the page.
        $todaysVisits = ($filters['view'] ?? null) !== 'today' || $ids === [] ? collect() : PatientVisit::query()->withoutGlobalScopes()
            ->where('clinic_id', $clinicId)->whereIn('patient_id', $ids)->whereOnDate('visit_date', $today)->whereIn('visit_status', self::ATTENDED)
            ->when($branchIds !== null, fn ($q) => $q->whereIn('branch_id', $branchIds ?: [0]))
            ->orderBy('id')->get(['patient_id', 'branch_id', 'visit_status'])->keyBy('patient_id');
        $visitBranchNames = $todaysVisits->isEmpty() ? collect() : Branch::query()->withoutGlobalScopes()->where('clinic_id', $clinicId)
            ->whereIn('id', $todaysVisits->pluck('branch_id')->unique())->pluck('name', 'id');

        // Active case lookup: batched for page
        $activeCases = $ids === [] ? collect() : PatientCase::query()->withoutGlobalScopes()
            ->where('clinic_id', $clinicId)->whereIn('patient_id', $ids)->whereIn('status', PatientCase::OPEN)
            ->when($branchIds !== null, fn ($q) => $q->whereIn('branch_id', $branchIds ?: [0]))
            ->orderByDesc('opened_at')->get(['patient_id', 'case_number', 'title', 'specialty'])->keyBy('patient_id');

        // Balances: batched only when user holds billing.view permission
        $balances = ! $withBilling || $ids === [] ? collect() : PatientBilling::query()->withoutGlobalScopes()
            ->where('clinic_id', $clinicId)->whereIn('patient_id', $ids)
            ->whereIn('status', [BillingStatus::Pending->value, BillingStatus::PartiallyPaid->value])
            ->groupBy('patient_id')
            ->selectRaw('patient_id, sum(final_amount - paid_amount - coalesce(adjusted_amount, 0)) as balance')
            ->pluck('balance', 'patient_id');

        return [
            'data' => collect($page->items())->map(fn (Patient $p) => [
                'id' => $p->id,
                'uhid' => $p->uhid,
                'name' => $p->full_name,
                // The full number and email only for members whose work is contacting patients.
                'phone' => $withContact ? $p->phone : null,
                'phone_last_digits' => $this->lastDigits($p->phone),
                'email' => $withContact ? $p->email : null,
                'gender' => $p->gender,
                // Completed years on the clinic's today, from the date of birth only; none without one.
                'age' => self::ageOn($p->date_of_birth, $clinicToday),
                'status' => $p->status?->value ?? 'active',
                'assigned_doctor' => isset($doctors[$p->current_practitioner_id]) ? [
                    'id' => $p->current_practitioner_id,
                    'name' => $doctors[$p->current_practitioner_id]->name,
                    'at_clinic' => ! in_array($doctors[$p->current_practitioner_id]->status, [EmploymentStatus::Inactive, EmploymentStatus::Resigned], true),
                ] : null,
                'specialties' => ($specialties[$p->id] ?? collect())->pluck('specialty_key')->values()->all(),
                'active_care' => $activeCare[$p->id] ?? [],
                'last_activity_at' => isset($lastSeen[$p->id]) ? (string) $lastSeen[$p->id]->last_seen_at : null,
                'last_branch' => isset($lastSeen[$p->id]) ? ($branchNames[$lastSeen[$p->id]->branch_id] ?? null) : null,
                // Other branches (of the member's own) where the patient has been seen.
                'other_branch_count' => isset($branchCounts[$p->id]) ? max(0, $branchCounts[$p->id] - 1) : 0,
                'today_visit' => isset($todaysVisits[$p->id]) ? [
                    'branch' => $visitBranchNames[$todaysVisits[$p->id]->branch_id] ?? null,
                    'status' => $todaysVisits[$p->id]->visit_status,
                ] : null,
                'case_type' => $latestVisits[$p->id]->registration_type?->value ?? null,
                'active_case' => isset($activeCases[$p->id]) ? [
                    'case_number' => $activeCases[$p->id]->case_number,
                    'title' => $activeCases[$p->id]->title,
                    'specialty' => $activeCases[$p->id]->specialty,
                ] : null,
                'treatment_progress' => isset($activeCare[$p->id]) ? 'ongoing' : (isset($completedCare[$p->id]) ? 'complete' : null),
                'critical_alerts' => (int) ($alerts[$p->id] ?? 0),
                'balance' => $withBilling ? (float) ($balances[$p->id] ?? 0.0) : null,
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
        $practitioner = $filters['practitioner_id'] ?? null;
        $today = ($filters['view'] ?? null) === 'today';
        $clinicToday = ClinicClock::today();

        return $this->branchAccess->restrict(Patient::query()->withoutGlobalScopes()->where('patients.clinic_id', $clinicId))
            // Every patient record, whatever status an old row carries; only a merged duplicate (kept for its survivor) is left out.
            ->whereIn('status', PatientStatus::working())
            ->when($q !== '', fn (Builder $b) => $b->matchingSearch($q))
            ->when($practitioner === 'none', fn (Builder $b) => $b->whereNull('current_practitioner_id'))
            ->when(filled($practitioner) && $practitioner !== 'none', fn (Builder $b) => $b->where('current_practitioner_id', (int) $practitioner))
            ->when(filled($filters['gender'] ?? null), fn (Builder $b) => $b->where('gender', $filters['gender']))
            ->when(filled($filters['status'] ?? null), fn (Builder $b) => $b->where('status', $filters['status']))
            ->when(filled($filters['registered_from'] ?? null), fn (Builder $b) => $b->where('created_at', '>=', Carbon::parse($filters['registered_from'])->startOfDay()))
            ->when(filled($filters['registered_to'] ?? null), fn (Builder $b) => $b->where('created_at', '<=', Carbon::parse($filters['registered_to'])->endOfDay()))
            // Today: everyone who attended today — new or returning, whenever they registered — by an attended
            // visit on the clinic's own date, at one of the member's branches (and the chosen one), to the doctor
            // when it is a doctor's Today. Several visits today are still one patient.
            ->when($today, fn (Builder $b) => $b->whereExists(fn ($s) => $s->selectRaw('1')->from('patient_visits as tv')
                ->whereColumn('tv.patient_id', 'patients.id')->where('tv.clinic_id', $clinicId)
                ->whereOnDate('tv.visit_date', ClinicClock::now()->toDateString())->whereIn('tv.visit_status', self::ATTENDED)
                ->when($branchIds !== null, fn ($v) => $v->whereIn('tv.branch_id', $branchIds ?: [0]))
                ->when($branchId !== null, fn ($v) => $v->where('tv.branch_id', $branchId))
                ->when(filled($filters['today_doctor_id'] ?? null), fn ($v) => $v->where('tv.doctor_id', (int) $filters['today_doctor_id']))))
            ->when($branchId !== null && ! $today, fn (Builder $b) => $b->whereExists(fn ($s) => $s->selectRaw('1')->from('patient_branch_activity as fba')
                ->whereColumn('fba.patient_id', 'patients.id')->whereColumn('fba.clinic_id', 'patients.clinic_id')->where('fba.branch_id', $branchId)))
            ->when(filled($filters['specialty'] ?? null), fn (Builder $b) => $b->whereExists(fn ($s) => $s->selectRaw('1')->from('patient_specialties as ps')
                ->join('clinic_specialties as cs', 'cs.id', '=', 'ps.clinic_specialty_id')
                ->whereColumn('ps.patient_id', 'patients.id')->where('ps.clinic_id', $clinicId)->where('ps.status', PatientSpecialty::ACTIVE)->where('cs.specialty_key', $filters['specialty'])))
            // Treatment status: ongoing / completed / not_started (plus active_care backwards-compat)
            ->when(($filters['treatment_status'] ?? null) === 'ongoing' || ($filters['active_care'] ?? null) === true, fn (Builder $b) => $b->whereExists(fn ($s) => $s->selectRaw('1')->from('patient_treatments as pt')
                ->whereColumn('pt.patient_id', 'patients.id')->where('pt.clinic_id', $clinicId)->whereIn('pt.status', TreatmentReadModel::OPEN)
                ->when($branchIds !== null, fn ($t) => $t->where(fn ($w) => $w->whereIn('pt.branch_id', $branchIds ?: [0])->orWhereNull('pt.branch_id')))))
            ->when(($filters['treatment_status'] ?? null) === 'completed', fn (Builder $b) => $b->whereExists(fn ($s) => $s->selectRaw('1')->from('patient_treatments as pt')
                ->whereColumn('pt.patient_id', 'patients.id')->where('pt.clinic_id', $clinicId)->where('pt.status', PatientTreatment::COMPLETED)
                ->when($branchIds !== null, fn ($t) => $t->where(fn ($w) => $w->whereIn('pt.branch_id', $branchIds ?: [0])->orWhereNull('pt.branch_id'))))
                ->whereNotExists(fn ($s) => $s->selectRaw('1')->from('patient_treatments as pt_open')
                    ->whereColumn('pt_open.patient_id', 'patients.id')->where('pt_open.clinic_id', $clinicId)->whereIn('pt_open.status', TreatmentReadModel::OPEN)
                    ->when($branchIds !== null, fn ($t) => $t->where(fn ($w) => $w->whereIn('pt_open.branch_id', $branchIds ?: [0])->orWhereNull('pt_open.branch_id')))))
            ->when(($filters['treatment_status'] ?? null) === 'not_started', fn (Builder $b) => $b->whereNotExists(fn ($s) => $s->selectRaw('1')->from('patient_treatments as pt')
                ->whereColumn('pt.patient_id', 'patients.id')->where('pt.clinic_id', $clinicId)
                ->when($branchIds !== null, fn ($t) => $t->where(fn ($w) => $w->whereIn('pt.branch_id', $branchIds ?: [0])->orWhereNull('pt.branch_id')))))
            // Patient type: new / repeat
            ->when(filled($filters['patient_type'] ?? null), function (Builder $b) use ($filters, $clinicId, $branchIds) {
                $type = $filters['patient_type'];
                $b->where(function (Builder $q) use ($clinicId, $branchIds, $type) {
                    $q->whereExists(function ($sub) use ($clinicId, $branchIds, $type) {
                        $sub->selectRaw('1')
                            ->from('patient_visits as pv')
                            ->whereColumn('pv.patient_id', 'patients.id')
                            ->where('pv.clinic_id', $clinicId)
                            ->whereIn('pv.visit_status', self::ATTENDED)
                            ->when($branchIds !== null, fn ($v) => $v->whereIn('pv.branch_id', $branchIds ?: [0]))
                            ->where('pv.registration_type', $type)
                            ->whereRaw('pv.id = (
                                select max(pv_sub.id) from patient_visits as pv_sub
                                where pv_sub.patient_id = patients.id
                                and pv_sub.clinic_id = pv.clinic_id
                                and pv_sub.visit_status in ('.implode(',', array_fill(0, count(self::ATTENDED), '?')).')
                                '.($branchIds !== null ? 'and pv_sub.branch_id in ('.implode(',', array_map('intval', $branchIds ?: [0])).')' : '').'
                            )', self::ATTENDED);
                    });
                    if ($type === 'new') {
                        $q->orWhere(function (Builder $noVisits) use ($clinicId, $branchIds) {
                            $noVisits->where(function ($w) {
                                $w->where('patients.registration_type', VisitRegistrationType::New)
                                    ->orWhereNull('patients.registration_type');
                            })->whereNotExists(function ($sub) use ($clinicId, $branchIds) {
                                $sub->selectRaw('1')
                                    ->from('patient_visits as pv')
                                    ->whereColumn('pv.patient_id', 'patients.id')
                                    ->where('pv.clinic_id', $clinicId)
                                    ->whereIn('pv.visit_status', self::ATTENDED)
                                    ->when($branchIds !== null, fn ($v) => $v->whereIn('pv.branch_id', $branchIds ?: [0]));
                            });
                        });
                    }
                });
            })
            // Age range: age_min / age_max
            ->when(filled($filters['age_min'] ?? null) || filled($filters['age_max'] ?? null), function (Builder $b) use ($filters, $clinicToday) {
                $ageMin = filled($filters['age_min'] ?? null) ? (int) $filters['age_min'] : null;
                $ageMax = filled($filters['age_max'] ?? null) ? (int) $filters['age_max'] : null;

                $b->where(function (Builder $ageQuery) use ($ageMin, $ageMax, $clinicToday) {
                    $ageQuery->where(function (Builder $dobQuery) use ($ageMin, $ageMax, $clinicToday) {
                        $dobQuery->whereNotNull('patients.date_of_birth');
                        if ($ageMin !== null) {
                            $dobQuery->where('patients.date_of_birth', '<=', $clinicToday->copy()->subYears($ageMin)->toDateString());
                        }
                        if ($ageMax !== null) {
                            $dobQuery->where('patients.date_of_birth', '>=', $clinicToday->copy()->subYears($ageMax + 1)->addDay()->toDateString());
                        }
                    })->orWhere(function (Builder $legacyQuery) use ($ageMin, $ageMax) {
                        $legacyQuery->whereNull('patients.date_of_birth')->whereNotNull('patients.age');
                        if ($ageMin !== null) {
                            $legacyQuery->where('patients.age', '>=', $ageMin);
                        }
                        if ($ageMax !== null) {
                            $legacyQuery->where('patients.age', '<=', $ageMax);
                        }
                    });
                });
            })
            // Last visit date range: last_visit_from / last_visit_to
            ->when(filled($filters['last_visit_from'] ?? null) || filled($filters['last_visit_to'] ?? null), function (Builder $b) use ($filters, $clinicId, $branchIds, $branchId) {
                $from = filled($filters['last_visit_from'] ?? null) ? Carbon::parse($filters['last_visit_from'])->startOfDay() : null;
                $to = filled($filters['last_visit_to'] ?? null) ? Carbon::parse($filters['last_visit_to'])->endOfDay() : null;

                $b->whereExists(function ($sub) use ($clinicId, $branchIds, $branchId, $from, $to) {
                    $sub->selectRaw('1')->from('patient_branch_activity as pba')
                        ->whereColumn('pba.patient_id', 'patients.id')
                        ->where('pba.clinic_id', $clinicId)
                        ->when($branchIds !== null, fn ($q) => $q->whereIn('pba.branch_id', $branchIds ?: [0]))
                        ->when($branchId !== null, fn ($q) => $q->where('pba.branch_id', $branchId))
                        ->when($from !== null, fn ($q) => $q->where('pba.last_seen_at', '>=', $from))
                        ->when($to !== null, fn ($q) => $q->where('pba.last_seen_at', '<=', $to));
                });
            });
    }

    /**
     * A patient's age on a day: completed years since the date of birth, one
     * fewer while that year's birthday is still to come (a 29 February
     * birthday comes on 1 March in other years). Null without a date of
     * birth, or with one after the day. The recorded `age` is never used:
     * it is a number from registration, not an age today.
     */
    public static function ageOn(?CarbonInterface $dateOfBirth, CarbonInterface $day): ?int
    {
        if ($dateOfBirth === null || $dateOfBirth->toDateString() > $day->toDateString()) {
            return null;
        }
        $years = $day->year - $dateOfBirth->year;
        $birthdayToCome = $day->month < $dateOfBirth->month || ($day->month === $dateOfBirth->month && $day->day < $dateOfBirth->day);

        return $birthdayToCome ? $years - 1 : $years;
    }

    /** The last four digits of a number, the most a member without contact access is shown. */
    private function lastDigits(?string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $phone) ?? '';

        return strlen($digits) >= 4 ? substr($digits, -4) : null;
    }
}
