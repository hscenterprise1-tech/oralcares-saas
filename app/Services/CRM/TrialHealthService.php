<?php

namespace App\Services\CRM;

use App\Services\CrmMetricsService;
use Illuminate\Support\Collection;

/**
 * The one definition of a trial's health.
 *
 * Before this service, "healthy" / "at risk" / "expiring" were computed twice:
 * once in {@see TrialPageService::healthCounts()} for the Trials watchtower,
 * reading the lifecycle-derived phase and the entitlement window; once in
 * {@see CrmMetricsService::liveTrialHealth()} for the Dashboard's
 * KPI cards, reading the raw `trials.status` column and the raw `end_date`.
 * Both used the same two thresholds — {@see self::HEALTHY_PRESCRIPTIONS} and
 * `TrialService::INACTIVE_AFTER_HOURS`'s `is_inactive` flag — so the two never
 * disagreed about what "healthy" or "at risk" *mean*, but between scheduler
 * runs they could disagree about *which trials are still live*: a trial whose
 * end date had passed read as closed here (derived status) and still open
 * there (stored status), and "expiring" was measured against two different
 * clocks (the entitlement window vs. the raw end date).
 *
 * This is now the only place that turns a trial's already-known phase,
 * loss state, telemetry and entitlement window into the four watchtower
 * buckets. Both call sites resolve those inputs the same way — the derived
 * lifecycle phase, never the stored column — before handing them here, so the
 * same trial cannot land in different buckets on different pages.
 */
class TrialHealthService
{
    /**
     * Prescriptions printed before a trial counts as healthy.
     *
     * Three, and prescriptions rather than logins: signing in proves somebody
     * opened the product, printing a prescription proves they used it on a
     * real patient. One is a test, two is a pair of tests, three is a habit
     * forming.
     */
    public const HEALTHY_PRESCRIPTIONS = 3;

    /**
     * Phases where the evaluation is over, however it ended.
     *
     * Mirrors {@see TrialLifecycleService::TERMINAL} plus the two the
     * scheduler reaches on its own, so "live" means the same here as it does
     * there.
     *
     * @var list<string>
     */
    public const CLOSED_PHASES = [
        'completed',
        'quotation_requested',
        'cancelled',
        'expired',
    ];

    /**
     * How many live trials are thriving, drifting, or about to run out.
     *
     * "Healthy" is three or more prescriptions printed, not a login count.
     * Logging in proves somebody opened the product; printing a prescription
     * proves they used it on a real patient, which is the only signal that
     * predicts a renewal.
     *
     * Only live trials are counted. A completed trial is not "at risk", and
     * including closed rows would make the figures drift further from each
     * other the longer the product runs.
     *
     * Every row's `phase` must already be the lifecycle-derived status, never
     * the raw stored column, and `windowState`/`daysRemaining` must already
     * come from the entitlement window, never the raw `end_date` — that is
     * what keeps this in agreement with the Trials watchtower's own list.
     *
     * @param  Collection<int, array{phase: string, lost: mixed, telemetry: array<string, mixed>|null, windowState: string|null, daysRemaining: int|null}>  $rows
     * @return array{active: int, healthy: int, at_risk: int, expiring_48h: int, unmeasured: int}
     */
    public function aggregateFromRows(Collection $rows): array
    {
        $live = $rows->filter(
            fn (array $row) => ! in_array($row['phase'], self::CLOSED_PHASES, true) && ($row['lost'] ?? null) === null
        );

        return [
            'active' => $live->count(),
            'healthy' => $live->filter(
                fn (array $row) => ($row['telemetry']['prescriptions'] ?? 0) >= self::HEALTHY_PRESCRIPTIONS
            )->count(),
            'at_risk' => $live->filter(
                fn (array $row) => (bool) ($row['telemetry']['is_inactive'] ?? false)
            )->count(),
            /*
             * Expiring inside two days, measured from the entitlement window
             * rather than `end_date` — after a deal is won that count is a
             * paid term, and counting it here would report a customer as
             * expiring.
             */
            'expiring_48h' => $live->filter(
                fn (array $row) => ($row['windowState'] ?? 'none') === 'trial'
                    && ($row['daysRemaining'] ?? null) !== null
                    && $row['daysRemaining'] <= 2
            )->count(),
            'unmeasured' => $live->filter(fn (array $row) => ($row['telemetry'] ?? null) === null)->count(),
        ];
    }
}
