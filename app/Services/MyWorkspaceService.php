<?php

namespace App\Services;

use App\LeadStatus;
use App\Models\Activity;
use App\Models\CompanyQuotation;
use App\Models\Demo;
use App\Models\Lead;
use App\Models\Task;
use App\Models\Trial;
use App\Models\User;
use App\QuotationStatus;
use App\Services\CRM\TrialExtensionApprovalService;
use App\TaskStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The employee's daily operating hub.
 *
 * This composes rather than re-derives: the assigned-lead, follow-up, demo,
 * trial and quotation queues already exist on LeadWorkspaceService and are
 * reused verbatim. What is added here is the day-shaped view a rep actually
 * works from — one prioritised focus list, one unified upcoming timeline, the
 * task queue, and their own recent actions.
 *
 * Everything is bounded. A rep's own book is small, but "small today" is not a
 * guarantee, and an unbounded query is a latent outage.
 */
class MyWorkspaceService
{
    /** Maximum rows returned for any single workspace list. */
    public const LIST_LIMIT = 50;

    /** How far ahead the unified upcoming timeline looks. */
    public const UPCOMING_DAYS = 14;

    public function __construct(
        protected LeadWorkspaceService $leadWorkspace,
        protected TrialExtensionApprovalService $extensionApproval,
        protected QuotationApprovalService $quotationApproval,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function payload(User $user, array $filters = []): array
    {
        $base = $this->leadWorkspace->getMyWorkspacePayload($user, $filters);

        $tasks = $this->myTasks($user);
        $upcoming = $this->upcoming($user);

        /*
         * Unlike every other queue on this page, approvals are not scoped to
         * "leads assigned to me" — they are scoped to "requests I have
         * authority over", which for a manager is their whole team. This is
         * the one deliberate exception to My Work's otherwise strict
         * assigned-to-me-only rule.
         *
         * Two entity types share this one queue — trial extensions and
         * quotation discounts — because both use the same manager-approval
         * gate. Each item carries its own `type` so the tab can route the
         * approve/reject click at the right endpoint without needing two
         * separate lists and two separate empty states.
         */
        $pendingExtensions = array_map(
            fn (array $item) => $item + ['type' => 'trial_extension'],
            $this->extensionApproval->pendingForUser($user),
        );
        $pendingDiscounts = array_map(
            fn (array $item) => $item + ['type' => 'quotation_discount'],
            $this->quotationApproval->pendingForUser($user),
        );
        $pendingApprovals = [...$pendingExtensions, ...$pendingDiscounts];

        return array_merge($base, [
            /*
             * Who is looking. Claiming an unclaimed lead is "give it to me",
             * and the page should not have to ask the server who "me" is to
             * offer a one-click take.
             */
            'currentUserId' => $user->id,
            'todays_focus' => $this->todaysFocus($user, $base, $tasks, $pendingApprovals),
            'tasks' => $tasks,
            'other_tasks' => $this->otherTasks($user),
            'upcoming' => $upcoming,
            'recent_activity' => $this->recentActivity($user),
            'pending_approvals' => [
                'items' => $pendingApprovals,
                'count' => count($pendingApprovals),
            ],
            'can_approve_extensions' => $this->extensionApproval->canApprove($user) || $this->quotationApproval->canApprove($user),
        ]);
    }

    /**
     * Today's Focus — a single ordered queue, most urgent first.
     *
     * The ordering is the priority the brief specifies: overdue follow-ups,
     * today's demos, trials needing action, quotations needing follow-up, newly
     * assigned leads, then tasks due today. Each entry carries the reason it
     * surfaced, so the rep never has to guess why an item is in front of them.
     *
     * @param  array<string, mixed>  $base
     * @param  list<array<string, mixed>>  $tasks
     * @param  list<array<string, mixed>>  $pendingApprovals
     * @return array<string, mixed>
     */
    protected function todaysFocus(User $user, array $base, array $tasks, array $pendingApprovals = []): array
    {
        $workload = $base['workload'] ?? [];
        $items = [];

        foreach ($pendingApprovals as $approval) {
            $items[] = $approval['type'] === 'quotation_discount'
                ? $this->focusItem('pending_approval', 1, 'Discount awaiting approval', $approval['company_name'] ?? 'Quotation', $approval['quotation_number'] ?? '', $approval['lead_id'] ?? null)
                : $this->focusItem('pending_approval', 1, 'Extension request pending', $approval['company_name'] ?? 'Trial', "+{$approval['additional_days']} days requested", $approval['lead_id'] ?? null);
        }

        foreach ($workload['followups']['overdue'] ?? [] as $followup) {
            $items[] = $this->focusItem('overdue_followup', 2, 'Overdue follow-up', $followup['company_name'] ?? 'Lead', $followup['time_ago'] ?? 'Overdue', $followup['lead_id'] ?? $followup['id'] ?? null);
        }

        foreach ($workload['demos'] ?? [] as $demo) {
            if (! empty($demo['is_today'])) {
                $items[] = $this->focusItem('demo_today', 3, 'Demo today', $demo['company_name'] ?? 'Demo', $demo['scheduled_at'] ?? 'Today', $demo['lead_id'] ?? null);
            }
        }

        foreach ($workload['trials'] ?? [] as $trial) {
            if (! empty($trial['is_ending_soon'])) {
                $items[] = $this->focusItem('trial_action', 4, 'Trial ending soon', $trial['company_name'] ?? 'Trial', "{$trial['days_remaining']} days left", $trial['lead_id'] ?? null);
            }
        }

        foreach ($workload['quotations'] ?? [] as $quotation) {
            if (! empty($quotation['is_expiring_soon']) || ($quotation['status'] ?? null) === 'sent') {
                $items[] = $this->focusItem('quotation_followup', 5, 'Quotation awaiting response', $quotation['company_name'] ?? 'Quotation', $quotation['quotation_number'] ?? '', $quotation['lead_id'] ?? null);
            }
        }

        foreach ($this->newlyAssignedLeads($user) as $lead) {
            $items[] = $this->focusItem('new_assignment', 6, 'Newly assigned lead', $lead['company_name'], $lead['assigned_ago'], $lead['id']);
        }

        foreach ($tasks as $task) {
            if (! empty($task['is_due_today'])) {
                $items[] = $this->focusItem(
                    'task_due',
                    7,
                    'Task due today',
                    $task['title'],
                    Str::headline((string) ($task['type'] ?? 'task')),
                    $task['lead_id'] ?? null
                );
            }
        }

        usort($items, fn (array $a, array $b) => $a['rank'] <=> $b['rank']);

        return [
            'items' => array_slice($items, 0, self::LIST_LIMIT),
            'total' => count($items),
            'is_clear' => $items === [],
        ];
    }

    /**
     * Leads assigned to this rep in the last 3 days that have had no stage
     * movement yet — the "someone just handed you this" queue.
     *
     * @return list<array<string, mixed>>
     */
    protected function newlyAssignedLeads(User $user): array
    {
        return Lead::query()
            ->where('assigned_user_id', $user->id)
            ->whereIn('status', [LeadStatus::New, LeadStatus::Assigned])
            ->where('updated_at', '>=', now()->subDays(3))
            ->orderByDesc('updated_at')
            ->limit(self::LIST_LIMIT)
            ->get(['id', 'company_name', 'contact_name', 'updated_at'])
            ->map(fn (Lead $lead) => [
                'id' => $lead->id,
                'company_name' => $lead->company_name,
                'contact_name' => $lead->contact_name,
                'assigned_ago' => $lead->updated_at?->diffForHumans() ?? 'Recently',
            ])
            ->values()
            ->all();
    }

    /**
     * The rep's own task queue.
     *
     * @return list<array<string, mixed>>
     */
    protected function myTasks(User $user): array
    {
        return Task::query()
            ->where('assigned_user_id', $user->id)
            ->where('status', TaskStatus::Pending)
            ->orderByRaw('due_at is null')
            ->orderBy('due_at')
            ->limit(self::LIST_LIMIT)
            ->get()
            ->map(fn (Task $task) => [
                'id' => $task->id,
                'title' => $task->title,
                'description' => $task->description,
                'type' => $task->type?->value ?? 'to_do',
                'priority' => $task->priority?->value ?? 'medium',
                'due_at' => $task->due_at?->toIso8601String(),
                'due_label' => $task->due_at?->format('d M Y') ?? 'No due date',
                'is_overdue' => $task->due_at ? $task->due_at->isBefore(now()->startOfDay()) : false,
                'is_due_today' => $task->due_at?->isToday() ?? false,
                'lead_id' => $task->taskable_type === Lead::class || $task->taskable_type === 'lead'
                    ? (int) $task->taskable_id
                    : null,
            ])
            ->values()
            ->all();
    }

    /**
     * Open tasks in this organization that belong to somebody else.
     *
     * The standalone Tasks page showed an admin every task in the company; My
     * work showed only your own. Folding the two together would have quietly
     * dropped that view, so it comes along — below your own list, and only
     * when there is something in it, because for a rep there never will be.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function otherTasks(User $user): array
    {
        $organizationId = $user->resolveOrganizationId();

        if (! $organizationId && ! $user->isMasterAdmin()) {
            return [];
        }

        return Task::query()
            ->where('status', TaskStatus::Pending)
            ->where(function ($query) use ($user) {
                $query->whereNull('assigned_user_id')
                    ->orWhere('assigned_user_id', '!=', $user->id);
            })
            ->when(
                ! $user->isMasterAdmin(),
                fn ($query) => $query->whereHasMorph(
                    'taskable',
                    [Lead::class],
                    fn ($q) => $q->where('organization_id', $organizationId)
                )
            )
            ->with('assignedUser:id,name')
            ->orderByRaw('due_at is null')
            ->orderBy('due_at')
            ->limit(self::LIST_LIMIT)
            ->get()
            ->map(fn (Task $task) => [
                'id' => $task->id,
                'title' => $task->title,
                'type' => $task->type?->value ?? 'to_do',
                'due_label' => $task->due_at?->format('d M Y') ?? 'No due date',
                'is_overdue' => $task->due_at ? $task->due_at->isBefore(now()->startOfDay()) : false,
                'owner' => $task->assignedUser?->name,
            ])
            ->values()
            ->all();
    }

    /**
     * One merged, date-ordered timeline of everything due next: demos,
     * follow-ups, tasks, trial deadlines and quotation expiries.
     *
     * @return list<array<string, mixed>>
     */
    protected function upcoming(User $user): array
    {
        $horizon = now()->addDays(self::UPCOMING_DAYS);
        $leadIds = Lead::where('assigned_user_id', $user->id)->pluck('id');

        $entries = collect();

        $entries = $entries->merge(
            Demo::query()
                ->whereIn('lead_id', $leadIds)
                ->whereNotNull('scheduled_at')
                ->whereBetween('scheduled_at', [now()->startOfDay(), $horizon])
                ->with('lead:id,company_name')
                ->orderBy('scheduled_at')
                ->limit(self::LIST_LIMIT)
                ->get()
                ->map(fn (Demo $demo) => $this->upcomingEntry(
                    'demo', "Demo — {$demo->lead?->company_name}", $demo->scheduled_at, $demo->lead_id
                ))
        );

        $entries = $entries->merge(
            Lead::query()
                ->where('assigned_user_id', $user->id)
                ->whereNotNull('next_followup_at')
                ->whereBetween('next_followup_at', [now()->startOfDay(), $horizon])
                ->orderBy('next_followup_at')
                ->limit(self::LIST_LIMIT)
                ->get(['id', 'company_name', 'next_followup_at'])
                ->map(fn (Lead $lead) => $this->upcomingEntry(
                    'followup', "Follow-up — {$lead->company_name}", $lead->next_followup_at, $lead->id
                ))
        );

        $entries = $entries->merge(
            Task::query()
                ->where('assigned_user_id', $user->id)
                ->where('status', TaskStatus::Pending)
                ->whereNotNull('due_at')
                ->whereBetween('due_at', [now()->startOfDay(), $horizon])
                ->orderBy('due_at')
                ->limit(self::LIST_LIMIT)
                ->get()
                ->map(fn (Task $task) => $this->upcomingEntry(
                    'task', $task->title, $task->due_at,
                    $task->taskable_type === Lead::class || $task->taskable_type === 'lead' ? (int) $task->taskable_id : null
                ))
        );

        $entries = $entries->merge(
            Trial::query()
                ->whereIn('lead_id', $leadIds)
                ->where('status', 'active')
                ->whereNotNull('end_date')
                ->whereBetween('end_date', [now()->startOfDay(), $horizon])
                ->with('lead:id,company_name')
                ->orderBy('end_date')
                ->limit(self::LIST_LIMIT)
                ->get()
                ->map(fn (Trial $trial) => $this->upcomingEntry(
                    'trial_deadline', "Trial ends — {$trial->lead?->company_name}", $trial->end_date, $trial->lead_id
                ))
        );

        $entries = $entries->merge(
            CompanyQuotation::query()
                ->whereIn('lead_id', $leadIds)
                ->where('status', QuotationStatus::Sent)
                ->whereNotNull('valid_until')
                ->whereBetween('valid_until', [now()->startOfDay(), $horizon])
                ->with('lead:id,company_name')
                ->orderBy('valid_until')
                ->limit(self::LIST_LIMIT)
                ->get()
                ->map(fn (CompanyQuotation $quotation) => $this->upcomingEntry(
                    'quotation_expiry',
                    "Quotation {$quotation->quotation_number} expires",
                    $quotation->valid_until,
                    $quotation->lead_id
                ))
        );

        return $entries
            ->filter(fn (array $entry) => $entry['occurs_at'] !== null)
            ->sortBy('occurs_at')
            ->take(self::LIST_LIMIT)
            ->values()
            ->all();
    }

    /**
     * The rep's own recent CRM actions.
     *
     * @return list<array<string, mixed>>
     */
    protected function recentActivity(User $user, int $limit = 20): array
    {
        return Activity::query()
            ->where('user_id', $user->id)
            ->whereIn('subject_type', ['lead', Lead::class])
            ->latest('created_at')
            ->limit($limit)
            ->get()
            ->map(fn (Activity $activity) => [
                'id' => $activity->id,
                'lead_id' => (int) $activity->subject_id,
                'type' => $activity->type?->value ?? 'activity',
                'description' => $activity->description ?? 'Activity recorded',
                'occurred_at' => $activity->created_at?->toIso8601String(),
                'time' => $activity->created_at?->diffForHumans() ?? 'Just now',
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    protected function focusItem(string $kind, int $rank, string $reason, string $title, string $detail, ?int $leadId): array
    {
        return [
            'kind' => $kind,
            'rank' => $rank,
            'reason' => $reason,
            'title' => $title,
            'detail' => $detail,
            'lead_id' => $leadId,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function upcomingEntry(string $kind, string $title, mixed $occursAt, ?int $leadId): array
    {
        $date = $occursAt instanceof \DateTimeInterface ? Carbon::instance($occursAt) : null;

        return [
            'kind' => $kind,
            'title' => $title,
            'occurs_at' => $date?->toIso8601String(),
            'date_label' => $date?->format('d M Y') ?? '',
            'time_label' => $date?->format('h:i A') ?? '',
            'is_today' => $date?->isToday() ?? false,
            'lead_id' => $leadId,
        ];
    }

    /**
     * Normalise a heterogeneous collection into a plain list.
     *
     * @param  Collection<int, array<string, mixed>>  $collection
     * @return list<array<string, mixed>>
     */
    protected function toList(Collection $collection): array
    {
        return $collection->values()->all();
    }
}
