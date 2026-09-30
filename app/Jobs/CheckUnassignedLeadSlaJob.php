<?php

namespace App\Jobs;

use App\ActivityType;
use App\LeadPriority;
use App\Models\Activity;
use App\Models\Lead;
use App\Models\User;
use App\Shared\Notification\DTO\SendNotificationDTO;
use App\Shared\Notification\Enums\NotificationCategory;
use App\Shared\Notification\Enums\NotificationPriority;
use App\Shared\Notification\Services\NotificationEngineService;
use App\Support\CrmScope;
use App\UserType;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;

class CheckUnassignedLeadSlaJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        $cutoff = Carbon::now()->subHours(2);

        Lead::whereNull('assigned_user_id')
            ->whereIn('status', ['new', 'lead'])
            ->where('created_at', '<=', $cutoff)
            ->chunkById(200, function ($unassignedLeads) {
                foreach ($unassignedLeads as $lead) {
                    // Elevate priority to HIGH due to SLA breach.
                    //
                    // `LeadPriority::Urgent` does not exist — the enum is High/Medium/
                    // Low — so this line threw an Error on every hourly run and the
                    // whole job never completed.
                    if ($lead->priority !== LeadPriority::High) {
                        $lead->priority = LeadPriority::High;
                        $lead->save();
                    }

                    // Logged as a canonical Activity on the lead's own timeline, the
                    // same place the overdue-follow-up job writes.
                    $alreadyLogged = Activity::where('subject_type', $lead->getMorphClass())
                        ->where('subject_id', $lead->id)
                        ->where('description', 'LIKE', '%unassigned for more than%')
                        ->exists();

                    if (! $alreadyLogged) {
                        Activity::create([
                            'subject_type' => $lead->getMorphClass(),
                            'subject_id' => $lead->id,
                            'user_id' => null,
                            'type' => ActivityType::SystemUpdate,
                            'description' => 'SLA breach — unassigned for more than 2 hours. Priority elevated to High.',
                            'properties' => [
                                'breach_type' => 'unassigned_sla',
                                'unassigned_duration_hours' => (int) Carbon::parse($lead->created_at)->diffInHours(Carbon::now()),
                            ],
                        ]);

                        // Raise it with the people who can act on it — a breach that is
                        // only recorded is a breach nobody answers.
                        $this->notifyManagers($lead);
                    }
                }
            });
    }

    /**
     * Alert the tenant's CRM managers that a lead has gone unowned.
     *
     * Managers rather than a single owner, because the defining fact of this
     * breach is that nobody owns it. Scoped to the lead's own tenant so an
     * escalation never crosses an organization boundary.
     */
    protected function notifyManagers(Lead $lead): void
    {
        if (! $lead->organization_id) {
            return;
        }

        $managers = User::query()
            ->where('organization_id', $lead->organization_id)
            ->where('user_type', '!=', UserType::ClinicUser)
            ->get()
            ->filter(fn (User $user) => CrmScope::isManager($user));

        $engine = app(NotificationEngineService::class);

        foreach ($managers as $manager) {
            $engine->send($manager, new SendNotificationDTO(
                title: 'Unassigned lead breached SLA',
                message: "{$lead->company_name} has been unassigned for more than 2 hours.",
                category: NotificationCategory::CRM,
                priority: NotificationPriority::HIGH,
                metadata: [
                    'type' => 'unassigned_lead_sla',
                    'lead_id' => $lead->id,
                ],
            ));
        }
    }
}
