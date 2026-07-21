<?php

namespace App\Shared\Notification\Models;

use App\Models\Clinic;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationPreference extends Model
{
    protected $table = 'ent_notification_preferences';

    protected $fillable = [
        'user_id',
        'clinic_id',
        'disabled_channels',
        'disabled_categories',
        'quiet_hours_start',
        'quiet_hours_end',
        'timezone',
    ];

    protected $casts = [
        'disabled_channels' => 'array',
        'disabled_categories' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    public function isChannelDisabled(string $channel): bool
    {
        return in_array($channel, $this->disabled_channels ?? []);
    }

    public function isCategoryDisabled(string $category): bool
    {
        return in_array($category, $this->disabled_categories ?? []);
    }

    public function isInQuietHours(): bool
    {
        if (!$this->quiet_hours_start || !$this->quiet_hours_end) {
            return false;
        }

        // Implementation of quiet hours logic based on user timezone
        // This will be fleshed out in the core engine
        $now = now()->setTimezone($this->timezone);
        $start = \Carbon\Carbon::createFromFormat('H:i:s', $this->quiet_hours_start, $this->timezone);
        $end = \Carbon\Carbon::createFromFormat('H:i:s', $this->quiet_hours_end, $this->timezone);

        if ($start->greaterThan($end)) {
            // e.g. 22:00 to 06:00
            return $now->greaterThanOrEqualTo($start) || $now->lessThanOrEqualTo($end);
        }

        // e.g. 13:00 to 15:00
        return $now->between($start, $end);
    }
}
