<?php

namespace App\Http\Controllers\Clinic;

use App\Http\Controllers\Controller;
use App\Models\DeletionRequest;
use App\Services\ClinicContext;
use App\Services\Governance\DeletionGovernance;
use App\Services\OwnerMode\OwnerMode;
use Closure;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * Destructive-action governance over HTTP: asking, the owner's approval
 * queue, and the owner's decisions. Every rule lives in
 * `DeletionGovernance`; the routes add Owner Mode for owner-only steps.
 */
class DeletionRequestController extends Controller
{
    public function __construct(
        protected ClinicContext $clinicContext,
        protected DeletionGovernance $governance,
        protected OwnerMode $ownerMode,
    ) {}

    /**
     * The owner's queue, or for anyone else the requests they made.
     */
    public function index(Request $request): Response
    {
        $clinic = $this->clinicContext->clinic();
        $owner = $this->ownerMode->isOwner();
        $tab = $request->query('tab') === 'decided' ? 'decided' : 'open';
        $open = [DeletionRequest::PENDING, DeletionRequest::CLARIFICATION];

        $base = fn () => DeletionRequest::query()->where('clinic_id', $clinic->id)
            ->when(! $owner, fn ($query) => $query->where('requester_user_id', $request->user()->id));

        $requests = $base()
            ->when($tab === 'open', fn ($query) => $query->whereIn('status', $open), fn ($query) => $query->whereNotIn('status', $open))
            ->with(['requester:id,name', 'approver:id,name', 'patient' => fn ($query) => $query->withoutGlobalScopes()->select('id', 'first_name', 'last_name', 'uhid')])
            ->latest('id')->paginate(20)->withQueryString()
            ->through(fn (DeletionRequest $item) => $this->present($item, $request->user()->id, $owner));

        return Inertia::render('ClinicPortal/Approvals/Index', [
            'requests' => $requests,
            'tab' => $tab,
            'counts' => [
                'open' => $base()->whereIn('status', $open)->count(),
                'decided' => $base()->whereNotIn('status', $open)->count(),
            ],
            'owner' => $owner,
            'ownerMode' => $this->ownerMode->authorized(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validateAction($request);

        return $this->attempt(function () use ($request, $validated) {
            $created = $this->governance->request($request->user(), $validated['action'], (int) $validated['target_id'], $validated['reason'], $validated['note'] ?? null, (array) ($validated['payload'] ?? []));

            return back()->with('success', "Sent to the owner for approval: {$created->target_label}.");
        });
    }

    /** The owner acting directly, in Owner Mode (route middleware). */
    public function execute(Request $request): RedirectResponse
    {
        $validated = $this->validateAction($request);

        return $this->attempt(function () use ($request, $validated) {
            $done = $this->governance->executeDirectly($request->user(), $validated['action'], (int) $validated['target_id'], $validated['reason'], (array) ($validated['payload'] ?? []));

            return back()->with('success', "Done: {$done->target_label}.");
        });
    }

    public function approve(Request $request, int $deletionRequest): RedirectResponse
    {
        $note = $request->validate(['note' => ['nullable', 'string', 'max:2000']])['note'] ?? null;

        return $this->attempt(fn () => tap(back()->with('success', 'Approved and carried out.'), fn () => $this->governance->approve($request->user(), $this->find($deletionRequest), $note)));
    }

    public function reject(Request $request, int $deletionRequest): RedirectResponse
    {
        $reason = $request->validate(['reason' => ['required', 'string', 'max:2000']])['reason'];

        return $this->attempt(fn () => tap(back()->with('success', 'Request rejected.'), fn () => $this->governance->reject($request->user(), $this->find($deletionRequest), $reason)));
    }

    public function clarify(Request $request, int $deletionRequest): RedirectResponse
    {
        $question = $request->validate(['question' => ['required', 'string', 'max:2000']])['question'];

        return $this->attempt(fn () => tap(back()->with('success', 'Question sent to the requester.'), fn () => $this->governance->askClarification($request->user(), $this->find($deletionRequest), $question)));
    }

    public function respond(Request $request, int $deletionRequest): RedirectResponse
    {
        $answer = $request->validate(['answer' => ['required', 'string', 'max:2000']])['answer'];

        return $this->attempt(fn () => tap(back()->with('success', 'Answer sent to the owner.'), fn () => $this->governance->respond($request->user(), $this->find($deletionRequest), $answer)));
    }

    public function cancel(Request $request, int $deletionRequest): RedirectResponse
    {
        return $this->attempt(fn () => tap(back()->with('success', 'Request withdrawn.'), fn () => $this->governance->cancel($request->user(), $this->find($deletionRequest))));
    }

    /**
     * @return array{action: string, target_id: int, reason: string, note?: ?string, payload?: ?array<string, mixed>}
     */
    private function validateAction(Request $request): array
    {
        return $request->validate([
            'action' => ['required', Rule::in(array_keys($this->governance->actions()))],
            'target_id' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
            'note' => ['nullable', 'string', 'max:2000'],
            // Action-specific input (e.g. a merge's survivor), validated by the action itself.
            'payload' => ['nullable', 'array', 'max:10'],
        ], ['reason.min' => 'Give a reason of at least 10 characters.']);
    }

    private function find(int $id): DeletionRequest
    {
        return DeletionRequest::query()->where('clinic_id', $this->clinicContext->clinic()?->id)->findOrFail($id);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(DeletionRequest $item, int $userId, bool $owner): array
    {
        $mine = $item->requester_user_id === $userId;

        return [
            'id' => $item->id,
            'action' => $item->action_type,
            'action_label' => ($this->governance->actions()[$item->action_type] ?? null)?->label() ?? $item->action_type,
            'target' => $item->target_label,
            'patient' => $item->patient ? ['id' => $item->patient->id, 'name' => $item->patient->full_name, 'uhid' => $item->patient->uhid, 'url' => route('clinic.patients.show', $item->patient->id)] : null,
            'requester' => $item->requester?->name,
            'approver' => $item->approver?->name,
            'reason' => $item->reason,
            'note' => $item->note,
            'payload' => $item->payload,
            'status' => $item->isExpired() ? DeletionRequest::EXPIRED : $item->status,
            'direct' => $item->direct,
            'thread' => $item->thread ?? [],
            'created_at' => $item->created_at?->toIso8601String(),
            'expires_at' => $item->expires_at?->toIso8601String(),
            'decided_at' => ($item->executed_at ?? $item->rejected_at ?? $item->cancelled_at)?->toIso8601String(),
            'can' => [
                'decide' => $owner && $item->isOpen() && ! $item->isExpired(),
                'respond' => $mine && $item->status === DeletionRequest::CLARIFICATION,
                'cancel' => $mine && $item->isOpen(),
            ],
        ];
    }

    private function attempt(Closure $action): RedirectResponse
    {
        try {
            return $action();
        } catch (DomainException|InvalidArgumentException $e) {
            return back()->withErrors(['governance' => $e->getMessage()]);
        }
    }
}
