<?php

namespace App\Shared\Notification\Enums;

enum NotificationChannel: string
{
    case IN_APP = 'in_app';
    case EMAIL = 'email';
    case SMS = 'sms';
    case WHATSAPP = 'whatsapp';
    case PUSH_MOBILE = 'push_mobile';
    case PUSH_WEB = 'push_web';
    case SLACK = 'slack';
    case WEBHOOK = 'webhook';

    public function label(): string
    {
        return match ($this) {
            self::IN_APP => 'In-App',
            self::EMAIL => 'Email',
            self::SMS => 'SMS',
            self::WHATSAPP => 'WhatsApp',
            self::PUSH_MOBILE => 'Mobile Push',
            self::PUSH_WEB => 'Web Push',
            self::SLACK => 'Slack',
            self::WEBHOOK => 'Webhook',
        };
    }
}
