<?php

namespace App\Shared\Notification\Enums;

enum NotificationStatus: string
{
    case PENDING = 'pending';
    case PROCESSING = 'processing';
    case SENT = 'sent';
    case DELIVERED = 'delivered';
    case FAILED = 'failed';
    case CANCELLED = 'cancelled';
    case RETRYING = 'retrying';

    public function isTerminal(): bool
    {
        return in_array($this, [self::SENT, self::DELIVERED, self::FAILED, self::CANCELLED]);
    }
}
