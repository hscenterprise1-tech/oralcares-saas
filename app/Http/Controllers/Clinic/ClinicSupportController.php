<?php

namespace App\Http\Controllers\Clinic;

use App\Http\Controllers\Controller;
use App\Models\Clinic;
use App\Models\CompanySupportTicket;
use App\Models\CompanySupportTicketEvent;
use App\Models\User;
use App\Services\ClinicContext;
use App\Services\Support\ClinicSupportTickets;
use App\Services\Support\SupportTicketService;
use App\SupportTicketStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The clinic's side of OralCares support: raise a request, follow it.
 *
 * Tickets are the support desk's own (`CompanySupportTicket`, opened through
 * `SupportTicketService`, channel `portal`), so a request raised here lands in
 * the same queue the support team works. This side only ever reads tickets of
 * the clinic of this session. Every member may ask and sees their own
 * requests; the owner and whoever manages the clinic's settings see all of
 * the clinic's. The team's internal notes, and the notes attached to their
 * status changes, never leave the desk.
 *
 * Replying from the clinic is not offered: the desk counts any customer-
 * visible comment as its own first response, so a clinic reply would stop
 * the response clock it is waiting on.
 */
class ClinicSupportController extends Controller
{
    private const PER_PAGE = 20;

    public function __construct(
        protected ClinicContext $clinicContext,
        protected ClinicSupportTickets $visibility,
        protected SupportTicketService $tickets,
    ) {}

    public function index(Request $request): Response
    {
        $clinic = $this->clinic();
        $seesAll = $this->visibility->seesAll();

        $page = $this->visibility->visible($clinic, $request->user())
            ->latest('id')
            ->paginate(self::PER_PAGE, ['id', 'reference', 'subject', 'status', 'opened_by', 'reporter_name', 'created_at', 'updated_at', 'resolved_at']);

        return Inertia::render('ClinicPortal/Support/Index', [
            'tickets' => [
                'data' => collect($page->items())->map(fn (CompanySupportTicket $ticket) => [
                    'id' => $ticket->id,
                    'reference' => $ticket->reference,
                    'subject' => $ticket->subject,
                    'status' => $this->status($ticket->status),
                    'raised_by' => $ticket->reporter_name,
                    'created_at' => $ticket->created_at?->toIso8601String(),
                    'updated_at' => $ticket->updated_at?->toIso8601String(),
                ])->all(),
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
            ],
            'scope' => $seesAll ? 'clinic' : 'mine',
            'links' => [
                'store' => route('clinic.support.store', $this->routeParameters($request), false),
                'show' => route('clinic.support.show', $this->routeParameters($request) + ['ticket' => '__ID__'], false),
            ],
        ]);
    }

    public function show(Request $request): Response
    {
        $clinic = $this->clinic();
        $record = $this->visibility->visible($clinic, $request->user())
            ->with(['events' => fn ($query) => $query->where(fn ($visible) => $visible
                ->whereIn('event_type', ['opened', 'status'])
                ->orWhere(fn ($reply) => $reply->where('event_type', 'reply')->where('is_internal', false)))])
            // By name: in domain mode the route also carries `{clinic}`.
            ->findOrFail((int) $request->route('ticket'));

        return Inertia::render('ClinicPortal/Support/Show', [
            'ticket' => [
                'id' => $record->id,
                'reference' => $record->reference,
                'subject' => $record->subject,
                'body' => $record->body,
                'status' => $this->status($record->status),
                'raised_by' => $record->reporter_name,
                'created_at' => $record->created_at?->toIso8601String(),
                'resolved_at' => $record->resolved_at?->toIso8601String(),
            ],
            'history' => $record->events->map(fn (CompanySupportTicketEvent $event) => [
                'id' => $event->id,
                'kind' => $event->event_type,
                // A reply's words are for the clinic; a status change says only what it became.
                'body' => $event->event_type === 'reply' ? $event->body : null,
                'status' => $event->event_type === 'status' && $event->to_value
                    ? $this->status(SupportTicketStatus::tryFrom($event->to_value))
                    : null,
                'at' => $event->created_at?->toIso8601String(),
            ])->values()->all(),
            'links' => [
                'index' => route('clinic.support.index', $this->routeParameters($request), false),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $clinic = $this->clinic();
        $validated = $request->validate([
            'subject' => ['required', 'string', 'max:160'],
            'body' => ['required', 'string', 'max:5000'],
        ], [], ['body' => 'description']);

        /** @var User $user */
        $user = $request->user();
        $ticket = $this->tickets->open([
            'organization_id' => $clinic->organization_id,
            'clinic_id' => $clinic->id,
            'reporter_name' => $user->name,
            'reporter_email' => $user->email,
            'subject' => $validated['subject'],
            'body' => $validated['body'],
            'channel' => 'portal',
        ], $user);

        return redirect()
            ->route('clinic.support.show', $this->routeParameters($request) + ['ticket' => $ticket->id])
            ->with('success', "Request {$ticket->reference} sent to OralCares support.");
    }

    private function clinic(): Clinic
    {
        return $this->clinicContext->clinic() ?? abort(403);
    }

    /** @return array{value: string, label: string}|null */
    private function status(?SupportTicketStatus $status): ?array
    {
        return $status ? ['value' => $status->value, 'label' => $status->label()] : null;
    }

    /** @return array<string, string> */
    private function routeParameters(Request $request): array
    {
        return array_filter(['clinic' => $request->route('clinic')], fn ($value) => is_string($value));
    }
}
