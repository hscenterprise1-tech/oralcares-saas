<?php

namespace App\Http\Controllers\Company\Security;

use App\Http\Controllers\Controller;
use App\Services\Security\SecurityReviewService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Security and access review.
 *
 * Read-only, and there is no write route behind any of it. Roles are changed
 * where they already are — `company.people.roles.*` — and nothing here revokes a
 * session or issues a token, because no existing backend supports either and
 * inventing them would be inventing security surface.
 *
 * Gated on `view-audit-logs`, the permission that already means "may see what
 * happened here". No new permission was created for this batch.
 */
class SecurityController extends Controller
{
    public function __construct(protected SecurityReviewService $review) {}

    public function index(Request $request): Response
    {
        Gate::authorize('view-audit-logs');

        $search = $request->string('search')->toString() ?: null;

        return Inertia::render('Company/Security/SecurityWorkspace', [
            'routes' => $this->review->routeAudit(),
            'roles' => $this->review->roles(),
            'permissions' => $this->review->permissionReconciliation(),
            'users' => $this->review->users($search),
            'logins' => $this->review->loginHistory(60),
            'sessions' => $this->review->sessions(),
            'filters' => ['search' => $search],
            'can' => [
                'manageRoles' => Gate::allows('roles.manage'),
                'viewAudit' => Gate::allows('view-audit-logs'),
            ],
        ]);
    }
}
