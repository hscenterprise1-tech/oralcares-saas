<?php

namespace App\Http\Middleware;

use App\ClinicPermission;
use App\Exceptions\OwnerModeRequired;
use App\Services\ClinicContext;
use App\Services\OwnerMode\OwnerMode;
use App\Services\Security\SecurityAudit;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `clinic_permission:settings.manage` — refuses the request unless the user
 * holds that clinic permission in the active clinic/branch (owners with the
 * owner's privileges and master admins always pass, see
 * `User::hasClinicPermission()`).
 *
 * A doctor-owner in Doctor Mode who asks for something only the owner holds
 * is offered Owner Mode (`OwnerModeRequired`, nothing executed) rather than a
 * plain refusal: entering it is theirs to choose.
 */
class EnsureClinicPermission
{
    public function __construct(
        protected OwnerMode $ownerMode,
        protected ClinicContext $clinicContext,
        protected SecurityAudit $audit,
    ) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        if ($request->user()?->hasClinicPermission(ClinicPermission::from($permission)) ?? false) {
            return $next($request);
        }

        if ($this->ownerMode->isDoctorOwner() && ! $this->ownerMode->authorized()) {
            // Recorded as any owner refusal is (`OwnerMode::requireForSensitiveAction`).
            $this->audit->record('owner_mode.action_refused', $this->clinicContext->membership(), [
                'action' => $request->route()?->getName() ?? $permission, 'permission' => $permission, 'tier' => OwnerMode::STANDARD, 'reason' => 'owner_mode_inactive',
            ]);

            throw OwnerModeRequired::inactive(OwnerMode::ACTION_REFUSAL);
        }

        abort(403, 'You do not have permission to do this.');
    }
}
