<?php

namespace App\Http\Controllers\Company\Crm;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreLeadRequest;
use App\Http\Requests\UpdateLeadRequest;
use App\LeadStatus;
use App\LostReason;
use App\Models\Lead;
use App\Models\Task;
use App\Models\User;
use App\Services\CRM\DealConversationService;
use App\Services\CRM\DealPageService;
use App\Services\CRM\DealStageService;
use App\Services\CrmEmailService;
use App\Services\LeadWorkspaceService;
use App\Services\MyWorkspaceService;
use App\Support\CrmScope;
use App\TaskPriority;
use App\TaskStatus;
use App\TaskType;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class LeadController extends Controller
{
    public function __construct(
        protected LeadWorkspaceService $workspaceService
    ) {}

    /*
     * There is no index() or pipeline() here any more.
     *
     * Neither was bound to a route: `Route::resource('leads', ...)` excludes
     * index, and `leads/pipeline` and `pipeline` are Route::redirect entries
     * that answer without a controller. Both methods did nothing except
     * redirect to Deals, which those redirect routes already do.
     *
     * `company.crm.leads.index` still exists and still lands on Deals — the
     * redirects further down this class continue to point at it.
     */

    /**
     * Display Personal CRM Execution Workstation.
     */
    public function myWorkspace(Request $request, MyWorkspaceService $myWorkspace)
    {
        Gate::authorize('viewAny', Lead::class);

        $filters = $request->only(['search', 'stage']);

        // Assigned-only scoping is enforced inside the service, for every
        // persona including managers and master admins: this surface is
        // deliberately "my work", not "all work I am allowed to see".
        return Inertia::render('Company/Crm/MyWorkspace', $myWorkspace->payload($request->user(), $filters));
    }

    /**
     * Send WhatsApp Message from inside CRM console.
     */
    public function sendWhatsApp(Request $request, Lead $lead)
    {
        Gate::authorize('update', $lead);

        $validated = $request->validate([
            'message' => 'required|string|max:2000',
            'template_name' => 'nullable|string',
        ]);

        /*
         * The Marketing conversation, not `lead_whatsapp_messages`.
         *
         * This used to write a row in a table the deal page never rendered and
         * report success, so a rep could believe they had messaged a customer
         * who never received anything. It now goes through the same account
         * and the same thread as a reply sent from the inbox — or fails with a
         * reason.
         */
        app(DealConversationService::class)->sendWhatsApp(
            $lead,
            $validated['message'],
            $request->user()
        );

        return back()->with('success', 'WhatsApp message sent.');
    }

    /**
     * Post-Call Disposition Outcome Handler.
     */
    public function dispositionCall(Request $request, Lead $lead)
    {
        Gate::authorize('update', $lead);

        $validated = $request->validate([
            'duration_seconds' => 'nullable|integer|min:0',
            'disposition' => 'required|string|in:connected,no_answer,busy,call_back,interested,not_interested,wrong_number,demo_requested,follow_up_required,demo_scheduled,follow_up',
            'notes' => 'nullable|string|max:1000',
            /*
             * A rep who logs "busy" or "follow-up requested" and walks away
             * without picking a date is the exact failure this cadence exists
             * to close — the call happened and nothing was promised. Both
             * dispositions, and the two legacy aliases that collapse into them
             * (`call_back` → follow-up, `no_answer` → busy), require a date.
             */
            'next_followup_at' => [
                Rule::requiredIf(in_array($request->input('disposition'), [
                    'follow_up_required', 'call_back', 'busy', 'no_answer',
                ], true)),
                'nullable',
                'date',
            ],
            'followup_notes' => 'nullable|string|max:1000',
        ]);

        $this->workspaceService->logCall($lead, $validated, $request->user());

        return back()->with('success', 'Call log and disposition recorded.');
    }

    /**
     * Mark a follow-up action complete.
     */
    public function completeFollowup(Request $request, Lead $lead)
    {
        Gate::authorize('update', $lead);

        $validated = $request->validate([
            'notes' => 'nullable|string|max:1000',
            'next_followup_at' => 'nullable|date',
            'next_followup_notes' => 'nullable|string|max:1000',
        ]);

        $this->workspaceService->completeFollowup($lead, $validated, $request->user());

        return back()->with('success', 'Follow-up completed successfully.');
    }

    /**
     * Transition lead stage via AJAX/Inertia request.
     */
    public function transitionStage(Request $request, Lead $lead)
    {
        Gate::authorize('update', $lead);

        $validated = $request->validate([
            'stage' => ['required', 'string', Rule::enum(LeadStatus::class)],
            /*
             * On a loss the reason is not free text — the service casts it to
             * `LostReason`, so anything outside the enum throws a ValueError
             * and the user gets a 500 instead of a message. Validated here so a
             * bad value is a field error on the form that produced it.
             */
            'reason' => [
                'nullable',
                'string',
                'max:500',
                Rule::when(
                    $request->input('stage') === LeadStatus::Lost->value,
                    ['required', Rule::enum(LostReason::class)]
                ),
            ],
            /*
             * The three facts a loss is actually worth recording.
             *
             * The Mark Lost form has always collected them and this endpoint
             * has always thrown them away, so a deal could be closed with
             * "lost to Practo, try again in March" typed in and none of it
             * survived the request. Loss triage — the page built to answer
             * "who are we losing to and when do we go back" — was reading
             * columns nothing filled.
             */
            'competitor' => 'nullable|string|max:255',
            'winback_scheduled_at' => 'nullable|date',
            'notes' => 'nullable|string|max:2000',

            /*
             * Closing a deal the commercials do not yet justify.
             *
             * It happens — a signed PO the finance system has not caught up
             * with, a payment settled offline. The escape exists because
             * refusing it entirely means somebody edits the database instead,
             * and then nobody knows it happened. It is restricted to managers
             * and it demands a sentence, which is written into the deal's
             * history as the reason the gate was skipped.
             */
            'override' => 'nullable|boolean',
            'override_reason' => ['nullable', 'string', 'min:10', 'max:500'],
        ]);

        $stages = app(DealStageService::class);
        $reason = $validated['reason'] ?? null;

        $override = (bool) ($validated['override'] ?? false);

        if ($override) {
            abort_unless(CrmScope::isManager($request->user()), 403, 'Only a manager can close a deal without settled commercials.');

            if ($validated['stage'] !== LeadStatus::Won->value) {
                throw ValidationException::withMessages([
                    'override' => 'An override only applies to closing a deal as Won.',
                ]);
            }

            /*
             * Validated here rather than with `required_if`, which compares
             * against the raw input and does not see a boolean `true` as `1`.
             * The rule is simple enough to state where the decision is made.
             */
            if (blank($validated['override_reason'] ?? null)) {
                throw ValidationException::withMessages([
                    'override_reason' => 'Say why this deal is being closed without settled commercials.',
                ]);
            }
        }

        /*
         * Is the edge legal at all?
         *
         * `transitionStage()` refuses an illegal one for every caller, which is
         * what makes it an invariant rather than a form rule. It is asked here
         * as well so that a rejection arrives as a field error the form can
         * render, rather than as an exception surfacing through the handler.
         */
        if (($blocked = $stages->blockReason($lead, $validated['stage'])) !== null) {
            throw ValidationException::withMessages(['stage' => $blocked]);
        }

        // Preconditions are answered before the attempt, as a validation
        // error the form can render against the field. transitionStage()
        // still enforces them — this stops the user meeting them as an
        // unhandled InvalidArgumentException rendered in a red banner, which
        // is all the Kanban board could offer.
        if (! $override && ! $stages->requirementMet($lead, $validated['stage'], $reason)) {
            /*
             * `?? null` because a stage may legitimately have no declared
             * requirement. Indexing this unconditionally was a fatal waiting
             * for the first ungated stage to grow a check — which is exactly
             * what Contacted and Demo Scheduled just did.
             */
            $spec = DealStageService::REQUIREMENTS[$validated['stage']] ?? null;
            $field = $validated['stage'] === LeadStatus::Lost->value ? 'reason' : 'stage';

            $label = LeadStatus::from($validated['stage'])->label();

            /*
             * Won gets its own sentence rather than the generic template.
             * "An approved quotation settled in full" is the underlying
             * check, but the person staring at a locked button needs to hear
             * the concrete, actionable fact: nothing has been paid yet.
             */
            $message = $validated['stage'] === LeadStatus::Won->value
                ? 'Cannot mark deal as Won without reconciled 100% advance payment.'
                : ($spec
                    ? "To move this deal to {$label}, {$spec['requirement']}."
                    : "This deal cannot be moved to {$label} yet.");

            throw ValidationException::withMessages([$field => $message]);
        }

        /*
         * The legality of the edge is not checked here. `transitionStage()`
         * refuses an illegal one for every caller, and throws
         * `IllegalStageTransition`, which renders itself as a 422 on this same
         * field — so a regression attempt reads like any other rejection
         * without this method having to know the rules.
         */
        $this->workspaceService->transitionStage(
            $lead,
            $validated['stage'],
            $request->user(),
            $override
                ? "Closed by manager override: {$validated['override_reason']}"
                : $reason
        );

        /*
         * Written after the transition, because the transition is what sets
         * `lost_at` and the status — this only adds the detail around it. Kept
         * to the Lost stage: a competitor name on a deal moving to Negotiation
         * would be a fact about the wrong moment.
         */
        if ($validated['stage'] === LeadStatus::Lost->value) {
            $lead->forceFill(array_filter([
                'lost_competitor' => $validated['competitor'] ?? null,
                'winback_scheduled_at' => $validated['winback_scheduled_at'] ?? null,
                'followup_notes' => $validated['notes'] ?? null,
            ], fn ($value) => $value !== null && $value !== ''))->save();
        }

        return back()->with('success', 'Stage updated to '.LeadStatus::from($validated['stage'])->label().'.');
    }

    /**
     * Put a lost deal back into the pipeline.
     *
     * The stepper cannot do this, and that is the point. A lost deal carries a
     * reason, a competitor and sometimes a win-back date; it has been counted
     * in a loss report. Letting it quietly become Contacted again takes all of
     * that with it and silently changes the shape of a number somebody has
     * already acted on.
     *
     * So it is a separate endpoint that asks why, records the answer on the
     * deal's history, and only then forces the move.
     *
     * Deliberately not available from Won. A won deal is a live customer with a
     * provisioned tenant; there is no "reopening" it, only a new deal.
     */
    public function reopen(Request $request, Lead $lead)
    {
        Gate::authorize('update', $lead);

        $current = $lead->status instanceof LeadStatus ? $lead->status->value : (string) $lead->status;

        if ($current !== LeadStatus::Lost->value) {
            throw ValidationException::withMessages([
                'stage' => 'Only a lost deal can be reopened.',
            ]);
        }

        $validated = $request->validate([
            /*
             * Where it lands. Restricted to the working stages: reopening into
             * Won would launder the commercial gate, and reopening into Lost is
             * a no-op dressed as an action.
             */
            'stage' => [
                'required',
                'string',
                Rule::enum(LeadStatus::class),
                Rule::notIn([LeadStatus::Won->value, LeadStatus::Lost->value]),
            ],
            'justification' => ['required', 'string', 'min:10', 'max:500'],
        ]);

        $this->workspaceService->transitionStage(
            $lead,
            $validated['stage'],
            $request->user(),
            "Reopened from Lost: {$validated['justification']}",
            force: true
        );

        /*
         * The loss is cleared, because it is no longer true. Leaving the reason
         * and the competitor on a live deal is how the Lost view ends up
         * listing deals that are being worked.
         */
        $lead->forceFill([
            'lost_reason' => null,
            'lost_competitor' => null,
            'lost_at' => null,
            'winback_scheduled_at' => null,
        ])->save();

        return back()->with('success', 'Deal reopened at '.LeadStatus::from($validated['stage'])->label().'.');
    }

    /**
     * Log communication against lead.
     */
    public function logCommunication(Request $request, Lead $lead)
    {
        Gate::authorize('update', $lead);

        $validated = $request->validate([
            'type' => 'required|string|in:call,email,whatsapp,meeting,note',
            'summary' => 'required|string|max:1000',
            'sentiment' => 'nullable|string|in:positive,neutral,negative',
        ]);

        $this->workspaceService->logCommunication($lead, $validated, $request->user());

        return back()->with('success', 'Communication log entry recorded.');
    }

    /**
     * Email conversation state for the lead detail page.
     *
     * @return array<string, mixed>
     */
    protected function emailChannelPayload(Lead $lead): array
    {
        $emails = app(CrmEmailService::class);

        return array_merge($emails->sendability($lead), [
            'default_to' => $lead->email,
            'threads' => $emails->history($lead),
        ]);
    }

    /**
     * Everything the Deal Detail needs to move the deal.
     *
     * All twelve statuses, each with whether it is currently possible and what
     * is missing when it is not — so the page can state a requirement inline
     * and offer the action that satisfies it.
     *
     * @return array<string, mixed>
     */
    protected function stageControlPayload(Lead $lead): array
    {
        $stages = app(DealStageService::class);

        return [
            'current' => $lead->status instanceof LeadStatus ? $lead->status->value : (string) $lead->status,
            'current_label' => $lead->status instanceof LeadStatus ? $lead->status->label() : (string) $lead->status,
            'next' => $stages->nextStage($lead),
            'transitions' => $stages->availableTransitions($lead),
            'is_qualified' => $stages->isQualified($lead),
            'is_disqualified' => $stages->isDisqualified($lead),
            'qualified_at' => $lead->qualified_at?->toISOString(),
            'lost_reasons' => collect(LostReason::cases())
                ->map(fn (LostReason $r) => ['value' => $r->value, 'label' => $r->value])
                ->values()
                ->all(),
        ];
    }

    /**
     * The deal page.
     *
     * Everything a rep reads or acts on arrives with the document. Only the
     * two audit tails — the activity history and the attachment list — are
     * deferred.
     *
     * It used to be the other way round. Eight `Inertia::defer()` calls across
     * six groups meant six independent round trips after first paint, so 91% of
     * the page's bytes arrived in six waves, each replacing a fixed-height grey
     * placeholder with content of a different height. The page visibly settled
     * six times before it could be read, and the stage stepper in the header
     * rendered empty until one of those waves landed.
     *
     * The consolidated payload costs a little more before first paint and
     * removes every layout shift after it. The two that remain are genuinely
     * secondary — nobody opens a deal to read its audit log — and they live in
     * a column of their own, so neither can move the workspace.
     */
    public function show(Lead $lead, Request $request)
    {
        Gate::authorize('view', $lead);

        $page = app(DealPageService::class);
        $user = $request->user();

        return Inertia::render('Company/Crm/Leads/Show', array_merge(
            $page->immediate($lead, $user),
            [
                /* The stage stepper, the next action and the task list. */
                'now' => $page->now($lead),
                /* The demo the rep is about to run, and the trial telemetry. */
                'evaluation' => $page->evaluation($lead),
                /* Quotations, payments, and the lifecycle strip. */
                'commercial' => $page->commercial($lead),
                /*
                 * Demo presenters. Needed the moment the schedule-demo modal
                 * opens, which can be the first thing a rep does on this page —
                 * deferring it meant the presenter dropdown was empty for
                 * whoever was quick.
                 */
                'assignable_users' => $page->assignableUsers($user),
                /* How this deal can be reached, for the feed's composer. */
                'outreach' => $page->outreach($lead),
                /*
                 * The WhatsApp thread, sent with the document rather than
                 * deferred — it is the primary surface of the right column
                 * now, not an audit tail, and a chat that pops in half a
                 * second after the page does not read as live.
                 */
                'conversation' => $page->conversation($lead),

                /*
                 * The audit tails.
                 *
                 * Deferred because they are long, paginated and not what the
                 * page is opened for. Both render inside the activity column,
                 * which is sized independently of the workspace, so their
                 * arrival cannot move anything a rep is reading or clicking.
                 */
                'history' => Inertia::defer(fn () => $page->history($lead, 25, max(1, (int) $request->integer('history_page', 1))), 'history'),
                /*
                 * The same group as the history, deliberately. Two group names
                 * means two requests and two moments at which the column
                 * changes size; these render one above the other in the same
                 * column, so they settle together or not at all.
                 */
                'files' => Inertia::defer(fn () => $page->files($lead), 'history'),

                /*
                 * `profile` is not sent. It was declared here and never
                 * destructured or requested by the page — the organisation
                 * profile is its own route (`leads/{lead}/profile`), which is
                 * where `DealPageService::profile()` is actually read.
                 *
                 * `customer_account` is not sent either. It was deferred, it
                 * cost a query, and the page rendered `{customer_account &&
                 * null}` — fetched on every view and discarded every time.
                 * `commercial.conversion` already carries the converted flag
                 * and the organisation id the page needs.
                 *
                 * `conversation` is not sent. The WhatsApp thread, the email
                 * list and the call log were three renderings of things the
                 * activity feed already holds in one chronological stream.
                 */
            ]
        ));
    }

    /**
     * The profile editor — everything the create form captured, on one page.
     *
     * A dedicated route rather than six inline editors, so reference data is
     * edited completely and once, and the page never has to police which of
     * six open drafts is the real one.
     */
    public function profile(Lead $lead, Request $request)
    {
        Gate::authorize('view', $lead);

        $page = app(DealPageService::class);
        $user = $request->user();

        return Inertia::render('Company/Crm/Leads/Profile', array_merge(
            [
                'deal' => $page->immediate($lead, $user)['deal'],
                'can' => $page->permissions($lead, $user),
                'profile' => $page->profile($lead),
            ],
            $page->profileOptions($user),
        ));
    }

    public function export(Request $request)
    {
        Gate::authorize('viewAny', Lead::class);

        $filters = $request->only([
            'search',
            'stage',
            'source',
            'assigned_user_id',
            'priority',
            'organization_type',
        ]);

        return $this->workspaceService->exportLeadsCsv($filters, $request->user());
    }

    public function create()
    {
        Gate::authorize('create', Lead::class);

        return Inertia::render('Company/Crm/Leads/Create', $this->workspaceService->getLeadCreatePayload(request()->user()));
    }

    public function store(StoreLeadRequest $request)
    {
        Gate::authorize('create', Lead::class);

        $validated = $request->validated();

        $result = $this->workspaceService->createLead($validated, $request->user());

        /*
         * Straight to `deals.index`, not the `leads.index` alias.
         *
         * That alias is itself now just `Route::redirect('leads', '/company/crm/deals')`
         * for old bookmarks — a second hop with nothing at the other end but
         * another redirect. Flash data survives exactly one request-response
         * cycle; routing through the alias spent that cycle on the
         * intermediate hop, so the toast this flashes was always empty by
         * the time a rep's browser actually landed on the pipeline.
         */
        if ($result['duplicate_detected']) {
            return redirect()->route('company.crm.deals.index')
                ->with('success', 'Lead ingested successfully.')
                ->with('duplicate_warning', [
                    'lead_id' => $result['duplicate_lead_id'],
                    'company_name' => $result['duplicate_company_name'],
                    'message' => "This may duplicate existing lead \"{$result['duplicate_company_name']}\" (#{$result['duplicate_lead_id']}).",
                ]);
        }

        return redirect()->route('company.crm.deals.index')
            ->with('success', 'Lead ingested successfully.');
    }

    public function update(UpdateLeadRequest $request, Lead $lead)
    {
        Gate::authorize('update', $lead);

        $validated = $request->validated();

        $this->workspaceService->updateLead($lead, $validated, $request->user());

        return back()->with('success', 'Lead updated successfully.');
    }

    public function destroy(Lead $lead)
    {
        /*
         * `LeadPolicy::delete` was written, registered and never called.
         *
         * The route carried no `can:` middleware and this method authorized
         * nothing, so any authenticated company user could delete any deal by
         * id — another tenant's included. The policy already says the right
         * thing: only a master admin may delete. This wires it up rather than
         * writing a second rule beside it.
         */
        Gate::authorize('delete', $lead);

        $lead->delete();

        return redirect()->route('company.crm.deals.index')->with('success', 'Lead deleted successfully.');
    }

    /**
     * Convert a lead to a customer through the commercial gate.
     *
     * This is the contract-backed conversion path: it requires an approved
     * quotation settled in full before the lead may be won. Deals closed
     * under the old flow — an accepted agreement plus a settled payment —
     * still qualify, so historical customers are not stranded. It does NOT
     * provision directly — it delegates to the canonical stage transition so
     * that task cancellation, marketing suppression, activity logging and
     * provisioning all run exactly once, in one place.
     *
     * Same gate as `DealStageService`'s Won transition — this endpoint must
     * never refuse a conversion the deal page already told the rep was ready.
     */
    public function convert(Request $request, Lead $lead)
    {
        Gate::authorize('update', $lead);

        if (! $lead->hasApprovedPaidQuotation() && ! ($lead->hasAcceptedAgreement() && $lead->hasSuccessfulPayment())) {
            return back()->with('error', 'Lead cannot be converted without an approved quotation settled in full.');
        }

        try {
            $this->workspaceService->transitionStage(
                $lead,
                LeadStatus::Won->value,
                $request->user(),
                'Converted via an approved quotation settled in full.'
            );

            $freshLead = $lead->fresh();
            if ($freshLead->customer_organization_id) {
                return redirect()->route('company.customer-success.accounts.show', $freshLead->customer_organization_id)
                    ->with('success', 'Lead successfully provisioned to an Organization!');
            }

            return redirect()->route('company.customer-success.accounts.index')
                ->with('success', 'Lead successfully provisioned to an Organization!');
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function assign(Request $request, Lead $lead)
    {
        Gate::authorize('update', $lead);

        $validated = $request->validate([
            'assigned_user_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        $targetUser = User::findOrFail($validated['assigned_user_id']);

        if ($lead->organization_id && $targetUser->resolveOrganizationId() !== (int) $lead->organization_id) {
            throw ValidationException::withMessages([
                'assigned_user_id' => 'That person is not on your team.',
            ]);
        }

        $this->workspaceService->assignLead($lead, $targetUser, $request->user());

        return back()->with('success', "Lead assigned to {$targetUser->name}.");
    }

    /**
     * Reassign several deals at once.
     *
     * Reassigning other people's work is a management action, and the previous
     * implementation checked neither that nor tenancy: it ran
     * `Lead::whereIn('id', $ids)` with no scope, so any id in the platform
     * could be moved to any user in the platform.
     *
     * Both sides are now checked against CrmScope, and the batch is rejected
     * whole rather than partially applied — a bulk action that silently skips
     * half its input is worse than one that refuses.
     */
    public function batchReassign(Request $request)
    {
        abort_unless(CrmScope::isManager($request->user()), 403);
        Gate::authorize('create', Lead::class);

        $validated = $request->validate([
            'lead_ids' => ['required', 'array', 'min:1'],
            'lead_ids.*' => ['integer', 'exists:leads,id'],
            'assigned_user_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        $visibleIds = CrmScope::leads($request->user())
            ->whereIn('id', $validated['lead_ids'])
            ->pluck('id')
            ->all();

        $unreachable = array_diff($validated['lead_ids'], $visibleIds);

        if ($unreachable !== []) {
            throw ValidationException::withMessages([
                'lead_ids' => 'Some of those deals are not yours to reassign.',
            ]);
        }

        $targetIds = CrmScope::visibleUserIds($request->user());

        if ($targetIds !== null && ! in_array((int) $validated['assigned_user_id'], $targetIds, true)) {
            throw ValidationException::withMessages([
                'assigned_user_id' => 'That person is not on your team.',
            ]);
        }

        $count = $this->workspaceService->batchReassign(
            $validated['lead_ids'],
            (int) $validated['assigned_user_id'],
            $request->user()
        );

        return back()->with('success', "Reassigned {$count} deal(s).");
    }

    /**
     * Move several deals to one stage.
     *
     * Requirements are enforced per deal, not waived because the action is
     * bulk: a deal with no quotation cannot reach `quotation_sent` from here
     * any more than it could from its own page. Deals that cannot make the
     * move are skipped and counted, so the reply says what actually happened
     * rather than implying every selected row moved.
     */
    public function batchStage(Request $request, DealStageService $stages)
    {
        abort_unless(CrmScope::isManager($request->user()), 403);
        Gate::authorize('create', Lead::class);

        $validated = $request->validate([
            'lead_ids' => ['required', 'array', 'min:1'],
            'lead_ids.*' => ['integer', 'exists:leads,id'],
            'status' => ['required', 'string', Rule::in(array_column(LeadStatus::cases(), 'value'))],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $leads = $this->visibleLeadsOrFail($request, $validated['lead_ids']);

        $moved = 0;
        $skipped = 0;

        foreach ($leads as $lead) {
            /*
             * An illegal edge is skipped, not attempted. Twenty deals selected
             * with one won among them should move nineteen and say so, rather
             * than throwing on the one and abandoning the rest.
             */
            if (! $stages->transitionAllowed($lead, $validated['status'])
                || ! $stages->requirementMet($lead, $validated['status'], $validated['reason'] ?? null)) {
                $skipped++;

                continue;
            }

            $this->workspaceService->transitionStage(
                $lead,
                $validated['status'],
                $request->user(),
                $validated['reason'] ?? 'Moved in bulk from the deals list.'
            );
            $moved++;
        }

        $message = "Moved {$moved} deal(s).";

        if ($skipped > 0) {
            $message .= " {$skipped} could not move — they are missing what that stage requires.";
        }

        return back()->with($skipped > 0 ? 'warning' : 'success', $message);
    }

    /**
     * Put the same task on several deals.
     *
     * The common case is a campaign of follow-ups after a pipeline review, so
     * one title and one due date across the selection is the whole feature.
     */
    public function batchTask(Request $request)
    {
        abort_unless(CrmScope::isManager($request->user()), 403);
        Gate::authorize('create', Lead::class);

        $validated = $request->validate([
            'lead_ids' => ['required', 'array', 'min:1'],
            'lead_ids.*' => ['integer', 'exists:leads,id'],
            'title' => ['required', 'string', 'max:255'],
            'due_at' => ['nullable', 'date'],
            'assigned_user_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $leads = $this->visibleLeadsOrFail($request, $validated['lead_ids']);

        foreach ($leads as $lead) {
            Task::create([
                'title' => $validated['title'],
                'type' => TaskType::FollowUp,
                'status' => TaskStatus::Pending,
                'priority' => TaskPriority::Medium,
                'due_at' => $validated['due_at'] ?? null,
                'assigned_user_id' => $validated['assigned_user_id'] ?? $lead->assigned_user_id,
                'creator_id' => $request->user()?->id,
                'taskable_type' => Lead::class,
                'taskable_id' => $lead->id,
            ]);
        }

        return back()->with('success', 'Task added to '.$leads->count().' deal(s).');
    }

    /**
     * The deals in this selection the caller may actually act on.
     *
     * Refuses the whole batch rather than silently acting on the reachable
     * subset — a bulk action that half-works is worse than one that declines.
     *
     * @param  list<int>  $ids
     * @return Collection<int, Lead>
     */
    protected function visibleLeadsOrFail(Request $request, array $ids)
    {
        $leads = CrmScope::leads($request->user())->whereIn('id', $ids)->get();

        if ($leads->count() !== count(array_unique($ids))) {
            throw ValidationException::withMessages([
                'lead_ids' => 'Some of those deals are not yours to change.',
            ]);
        }

        return $leads;
    }
}
