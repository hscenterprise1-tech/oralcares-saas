<?php

namespace App\Http\Controllers\Company\Marketing;

use App\Http\Controllers\Controller;
use App\Models\MarketingContact;
use App\Models\MarketingContactSuppressionLog;
use App\Services\Marketing\ContactWorkspaceCounts;
use App\Services\Marketing\MarketingSuppressionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MarketingSuppressionController extends Controller
{
    public function __construct(
        protected MarketingSuppressionService $suppressionService
    ) {}

    /**
     * Suppression Directory & Compliance Safety Summary.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();
        $orgId = $user->resolveOrganizationId();

        $statusFilter = $request->input('status');
        $reasonFilter = $request->input('reason');
        $sourceFilter = $request->input('source');
        $searchTerm = $request->input('search');

        $query = MarketingContact::query()
            ->where('organization_id', $orgId)
            ->with(['crmLeads', 'tags'])
            ->search($searchTerm)
            ->filterStatus($statusFilter)
            ->when($reasonFilter && $reasonFilter !== 'all', fn ($q) => $q->where('suppression_reason', $reasonFilter))
            ->when($sourceFilter && $sourceFilter !== 'all', fn ($q) => $q->where('suppression_source', $sourceFilter));

        $contacts = $query->latest('status_changed_at')
            ->latest('updated_at')
            ->paginate(20)
            ->withQueryString();

        $summary = $this->suppressionService->getComplianceSummary($orgId);

        return Inertia::render('Company/Marketing/Suppression/Index', [
            'contacts' => $contacts,
            'summary' => $summary,
            'workspaceCounts' => ContactWorkspaceCounts::for($orgId),
            'filters' => [
                'status' => $statusFilter ?? 'all',
                'reason' => $reasonFilter ?? 'all',
                'source' => $sourceFilter ?? 'all',
                'search' => $searchTerm ?? '',
            ],
        ]);
    }

    /**
     * Contact Compliance Detail & Chronological Audit Timeline.
     */
    public function show(Request $request, int $contactId): Response
    {
        $user = $request->user();
        $orgId = $user->resolveOrganizationId();

        $contact = MarketingContact::query()
            ->where('organization_id', $orgId)
            ->with(['crmLeads', 'tags'])
            ->findOrFail($contactId);

        $logs = MarketingContactSuppressionLog::query()
            ->where('organization_id', $orgId)
            ->where('contact_id', $contact->id)
            ->with(['changedBy'])
            ->latest()
            ->get();

        return Inertia::render('Company/Marketing/Suppression/Show', [
            'contact' => $contact,
            'logs' => $logs,
        ]);
    }

    /**
     * Manually suppress a contact.
     */
    public function suppress(Request $request, int $contactId): RedirectResponse
    {
        $user = $request->user();
        $orgId = $user->resolveOrganizationId();

        $validated = $request->validate([
            'reason' => 'required|string|in:manual_suppression,invalid_number,whatsapp_blocked,compliance,other',
            'notes' => 'nullable|string|max:1000',
        ]);

        $contact = MarketingContact::where('organization_id', $orgId)->findOrFail($contactId);

        $this->suppressionService->suppressContact(
            contact: $contact,
            reason: $validated['reason'],
            notes: $validated['notes'] ?? null,
            changedBy: $user,
            source: 'user_action'
        );

        return back()->with('success', "Contact {$contact->name} has been suppressed.");
    }

    /**
     * Restore marketing eligibility (unsuppress).
     */
    public function unsuppress(Request $request, int $contactId): RedirectResponse
    {
        $user = $request->user();
        $orgId = $user->resolveOrganizationId();

        $validated = $request->validate([
            'reason' => 'required|string|in:manual_unsuppress,authorized_override',
            'notes' => 'nullable|string|max:1000',
        ]);

        $contact = MarketingContact::where('organization_id', $orgId)->findOrFail($contactId);

        try {
            $this->suppressionService->unsuppressContact(
                contact: $contact,
                reason: $validated['reason'],
                notes: $validated['notes'] ?? null,
                changedBy: $user,
                source: 'user_action'
            );
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['unsuppress' => $e->getMessage()]);
        }

        return back()->with('success', "Marketing eligibility restored for {$contact->name}.");
    }

    /**
     * Mark a contact as Opted Out (Highest Priority Status).
     */
    public function optOut(Request $request, int $contactId): RedirectResponse
    {
        $user = $request->user();
        $orgId = $user->resolveOrganizationId();

        $validated = $request->validate([
            'reason' => 'required|string|in:customer_request,opt_out_form,other',
            'notes' => 'nullable|string|max:1000',
        ]);

        $contact = MarketingContact::where('organization_id', $orgId)->findOrFail($contactId);

        $this->suppressionService->optOutContact(
            contact: $contact,
            reason: $validated['reason'],
            notes: $validated['notes'] ?? null,
            changedBy: $user,
            source: 'user_action'
        );

        return back()->with('success', "Contact {$contact->name} marked as Opted Out.");
    }
}
