<?php

namespace App\Services\Provisioning;

use App\Models\Clinic;
use App\Models\CompanySubscription;
use App\SubscriptionStatus;
use Illuminate\Support\Facades\Log;

/**
 * Which of a clinic's subscriptions a question is answered from.
 *
 * Two rules are in force, and they are kept apart on purpose until the access
 * policy is decided:
 *
 * - Access, modules and the subscription window (`EntitlementResolver`): the
 *   clinic's most recent subscription, whatever its status. Its dates decide
 *   access; its status does not.
 * - Seat limits (`ResourceLimitService`): the most recent subscription whose
 *   status is active. With none, seats cannot be granted.
 *
 * Both used to pick their row on their own — seats took any active row in no
 * particular order — so with more than one row they could answer from
 * different subscriptions without anyone knowing. Now both are picked here,
 * deterministically (newest first), and when they differ, or a clinic has
 * more than one active subscription, it is logged with the ids only.
 */
class ClinicSubscriptionSelector
{
    /** The subscription access and modules are read from. */
    public function forAccess(Clinic $clinic): ?CompanySubscription
    {
        return CompanySubscription::query()->where('clinic_id', $clinic->id)->latest('id')->first();
    }

    /** The subscription seat limits are read from. */
    public function forSeats(Clinic $clinic): ?CompanySubscription
    {
        $active = CompanySubscription::query()->where('clinic_id', $clinic->id)
            ->where('status', SubscriptionStatus::Active)
            ->orderByDesc('id')->limit(2)->get();
        $seats = $active->first();
        $accessId = CompanySubscription::query()->where('clinic_id', $clinic->id)->max('id');

        if ($active->count() > 1 || ($seats !== null && (int) $accessId !== $seats->id)) {
            Log::warning('Clinic subscriptions disagree: seats and access are read from different rows, or more than one is active.', [
                'clinic_id' => $clinic->id,
                'seat_subscription_id' => $seats?->id,
                'access_subscription_id' => $accessId === null ? null : (int) $accessId,
                'more_than_one_active' => $active->count() > 1,
            ]);
        }

        return $seats;
    }
}
