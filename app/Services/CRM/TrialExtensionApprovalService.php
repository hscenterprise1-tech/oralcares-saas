<?php

namespace App\Services\CRM;

use App\Models\Trial;
use App\Models\TrialExtensionRequest;
use App\Models\User;
use App\Services\LeadWorkspaceService;
use App\Services\QuotationApprovalService;
use App\Shared\Notification\DTO\NotificationActionDTO;
use App\Shared\Notification\Enums\NotificationCategory;
use App\Shared\Notification\Enums\NotificationPriority;
use App\TrialExtensionRequestStatus;
use App\UserType;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * Who may push a trial's end date out, and who merely gets to ask.
 *
 * Mirrors `QuotationApprovalService`'s shape — a `canApprove()` gate check
 * plus the actions the gate protects — but where quotation approval is a
 * status and two timestamps on the quotation itself, an extension is
 * consequential enough on someone else's live access that it earns its own
 * request row every time, including the ones a manager applies to themself.
 * `requestExtension()` never leaves that row silently absent: a manager's own
 * ask is approved in the same transaction it is created in, not fast-pathed
 * around the table.
 *
 * The actual date move is never this class's job. Every approval — instant
 * or delayed — ends by calling `TrialLifecycleService::extend()`, which is
 * the one place that knows the trial record and the tenant's subscription
 * window have to move together.
 */
class TrialExtensionApprovalService
{
    public function __construct(
        protected TrialLifecycleService $lifecycle,
        protected LeadWorkspaceService $workspace,
    ) {}

    /**
     * Whether this user may decide a pending request, or have their own
     * request applied immediately rather than queued.
     */
    public function canApprove(?User $user): bool
    {
        return Gate::forUser($user)->allows('crm.trials.approve-extension');
    }

    /**
     * Everyone in this organization who can act on a pending request.
     *
     * Mirrors {@see QuotationApprovalService::approversFor()} —
     * same reasoning: scoped to one tenant, filtered candidate-by-candidate
     * through the real gate rather than a Spatie role-pivot query, which does
     * not reliably scope outside a single user's own request context.
     *
     * @return Collection<int, User>
     */
    public function approversFor(int $organizationId): Collection
    {
        return User::query()
            ->where('organization_id', $organizationId)
            ->whereIn('user_type', [UserType::CompanyUser->value, UserType::MasterAdmin->value])
            ->get()
            ->filter(fn (User $candidate) => $this->canApprove($candidate))
            ->values();
    }

    /**
     * Ask for more time.
     *
     * A requester who already holds approval authority does not wait on
     * themselves: the request is created and approved — applying the
     * extension — inside the same transaction. Everyone else gets a pending
     * row and the trial's dates do not move until someone else acts on it.
     */
    public function requestExtension(Trial $trial, User $requestedBy, int $additionalDays, string $reason): TrialExtensionRequest
    {
        /*
         * A self-approving request is caught downstream — `approve()` calls
         * `TrialLifecycleService::extend()`, which already refuses a terminal
         * trial, and the whole transaction rolls back with it. Checked here
         * too so a request that will never be actioned isn't left queued for
         * an approver on a trial that already ended.
         */
        if (in_array($trial->status, TrialLifecycleService::TERMINAL, true)) {
            throw new InvalidArgumentException("This trial is already {$trial->status->label()} and cannot be extended.");
        }

        $selfApproving = $this->canApprove($requestedBy);

        $request = DB::transaction(function () use ($trial, $requestedBy, $additionalDays, $reason, $selfApproving) {
            $request = TrialExtensionRequest::create([
                'trial_id' => $trial->id,
                'requested_by' => $requestedBy->id,
                'additional_days' => $additionalDays,
                'reason' => $reason,
                'status' => TrialExtensionRequestStatus::Pending,
            ]);

            return $selfApproving ? $this->approve($request, $requestedBy) : $request;
        });

        // Nobody to tell when the requester approved their own ask —
        // outside the transaction, since a notification failure must never
        // undo a request that has already committed.
        if (! $selfApproving) {
            $this->notifyExtensionApprovalRequested($request, $trial, $requestedBy);
        }

        return $request;
    }

    /**
     * Grant a pending request: applies the extension through
     * `TrialLifecycleService::extend()` and records who approved it.
     */
    public function approve(TrialExtensionRequest $request, User $approver): TrialExtensionRequest
    {
        if ($request->status !== TrialExtensionRequestStatus::Pending) {
            throw new InvalidArgumentException('Only a pending extension request can be approved.');
        }

        $requestedById = $request->requested_by;

        $approved = DB::transaction(function () use ($request, $approver) {
            $this->lifecycle->extend($request->trial, $request->additional_days, $request->reason, $approver);

            $request->forceFill([
                'status' => TrialExtensionRequestStatus::Approved,
                'approved_by' => $approver->id,
            ])->save();

            return $request->fresh();
        });

        /*
         * Skipped for a self-approval — nobody needs telling they approved
         * their own request — and always outside the transaction above, so a
         * notification failure can never undo an extension that already
         * moved the tenant's access window.
         */
        if ($approver->id !== $requestedById) {
            $this->notifyExtensionApprovalDecision($approved, 'approved', $approver);
        }

        return $approved;
    }

    /**
     * The requests sitting on this user's desk right now.
     *
     * Empty for anyone without approval authority — this is not "requests I
     * raised", it is the queue My Work's Pending Approvals surface reads, and
     * that surface only exists for the people who can act on it. Scoped to
     * the approver's own tenant so a master admin approving across every
     * organisation still only sees one company's asks at a time.
     *
     * @return array<int, array<string, mixed>>
     */
    public function pendingForUser(User $user): array
    {
        if (! $this->canApprove($user)) {
            return [];
        }

        $orgId = $user->resolveOrganizationId();
        $isMasterAdmin = $user->isMasterAdmin();

        return TrialExtensionRequest::query()
            ->where('status', TrialExtensionRequestStatus::Pending)
            ->whereHas('trial.lead', fn ($query) => $query->when(
                $orgId && ! $isMasterAdmin,
                fn ($inner) => $inner->where('organization_id', $orgId)
            ))
            ->with(['trial.lead:id,company_name,contact_name,city', 'requestedBy:id,name'])
            ->latest('created_at')
            ->limit(50)
            ->get()
            ->map(fn (TrialExtensionRequest $request) => [
                'id' => $request->id,
                'trial_id' => $request->trial_id,
                'lead_id' => $request->trial->lead_id,
                'company_name' => $request->trial->lead?->company_name ?? 'Trial Clinic',
                'contact_name' => $request->trial->lead?->contact_name ?? '',
                'city' => $request->trial->lead?->city,
                'requested_by' => $request->requestedBy?->name ?? 'Unknown',
                'additional_days' => $request->additional_days,
                'reason' => $request->reason,
                'time_ago' => $request->created_at->diffForHumans(),
            ])
            ->values()
            ->all();
    }

    /**
     * Decline a pending request. The trial's dates never move.
     */
    public function reject(TrialExtensionRequest $request, User $approver, string $rejectionReason): TrialExtensionRequest
    {
        if ($request->status !== TrialExtensionRequestStatus::Pending) {
            throw new InvalidArgumentException('Only a pending extension request can be rejected.');
        }

        $request->forceFill([
            'status' => TrialExtensionRequestStatus::Rejected,
            'approved_by' => $approver->id,
            'rejection_reason' => $rejectionReason,
        ])->save();

        $rejected = $request->fresh();

        $this->notifyExtensionApprovalDecision($rejected, 'rejected', $approver);

        return $rejected;
    }

    /**
     * Tell every manager who can act on it that a trial extension needs
     * deciding.
     */
    protected function notifyExtensionApprovalRequested(TrialExtensionRequest $request, Trial $trial, User $requester): void
    {
        $organizationId = $trial->lead?->organization_id;

        if (! $organizationId) {
            return;
        }

        $company = $trial->lead?->company_name ?? 'a customer';

        foreach ($this->approversFor($organizationId) as $approver) {
            $this->workspace->notifyUser(
                $approver->id,
                'Trial extension needs approval',
                "{$requester->name} requested {$request->additional_days} more day(s) for {$company}'s trial.",
                NotificationPriority::HIGH,
                [
                    'type' => 'trial_extension_approval_requested',
                    'trial_id' => $trial->id,
                    'extension_request_id' => $request->id,
                    'requested_by' => $requester->id,
                    'additional_days' => $request->additional_days,
                ],
                NotificationCategory::APPROVAL,
                [new NotificationActionDTO('Review request', route('company.crm.trials.show', $trial))],
            );
        }
    }

    /**
     * Tell whoever asked for the extension what the manager decided.
     */
    protected function notifyExtensionApprovalDecision(TrialExtensionRequest $request, string $decision, User $actor): void
    {
        $trial = $request->trial;
        $company = $trial?->lead?->company_name ?? 'a customer';

        $this->workspace->notifyUser(
            $request->requested_by,
            $decision === 'approved' ? 'Trial extension approved' : 'Trial extension rejected',
            "{$actor->name} {$decision} the {$request->additional_days}-day extension for {$company}'s trial.",
            NotificationPriority::HIGH,
            [
                'type' => 'trial_extension_approval_decided',
                'trial_id' => $request->trial_id,
                'extension_request_id' => $request->id,
                'decision' => $decision,
                'decided_by' => $actor->id,
            ],
            NotificationCategory::APPROVAL,
            $trial ? [new NotificationActionDTO('View trial', route('company.crm.trials.show', $trial))] : [],
        );
    }
}
