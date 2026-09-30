<?php

namespace App\Listeners;

use App\Services\Security\SecurityAudit;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Str;

/**
 * Puts the framework's authentication events into the security trail, for
 * every portal: sign-ins, sign-outs, failed attempts and lockouts.
 *
 * A failed attempt is recorded with the email that was tried, lower-cased.
 * That is what makes a password-spraying run visible later. The password is
 * never recorded.
 */
class RecordSecurityAuthEvents
{
    public function __construct(protected SecurityAudit $audit) {}

    public function handleLogin(Login $event): void
    {
        $this->audit->record('auth.login', $event->user, ['guard' => $event->guard, 'remember' => $event->remember], [
            'actor_user_id' => $event->user->getAuthIdentifier(),
        ]);
    }

    public function handleLogout(Logout $event): void
    {
        if (! $event->user) {
            return;
        }

        $this->audit->record('auth.logout', $event->user, ['guard' => $event->guard], [
            'actor_user_id' => $event->user->getAuthIdentifier(),
        ]);
    }

    public function handleFailed(Failed $event): void
    {
        $this->audit->record('auth.failed', $event->user, [
            'guard' => $event->guard,
            'email' => Str::lower((string) ($event->credentials['email'] ?? '')),
            'known_account' => $event->user !== null,
        ], ['actor_user_id' => null]);
    }

    public function handleLockout(Lockout $event): void
    {
        $this->audit->record('auth.lockout', null, [
            'email' => Str::lower((string) $event->request->input('email', '')),
        ], ['actor_user_id' => null]);
    }
}
