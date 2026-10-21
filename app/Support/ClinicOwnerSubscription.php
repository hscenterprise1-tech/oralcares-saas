<?php

namespace App\Support;

use App\Models\Clinic;
use App\Services\Provisioning\EntitlementResolver;
use App\Support\ClinicAccess\ClinicAccess;
use Carbon\CarbonImmutable;

/**
 * The clinic's plan, as the sidebar shows it to the owner in Owner Mode.
 *
 * Everything comes from `EntitlementResolver`, the one source of truth for a
 * tenant's access window; nothing here works out a date or a state of its
 * own. Only the owner with Owner Mode verified on the server receives it —
 * everyone else, the owner included outside Owner Mode, gets null, so the
 * figures are never in their page at all.
 *
 * Only what the owner needs to act on: the plan's name, the access state, the
 * date that state ends and the days left. No price, limits or commercial
 * status. A paid term with no end date is open-ended: no date is invented,
 * and nothing claims it renews.
 */
class ClinicOwnerSubscription
{
    /**
     * @return array{state: 'active'|'trial'|'grace'|'lapsed'|'none', plan: ?string, ends_on: ?string, days_remaining: ?int}|null
     */
    public static function for(ClinicAccess $access, ?Clinic $clinic): ?array
    {
        if (! $clinic || ! $access->allows(['owner_mode' => true])) {
            return null;
        }

        $entitlement = app(EntitlementResolver::class)->forClinic($clinic);
        if (! $entitlement['has_subscription']) {
            return ['state' => 'none', 'plan' => null, 'ends_on' => null, 'days_remaining' => null];
        }

        $window = $entitlement['window'];
        // The boundary of the state in force, as the resolver chose it.
        $ends = match ($window['state']) {
            'trial' => $window['trial_ends_at'],
            'grace' => $window['grace_ends_at'],
            'paid' => $window['ends_at'],
            default => null,
        };

        return [
            'state' => match ($window['state']) {
                'paid' => 'active',
                'trial', 'grace', 'lapsed' => $window['state'],
                default => 'none',
            },
            'plan' => $entitlement['plan']['name'] ?? null,
            // The calendar day at the clinic, so the owner reads the date they will see it end.
            'ends_on' => $ends ? CarbonImmutable::parse($ends)->setTimezone(ClinicClock::timezone())->toDateString() : null,
            'days_remaining' => $window['days_remaining'],
        ];
    }
}
