<?php

namespace App\Jobs;

use App\Services\Onboarding\OwnerInvitationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Marks owner invitations that ran out unaccepted as expired, so Customer
 * Success sees them and can resend. One update across all clinics.
 */
class ExpireOwnerInvitationsJob implements ShouldQueue
{
    use Queueable;

    public function handle(OwnerInvitationService $invitations): void
    {
        $expired = $invitations->expireStale();

        if ($expired > 0) {
            Log::info('Owner invitations expired.', ['count' => $expired]);
        }
    }
}
