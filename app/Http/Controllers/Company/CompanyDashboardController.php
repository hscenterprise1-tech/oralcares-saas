<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\LeadStatus;
use App\QuotationStatus;
use App\Services\ExecutiveDashboardService;
use App\Support\CrmScope;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CompanyDashboardController extends Controller
{
    public function __construct(
        protected ExecutiveDashboardService $dashboardService
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $payload = $this->dashboardService->getDashboardPayload($user);

        /*
         * Scoped through the same CrmScope boundary as everything else on
         * this page — these four figures used to query every tenant's leads
         * and quotations unscoped, alongside a second, independent
         * computation of nearly the same numbers inside
         * ExecutiveDashboardService itself.
         */
        $leads = CrmScope::leads($user);
        $newLeads = (clone $leads)->where(fn ($q) => $q->where('status', LeadStatus::New->value)->orWhere('status', 'new'))->count();
        $totalLeads = (clone $leads)->count();
        $followUpsToday = (clone $leads)->whereDate('next_followup_at', today())->count();
        $quotationsPending = CrmScope::quotations($user)->where(fn ($q) => $q->where('status', QuotationStatus::Draft)->orWhere('status', 'draft'))->count();

        return Inertia::render('Company/Dashboard', array_merge([
            'finalFreezeData' => $payload,
            'newLeads' => $newLeads,
            'totalLeads' => $totalLeads,
            'followUpsToday' => $followUpsToday,
            'quotationsPending' => $quotationsPending,
        ], $payload));
    }
}
