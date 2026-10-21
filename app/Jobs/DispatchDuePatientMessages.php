<?php

namespace App\Jobs;

use App\Models\Notification;
use App\Services\NotificationService;
use App\Support\ClinicClock;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Sends patient messages that are due: queued with no time, or scheduled at
 * or before now (clinic wall-clock, like appointments).
 *
 * Each message is claimed with a conditional update before it is sent, so
 * two overlapping runs never send the same message; a withdrawn (cancelled)
 * reminder is never picked up. Bounded per run.
 */
class DispatchDuePatientMessages implements ShouldQueue
{
    use Queueable;

    private const BATCH = 500;

    public function handle(NotificationService $notifications): void
    {
        $due = Notification::query()
            ->where('status', 'pending')
            ->where('recipient_type', 'patient')
            ->where(fn ($query) => $query->whereNull('scheduled_at')->orWhere('scheduled_at', '<=', ClinicClock::now()->format('Y-m-d H:i:s')))
            ->orderBy('id')
            ->limit(self::BATCH)
            ->pluck('id');

        foreach ($due as $id) {
            $claimed = Notification::query()->whereKey($id)->where('status', 'pending')->update(['status' => 'processing']);
            if ($claimed === 1) {
                $notifications->sendNotification(Notification::query()->findOrFail($id));
            }
        }
    }
}
