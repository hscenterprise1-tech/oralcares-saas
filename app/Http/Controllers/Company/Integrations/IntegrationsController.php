<?php

namespace App\Http\Controllers\Company\Integrations;

use App\Http\Controllers\Controller;
use App\Services\Integrations\IntegrationsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Company → Integrations.
 *
 * Read-only, and deliberately so. WhatsApp numbers are connected and
 * disconnected in Marketing settings, which owns that flow; this reports
 * whether what was connected is still working. Nothing here retries, replays or
 * re-sends, because the send path already owns that and a second one would
 * diverge from it.
 *
 * Gated on `view-integrations`, which already existed and, until now, gated
 * nothing — there was no integrations route in the application at all.
 */
class IntegrationsController extends Controller
{
    public function __construct(protected IntegrationsService $integrations) {}

    public function index(Request $request): Response
    {
        Gate::authorize('view-integrations');

        $days = (int) $request->integer('days', 30);
        $days = in_array($days, [7, 30, 90], true) ? $days : 30;

        return Inertia::render('Company/Integrations/IntegrationsWorkspace', [
            'connections' => $this->integrations->connections(),
            'inbound' => $this->integrations->inbound($days),
            'outbound' => $this->integrations->outbound($days),
            'unavailable' => $this->integrations->unavailable(),
            'filters' => ['days' => $days],
            'can' => [
                'manageMarketingSettings' => Gate::allows('manage-marketing-settings'),
            ],
        ]);
    }
}
