<?php

namespace App\Observers\CRM;

use App\ActivityType;
use App\LeadPriority;
use App\LeadStatus;
use App\Models\Activity;
use App\Models\Lead;
use App\Models\Task;
use App\Models\User;
use App\Services\CRM\DealStageService;
use App\Services\CRM\LeadAssignmentService;
use App\Services\CRM\LeadScoringService;
use App\Services\CRM\NextBestActionService;
use App\Services\Marketing\MarketingAutomationEventService;
use App\Services\Marketing\WhatsAppLeadService;
use App\TaskPriority;
use App\TaskStatus;
use App\TaskType;
use Carbon\Carbon;

class LeadObserver
{
    /**
     * Ephemeral context set by LeadWorkspaceService::assignLead() right
     * before saving, so this observer's single `assigned_user_id` dirty-check
     * can log a precise, reason-aware message (who assigned it and why)
     * instead of a generic one — without needing a dedicated DB column.
     * Always cleared immediately after the save that used it.
     *
     * @var array{actor: ?User, reason: string}|null
     */
    public static ?array $pendingAssignmentContext = null;

    /**
     * Ephemeral context set by LeadWorkspaceService::transitionStage() right
     * before saving, so this observer can write ONE stage-change activity that
     * carries the reason and the acting user.
     *
     * The observer owns the write because it is the only hook a status change
     * cannot bypass — a plain `$lead->update(['status' => ...])` anywhere in
     * the codebase still lands on the timeline. transitionStage used to write
     * its own richer activity as well, which is why every transition produced
     * two rows.
     *
     * Always cleared immediately after the save that used it.
     *
     * @var array{actor: ?User, reason: ?string}|null
     */
    public static ?array $pendingStageContext = null;

    /**
     * Ephemeral flag set by LeadWorkspaceService::createLead(), which composes
     * its own richer "Lead created" activity. Suppresses the generic one this
     * observer would otherwise add, so a new lead gets exactly one birth event.
     */
    public static bool $suppressCreationActivity = false;

    /**
     * Turn off automatic assignment for the duration of a bulk load.
     *
     * A seeder inserting ten thousand leads does not want ten thousand
     * assignment lookups and ten thousand "new lead assigned" notifications,
     * and it usually knows who each lead belongs to anyway. Everything else
     * leaves this alone: the whole point of hooking `created` is that no
     * ordinary path can avoid it.
     */
    public static bool $suppressAutoAssignment = false;

    /**
     * Hold the stage clock still across a save that is not a stage change.
     *
     * A save nested inside the `created` event — automatic assignment is one —
     * compares `status` against the model's default rather than the value just
     * inserted, so it reads dirty however the lead was created.
     * `trackStageDuration` then banked a duration for a stage the lead had never
     * been in and reset `stage_entered_at` to now. Every auto-assigned lead lost
     * the stage clock it was created with, so none of them could ever be
     * reported stale and both the Deals list and the Overview went quiet.
     *
     * Deliberately a flag rather than a `wasRecentlyCreated` check: a lead
     * genuinely can be created and then advanced in the same request, and that
     * second move is a real stage change whose duration must be banked.
     */
    public static bool $suppressStageTracking = false;

    /**
     * Handle the Lead "saving" event.
     */
    public function saving(Lead $lead): void
    {
        $scoringService = app(LeadScoringService::class);
        $nbaService = app(NextBestActionService::class);

        $lead->automated_score = $scoringService->calculateScore($lead);
        $lead->lead_score = $lead->automated_score; // Initially match
        $lead->next_best_action = $nbaService->determineNextAction($lead);

        $this->trackStageDuration($lead);
        $this->trackCloseProbability($lead);
    }

    /**
     * Keep `probability_to_close` in step with the stage.
     *
     * The column existed and was fillable but nothing ever wrote it, so every
     * open deal sat at 0 and the Overview's weighted forecast was structurally
     * ₹0 — ₹53L of pipeline discounted to nothing. It is a derived value, like
     * `stage_durations` above, so it is maintained here in the same write as
     * the status change and has exactly one writer.
     *
     * DealStageService::PROBABILITY is the map; this method never invents a
     * number of its own.
     */
    protected function trackCloseProbability(Lead $lead): void
    {
        /*
         * A probability supplied in this same write always wins: a rep who
         * judges a deal at 40% is making a call the stage default cannot see,
         * and overruling it would make the field unusable.
         */
        if ($lead->isDirty('probability_to_close')) {
            return;
        }

        if (! $lead->exists || $lead->isDirty('status')) {
            $lead->probability_to_close = app(DealStageService::class)->probabilityFor($lead);
        }
    }

    /**
     * Maintain stage velocity fields.
     *
     * On every stage change we bank the time spent in the outgoing stage into
     * `stage_durations` and reset `stage_entered_at`. Running this in `saving`
     * (rather than `updated`) means the values persist in the same write as the
     * status change, so no second query is needed and the two can never drift.
     */
    protected function trackStageDuration(Lead $lead): void
    {
        if (self::$suppressStageTracking) {
            return;
        }

        if (! $lead->exists) {
            $lead->stage_entered_at = $lead->stage_entered_at ?: now();

            return;
        }

        if (! $lead->isDirty('status')) {
            return;
        }

        $previousStatus = $lead->getOriginal('status');

        $previousStage = is_object($previousStatus) && isset($previousStatus->value)
            ? $previousStatus->value
            : (string) $previousStatus;

        $enteredAt = $lead->getOriginal('stage_entered_at');

        if ($previousStage && $enteredAt) {
            $seconds = max(0, Carbon::parse($enteredAt)->diffInSeconds(now()));

            $durations = $lead->getOriginal('stage_durations') ?? [];
            if (is_string($durations)) {
                $durations = json_decode($durations, true) ?: [];
            }

            $durations[$previousStage] = ($durations[$previousStage] ?? 0) + $seconds;
            $lead->stage_durations = $durations;
        }

        $lead->stage_entered_at = now();
    }

    /**
     * Handle the Lead "created" event.
     */
    public function created(Lead $lead): void
    {
        if (! self::$suppressCreationActivity) {
            $this->logActivity($lead, 'Lead created in '.$lead->status->value.' stage');
        }

        /*
         * Every lead gets an owner, here, at creation.
         *
         * Auto-assignment used to live inside
         * `LeadWorkspaceService::createLead()`, so a lead arriving through an
         * import, the marketing hand-off or the API was never assigned at all —
         * and an unowned lead is not a dashboard metric, it is a lead nobody is
         * working. `created` is the one hook no caller can route around.
         *
         * A lead that already names an owner is left alone, so this never
         * overrides a deliberate choice.
         */
        if (! self::$suppressAutoAssignment
            && $lead->assigned_user_id === null
            && $lead->assigned_team_id === null) {
            self::$suppressStageTracking = true;

            try {
                app(LeadAssignmentService::class)->autoAssign($lead);
            } finally {
                self::$suppressStageTracking = false;
            }
        }

        if ($lead->organization_id) {
            app(MarketingAutomationEventService::class)->dispatchTriggerEvent(
                organizationId: $lead->organization_id,
                eventType: 'lead.created',
                eventKey: "lead:{$lead->id}:created",
                payload: ['lead_id' => $lead->id, 'status' => $lead->status->value],
                contact: $lead->marketingContact,
                lead: $lead
            );
        }
    }

    /**
     * Handle the Lead "updated" event.
     */
    public function updated(Lead $lead): void
    {
        if ($lead->isDirty('status')) {
            $oldStatus = $lead->getOriginal('status')?->value ?? 'None';
            $newStatus = $lead->status->value;
            $this->logStageChange($lead, $oldStatus, $newStatus);

            if ($lead->organization_id) {
                app(MarketingAutomationEventService::class)->dispatchTriggerEvent(
                    organizationId: $lead->organization_id,
                    eventType: 'lead.status_changed',
                    eventKey: "lead:{$lead->id}:status_changed:{$newStatus}:".now()->timestamp,
                    payload: ['lead_id' => $lead->id, 'old_status' => $oldStatus, 'new_status' => $newStatus],
                    contact: $lead->marketingContact,
                    lead: $lead
                );
            }

            if ($lead->status === LeadStatus::Won) {
                app(WhatsAppLeadService::class)->handleLeadWon($lead);

                if ($lead->organization_id) {
                    app(MarketingAutomationEventService::class)->dispatchTriggerEvent(
                        organizationId: $lead->organization_id,
                        eventType: 'lead.won',
                        eventKey: "lead:{$lead->id}:won:".now()->timestamp,
                        payload: ['lead_id' => $lead->id],
                        contact: $lead->marketingContact,
                        lead: $lead
                    );
                }
            } elseif ($lead->status === LeadStatus::Lost) {
                if ($lead->organization_id) {
                    app(MarketingAutomationEventService::class)->dispatchTriggerEvent(
                        organizationId: $lead->organization_id,
                        eventType: 'lead.lost',
                        eventKey: "lead:{$lead->id}:lost:".now()->timestamp,
                        payload: ['lead_id' => $lead->id],
                        contact: $lead->marketingContact,
                        lead: $lead
                    );
                }
            }
        }

        if ($lead->isDirty('assigned_user_id')) {
            $newOwner = $lead->assignedUser?->name ?? 'Unassigned';
            $this->logActivity($lead, $this->describeAssignment($newOwner));

            /*
             * Automatic assignment at intake hands the lead over without
             * inventing a follow-up — see `LeadWorkspaceService::assignLead()`.
             */
            if ($lead->assigned_user_id && (self::$pendingAssignmentContext['task'] ?? true)) {
                $this->createAssignmentTask($lead);
            }
        }
    }

    /**
     * Build a human-readable assignment message using whatever context
     * LeadWorkspaceService::assignLead() left behind, falling back to a
     * generic message for any other code path that changes assigned_user_id
     * directly (e.g. a plain lead update).
     */
    protected function describeAssignment(string $newOwner): string
    {
        $context = self::$pendingAssignmentContext;

        if (! $context) {
            return "Lead reassigned to {$newOwner}";
        }

        if ($context['actor'] instanceof User) {
            return "Lead assigned to {$newOwner} by {$context['actor']->name}";
        }

        /*
         * The two fallback reasons are spelled out rather than sharing one
         * message, because they call for different fixes: "no_teams_configured"
         * means the routing rules have never been set up, while
         * "no_rule_matched" means they exist and this lead fell outside all of
         * them. Collapsed into one line, nobody could tell which was happening.
         */
        return match ($context['reason']) {
            'territory_match' => "Lead auto-assigned to {$newOwner} (state/territory match)",
            'least_loaded' => "Lead auto-assigned to {$newOwner} (least_loaded — fewest open deals)",
            'round_robin' => "Lead auto-assigned to {$newOwner} (round-robin)",
            'fallback_owner' => "Lead assigned to {$newOwner} as fallback owner (fallback_owner — no sales rep available to take it)",
            'no_teams_configured' => "Lead assigned to {$newOwner} as fallback owner (no_teams_configured — no sales teams set up)",
            'no_rule_matched' => "Lead assigned to {$newOwner} as fallback owner (no_rule_matched — no territory or round-robin rule claimed it)",
            default => "Lead auto-assigned to {$newOwner}",
        };
    }

    /**
     * Auto-create a follow-up task for the newly assigned rep — fires for
     * every assignment path (auto or manual) since it's driven by the
     * `assigned_user_id` dirty-check above, not by which code triggered it.
     */
    protected function createAssignmentTask(Lead $lead): void
    {
        Task::create([
            'title' => "New lead assigned: {$lead->company_name}",
            'description' => "Follow up with {$lead->contact_name}".($lead->phone ? " ({$lead->phone})" : '').'. Review the lead details and plan next steps.',
            'type' => TaskType::FollowUp,
            'status' => TaskStatus::Pending,
            'priority' => $lead->priority === LeadPriority::High ? TaskPriority::High : TaskPriority::Medium,
            'due_at' => now()->addDay(),
            'assigned_user_id' => $lead->assigned_user_id,
            'creator_id' => auth()->id(),
            'taskable_type' => $lead->getMorphClass(),
            'taskable_id' => $lead->id,
        ]);
    }

    /**
     * The single canonical stage-change activity.
     *
     * Carries the reason and actor when transitionStage supplied them, and
     * falls back to a plain record for any other writer, so the timeline reads
     * the same whichever path moved the deal.
     */
    /** The human name for a stage value, falling back to the value itself. */
    protected static function stageLabel(string $status): string
    {
        return LeadStatus::tryFrom($status)?->label() ?? str_replace('_', ' ', $status);
    }

    protected function logStageChange(Lead $lead, string $oldStatus, string $newStatus): void
    {
        $context = self::$pendingStageContext;
        $reason = $context['reason'] ?? null;
        $actor = $context['actor'] ?? null;

        Activity::create([
            'subject_type' => $lead->getMorphClass(),
            'subject_id' => $lead->getKey(),
            'type' => ActivityType::SystemUpdate,
            /*
             * Stage names as people say them, not as the column stores them.
             *
             * This wrote the raw enum values, so every timeline and activity
             * list in the product read "Stage changed from payment_received to
             * won" — a database value on display, in the one place a person
             * goes to catch up on what happened.
             *
             * The `properties` payload below keeps the machine values, which is
             * what the movement report reads, so nothing that parses this loses
             * anything.
             */
            'description' => 'Moved to '.self::stageLabel($newStatus).' from '.self::stageLabel($oldStatus)
                .($reason ? ". Reason: {$reason}" : ''),
            'properties' => [
                'previous_stage' => $oldStatus,
                'new_stage' => $newStatus,
            ],
            'user_id' => $actor?->id ?? auth()->id(),
        ]);
    }

    protected function logActivity(Lead $lead, string $description): void
    {
        // Avoid recursive loop since creating activity might touch the lead
        Activity::create([
            'subject_type' => $lead->getMorphClass(),
            'subject_id' => $lead->getKey(),
            'type' => ActivityType::SystemUpdate,
            'description' => $description,
            'user_id' => auth()->id(), // null if system
        ]);
    }
}
