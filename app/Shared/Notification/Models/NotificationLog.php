<?php

namespace App\Shared\Notification\Models;

use App\Shared\Notification\Enums\NotificationChannel;
use App\Shared\Notification\Enums\NotificationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationLog extends Model
{
    protected $table = 'ent_notification_logs';

    protected $fillable = [
        'notification_id',
        'channel',
        'status',
        'recipient_contact',
        'provider_response',
        'error_message',
        'retry_count',
        'sent_at',
    ];

    protected $casts = [
        'channel' => NotificationChannel::class,
        'status' => NotificationStatus::class,
        'sent_at' => 'datetime',
    ];

    public function notification(): BelongsTo
    {
        return $this->belongsTo(EnterpriseNotification::class, 'notification_id', 'id');
    }
}
