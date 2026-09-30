<?php

namespace App\Services\OwnerMode;

use App\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * Owner Mode confirmed with the signed-in account's own password.
 */
class PasswordOwnerModeVerifier implements OwnerModeVerifier
{
    public function method(): string
    {
        return 'password';
    }

    public function verify(User $user, array $input): bool
    {
        $password = $input['password'] ?? null;

        return is_string($password) && $password !== '' && Hash::check($password, $user->password);
    }
}
