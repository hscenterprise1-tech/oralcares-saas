<?php

namespace App\Jobs;

use App\ActivityType;
use App\Models\Activity;
use App\Models\Lead;
use App\Services\LeadWorkspaceService;
use App\Shared\Notification\Enums\NotificationPriority;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;

class EscalateOverdueFollowupsJob implements ShouldQueue
{
    use Queueable;

    public function handle(LeadWorkspaceService $workspace): void
    {
        $cutoff = Carbon::now()->subHours(24);

        Lead::whereNotNull('next_followup_at')
            ->where('next_followup_at', '<=', $cutoff)
            ->chunkById(200, function ($overdueLeads) use ($workspace) {
                foreach ($overdueLeads as $lead) {
                    // Logged as a canonical Activity on the lead's own timeline, which
                    // is what the CRM actually reads.
                    $alreadyLogged = Activity::where('subject_type', $lead->getMorphClass())
                        ->where('subject_id', $lead->id)
                        ->where('description', 'LIKE', '%Follow-up escalated%')
                        ->where('created_at', '>=', Carbon::now()->subHours(24))
                        ->exists();

                    if (! $alreadyLogged) {
                        Activity::create([
                            'subject_type' => $lead->getMorphClass(),
                            'subject_id' => $lead->id,
                            'user_id' => $lead->assigned_user_id,
                            'type' => ActivityType::SystemUpdate,
                            'description' => 'Follow-up escalated — overdue by more than 24 hours.',
                            'properties' => [
                                'escalation_type' => 'overdue_followup_24h',
                                'scheduled_followup_at' => $lead->next_followup_at?->toIso8601String(),
                                'hours_overdue' => (int) Carbon::parse($lead->next_followup_at)->diffInHours(Carbon::now()),
                            ],
                        ]);

                        // Escalate to the owner. Routed through the same
                        // `notifyUser()` every other CRM alert uses (and its
                        // built-in try/catch) rather than calling
                        // NotificationEngineService directly — this used to
                        // be the one call site that bypassed it, with no
                        // failure isolation of its own, so one bad
                        // notification aborted escalation for every other
                        // overdue lead still left in this run.
                        $this->notifyOwner($lead, $workspace);
                    }
                }
            });
    }

    /**
     * Send the escalation to the lead's owner.
     *
     * Silent when the lead is unassigned — CheckUnassignedLeadSlaJob owns that
     * case, and a notification with no recipient is not an escalation.
     */
    protected function notifyOwner(Lead $lead, LeadWorkspaceService $workspace): void
    {
        if (! $lead->assigned_user_id) {
            return;
        }

        $workspace->notifyUser(
            $lead->assigned_user_id,
            'Follow-up overdue',
            "Follow-up for {$lead->company_name} is more than 24 hours overdue.",
            NotificationPriority::HIGH,
            [
                'type' => 'overdue_followup',
                'lead_id' => $lead->id,
                'scheduled_followup_at' => $lead->next_followup_at?->toIso8601String(),
            ],
        );
    }
}
