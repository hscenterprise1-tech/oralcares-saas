<?php

namespace App\Shared\Notification\Models;

use App\Models\Branch;
use App\Models\Clinic;
use App\Shared\Notification\Enums\NotificationCategory;
use App\Shared\Notification\Enums\NotificationPriority;
use Database\Factories\Shared\Notification\Models\EnterpriseNotificationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Notifications\DatabaseNotification;

class EnterpriseNotification extends DatabaseNotification
{
    use HasFactory;

    protected $table = 'ent_notifications';

    // Disable incrementing since we use UUIDs
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'type',
        'notifiable_type',
        'notifiable_id',
        'data',
        'read_at',
        'archived_at',
        'clinic_id',
        'branch_id',
        'category',
        'priority',
        'title',
        'actions',
    ];

    protected $casts = [
        'id' => 'string',
        'data' => 'array',
        'read_at' => 'datetime',
        'archived_at' => 'datetime',
        'category' => NotificationCategory::class,
        'priority' => NotificationPriority::class,
        'actions' => 'array',
    ];

    protected static function newFactory()
    {
        return EnterpriseNotificationFactory::new();
    }

    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(NotificationLog::class, 'notification_id', 'id');
    }

    public function markAsArchived(): void
    {
        if (is_null($this->archived_at)) {
            $this->forceFill(['archived_at' => $this->freshTimestamp()])->save();
        }
    }
}
