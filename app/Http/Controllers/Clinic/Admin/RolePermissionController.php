<?php

namespace App\Http\Controllers\Clinic\Admin;

use App\ClinicPermission;
use App\ClinicRole;
use App\Http\Controllers\Controller;
use App\Services\ClinicContext;
use App\Services\OwnerMode\OwnerMode;
use App\Services\Staff\ClinicRolePermissions;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Roles and permissions: the clinic's owner decides what each standard role
 * (Clinic Admin, Doctor, Receptionist, Billing Staff) may do at this clinic.
 *
 * Only the primary owner may open or change this: whoever may hand out
 * permissions could otherwise hand them to themself. A change is a sensitive
 * action (Owner Mode), is audited, and applies to this clinic only; the
 * standard roles shared by every clinic are never edited.
 */
class RolePermissionController extends Controller
{
    public function __construct(
        protected ClinicContext $clinicContext,
        protected ClinicRolePermissions $permissions,
    ) {}

    public function index(): Response
    {
        $clinic = $this->clinicContext->clinic() ?? abort(403);
        $this->assertOwner();

        return Inertia::render('ClinicPortal/Admin/Roles', $this->permissions->matrix($clinic) + [
            'links' => ['base' => route('admin.roles.index')],
        ]);
    }

    public function update(Request $request, string $role): RedirectResponse
    {
        $clinic = $this->clinicContext->clinic() ?? abort(403);
        $this->assertOwner();
        $data = $request->validate([
            'permission' => ['required', Rule::enum(ClinicPermission::class)],
            'granted' => ['required', 'boolean'],
        ]);
        $clinicRole = ClinicRole::tryFrom($role) ?? abort(404);
        app(OwnerMode::class)->requireForSensitiveAction($request->user(), 'roles.permissions', OwnerMode::STANDARD, ['role' => $role, 'permission' => $data['permission']]);

        try {
            $this->permissions->set($clinic, $clinicRole, ClinicPermission::from($data['permission']), (bool) $data['granted'], $request->user());
        } catch (DomainException $e) {
            return back()->withErrors(['permission' => $e->getMessage()]);
        }

        return back()->with('success', 'Saved.');
    }

    public function reset(Request $request, string $role): RedirectResponse
    {
        $clinic = $this->clinicContext->clinic() ?? abort(403);
        $this->assertOwner();
        $clinicRole = ClinicRole::tryFrom($role) ?? abort(404);
        app(OwnerMode::class)->requireForSensitiveAction($request->user(), 'roles.permissions', OwnerMode::STANDARD, ['role' => $role, 'reset' => true]);

        $undone = $this->permissions->reset($clinic, $clinicRole, $request->user());

        return back()->with('success', $undone > 0 ? "{$role} is back to the standard. {$undone} ".($undone === 1 ? 'change' : 'changes').' undone.' : "{$role} already follows the standard.");
    }

    private function assertOwner(): void
    {
        abort_unless($this->clinicContext->membership()?->is_primary_owner, 403, 'Only the owner decides what each role may do.');
    }
}
