<?php

namespace App\Shared\Notification\Enums;

enum NotificationPriority: string
{
    case LOW = 'low';
    case NORMAL = 'normal';
    case MEDIUM = 'medium';
    case HIGH = 'high';
    case CRITICAL = 'critical';
    case EMERGENCY = 'emergency';

    public function color(): string
    {
        return match ($this) {
            self::LOW => 'bg-gray-100 text-gray-800',
            self::NORMAL => 'bg-blue-100 text-blue-800',
            self::MEDIUM => 'bg-yellow-100 text-yellow-800',
            self::HIGH => 'bg-orange-100 text-orange-800',
            self::CRITICAL, self::EMERGENCY => 'bg-red-100 text-red-800',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::LOW, self::NORMAL => 'info',
            self::MEDIUM => 'exclamation-circle',
            self::HIGH, self::CRITICAL, self::EMERGENCY => 'exclamation-triangle',
        };
    }
}
