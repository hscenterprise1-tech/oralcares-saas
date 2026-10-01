<?php

namespace App\Http\Controllers\Clinic;

use App\ClinicRole;
use App\Http\Controllers\Controller;
use App\Services\ClinicContext;
use App\Support\ClinicAccess\ClinicAccess;
use App\Support\ClinicActions;
use App\Support\Dashboard\DashboardData;
use App\Support\Dashboard\DashboardVisibility;
use App\Support\Dashboard\DashboardWidgets;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Clinic Portal dashboard: one engine for every user.
 *
 * The widgets are resolved from the registry for this user (permissions,
 * plan, specialty, capabilities, branch scope, Owner Mode). Their data is a
 * deferred prop: the page paints at once with each widget's frame, then
 * fetches the figures of those widgets only.
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
        $eligible = DashboardWidgets::eligible($this->access, ClinicActions::for($this->access, $routeParameters), $routeParameters);

        return Inertia::render('ClinicPortal/Home', [
            'widgets' => DashboardWidgets::forBrowser($eligible),
            'widgetData' => Inertia::defer(fn () => DashboardWidgets::data($eligible, $data)),
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
                fn (array $widget) => ['key' => $widget['key'], 'title' => $widget['title'].($widget['zone'] === 'summary' ? ' (figure)' : '')],
                array_filter(DashboardWidgets::WIDGETS, fn (array $widget) => in_array($widget['key'], $configurable, true)),
            )),
            'hidden' => $visibility->hidden($this->access->clinic()),
        ];
    }
}
