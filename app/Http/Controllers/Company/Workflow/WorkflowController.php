<?php

namespace App\Http\Controllers\Company\Workflow;

use App\Http\Controllers\Controller;
use App\Services\Workflow\WorkflowAdministrationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Company → Workflow administration.
 *
 * Read-only. The engine under `App\Services\Workflow*` is the authoritative one
 * and is reused unchanged; nothing here starts, moves, completes or cancels a
 * run.
 *
 * There is no write path because the schema makes HQ authorship undefined: a
 * definition belongs to a clinic, a run to a clinical encounter, and a history
 * entry names a `clinic_employee` as its performer, so an HQ user cannot be
 * recorded as having done anything. Whether HQ should author tenant workflows is
 * a business decision and is reported rather than assumed.
 *
 * Gated on `view-platform`, which was already seeded and, until now, gated
 * nothing.
 */
class WorkflowController extends Controller
{
    public function __construct(protected WorkflowAdministrationService $workflows) {}

    public function index(Request $request): Response
    {
        Gate::authorize('view-platform');

        $search = $request->string('search')->toString() ?: null;

        return Inertia::render('Company/Workflow/WorkflowWorkspace', [
            'definitions' => $this->workflows->definitions($search),
            'integrity' => $this->workflows->integrity(),
            'runs' => $this->workflows->runs(),
            'history' => $this->workflows->history(),
            'unsupported' => $this->workflows->unsupported(),
            'filters' => ['search' => $search],
        ]);
    }
}
