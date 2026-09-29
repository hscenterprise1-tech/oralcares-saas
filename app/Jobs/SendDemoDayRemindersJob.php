<?php

namespace App\Jobs;

use App\DemoStatus;
use App\Models\Demo;
use App\Services\LeadWorkspaceService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Runs once each morning: sends the client a WhatsApp reminder for every
 * demo scheduled today, and a "today's demos" digest to each presenting rep.
 */
class SendDemoDayRemindersJob implements ShouldQueue
{
    use Queueable;

    public function handle(LeadWorkspaceService $workspaceService): void
    {
        $demos = Demo::query()
            ->whereDate('scheduled_at', today())
            ->whereIn('status', [DemoStatus::Scheduled, DemoStatus::Confirmed])
            ->with(['lead', 'presenter'])
            ->orderBy('scheduled_at')
            ->get();

        foreach ($demos as $demo) {
            $workspaceService->sendClientDemoReminder($demo);
        }

        $demos->filter(fn (Demo $demo) => $demo->presenter !== null)
            ->groupBy('presenter_id')
            ->each(function ($presenterDemos) use ($workspaceService) {
                $workspaceService->sendRepDemoDigest($presenterDemos->first()->presenter, $presenterDemos);
            });
    }
}
