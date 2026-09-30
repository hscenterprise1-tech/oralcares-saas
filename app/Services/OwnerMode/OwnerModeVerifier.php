<?php

namespace App\Services\OwnerMode;

use App\Models\User;

/**
 * The step-up check a clinic owner passes to enter Owner Mode.
 *
 * The password is the first implementation. A one-time code or an
 * authenticator app is another class behind this contract: Owner Mode
 * itself only asks "did the owner prove it is them, just now, and how".
 */
interface OwnerModeVerifier
{
    /** A short name for the audit trail, e.g. "password". */
    public function method(): string;

    /**
     * @param  array<string, mixed>  $input  the request fields the verifier needs
     */
    public function verify(User $user, array $input): bool;
}
