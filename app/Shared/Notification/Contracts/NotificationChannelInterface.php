<?php

namespace App\Shared\Notification\Contracts;

use App\Shared\Notification\Models\EnterpriseNotification;
use App\Shared\Notification\DTO\SendNotificationDTO;

interface NotificationChannelInterface
{
    /**
     * Send the given notification through the channel.
     *
     * @param EnterpriseNotification $notification
     * @param SendNotificationDTO $dto
     * @return bool True if successful, false otherwise
     */
    public function send(EnterpriseNotification $notification, SendNotificationDTO $dto): bool;

    /**
     * Get the identifier name of the channel.
     *
     * @return string
     */
    public function getName(): string;
}
