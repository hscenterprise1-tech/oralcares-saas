<?php

namespace App\Http\Controllers\Clinic;

use App\Http\Controllers\Controller;
use App\Services\ClinicContext;
use App\Support\ClinicAccess\ClinicAccess;
use App\Support\ClinicWorkspaces;
use App\Support\Dashboard\OwnerCommandCenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * `clinic.dashboard`: the Command Center, for whoever runs the clinic.
 *
 * There is no separate staff dashboard: reception's dashboard is the Front
 * Desk, billing staff work from Billing, practitioners from the Calendar
 * (`ClinicWorkspaces::home`). Anyone else who opens this address is sent to
 * their own home.
 */
class ClinicDashboardController extends Controller
{
    public function __construct(
        protected ClinicContext $clinicContext,
        protected ClinicAccess $access,
    ) {}

    public function index(Request $request, OwnerCommandCenter $commandCenter): Response|RedirectResponse
    {
        $routeParameters = array_filter(['clinic' => $request->route('clinic')], fn ($value) => is_string($value));

        if (! $commandCenter->appliesTo()) {
            return redirect()->to(ClinicWorkspaces::landing($this->access, $routeParameters));
        }

        // A member with no branch access sees no branch's figures.
        $this->clinicContext->branchScopeOrAbort();

        $membership = $this->clinicContext->membership();
        $branch = $this->clinicContext->branch();
        $branchCount = $membership ? $membership->accessibleActiveBranches()->count() : 0;
        $isOwner = (bool) $membership?->is_primary_owner;
        // The owner's privileges: always for an owner who does not treat patients; for a
        // doctor-owner only in Owner Mode (in Doctor Mode they work as a doctor).
        $ownerPrivileges = $this->access->ownerModeActive();
        // What the figures cover. Only the owner may look across every branch at once
        // (staff roles are held per branch); anyone else with several branches picks one.
        $scope = [
            'branch' => $branch ? ['id' => $branch->id, 'name' => $branch->name] : null,
            'all_branches' => $branch === null && $ownerPrivileges && $branchCount > 1,
            'needs_branch' => $branch === null && ! $ownerPrivileges && $branchCount > 1,
        ];

        // The Command Center: its sections are a lazy prop, so a live refresh asks for just them.
        // It is a management view: patient intake (New Case, Repeat Case) stays in the top bar
        // and the patient pages, never among this page's actions.
        return Inertia::render('ClinicPortal/CommandCenter/Index', [
            'sections' => fn () => $commandCenter->sections($routeParameters),
            'scope' => $scope,
            'is_owner' => $isOwner,
        ]);
    }
}
