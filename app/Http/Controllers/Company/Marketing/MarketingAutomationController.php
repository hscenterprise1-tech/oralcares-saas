<?php

namespace App\Http\Controllers\Company\Marketing;

use App\Http\Controllers\Controller;
use App\Models\MarketingAutomation;
use App\Models\MarketingAutomationExecution;
use App\Models\MarketingWhatsAppTemplate;
use App\Services\Marketing\MarketingAutomationDefinitionValidator;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MarketingAutomationController extends Controller
{
    public function __construct(
        protected MarketingAutomationDefinitionValidator $validator
    ) {}

    /**
     * Display Automations Directory with KPI Cards & Search/Status Filters.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();
        $orgId = $user?->organization_id;

        $search = $request->input('search');
        $status = $request->input('status');

        $query = MarketingAutomation::query()
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->when($search, fn ($q) => $q->where('name', 'like', "%{$search}%"))
            ->when($status && $status !== 'all', fn ($q) => $q->where('status', $status))
            ->withCount(['executions'])
            ->latest();

        $automations = $query->paginate(15)->withQueryString();

        // Calculate KPI Metrics
        $totalAutomations = MarketingAutomation::query()
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->count();

        $activeCount = MarketingAutomation::query()
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->where('status', 'active')
            ->count();

        $runningExecutionsCount = MarketingAutomationExecution::query()
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->whereIn('status', ['running', 'waiting'])
            ->count();

        $completedTodayCount = MarketingAutomationExecution::query()
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->where('status', 'completed')
            ->whereDate('completed_at', now()->today())
            ->count();

        return Inertia::render('Company/Marketing/Automations/Index', [
            'automations' => $automations,
            'metrics' => [
                'totalAutomations' => $totalAutomations,
                'activeCount' => $activeCount,
                'runningExecutionsCount' => $runningExecutionsCount,
                'completedTodayCount' => $completedTodayCount,
            ],
            'filters' => [
                'search' => $search ?? '',
                'status' => $status ?? 'all',
            ],
        ]);
    }

    /**
     * Display Visual Workflow Builder for creating a new automation.
     */
    public function create(Request $request): Response
    {
        return Inertia::render('Company/Marketing/Automations/Builder', [
            'automation' => null,
            'templates' => $this->approvedTemplatesFor($request),
        ]);
    }

    /**
     * Approved WhatsApp templates available to attach to a send_template step,
     * including their quick-reply button labels and which WhatsApp account
     * each belongs to — reusing the template's existing `whatsappAccount`
     * relationship, the same account `executeSendTemplateAction()` already
     * resolves from at send time. Purely informational for the Builder's
     * picker: an org with more than one connected number otherwise has no
     * way to see, before saving, which number a step will actually send
     * from.
     */
    protected function approvedTemplatesFor(Request $request)
    {
        $orgId = $request->user()?->organization_id;

        return MarketingWhatsAppTemplate::query()
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->approved()
            ->with('whatsappAccount:id,verified_name,display_phone_number')
            ->orderBy('name')
            ->get(['id', 'whatsapp_account_id', 'name', 'language', 'body', 'variables', 'buttons'])
            ->map(fn ($t) => [
                'id' => $t->id,
                'name' => $t->name,
                'language' => $t->language,
                'body' => $t->body,
                'variables' => $t->variables,
                'quick_reply_labels' => $t->quick_reply_labels,
                'whatsapp_account_name' => $t->whatsappAccount?->verified_name
                    ?? $t->whatsappAccount?->display_phone_number
                    ?? 'Unknown account',
            ]);
    }

    /**
     * Store new Marketing Automation draft.
     */
    public function store(Request $request)
    {
        $user = $request->user();
        $orgId = $user?->organization_id;

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'trigger_type' => 'required|string',
            'definition' => 'required|array',
        ]);

        $errors = $this->validator->validate($validated['definition'], $orgId);
        if (! empty($errors)) {
            return redirect()->back()->withErrors(['definition' => $errors[0]])->withInput();
        }

        $automation = MarketingAutomation::create([
            'organization_id' => $orgId,
            'name' => trim($validated['name']),
            'description' => $validated['description'] ?? null,
            'status' => 'draft',
            'trigger_type' => $validated['trigger_type'],
            'definition' => $validated['definition'],
            'created_by_user_id' => $user->id,
        ]);

        return redirect()->route('company.marketing.automations.show', $automation->id)
            ->with('success', 'Automation draft created successfully.');
    }

    /**
     * Display Automation Detail & Execution Timeline.
     */
    public function show(Request $request, int $id): Response
    {
        $user = $request->user();
        $orgId = $user?->organization_id;

        $automation = MarketingAutomation::query()
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->with(['creator'])
            ->findOrFail($id);

        $executions = $automation->executions()
            ->with(['contact', 'lead'])
            ->latest()
            ->paginate(20);

        $executionStats = [
            'total' => $automation->executions()->count(),
            'running' => $automation->executions()->whereIn('status', ['running', 'waiting'])->count(),
            'completed' => $automation->executions()->where('status', 'completed')->count(),
            'stopped' => $automation->executions()->where('status', 'stopped')->count(),
            'failed' => $automation->executions()->where('status', 'failed')->count(),
        ];

        return Inertia::render('Company/Marketing/Automations/Show', [
            'automation' => $automation,
            'executions' => $executions,
            'stats' => $executionStats,
        ]);
    }

    /**
     * Edit Automation definition in Visual Builder.
     */
    public function edit(Request $request, int $id): Response
    {
        $user = $request->user();
        $orgId = $user?->organization_id;

        $automation = MarketingAutomation::query()
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->findOrFail($id);

        return Inertia::render('Company/Marketing/Automations/Builder', [
            'automation' => $automation,
            'templates' => $this->approvedTemplatesFor($request),
        ]);
    }

    /**
     * Update Automation definition.
     */
    public function update(Request $request, int $id)
    {
        $user = $request->user();
        $orgId = $user?->organization_id;

        $automation = MarketingAutomation::query()
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->findOrFail($id);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'trigger_type' => 'required|string',
            'definition' => 'required|array',
        ]);

        $errors = $this->validator->validate($validated['definition'], $orgId);
        if (! empty($errors)) {
            return redirect()->back()->withErrors(['definition' => $errors[0]])->withInput();
        }

        $automation->update([
            'name' => trim($validated['name']),
            'description' => $validated['description'] ?? null,
            'trigger_type' => $validated['trigger_type'],
            'definition' => $validated['definition'],
            'updated_by_user_id' => $user->id,
        ]);

        return redirect()->route('company.marketing.automations.show', $automation->id)
            ->with('success', 'Automation definition updated successfully.');
    }

    /**
     * Activate Automation.
     */
    public function activate(Request $request, int $id)
    {
        $user = $request->user();
        $orgId = $user?->organization_id;

        $automation = MarketingAutomation::query()
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->findOrFail($id);

        $errors = $this->validator->validate($automation->definition, $orgId);
        if (! empty($errors)) {
            return redirect()->back()->with('error', "Cannot activate: {$errors[0]}");
        }

        $automation->update([
            'status' => 'active',
            'activated_at' => now(),
        ]);

        return redirect()->back()->with('success', 'Automation activated successfully.');
    }

    /**
     * Pause Automation.
     */
    public function pause(Request $request, int $id)
    {
        $user = $request->user();
        $orgId = $user?->organization_id;

        $automation = MarketingAutomation::query()
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->findOrFail($id);

        $automation->update([
            'status' => 'paused',
            'paused_at' => now(),
        ]);

        return redirect()->back()->with('success', 'Automation paused.');
    }

    /**
     * Resume Automation.
     */
    public function resume(Request $request, int $id)
    {
        $user = $request->user();
        $orgId = $user?->organization_id;

        $automation = MarketingAutomation::query()
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->findOrFail($id);

        $automation->update([
            'status' => 'active',
        ]);

        return redirect()->back()->with('success', 'Automation resumed.');
    }

    /**
     * Archive Automation.
     */
    public function archive(Request $request, int $id)
    {
        $user = $request->user();
        $orgId = $user?->organization_id;

        $automation = MarketingAutomation::query()
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->findOrFail($id);

        $automation->update([
            'status' => 'archived',
            'archived_at' => now(),
        ]);

        return redirect()->back()->with('success', 'Automation archived.');
    }

    /**
     * Duplicate Automation configuration into a draft (Section 38).
     */
    public function duplicate(Request $request, int $id)
    {
        $user = $request->user();
        $orgId = $user?->organization_id;

        $automation = MarketingAutomation::query()
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->findOrFail($id);

        $clone = MarketingAutomation::create([
            'organization_id' => $orgId,
            'name' => "{$automation->name} (Copy)",
            'description' => $automation->description,
            'status' => 'draft',
            'trigger_type' => $automation->trigger_type,
            'definition' => $automation->definition,
            'created_by_user_id' => $user->id,
        ]);

        return redirect()->route('company.marketing.automations.edit', $clone->id)
            ->with('success', 'Automation duplicated into new draft.');
    }
}
