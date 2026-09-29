<?php

namespace App\Http\Controllers\Company\CustomerSuccess;

use App\Http\Controllers\Controller;
use App\Models\CompanyPlan;
use App\Models\User;
use App\Services\CommercialRenewalService;
use App\Services\LeadWorkspaceService;
use App\Support\CrmScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class RenewalController extends Controller
{
    public function __construct(
        protected CommercialRenewalService $renewalService,
        protected LeadWorkspaceService $leadService
    ) {}

    /**
     * Cross-Account Renewals Queue Workspace.
     */
    public function index(Request $request): Response
    {
        Gate::authorize('cs.renewals.view');

        $filters = $request->only(['search', 'horizon', 'window', 'status', 'owner_id', 'account_id', 'sort', 'direction', 'page', 'per_page']);
        $payload = $this->renewalService->getRenewalsPayload($request->user(), $filters);

        // Scoped owners for filter dropdown: users assigned to scoped customer accounts
        $baseScoped = CrmScope::customerAccounts($request->user());
        $eligibleOwners = User::query()
            ->whereIn('id', (clone $baseScoped)->whereNotNull('assigned_user_id')->select('assigned_user_id'))
            ->select('id', 'name', 'email')
            ->orderBy('name')
            ->get();

        return Inertia::render('Company/CustomerSuccess/Renewals/Index', array_merge($payload, [
            'eligibleOwners' => $eligibleOwners,
            /*
             * The plan catalogue, so a renewal can be completed here.
             *
             * This queue could only raise a CRM *deal* against a customer who
             * had already bought — the pipeline built for winning a stranger.
             * Another year on the plan they already hold is not a sale, so the
             * contract verbs now live on this page.
             */
            'plans' => CompanyPlan::query()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (CompanyPlan $plan) => ['id' => $plan->id, 'name' => $plan->name])
                ->values(),
            'can' => [
                'manageContract' => Gate::allows('cs.accounts.manage'),
                'viewAccount' => Gate::allows('view-organizations'),
            ],
        ]));
    }
}
