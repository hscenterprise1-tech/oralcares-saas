<?php

namespace App\Services\Auth;

use App\ClinicPasswordTokenPurpose;
use App\Models\ClinicPasswordToken;
use App\Models\User;
use App\Services\Security\SecurityAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Issues and redeems the one-time links a clinic user sets a password with.
 *
 * The link carries 64 random characters (from the CSPRNG, via `Str::random`),
 * and only their SHA-256 is stored. That is the same scheme the invitation
 * links and Sanctum tokens already use: a high-entropy random secret needs a
 * fast lookup hash, not a slow password hash. Nothing about the user, their
 * id or their email goes into the token, so it cannot be rebuilt from facts
 * someone already knows. The link used to be `sha1(email)`, which could.
 *
 * A token works once, until it expires, and only while the account still has
 * the email it was issued to. Issuing a new one retires any older link of the
 * same kind, and setting a password retires every open link on the account.
 */
class ClinicPasswordTokenService
{
    public function __construct(protected SecurityAudit $audit) {}

    /**
     * Create a link for `$user` and return the plain token to put in it.
     * The plain token is never stored and cannot be read back later.
     */
    public function issue(User $user, ClinicPasswordTokenPurpose $purpose): string
    {
        $plainToken = Str::random(64);

        DB::transaction(function () use ($user, $purpose, $plainToken): void {
            ClinicPasswordToken::query()
                ->where('user_id', $user->id)
                ->where('purpose', $purpose)
                ->whereNull('used_at')
                ->delete();

            ClinicPasswordToken::create([
                'user_id' => $user->id,
                'purpose' => $purpose,
                'email' => $user->email,
                'token_hash' => $this->hash($plainToken),
                'expires_at' => now()->addMinutes($purpose->lifetimeInMinutes()),
            ]);
        });

        $this->audit->record('auth.password_link_issued', $user, ['purpose' => $purpose->value]);

        return $plainToken;
    }

    /**
     * The usable token behind a plain token from a link, or null when it is
     * unknown, expired, already used, of the wrong kind, or issued to an
     * email the account no longer has.
     */
    public function find(string $plainToken, ClinicPasswordTokenPurpose $purpose): ?ClinicPasswordToken
    {
        $token = ClinicPasswordToken::query()
            ->with('user')
            ->where('token_hash', $this->hash($plainToken))
            ->where('purpose', $purpose)
            ->usable()
            ->first();

        if (! $token || ! $token->user || ! hash_equals((string) $token->email, (string) $token->user->email)) {
            return null;
        }

        return $token;
    }

    /**
     * Set the password behind a link, and return the user it belongs to, or
     * null if the link stopped being usable in the meantime.
     *
     * The token row is locked for the duration, so two submissions of the
     * same link cannot both succeed. Every other open link on the account is
     * retired with it, and the remember-me token is rotated, which signs out
     * any "keep me signed in" session holding the old one.
     */
    public function redeem(string $plainToken, ClinicPasswordTokenPurpose $purpose, string $newPassword): ?User
    {
        return DB::transaction(function () use ($plainToken, $purpose, $newPassword): ?User {
            $token = ClinicPasswordToken::query()
                ->where('token_hash', $this->hash($plainToken))
                ->where('purpose', $purpose)
                ->usable()
                ->lockForUpdate()
                ->first();

            $user = $token?->user;

            if (! $token || ! $user || ! hash_equals((string) $token->email, (string) $user->email)) {
                return null;
            }

            $user->forceFill([
                'password' => $newPassword,
                'remember_token' => Str::random(60),
            ])->save();

            ClinicPasswordToken::query()
                ->where('user_id', $user->id)
                ->whereNull('used_at')
                ->update(['used_at' => now()]);

            $this->audit->record('auth.password_set', $user, ['via' => $purpose->value], ['actor_user_id' => $user->id]);

            return $user;
        });
    }

    private function hash(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }
}
