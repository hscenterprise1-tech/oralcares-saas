<?php

namespace App\Http\Controllers\Company\Operations;

use App\Http\Controllers\Controller;
use App\Models\CompanyEmployee;
use App\Models\CompanySupportSlaTarget;
use App\Models\CompanySupportTicket;
use App\Models\Organization;
use App\Services\Support\SupportTicketService;
use App\SupportTicketPriority;
use App\SupportTicketStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The support desk.
 *
 * Reading takes `view-support`; changing anything takes `manage-support`.
 * Master admins arrive through the `Gate::before` bypass like everywhere else.
 *
 * HQ-global: this is the vendor's queue across every customer. A ticket carries
 * its account explicitly, which is how it stays attributable.
 */
class SupportController extends Controller
{
    public function __construct(protected SupportTicketService $tickets) {}

    public function index(Request $request): Response
    {
        Gate::authorize('view-support');

        $filters = $request->only(['search', 'status', 'priority', 'organization_id', 'assigned_to']);

        $query = CompanySupportTicket::query()
            ->with(['organization:id,name', 'assignee.user:id,name'])
            ->when($filters['search'] ?? null, function ($q, $search) {
                /* Grouped, so the search cannot escape the filters beside it. */
                $q->where(function ($inner) use ($search) {
                    $inner->where('subject', 'like', "%{$search}%")
                        ->orWhere('reference', 'like', "%{$search}%")
                        ->orWhere('reporter_email', 'like', "%{$search}%");
                });
            })
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['priority'] ?? null, fn ($q, $priority) => $q->where('priority', $priority))
            ->when($filters['organization_id'] ?? null, fn ($q, $id) => $q->where('organization_id', $id))
            ->when($filters['assigned_to'] ?? null, fn ($q, $id) => $q->where('assigned_to', $id))
            ->orderByDesc('id');

        return Inertia::render('Company/Operations/SupportWorkspace', [
            'tickets' => $query->paginate(20)->withQueryString()
                ->through(fn (CompanySupportTicket $ticket) => $this->row($ticket)),
            'filters' => $filters,
            'summary' => [
                'open' => CompanySupportTicket::query()->open()->count(),
                'unassigned' => CompanySupportTicket::query()->open()->whereNull('assigned_to')->count(),
                'urgent' => CompanySupportTicket::query()->open()->where('priority', SupportTicketPriority::Urgent->value)->count(),
                'resolved_total' => CompanySupportTicket::query()->whereNotNull('resolved_at')->count(),
            ],
            /*
             * Whether anybody has said how long a reply may take. Until they
             * have, no ticket carries a due date and the page says so rather
             * than implying everything is on time.
             */
            'slaConfigured' => CompanySupportSlaTarget::query()->exists(),
            'slaTargets' => CompanySupportSlaTarget::query()->get()->keyBy('priority'),
            'organizations' => Organization::query()->orderBy('name')->get(['id', 'name']),
            'agents' => $this->agents(),
            'can' => ['manage' => Gate::allows('manage-support')],
        ]);
    }

    public function show(Request $request, CompanySupportTicket $ticket): Response
    {
        Gate::authorize('view-support');

        $ticket->load(['organization:id,name', 'clinic:id,name', 'assignee.user:id,name', 'opener:id,name', 'events.actor:id,name']);

        return Inertia::render('Company/Operations/SupportTicketDetail', [
            'ticket' => $this->row($ticket) + [
                'body' => $ticket->body,
                'reporter_name' => $ticket->reporter_name,
                'reporter_email' => $ticket->reporter_email,
                'clinic' => $ticket->clinic?->name,
                'channel' => $ticket->channel,
                'opened_by' => $ticket->opener?->name,
                'resolution_note' => $ticket->resolution_note,
            ],
            'events' => $ticket->events->map(fn ($event) => [
                'id' => $event->id,
                'type' => $event->event_type,
                'body' => $event->body,
                'from' => $event->from_value,
                'to' => $event->to_value,
                'is_internal' => $event->is_internal,
                'actor' => $event->actor?->name,
                'at' => $event->created_at?->format('d M Y, H:i'),
            ])->values(),
            'allowedNext' => collect($ticket->status->allowedNext())
                ->map(fn (SupportTicketStatus $status) => ['value' => $status->value, 'label' => $status->label()])
                ->values(),
            'agents' => $this->agents(),
            'can' => ['manage' => Gate::allows('manage-support')],
        ]);
    }

    public function store(Request $request)
    {
        Gate::authorize('manage-support');

        $validated = $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['nullable', 'string'],
            'organization_id' => ['nullable', 'exists:organizations,id'],
            'clinic_id' => ['nullable', 'exists:clinics,id'],
            'reporter_name' => ['nullable', 'string', 'max:120'],
            'reporter_email' => ['nullable', 'email', 'max:180'],
            'category' => ['nullable', 'string', 'max:60'],
            'channel' => ['nullable', 'string', 'max:30'],
            'priority' => ['required', Rule::enum(SupportTicketPriority::class)],
            'assigned_to' => ['nullable', 'exists:company_employees,id'],
        ]);

        $ticket = $this->tickets->open($validated, $request->user());

        return redirect()
            ->route('company.support.show', $ticket)
            ->with('success', "Ticket {$ticket->reference} opened.");
    }

    public function comment(Request $request, CompanySupportTicket $ticket)
    {
        Gate::authorize('manage-support');

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
            'is_internal' => ['boolean'],
        ]);

        try {
            $this->tickets->comment($ticket, $validated['body'], $validated['is_internal'] ?? true, $request->user());
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['body' => $e->getMessage()]);
        }

        return back()->with('success', 'Added to the ticket.');
    }

    public function update(Request $request, CompanySupportTicket $ticket)
    {
        Gate::authorize('manage-support');

        $validated = $request->validate([
            'status' => ['nullable', Rule::enum(SupportTicketStatus::class)],
            'priority' => ['nullable', Rule::enum(SupportTicketPriority::class)],
            'assigned_to' => ['nullable', 'exists:company_employees,id'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            if (! empty($validated['priority'])) {
                $this->tickets->reprioritise($ticket, SupportTicketPriority::from($validated['priority']), $request->user());
            }

            if (array_key_exists('assigned_to', $validated)) {
                $this->tickets->assign(
                    $ticket->fresh(),
                    $validated['assigned_to'] ? CompanyEmployee::find($validated['assigned_to']) : null,
                    $request->user(),
                );
            }

            if (! empty($validated['status'])) {
                $this->tickets->transition(
                    $ticket->fresh(),
                    SupportTicketStatus::from($validated['status']),
                    $request->user(),
                    $validated['note'] ?? null,
                );
            }
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }

        return back()->with('success', 'Ticket updated.');
    }

    /**
     * Set how long each priority is allowed to take.
     *
     * The only place an SLA interval is ever written. Nothing is seeded, and a
     * priority left blank simply produces no due date.
     */
    public function updateSla(Request $request)
    {
        Gate::authorize('manage-support');

        $validated = $request->validate([
            'targets' => ['required', 'array'],
            'targets.*.priority' => ['required', Rule::enum(SupportTicketPriority::class)],
            'targets.*.first_response_minutes' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'targets.*.resolution_minutes' => ['nullable', 'integer', 'min:1', 'max:1000000'],
        ]);

        foreach ($validated['targets'] as $target) {
            CompanySupportSlaTarget::updateOrCreate(
                ['priority' => $target['priority']],
                [
                    'first_response_minutes' => $target['first_response_minutes'] ?? null,
                    'resolution_minutes' => $target['resolution_minutes'] ?? null,
                    'updated_by' => $request->user()?->id,
                ],
            );
        }

        return back()->with('success', 'Response targets saved.');
    }

    /** @return list<array<string, mixed>> */
    protected function agents(): array
    {
        return CompanyEmployee::with('user:id,name')->get()
            ->map(fn (CompanyEmployee $employee) => [
                'id' => $employee->id,
                'name' => $employee->user?->name ?? $employee->employee_code,
            ])
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    protected function row(CompanySupportTicket $ticket): array
    {
        return [
            'id' => $ticket->id,
            'reference' => $ticket->reference,
            'subject' => $ticket->subject,
            'category' => $ticket->category,
            'status' => $ticket->status->value,
            'status_label' => $ticket->status->label(),
            'priority' => $ticket->priority->value,
            'priority_label' => $ticket->priority->label(),
            'organization' => $ticket->organization?->name,
            'organization_id' => $ticket->organization_id,
            'assignee' => $ticket->assignee?->user?->name ?? $ticket->assignee?->employee_code,
            'assigned_to' => $ticket->assigned_to,
            'opened_at' => $ticket->created_at?->format('d M Y, H:i'),
            'first_response_due_at' => $ticket->first_response_due_at?->format('d M Y, H:i'),
            'resolution_due_at' => $ticket->resolution_due_at?->format('d M Y, H:i'),
            'first_responded_at' => $ticket->first_responded_at?->format('d M Y, H:i'),
            'resolved_at' => $ticket->resolved_at?->format('d M Y, H:i'),
            /* Null where no target is configured — not "on time". */
            'breached_first_response' => $ticket->breachedFirstResponse(),
            'breached_resolution' => $ticket->breachedResolution(),
        ];
    }
}
