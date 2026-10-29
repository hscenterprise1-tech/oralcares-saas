<?php

namespace App\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The clinic owner was refused because Owner Mode is off, or its password
 * confirmation is older than a T2 action allows: a step-up, not a dead end.
 *
 * It is thrown only before anything is written (route middleware, or a check
 * that runs ahead of the action's transaction; one that runs inside it rolls
 * the transaction back), so the refused request is known not to have been
 * carried out and may be sent again once the owner has confirmed.
 *
 * Still a 403, so every existing check and test holds. For the portal's own
 * requests (Inertia visits and JSON calls) it answers with an explicit
 * contract the browser cannot confuse with any other refusal:
 *
 *   403, header `X-Owner-Mode-Required: inactive|stale`,
 *   body `{message, owner_mode: {required: true, reason, executed: false}}`.
 *
 * Staff, and anyone who is not the owner, never get this: they are refused
 * with a plain 403, because there is nothing they could confirm.
 */
class OwnerModeRequired extends AuthorizationException
{
    public const HEADER = 'X-Owner-Mode-Required';

    /** Owner Mode is off (never entered, idle, past its lifetime, or another clinic's). */
    public const INACTIVE = 'inactive';

    /** Owner Mode is on, but the password was not confirmed recently enough for a T2 action. */
    public const STALE = 'stale';

    public function __construct(string $message, public readonly string $reason = self::INACTIVE)
    {
        parent::__construct($message);
    }

    public static function inactive(string $message): self
    {
        return new self($message, self::INACTIVE);
    }

    public static function stale(string $message): self
    {
        return new self($message, self::STALE);
    }

    /**
     * The step-up contract for the portal's own requests; anything else gets
     * the framework's usual 403 page.
     */
    public function render(Request $request): ?JsonResponse
    {
        if (! $request->header('X-Inertia') && ! $request->expectsJson()) {
            return null;
        }

        return response()->json([
            'message' => $this->getMessage(),
            'owner_mode' => ['required' => true, 'reason' => $this->reason, 'executed' => false],
        ], 403, [self::HEADER => $this->reason]);
    }
}
