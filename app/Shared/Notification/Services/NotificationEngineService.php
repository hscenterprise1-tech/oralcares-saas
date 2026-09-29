<?php

namespace App\Shared\Notification\Services;

use App\Models\User;
use App\Shared\Notification\Contracts\NotificationChannelInterface;
use App\Shared\Notification\DTO\SendNotificationDTO;
use App\Shared\Notification\Enums\NotificationChannel;
use App\Shared\Notification\Enums\NotificationPriority;
use App\Shared\Notification\Models\EnterpriseNotification;
use App\Shared\Notification\Repositories\PreferenceRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class NotificationEngineService
{
    /**
     * @var array<string, NotificationChannelInterface>
     */
    protected array $channels = [];

    public function __construct(
        protected PreferenceRepository $preferenceRepository
    ) {}

    public function registerChannel(NotificationChannelInterface $channel): void
    {
        $this->channels[$channel->getName()] = $channel;
    }

    /**
     * @param  User|Collection<int, User>  $recipients
     */
    public function send($recipients, SendNotificationDTO $dto): void
    {
        $users = $recipients instanceof Collection ? $recipients : collect([$recipients]);

        foreach ($users as $user) {
            $this->sendToUser($user, $dto);
        }
    }

    protected function sendToUser(User $user, SendNotificationDTO $dto): void
    {
        $preferences = $this->preferenceRepository->getForUser($user, $dto->clinicId);

        // Check if the entire category is disabled by the user
        if ($preferences->isCategoryDisabled($dto->category->value)) {
            Log::info('Notification disabled by user preference category', [
                'user_id' => $user->id,
                'category' => $dto->category->value,
            ]);

            return;
        }

        // Determine which channels to send to
        $channelsToSend = empty($dto->channels)
            ? [NotificationChannel::IN_APP]
            : $dto->channels;

        // Persist the Notification to the database (In-App is always created if it's in the channels)
        // Laravel's native Database channel handles the creation, but we will create it manually here
        // to have the exact model and ID ready for the Omni-channel logs.
        $notificationModel = EnterpriseNotification::create([
            'id' => Str::uuid()->toString(),
            'type' => 'App\Shared\Notification\Notifications\EnterpriseNotificationMessage',
            'notifiable_type' => $user->getMorphClass(),
            'notifiable_id' => $user->getKey(),
            'data' => [
                'title' => $dto->title,
                'message' => $dto->message,
                'metadata' => $dto->metadata,
            ],
            'clinic_id' => $dto->clinicId,
            'branch_id' => $dto->branchId,
            'category' => $dto->category,
            'priority' => $dto->priority,
            'title' => $dto->title,
            'actions' => collect($dto->actions)->map(fn ($action) => (array) $action)->toArray(),
        ]);

        foreach ($channelsToSend as $channelEnum) {
            $channelName = $channelEnum->value;

            // Check if user disabled this specific channel
            if ($preferences->isChannelDisabled($channelName)) {
                continue;
            }

            // Check quiet hours (except for IN_APP or CRITICAL priority)
            if ($preferences->isInQuietHours()
                && $channelName !== NotificationChannel::IN_APP->value
                && $dto->priority !== NotificationPriority::CRITICAL
                && $dto->priority !== NotificationPriority::EMERGENCY
            ) {
                // Future enhancement: Queue it for after quiet hours
                Log::info('Notification suppressed by quiet hours', ['user_id' => $user->id, 'channel' => $channelName]);

                continue;
            }

            if (isset($this->channels[$channelName])) {
                $this->channels[$channelName]->send($notificationModel, $dto);
            }
        }
    }
}
