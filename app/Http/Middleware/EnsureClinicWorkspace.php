<?php

namespace App\Http\Middleware;

use App\Exceptions\OwnerModeRequired;
use App\Services\ClinicContext;
use App\Services\OwnerMode\OwnerMode;
use App\Services\Security\SecurityAudit;
use App\Support\ClinicAccess\ClinicAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `clinic_workspace:front_desk` (or several, any one passing) — refuses the
 * request unless the user works in that workspace here. The same rule
 * (`ClinicAccess::workspaces()`) decides home routing, navigation and the
 * workspace switcher, so a page hidden from someone is also closed to them.
 *
 * A doctor-owner in Doctor Mode asking for a workspace the owner holds is
 * offered Owner Mode (`OwnerModeRequired`, nothing executed) instead.
 */
class EnsureClinicWorkspace
{
    public function __construct(
        protected ClinicAccess $access,
        protected OwnerMode $ownerMode,
        protected ClinicContext $clinicContext,
        protected SecurityAudit $audit,
    ) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$workspaces): Response
    {
        if (array_intersect($workspaces, $this->access->workspaces()) !== []) {
            return $next($request);
        }

        if ($this->ownerMode->isDoctorOwner() && ! $this->ownerMode->authorized()) {
            $this->audit->record('owner_mode.action_refused', $this->clinicContext->membership(), [
                'action' => $request->route()?->getName() ?? 'workspace', 'workspace' => implode(',', $workspaces), 'tier' => OwnerMode::STANDARD, 'reason' => 'owner_mode_inactive',
            ]);

            throw OwnerModeRequired::inactive(OwnerMode::ACTION_REFUSAL);
        }

        abort(403, 'This workspace is not part of your role here.');
    }
}
