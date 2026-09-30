<?php

namespace App\Http\Requests\Auth;

use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The clinic sign-in form, with its own brute-force limit.
 *
 * Five failed passwords for one email from one address lock that pair for a
 * minute, the same limit the Company login applies through `LoginRequest`.
 * A per-address ceiling across all emails is applied separately, on the
 * route (`throttle:clinic-login`), so cycling through email addresses from
 * one machine is capped too.
 */
class ClinicLoginRequest extends FormRequest
{
    /** Failed attempts allowed for one email from one address before a lockout. */
    public const MAX_ATTEMPTS = 5;

    /** How long a lockout lasts. */
    public const DECAY_SECONDS = 60;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), self::MAX_ATTEMPTS)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    public function recordFailedAttempt(): void
    {
        RateLimiter::hit($this->throttleKey(), self::DECAY_SECONDS);
    }

    public function clearFailedAttempts(): void
    {
        RateLimiter::clear($this->throttleKey());
    }

    /**
     * Keyed on the email as typed (case-folded) and the client address, and
     * prefixed so a clinic lockout never collides with a Company one.
     */
    public function throttleKey(): string
    {
        return 'clinic-login|'.Str::transliterate(Str::lower($this->string('email')).'|'.$this->ip());
    }
}
