<?php

namespace App\Jobs;

use App\Models\Trial;
use App\Models\TrialUsageSnapshot;
use App\Services\CRM\TrialLifecycleService;
use App\Services\Provisioning\TenantTelemetryService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Measure every live trial, once a night.
 *
 * Nightly rather than continuous because the questions these numbers answer are
 * daily ones — has this customer started using it, have they gone quiet, are
 * they near a limit — and because each pass runs a dozen aggregate queries
 * against a live clinic database. There is no reason to do that hourly.
 *
 * Idempotent per day: a second run replaces that day's snapshot rather than
 * writing a second one, so a retry after a partial failure is safe and the
 * history stays one row per trial per day.
 *
 * Only trials that still grant access are measured. A trial that lapsed a month
 * ago is not generating usage, and re-counting a cancelled tenant's tables every
 * night is a privacy cost with no reader.
 */
class TrialUsageSnapshotJob implements ShouldQueue
{
    use Queueable;

    public function handle(TenantTelemetryService $telemetry, TrialLifecycleService $lifecycle): void
    {
        $now = Carbon::now();
        $captured = 0;
        $skipped = 0;

        Trial::with(['clinic.organization', 'lead'])
            ->whereNotNull('clinic_id')
            ->whereNotNull('provisioned_at')
            ->each(function (Trial $trial) use ($telemetry, $lifecycle, $now, &$captured, &$skipped) {
                if (! $lifecycle->resolveStatus($trial, $now)->grantsAccess()) {
                    $skipped++;

                    return;
                }

                $clinic = $trial->clinic;

                if (! $clinic) {
                    $skipped++;

                    return;
                }

                try {
                    $metrics = $telemetry->capture($clinic, $clinic->organization, $now);

                    TrialUsageSnapshot::updateOrCreate(
                        [
                            'trial_id' => $trial->id,
                            /*
                             * One row per trial per day. Keying on the date is
                             * what makes a re-run replace rather than duplicate.
                             */
                            'captured_at' => $now->copy()->startOfDay(),
                        ],
                        array_merge($metrics, ['captured_at' => $now->copy()->startOfDay()]),
                    );

                    $captured++;
                } catch (\Throwable $e) {
                    /*
                     * One tenant's measurement failing must not stop the rest.
                     * A missing snapshot is a gap in a chart; an aborted run is
                     * a gap for every customer.
                     */
                    Log::warning('Trial usage snapshot failed', [
                        'trial_id' => $trial->id,
                        'clinic_id' => $trial->clinic_id,
                        'reason' => $e->getMessage(),
                    ]);

                    $skipped++;
                }
            });

        Log::info('Trial usage snapshots captured', ['captured' => $captured, 'skipped' => $skipped]);
    }
}
