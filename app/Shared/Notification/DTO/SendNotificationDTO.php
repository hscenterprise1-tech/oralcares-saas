<?php

namespace App\Shared\Notification\DTO;

use App\Shared\Notification\Enums\NotificationCategory;
use App\Shared\Notification\Enums\NotificationPriority;
use App\Shared\Notification\Enums\NotificationChannel;

readonly class SendNotificationDTO
{
    /**
     * @param array<int, NotificationChannel> $channels
     * @param array<int, NotificationActionDTO> $actions
     */
    public function __construct(
        public string $title,
        public string $message,
        public NotificationCategory $category,
        public NotificationPriority $priority = NotificationPriority::NORMAL,
        public ?int $companyId = null,
        public ?int $clinicId = null,
        public ?int $branchId = null,
        public array $channels = [],
        public array $actions = [],
        public array $metadata = []
    ) {}
}
