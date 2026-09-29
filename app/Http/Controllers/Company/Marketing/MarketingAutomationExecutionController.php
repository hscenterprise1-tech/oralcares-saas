<?php

namespace App\Http\Controllers\Company\Marketing;

use App\Http\Controllers\Controller;
use App\Models\MarketingAutomation;
use App\Models\MarketingAutomationExecution;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MarketingAutomationExecutionController extends Controller
{
    /**
     * Every automation run, or one automation's runs.
     *
     * This served both routes but read neither: mounted at
     * `automations/{id}/executions` it ignored the id and listed every
     * execution in the organization, so "this rule's history" and "everything"
     * were the same page showing the same rows. The id is honoured now.
     *
     * Reached without one, it is the cross-automation log the Execution Logs
     * sidebar entry has always promised — the view that answers "is anything
     * failing", which no per-rule page can.
     */
    public function index(Request $request, ?int $id = null): Response
    {
        $user = $request->user();
        $orgId = $user?->organization_id;

        $automation = null;

        if ($id) {
            $automation = MarketingAutomation::query()
                ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
                ->findOrFail($id);
        }

        $scoped = fn () => MarketingAutomationExecution::query()
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->when($automation, fn ($q) => $q->where('automation_id', $automation->id));

        $executions = $scoped()
            ->with(['automation', 'contact', 'lead'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->latest()
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Company/Marketing/Automations/Executions/Index', [
            'executions' => $executions,
            'automation' => $automation ? ['id' => $automation->id, 'name' => $automation->name] : null,
            'filters' => ['status' => $request->string('status')->toString() ?: null],
            /*
             * Counts come from the same scoped query as the rows, so the figure
             * above a list and the list itself cannot disagree.
             */
            'stats' => [
                'total' => $scoped()->count(),
                'running' => $scoped()->whereIn('status', ['running', 'waiting'])->count(),
                'completed' => $scoped()->where('status', 'completed')->count(),
                'failed' => $scoped()->where('status', 'failed')->count(),
                'stopped' => $scoped()->where('status', 'stopped')->count(),
            ],
        ]);
    }

    /**
     * Display detailed execution audit timeline.
     */
    public function show(Request $request, int $id): Response
    {
        $user = $request->user();
        $orgId = $user?->organization_id;

        $execution = MarketingAutomationExecution::query()
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->with(['automation', 'contact', 'lead', 'conversation', 'steps' => fn ($q) => $q->orderBy('created_at', 'asc')])
            ->findOrFail($id);

        return Inertia::render('Company/Marketing/Automations/Executions/Show', [
            'execution' => $execution,
        ]);
    }
}
