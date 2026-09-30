<?php

declare(strict_types=1);

namespace App\Services\CustomerSuccess;

use App\CustomerChurnRisk;
use App\Models\Organization;
use Carbon\CarbonInterface;

/**
 * What the system already knows about an account going quiet.
 *
 * `organizations.churn_risk` has always existed and nothing has ever written
 * it except a person typing into a form. That is workable while somebody can
 * hold the whole customer book in their head, and it stops being workable long
 * before a hundred and twenty thousand clinics — every account simply keeps the
 * "Low" it was created with, and the field stops carrying information.
 *
 * Four signals, all of them already recorded, none of them new telemetry:
 *
 *   window       a lapsed or grace subscription is the strongest signal there
 *                is — they have stopped paying, or are about to.
 *   adoption     seats bought and not used. A clinic using two of twenty is
 *                not getting value, whatever the invoice says.
 *   silence      no recorded activity for a long time. The gap is measured
 *                from the account's own timeline, which every write path here
 *                appends to.
 *   onboarding   never went live. An account stuck in setup cannot renew, and
 *                the longer it sits the less likely it ever will.
 *
 * Signals add up rather than override each other, because one bad reading is a
 * question and three are an answer.
 *
 * This never writes. {@see AccountSnapshotService} decides whether the derived
 * value is used, and honours a human's override over anything computed here.
 */
class ChurnRiskService
{
    /** Days of silence before an account counts as quiet. */
    public const SILENCE_DAYS = 60;

    /** Seat utilisation at or below which an account counts as unadopted. */
    public const LOW_ADOPTION_PCT = 20;

    /**
     * Derive a churn risk from what the account's own records say.
     *
     * @param  array{
     *     window_state?: string|null,
     *     seat_utilization_pct?: int|null,
     *     has_subscription?: bool,
     *     onboarding_status?: string|null,
     *     last_activity_at?: CarbonInterface|null,
     * }  $signals
     * @return array{risk: CustomerChurnRisk, reasons: list<string>}
     */
    public function derive(array $signals): array
    {
        $reasons = [];
        $score = 0;

        $window = $signals['window_state'] ?? null;

        if ($window === 'lapsed') {
            $score += 3;
            $reasons[] = 'Subscription has lapsed';
        } elseif ($window === 'grace') {
            $score += 2;
            $reasons[] = 'Subscription is in its grace period';
        }

        /*
         * Only meaningful once something was actually bought. A null
         * utilisation means nothing was purchased, which is a different
         * problem from buying and not using.
         */
        $seatUtilisation = $signals['seat_utilization_pct'] ?? null;

        if ($seatUtilisation !== null && $seatUtilisation <= self::LOW_ADOPTION_PCT) {
            $score += 1;
            $reasons[] = "Only {$seatUtilisation}% of purchased seats are in use";
        }

        $lastActivity = $signals['last_activity_at'] ?? null;
        $silentDays = $lastActivity ? (int) $lastActivity->diffInDays(now()) : null;

        if ($silentDays !== null && $silentDays >= self::SILENCE_DAYS) {
            $score += 1;
            $reasons[] = "No recorded contact for {$silentDays} days";
        } elseif ($lastActivity === null && ($signals['has_subscription'] ?? false)) {
            /*
             * A paying account with no timeline at all has never been spoken
             * to, which is the same silence by a different route.
             */
            $score += 1;
            $reasons[] = 'No contact has ever been recorded';
        }

        $onboarding = $signals['onboarding_status'] ?? null;

        if ($onboarding !== null && $onboarding !== 'completed') {
            $score += 1;
            $reasons[] = 'Onboarding never completed';
        }

        return [
            'risk' => match (true) {
                $score >= 3 => CustomerChurnRisk::High,
                $score >= 1 => CustomerChurnRisk::Medium,
                default => CustomerChurnRisk::Low,
            },
            'reasons' => $reasons,
        ];
    }

    /**
     * Whether a human has pinned this account's churn risk by hand.
     *
     * An override is permanent until somebody clears it. A risk a rep set after
     * speaking to the customer is worth more than anything this class can infer,
     * and silently replacing it overnight is how people stop trusting the field.
     */
    public function isOverridden(Organization $organization): bool
    {
        return $organization->churn_risk_overridden_at !== null;
    }
}
