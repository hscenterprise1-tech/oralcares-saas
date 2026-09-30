<?php

namespace App\Listeners;

use App\Models\LoginHistory;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Log;

/**
 * Write down that someone signed in.
 *
 * This is the telemetry source the trial-health metrics rest on. Until now the
 * only evidence of activity was `sessions.last_activity`, which the database
 * session driver overwrites and garbage-collects — so a customer who used the
 * product enthusiastically for three days and then stopped was indistinguishable
 * from one who never logged in at all.
 *
 * It records the fact of a login, not its contents. Aggregate counts derived
 * from these rows are what CRM is entitled to see; nothing about the customer's
 * patients, clinical notes or messages is or becomes visible through this.
 *
 * Never throws. A telemetry write failing must not stop a legitimate person
 * from signing in.
 */
class RecordLoginHistory
{
    public function handle(Login $event): void
    {
        try {
            $request = request();

            LoginHistory::create([
                'user_id' => $event->user->getAuthIdentifier(),
                /*
                 * The raw user agent, truncated to the column. Parsing it into
                 * a device and browser name needs a library this project does
                 * not have, and a guessed value is worse than a plain one.
                 */
                'browser' => substr((string) $request?->userAgent(), 0, 255) ?: null,
                'device' => null,
                'ip_address' => $request?->ip(),
                'login_time' => now(),
                'status' => 'success',
            ]);
        } catch (\Throwable $e) {
            Log::warning('Could not record login history', [
                'user_id' => $event->user->getAuthIdentifier(),
                'reason' => $e->getMessage(),
            ]);
        }
    }
}
