<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Notification extends Model
{
    protected $fillable = [
        'clinic_id',
        'branch_id',
        'recipient_type',
        'recipient_id',
        'channel',
        'priority',
        'status',
        'notification_template_id',
        'metadata',
        'scheduled_at',
        'sent_at',
        'failed_reason',
        'retry_count',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'scheduled_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(NotificationTemplate::class, 'notification_template_id');
    }

    public function recipient()
    {
        return $this->morphTo();
    }
}
