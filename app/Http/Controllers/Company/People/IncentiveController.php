<?php

namespace App\Http\Controllers\Company\People;

use App\Http\Controllers\Controller;
use App\Models\CompanyEmployee;
use App\Models\CompanyIncentive;
use App\Support\CrmScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Incentive awards — a separate ledger, not part of payroll.
 *
 * `company_incentives` ties an award to an employee, an amount, a date and
 * optionally a CRM deal. It carries no payroll id, no period and no month, and
 * nothing in the application joins it to a payroll run. Whether a commission is
 * eventually disbursed through a run or paid on its own is a business decision
 * nobody has made, so the two stay apart.
 *
 * The schema is already shaped for that decision to go either way later: an
 * award has its own date and amount, so including eligible awards as earning
 * lines in a future run would read this ledger rather than rewrite it.
 */
class IncentiveController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('view-incentives');

        $filters = $request->only(['search', 'employee_id']);

        $incentives = CompanyIncentive::query()
            ->with(['employee.user:id,name', 'lead:id,company_name'])
            /*
             * The search is grouped. Left ungrouped, an `orWhere` escapes the
             * filters beside it and the list stops obeying them — the defect
             * the payroll list still has.
             */
            ->when($filters['search'] ?? null, function ($query, $search) {
                $query->where(function ($inner) use ($search) {
                    $inner->where('description', 'like', "%{$search}%")
                        ->orWhereHas('employee.user', fn ($q) => $q->where('name', 'like', "%{$search}%"));
                });
            })
            ->when($filters['employee_id'] ?? null, fn ($query, $id) => $query->where('employee_id', $id))
            ->orderByDesc('awarded_date')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (CompanyIncentive $incentive) => [
                'id' => $incentive->id,
                'employee_name' => $incentive->employee?->user?->name ?? $incentive->employee?->employee_code,
                'employee_code' => $incentive->employee?->employee_code,
                'description' => $incentive->description,
                'amount' => (float) $incentive->amount,
                'awarded_date' => $incentive->awarded_date?->toDateString(),
                'deal' => $incentive->lead ? [
                    'id' => $incentive->lead->id,
                    'company_name' => $incentive->lead->company_name,
                ] : null,
                'recorded_at' => $incentive->created_at?->format('d M Y, H:i'),
            ]);

        return Inertia::render('Company/People/IncentivesWorkspace', [
            'incentives' => $incentives,
            'filters' => $filters,
            'employees' => CompanyEmployee::with('user:id,name')
                ->get()
                ->map(fn (CompanyEmployee $e) => [
                    'id' => $e->id,
                    'name' => $e->user?->name ?? $e->employee_code,
                    'employee_code' => $e->employee_code,
                ])
                ->values(),
            'totals' => [
                'awarded' => (float) CompanyIncentive::sum('amount'),
                'count' => CompanyIncentive::count(),
            ],
            'can' => ['manage' => Gate::allows('manage-incentives')],
        ]);
    }

    public function store(Request $request)
    {
        Gate::authorize('manage-incentives');

        $validated = $request->validate([
            'employee_id' => ['required', 'exists:company_employees,id'],
            'description' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0'],
            'awarded_date' => ['required', 'date'],
            'related_lead_id' => ['nullable', 'integer'],
        ]);

        /*
         * A deal the awarder may actually see.
         *
         * `related_lead_id` reaches into CRM, so the reference is resolved
         * through CrmScope rather than a bare `exists` rule — otherwise an
         * award could quietly name another tenant's deal.
         */
        if (! empty($validated['related_lead_id'])) {
            $lead = CrmScope::leads($request->user())
                ->whereKey($validated['related_lead_id'])
                ->first();

            abort_unless($lead !== null, 404);
        }

        $incentive = CompanyIncentive::create($validated);

        return back()->with('success', "Incentive of {$incentive->amount} recorded.");
    }
}
