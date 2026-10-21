<?php

namespace App\Http\Controllers\Clinic;

use App\ClinicRole;
use App\Http\Controllers\Controller;
use App\Services\ClinicContext;
use App\Support\ClinicAccess\ClinicAccess;
use App\Support\Dashboard\DashboardData;
use App\Support\Dashboard\DashboardVisibility;
use App\Support\Dashboard\DashboardWidgets;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Clinic Portal dashboard: one entry point and one engine for every user.
 *
 * What the page shows is composed on the server (`DashboardWidgets`): the
 * widgets this user is eligible for (permissions, plan, specialty,
 * capabilities, branch scope, Owner Mode), zone by zone and in order, and the
 * quick actions whose destination works. The page draws that composition; it
 * decides nothing about access.
 *
 * The figures come with the page, in the same response: computing them costs
 * far less than the second round trip a deferred prop would add before any
 * card shows its number. They are a lazy prop, so the dashboard's live
 * refresh can still ask for just these (a partial reload) without
 * recomputing anything else.
 */
class ClinicDashboardController extends Controller
{
    public function __construct(
        protected ClinicContext $clinicContext,
        protected ClinicAccess $access,
    ) {}

    public function index(Request $request, DashboardData $data, DashboardVisibility $visibility): Response
    {
        // A member with no branch access sees no branch's figures.
        $this->clinicContext->branchScopeOrAbort();

        $routeParameters = array_filter(['clinic' => $request->route('clinic')], fn ($value) => is_string($value));
        $membership = $this->clinicContext->membership();
        $branch = $this->clinicContext->branch();
        $branchCount = $membership ? $membership->accessibleActiveBranches()->count() : 0;
        $isOwner = (bool) $membership?->is_primary_owner;

        // Everyone stays here: the dashboard is composed from what this user may do, so a
        // receptionist who may also bill gets one dashboard with both, never a redirect.
        $composition = DashboardWidgets::compose($this->access, $routeParameters);

        return Inertia::render('ClinicPortal/Dashboard', [
            'widgets' => DashboardWidgets::forBrowser($composition['widgets']),
            'actions' => $composition['actions'],
            'widgetData' => fn () => DashboardWidgets::data($composition['widgets'], $data),
            // What the figures cover. Only the owner may look across every branch at once
            // (staff roles are held per branch); anyone else with several branches picks one.
            'scope' => [
                'branch' => $branch ? ['id' => $branch->id, 'name' => $branch->name] : null,
                'all_branches' => $branch === null && $isOwner && $branchCount > 1,
                'needs_branch' => $branch === null && ! $isOwner && $branchCount > 1,
            ],
            // The owner's dashboard choices for staff roles, only in Owner Mode.
            'visibility' => $this->access->ownerModeActive() ? $this->visibilitySettings($visibility, $routeParameters) : null,
        ]);
    }

    /**
     * @param  array<string, string>  $routeParameters
     * @return array{action: string, roles: list<string>, widgets: list<array{key: string, title: string}>, hidden: array<string, list<string>>}
     */
    private function visibilitySettings(DashboardVisibility $visibility, array $routeParameters): array
    {
        $configurable = DashboardVisibility::configurableKeys();

        return [
            'action' => route('clinic.dashboard.visibility', $routeParameters, false),
            'roles' => array_column(ClinicRole::cases(), 'value'),
            'widgets' => array_values(array_map(
                fn (array $widget) => ['key' => $widget['key'], 'title' => $widget['title'].($widget['zone'] === 'figures' ? ' (figure)' : '')],
                array_filter(DashboardWidgets::WIDGETS, fn (array $widget) => in_array($widget['key'], $configurable, true)),
            )),
            'hidden' => $visibility->hidden($this->access->clinic()),
        ];
    }
}
