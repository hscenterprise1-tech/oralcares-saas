<?php

namespace App\Http\Controllers\Clinic;

use App\ClinicPermission;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Services\Billing\BillingRegister;
use App\Services\ClinicContext;
use App\Support\BillingTabs;
use App\Support\ClinicAccess\BranchReach;
use App\Support\ClinicAccess\ClinicAccess;
use App\Support\ClinicClock;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Billing area's read-only sections: the history of refunds and
 * adjustments, and the financial summary (`BillingRegister`). Neither
 * changes anything; refunds and adjustments are made from a bill, through
 * their own authorized, idempotent routes.
 */
class BillingRegisterController extends Controller
{
    public function __construct(
        protected ClinicContext $clinicContext,
        protected BillingRegister $register,
        protected ClinicAccess $access,
    ) {}

    /** Refunds & Adjustments: every one recorded, newest first, with totals of the same rows. */
    public function reversals(Request $request): Response
    {
        $clinic = $this->clinicContext->clinic() ?? abort(403);
        $reach = $this->register->reach(ClinicPermission::BillingView);
        $filters = $this->register->filters($request);
        $rows = $this->register->reversals($clinic->id, $reach, $filters);

        return Inertia::render('ClinicPortal/Billing/Reversals', [
            'reversals' => (clone $rows)
                ->leftJoin('patients', 'patients.id', '=', 'reversals.patient_id')
                ->leftJoin('branches', 'branches.id', '=', 'reversals.branch_id')
                ->orderByDesc('reversals.created_at')->orderByDesc('reversals.id')
                ->select('reversals.*', 'patients.first_name', 'patients.last_name', 'patients.uhid', 'branches.name as branch_name')
                ->paginate(BillingRegister::PAGE)->withQueryString()
                ->through(fn ($row) => [
                    'key' => "{$row->type}-{$row->id}",
                    'type' => $row->type,
                    'number' => $row->number,
                    'bill_id' => (int) $row->bill_id,
                    'bill_number' => $row->bill_number,
                    'patient' => trim($row->first_name.' '.$row->last_name),
                    'uhid' => $row->uhid,
                    'branch' => $row->branch_name,
                    'amount' => (float) $row->amount,
                    'method' => $row->method,
                    'reason' => $row->reason,
                    'recorded_by' => $row->recorded_by,
                    'recorded_at' => CarbonImmutable::parse($row->created_at, 'UTC')->toIso8601String(),
                ]),
            'totals' => $this->register->reversalTotals($rows),
            'filters' => $filters,
            'branches' => $this->branchOptions($clinic->id, $reach),
            'tabs' => BillingTabs::for($this->access, $this->routeParameters($request)),
            'links' => ['bill' => route('clinic.billing.bills.show', ['bill' => '__ID__'], false)],
        ]);
    }

    /**
     * Financial Summaries for a range (this month by default): what was
     * billed, collected, refunded and adjusted, by payment method and by
     * branch, outstanding as of now, and spending for whoever may see it.
     */
    public function summary(Request $request): Response
    {
        $clinic = $this->clinicContext->clinic() ?? abort(403);
        $reach = $this->register->reach(ClinicPermission::BillingView);
        $filters = $this->register->filters($request);
        $today = ClinicClock::today();
        $filters['from'] ??= $today->startOfMonth()->toDateString();
        $filters['to'] ??= $today->toDateString();
        $expenses = $request->user()->hasClinicPermission(ClinicPermission::ExpenseManage) ? $this->register->reach(ClinicPermission::ExpenseManage) : null;

        return Inertia::render('ClinicPortal/Billing/Summary', [
            'summary' => $this->register->summary($clinic->id, $reach, $expenses, $filters),
            'filters' => $filters,
            'branches' => $this->branchOptions($clinic->id, $reach),
            'tabs' => BillingTabs::for($this->access, $this->routeParameters($request)),
            'links' => [
                'bills' => route('clinic.billing.index', $this->routeParameters($request), false),
                'reversals' => route('clinic.billing.reversals', $this->routeParameters($request), false),
            ],
        ]);
    }

    /** @return list<array{value: string, label: string}> */
    private function branchOptions(int $clinicId, BranchReach $reach): array
    {
        return Branch::query()->withoutGlobalScopes()->where('clinic_id', $clinicId)->orderByDesc('is_main')->orderBy('name')->get(['id', 'name'])
            ->filter(fn (Branch $branch) => $reach->covers($branch->id))
            ->map(fn (Branch $branch) => ['value' => (string) $branch->id, 'label' => $branch->name])->values()->all();
    }

    /** @return array<string, string> */
    private function routeParameters(Request $request): array
    {
        return array_filter(['clinic' => $request->route('clinic')], fn ($value) => is_string($value));
    }
}
