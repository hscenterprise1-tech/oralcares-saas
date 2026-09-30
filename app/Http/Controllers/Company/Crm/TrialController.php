<?php

namespace App\Http\Controllers\Company\Crm;

use App\Http\Controllers\Controller;
use App\LostReason;
use App\Models\Lead;
use App\Models\Trial;
use App\Models\TrialExtensionRequest;
use App\Services\CRM\TrialExtensionApprovalService;
use App\Services\CRM\TrialLifecycleService;
use App\Services\CRM\TrialPageService;
use App\Services\CRM\TrialService;
use App\Services\LeadWorkspaceService;
use App\Services\Provisioning\TrialProvisioningService;
use App\TrialExtensionRequestStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use InvalidArgumentException;

class TrialController extends Controller
{
    public function __construct(
        protected LeadWorkspaceService $leadWorkspaceService,
        protected TrialProvisioningService $provisioning,
        protected TrialLifecycleService $lifecycle,
        protected TrialPageService $page,
        protected TrialExtensionApprovalService $extensionApproval,
    ) {}

    /**
     * Display the Trials Command Center workspace.
     */
    public function index(Request $request)
    {
        Gate::authorize('viewAny', Trial::class);

        /*
         * The list is served from the same contract as the detail page rather
         * than from raw models, so the phase a row shows is the derived one and
         * its usage figures are the measured ones.
         */
        return Inertia::render('Company/Crm/TrialsWorkspace', $this->page->listPayload(
            $request->user(),
            $request->only(['phase', 'search']),
        ));
    }

    /**
     * A single trial, as its own page.
     *
     * Replaces the master-detail sheet on the trials board. A trial runs for
     * weeks and gets handed between people, so a shareable URL matters more
     * here than on most records — and the check becomes per-record rather than
     * the board's viewAny.
     */
    public function show(Trial $trial)
    {
        Gate::authorize('view', $trial);

        /*
         * One assembled payload rather than a raw model. The shape is the
         * contract `trial.ts` mirrors, and the `can` flags in it are real Gate
         * checks — so what the page offers and what the server accepts cannot
         * drift apart.
         */
        return Inertia::render('Company/Crm/Trials/Show', $this->page->payload($trial, request()->user()));
    }

    /**
     * Store/Create a new Trial for a Lead.
     */
    public function store(Request $request)
    {
        Gate::authorize('create', Trial::class);

        $validated = $request->validate([
            'lead_id' => 'required|exists:leads,id',
            'company_plan_id' => 'nullable|exists:company_plans,id',
            'plan' => 'nullable|string',
            'status' => 'nullable|string',
            'duration_days' => 'nullable|integer|min:1|max:90',
            'expected_users' => 'nullable|integer',
            'max_doctors' => 'nullable|integer|min:1',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'notes' => 'nullable|string',
            'assigned_user_id' => 'nullable|exists:users,id',
        ]);

        $lead = Lead::findOrFail($validated['lead_id']);

        if (! $request->user()->isMasterAdmin() && $lead->organization_id !== $request->user()->resolveOrganizationId()) {
            abort(403, 'Unauthorized cross-tenant action.');
        }

        $trial = $this->leadWorkspaceService->createTrial($lead, $validated, $request->user());

        return $this->provisionAndRespond($trial);
    }

    /**
     * Create Trial for a specific Lead.
     */
    public function storeForLead(Request $request, Lead $lead)
    {
        Gate::authorize('create', Trial::class);

        if (! $request->user()->isMasterAdmin() && $lead->organization_id !== $request->user()->resolveOrganizationId()) {
            abort(403, 'Unauthorized cross-tenant action.');
        }

        $validated = $request->validate([
            'company_plan_id' => 'nullable|exists:company_plans,id',
            'plan' => 'nullable|string',
            'duration_days' => 'nullable|integer|min:1|max:90',
            'max_doctors' => 'nullable|integer|min:1',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'notes' => 'nullable|string',
            'assigned_user_id' => 'nullable|exists:users,id',
        ]);

        $trial = $this->leadWorkspaceService->createTrial($lead, $validated, $request->user());

        return $this->provisionAndRespond($trial);
    }

    /**
     * Try provisioning again after a failure.
     *
     * Replaces the old manual "Activate" button, which set the status to Active
     * and nothing else — it declared a trial live without there being anything
     * for the customer to log in to. A trial is live when its environment
     * exists, so the only button that makes sense here is the one that builds
     * it.
     */
    public function retry(Request $request, Trial $trial)
    {
        Gate::authorize('provision', $trial);

        $result = $this->provisioning->retry($trial);

        if (! $result['provisioned']) {
            return back()->withErrors(['provisioning' => $result['error']]);
        }

        return back()->with('success', $this->handoverMessage($result));
    }

    /**
     * Send the admin their setup link again.
     *
     * Separate from retry on purpose: a WhatsApp that did not arrive is not a
     * reason to touch an environment that provisioned correctly.
     */
    public function resendHandover(Request $request, Trial $trial)
    {
        Gate::authorize('provision', $trial);

        $result = $this->provisioning->resendHandover($trial);

        if (! $result['delivered']) {
            return back()->withErrors(['handover' => $result['reason']]);
        }

        return back()->with('success', 'Setup link sent to the client on WhatsApp.');
    }

    /**
     * Cancel a trial and revoke its access.
     */
    public function cancel(Request $request, Trial $trial)
    {
        Gate::authorize('provision', $trial);

        $validated = $request->validate([
            'reason' => 'required|string|min:3|max:255',
        ]);

        try {
            $this->lifecycle->cancel($trial, $validated['reason'], $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['cancel' => $e->getMessage()]);
        }

        return back()->with('success', 'Trial cancelled and access revoked.');
    }

    /**
     * Update onboarding checklist for a Trial.
     */
    public function checklist(Request $request, Trial $trial)
    {
        Gate::authorize('update', $trial);

        $validated = $request->validate([
            'checklist' => 'required|array',
        ]);

        $this->leadWorkspaceService->updateTrialChecklist($trial, $validated['checklist'], $request->user());

        return back()->with('success', 'Trial checklist updated.');
    }

    /**
     * Extend an active Trial.
     */
    public function extend(Request $request, Trial $trial)
    {
        Gate::authorize('provision', $trial);

        $validated = $request->validate([
            'additional_days' => 'required|integer|min:1|max:60',
            'extension_reason' => 'required|string|min:3',
        ]);

        try {
            /*
             * Through the lifecycle service, not the workspace one: extending
             * has to move the subscription window as well as the trial row, or
             * the customer is told they have another week and locked out on the
             * original date.
             */
            $this->lifecycle->extend(
                $trial,
                (int) $validated['additional_days'],
                $validated['extension_reason'],
                $request->user(),
            );
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['extension' => $e->getMessage()]);
        }

        return back()->with('success', 'Trial extended and access extended with it.');
    }

    /**
     * Ask for more time — or, held by someone who can already approve it,
     * apply it immediately.
     *
     * Authorized as `requestExtension` rather than `provision`: a rep who
     * cannot touch a customer's live environment directly should still be
     * able to ask somebody who can. `TrialExtensionApprovalService` is what
     * decides whether "ask" becomes "ask and immediately grant" — this action
     * only checks that the requester may ask at all.
     */
    public function requestExtension(Request $request, Trial $trial)
    {
        Gate::authorize('requestExtension', $trial);

        $validated = $request->validate([
            'additional_days' => 'required|integer|min:1|max:60',
            'reason' => 'required|string|min:3|max:1000',
        ]);

        try {
            $extensionRequest = $this->extensionApproval->requestExtension(
                $trial,
                $request->user(),
                (int) $validated['additional_days'],
                $validated['reason'],
            );
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['additional_days' => $e->getMessage()]);
        }

        $message = $extensionRequest->status === TrialExtensionRequestStatus::Approved
            ? 'Trial extended and access extended with it.'
            : 'Extension request sent for approval.';

        return back()->with('success', $message);
    }

    /**
     * Grant a pending extension request.
     */
    public function approveExtension(Request $request, Trial $trial, TrialExtensionRequest $extensionRequest)
    {
        Gate::authorize('approveExtension', $trial);
        abort_unless($extensionRequest->trial_id === $trial->id, 404);

        try {
            $this->extensionApproval->approve($extensionRequest, $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['extension' => $e->getMessage()]);
        }

        return back()->with('success', 'Extension approved and applied.');
    }

    /**
     * Decline a pending extension request. The trial's dates never move.
     */
    public function rejectExtension(Request $request, Trial $trial, TrialExtensionRequest $extensionRequest)
    {
        Gate::authorize('approveExtension', $trial);
        abort_unless($extensionRequest->trial_id === $trial->id, 404);

        $validated = $request->validate([
            'rejection_reason' => 'required|string|min:3|max:1000',
        ]);

        try {
            $this->extensionApproval->reject($extensionRequest, $request->user(), $validated['rejection_reason']);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['extension' => $e->getMessage()]);
        }

        return back()->with('success', 'Extension request rejected.');
    }

    /**
     * Record that the evaluation was lost, and close the deal behind it.
     *
     * Authorized as `provision` rather than `update`, alongside extend and
     * cancel: this ends a customer's evaluation and moves their deal to Lost,
     * which is the same weight of action as stopping their access.
     *
     * The reason is validated against `LostReason` because `leads.lost_reason`
     * casts to it — a value outside the enum was previously accepted at the
     * request layer and threw on the way back out.
     */
    public function markLost(Request $request, Trial $trial, TrialService $trials)
    {
        Gate::authorize('provision', $trial);

        $validated = $request->validate([
            'lost_reason' => ['required', Rule::enum(LostReason::class)],
            'lost_competitor' => 'nullable|string|max:255',
        ]);

        $trials->markLost(
            $trial,
            LostReason::from($validated['lost_reason']),
            $validated['lost_competitor'] ?? null,
            $request->user(),
        );

        return back()->with('success', 'Trial marked lost, and the deal closed with it.');
    }

    /**
     * Refresh this trial's live usage figures from its tenant.
     *
     * The nightly snapshot is the history; this is the "measure it now" a rep
     * presses before a renewal call, when last night's numbers are a day old and
     * the conversation is about what the practice did this morning.
     */
    public function syncUsage(Request $request, Trial $trial, TrialService $trials)
    {
        Gate::authorize('view', $trial);

        $telemetry = $trials->syncTrialUsage($trial);

        return back()->with(
            $telemetry === null ? 'error' : 'success',
            $telemetry === null
                ? 'This trial has no environment yet, so there is nothing to measure.'
                : 'Usage refreshed.',
        );
    }

    /**
     * Complete a Trial with outcome, feedback, and next steps.
     */
    public function complete(Request $request, Trial $trial)
    {
        Gate::authorize('complete', $trial);

        $validated = $request->validate([
            'outcome' => 'required|string',
            'feedback' => 'nullable|string',
            'objections' => 'nullable|string',
            'requested_features' => 'nullable|string',
            'next_action' => 'nullable|string',
            'next_followup_at' => 'nullable|date',
        ]);

        try {
            $this->leadWorkspaceService->completeTrial($trial, $validated, $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['outcome' => $e->getMessage()]);
        }

        return back()->with('success', 'Trial completed.');
    }

    /**
     * Edit a trial's own record.
     *
     * Narrowed deliberately. This used to accept `status` and four usage
     * columns — `usage_metrics`, `usage_percent`, `users_created`,
     * `inactive_days` — none of which anything measured, so a trial could be
     * declared active with no environment behind it and a usage figure was
     * whatever the last person typed.
     *
     * Status is derived from the dates by {@see TrialLifecycleService}. Usage is
     * measured nightly into `trial_usage_snapshots`. The end date moves through
     * the extend action, which carries the customer's access window with it.
     * What is left here is what a human legitimately authors.
     */
    public function update(Request $request, Trial $trial)
    {
        Gate::authorize('update', $trial);

        $validated = $request->validate([
            'notes' => 'nullable|string',
            'next_action' => 'nullable|string',
            'assigned_user_id' => 'nullable|exists:users,id',
        ]);

        $trial->update($validated);

        return back()->with('success', 'Trial updated successfully.');
    }

    /**
     * Destroy / delete a Trial.
     */
    public function destroy(Request $request, Trial $trial)
    {
        Gate::authorize('delete', $trial);

        $trial->delete();

        return back()->with('success', 'Trial deleted successfully.');
    }

    /**
     * Provision the environment a freshly created trial needs, and say what
     * happened.
     *
     * Creating a trial used to write a row and stop, leaving a rep to press
     * "Activate" on an environment that did not exist. Provisioning here is
     * what makes the create action mean something.
     *
     * A provisioning failure is a warning, not a rejected form. The trial row
     * is real and is what the rep retries from: it sits in `preparing` with the
     * reason on the record. Rejecting the create would throw that away and make
     * them re-enter everything to try again.
     */
    protected function provisionAndRespond(Trial $trial)
    {
        $result = $this->provisioning->provision($trial);

        if (! $result['provisioned']) {
            return back()->with('warning', 'Trial created, but the environment could not be set up: '.$result['error']);
        }

        return back()->with('success', $this->handoverMessage($result));
    }

    /**
     * @param  array{handover: ?array<string, mixed>}  $result
     */
    protected function handoverMessage(array $result): string
    {
        $delivered = $result['handover']['delivered'] ?? false;

        return $delivered
            ? 'Trial environment created and the setup link sent to the client.'
            : 'Trial environment created, but the setup link was not delivered. Use Resend to try again.';
    }
}
