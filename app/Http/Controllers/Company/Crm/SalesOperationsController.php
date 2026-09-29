<?php

namespace App\Http\Controllers\Company\Crm;

use App\Http\Controllers\Controller;
use App\LostReason;
use App\Models\Lead;
use App\Services\LeadWorkspaceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * The two write actions loss triage performs.
 *
 * The workspace this controller used to render is gone: it listed the same
 * `leads` rows the Deals list holds, so a lost deal was counted twice and the
 * two figures drifted. Triage and win-back are now invoked from the Deals
 * "Lost" view, against the row they belong to. The endpoints themselves are
 * unchanged — only the page that called them moved.
 */
class SalesOperationsController extends Controller
{
    public function __construct(protected LeadWorkspaceService $workspaceService) {}

    /**
     * Triage a lost lead.
     */
    public function triage(Request $request, Lead $lead)
    {
        Gate::authorize('update', $lead);

        $validated = $request->validate([
            /*
             * The enum, not any string.
             *
             * `leads.lost_reason` casts to `LostReason`, so a value outside it
             * was accepted here and then threw a ValueError on the way back out
             * — a 500 instead of a field error. Every stored value already fits
             * the enum, and the form's options come from `LostReason::cases()`.
             */
            'lost_reason' => ['required', Rule::enum(LostReason::class)],
            'lost_competitor' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
            'winback_date' => 'nullable|date|after_or_equal:today',
            'winback_notes' => 'nullable|string',
        ]);

        $this->workspaceService->triageLostLead($lead, $validated, $request->user());

        return back()->with('success', "Lost deal triage saved for {$lead->company_name}.");
    }

    /**
     * Schedule win-back task for a lost lead.
     */
    public function scheduleWinBack(Request $request, Lead $lead)
    {
        Gate::authorize('update', $lead);

        $validated = $request->validate([
            'winback_date' => 'required|date|after_or_equal:today',
            'notes' => 'nullable|string',
        ]);

        $this->workspaceService->scheduleWinBack($lead, $validated['winback_date'], $validated['notes'] ?? null, $request->user());

        return back()->with('success', "Win-back follow-up task scheduled for {$lead->company_name}.");
    }
}
