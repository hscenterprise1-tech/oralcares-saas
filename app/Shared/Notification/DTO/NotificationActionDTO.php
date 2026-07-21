<?php

namespace App\Shared\Notification\DTO;

readonly class NotificationActionDTO
{
    public function __construct(
        public string $label,
        public string $url,
        public string $style = 'primary', // primary, secondary, danger, etc.
        public ?string $icon = null
    ) {}
}
