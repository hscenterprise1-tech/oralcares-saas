<?php

namespace App\Http\Middleware;

use App\Services\OwnerMode\OwnerMode;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards an owner-only route: `clinic_owner_mode`.
 *
 * Passes only for the actual owner of the session's clinic, with Owner Mode
 * on and not expired. Use it after `clinic_portal` and `clinic_context`.
 */
class EnsureOwnerMode
{
    public const REFUSAL = 'Owner Mode is required. Enter Owner Mode from your account menu.';

    public function __construct(protected OwnerMode $ownerMode) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($this->ownerMode->authorized(), 403, self::REFUSAL);

        return $next($request);
    }
}
