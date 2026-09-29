<?php

namespace App\Shared\Notification\Contracts;

use App\Shared\Notification\DTO\SendNotificationDTO;
use App\Shared\Notification\Models\EnterpriseNotification;

interface NotificationChannelInterface
{
    /**
     * Send the given notification through the channel.
     *
     * @return bool True if successful, false otherwise
     */
    public function send(EnterpriseNotification $notification, SendNotificationDTO $dto): bool;

    /**
     * Get the identifier name of the channel.
     */
    public function getName(): string;
}
