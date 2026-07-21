<?php

namespace App\Shared\Notification\Listeners;

use App\Shared\Notification\DTO\SendNotificationDTO;
use App\Shared\Notification\Enums\NotificationCategory;
use App\Shared\Notification\Enums\NotificationPriority;
use App\Shared\Notification\Jobs\ProcessNotificationJob;
use Illuminate\Support\Facades\Log;

class CentralNotificationListener
{
    /**
     * Map domain events to a SendNotificationDTO and dispatch them.
     * This acts as the central router for system events -> notifications.
     */
    public function handle(object $event): void
    {
        // We determine the type of event and create the appropriate DTO
        $dto = $this->mapEventToDTO($event);

        if (!$dto) {
            return; // Event not configured for notifications or mapping failed
        }

        // Determine recipients based on event (e.g. clinic admins, specific user)
        $recipients = $this->determineRecipients($event);

        foreach ($recipients as $recipient) {
            ProcessNotificationJob::dispatch($recipient, $dto);
        }
    }

    protected function mapEventToDTO(object $event): ?SendNotificationDTO
    {
        $eventName = class_basename($event);

        return match ($eventName) {
            'PatientRegistered' => new SendNotificationDTO(
                title: 'New Patient Registered',
                message: 'A new patient has been registered in the clinic.',
                category: NotificationCategory::PATIENT,
                priority: NotificationPriority::NORMAL,
                clinicId: $event->clinicId ?? null,
                channels: [] // Empty defaults to checking user preferences
            ),
            'InvoiceCreated' => new SendNotificationDTO(
                title: 'Invoice Created',
                message: 'A new invoice has been generated.',
                category: NotificationCategory::BILLING,
                priority: NotificationPriority::NORMAL,
                clinicId: $event->clinicId ?? null
            ),
            'WorkflowApproved' => new SendNotificationDTO(
                title: 'Workflow Approved',
                message: 'A workflow step requires your attention or has been approved.',
                category: NotificationCategory::WORKFLOW,
                priority: NotificationPriority::HIGH,
                clinicId: $event->clinicId ?? null
            ),
            default => null,
        };
    }

    /**
     * @return \Illuminate\Support\Collection<int, \App\Models\User>|array
     */
    protected function determineRecipients(object $event)
    {
        // Mock implementation. Real implementation would inspect the $event
        // to find specific assigned users, or query the clinic for users with specific roles.
        // E.g. $event->clinic->users()->whereHasRole('admin')->get();
        
        if (isset($event->targetUserId)) {
            return \App\Models\User::where('id', $event->targetUserId)->get();
        }

        return collect(); 
    }
}
