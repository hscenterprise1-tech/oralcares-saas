<?php

namespace App\Http\Controllers\Clinic;

use App\ClinicPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Clinic\Consultation\PlaceOrderRequest;
use App\Http\Requests\Clinic\Consultation\RecordResultRequest;
use App\Models\AttachedDocument;
use App\Models\ClinicalEncounter;
use App\Models\ClinicalOrder;
use App\Models\ClinicalOrderItem;
use App\Models\ClinicInvestigation;
use App\Services\ClinicalOrderService;
use App\Services\ClinicContext;
use Closure;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * Investigations: ordering from a consultation, the branch worklist, and
 * one order's fulfilment, results and review.
 *
 * Routes sit behind `clinic_permission:clinical.view` (writes also
 * `clinical.edit`); each action is authorised on the order by
 * `ClinicalOrderPolicy`. Every write goes through `ClinicalOrderService`.
 */
class InvestigationController extends Controller
{
    /** Worklist tabs and the item statuses each shows. */
    private const TABS = [
        'pending' => [ClinicalOrderItem::ORDERED, ClinicalOrderItem::COLLECTED],
        'review' => [ClinicalOrderItem::RESULTED],
        'done' => [ClinicalOrderItem::REVIEWED, ClinicalOrderItem::CANCELLED],
    ];

    public function __construct(
        protected ClinicContext $clinicContext,
        protected ClinicalOrderService $orders,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', ClinicalOrder::class);
        $clinic = $this->clinicContext->clinic() ?? abort(403);
        $branch = $this->clinicContext->branchScopeOrAbort();
        $filters = $request->validate([
            'tab' => ['nullable', Rule::in(array_keys(self::TABS))],
            'category' => ['nullable', Rule::in(array_keys(ClinicalOrderItem::CATEGORIES))],
        ]);
        $tab = $filters['tab'] ?? 'pending';
        $category = $filters['category'] ?? null;

        $base = fn () => ClinicalOrderItem::query()
            ->where('clinical_order_items.clinic_id', $clinic->id)
            ->whereHas('order', fn (Builder $order) => $order->when($branch, fn (Builder $query) => $query->where('branch_id', $branch->id)))
            ->when($category, fn (Builder $query) => $query->where('category', $category));

        $items = $base()
            ->whereIn('status', self::TABS[$tab])
            ->when($tab === 'done', fn (Builder $query) => $query->where('updated_at', '>=', now()->subDays(14)))
            ->with(['order' => fn ($query) => $query->with(['patient' => fn ($patient) => $patient->withoutGlobalScopes(), 'orderingDoctor:id,name']), 'result:id,clinical_order_item_id,is_abnormal'])
            ->orderByRaw("case when exists (select 1 from clinical_orders o where o.id = clinical_order_items.clinical_order_id and o.priority in ('stat', 'urgent')) then 0 else 1 end")
            ->orderBy($tab === 'done' ? 'updated_at' : 'created_at', $tab === 'done' ? 'desc' : 'asc')
            ->limit(100)
            ->get();

        return Inertia::render('ClinicPortal/Orders/Index', [
            'branch' => $branch ? ['id' => $branch->id, 'name' => $branch->name] : null,
            'tab' => $tab,
            'category' => $category,
            'counts' => collect(self::TABS)->except('done')->map(fn (array $statuses) => $base()->whereIn('status', $statuses)->count()),
            'categories' => collect(ClinicalOrderItem::CATEGORIES)->map(fn (array $c) => $c['label']),
            'items' => $items->map(fn (ClinicalOrderItem $item) => [
                'id' => $item->id,
                'name' => $item->item_name,
                'category' => $item->category,
                'status' => $item->status,
                'body_site' => $item->body_site,
                'is_abnormal' => (bool) $item->result?->is_abnormal,
                'ordered_at' => $item->created_at?->toIso8601String(),
                'order' => [
                    'number' => $item->order->order_number,
                    'priority' => $item->order->priority,
                    'doctor' => $item->order->orderingDoctor?->name,
                    'url' => route('clinic.orders.show', $item->clinical_order_id),
                ],
                'patient' => $item->order->patient ? ['name' => $item->order->patient->short_name, 'uhid' => $item->order->patient->uhid] : null,
            ])->values(),
            'links' => ['index' => route('clinic.orders.index')],
        ]);
    }

    public function show(ClinicalOrder $order): Response
    {
        $this->authorize('view', $order);

        $order->load([
            'patient' => fn ($query) => $query->withoutGlobalScopes(),
            'orderingDoctor:id,name', 'branch:id,name',
            'items' => fn ($query) => $query->orderBy('id')->with([
                'result.reportingStaff:id,name', 'result.reviewingClinician:id,name',
                'documents', 'collector:id,name', 'reviewer:id,name',
            ]),
        ]);
        $user = request()->user();
        $encounter = $order->encounter_id ? ClinicalEncounter::query()->find($order->encounter_id) : null;

        return Inertia::render('ClinicPortal/Orders/Show', [
            'order' => [
                'id' => $order->id,
                'number' => $order->order_number,
                'status' => $order->status,
                'priority' => $order->priority,
                'instructions' => $order->clinical_instructions,
                'ordered_at' => $order->created_at?->toIso8601String(),
                'doctor' => $order->orderingDoctor?->name,
                'branch' => $order->branch?->name,
                'consultation_url' => $encounter && $user->can('view', $encounter) ? route('clinic.consultations.show', $encounter) : null,
            ],
            'patient' => [
                'name' => $order->patient->full_name,
                'uhid' => $order->patient->uhid,
                'url' => route('clinic.patients.show', $order->patient_id),
            ],
            'items' => $order->items->map(fn (ClinicalOrderItem $item) => [
                'id' => $item->id,
                'name' => $item->item_name,
                'code' => $item->item_code,
                'category' => $item->category,
                'category_label' => ClinicalOrderItem::CATEGORIES[$item->category]['label'] ?? $item->category,
                'collect_label' => ClinicalOrderItem::CATEGORIES[$item->category]['collect'] ?? 'Collected',
                'status' => $item->status,
                'body_site' => $item->body_site,
                'external_provider' => $item->external_provider,
                'notes' => $item->notes,
                'collected' => $item->collected_at ? ['at' => $item->collected_at->toIso8601String(), 'by' => $item->collector?->name] : null,
                'reviewed' => $item->reviewed_at ? ['at' => $item->reviewed_at->toIso8601String(), 'by' => $item->reviewer?->name] : null,
                'cancellation_reason' => $item->cancellation_reason,
                'result' => $item->result ? [
                    'status' => $item->result->status,
                    'summary' => $item->result->summary,
                    'values' => $item->result->content['values'] ?? [],
                    'is_abnormal' => $item->result->is_abnormal,
                    'reported_at' => $item->result->reported_at?->toIso8601String(),
                    'reported_by' => $item->result->reportingStaff?->name,
                    'review_note' => $item->result->review_note,
                ] : null,
                'documents' => $item->documents->map(fn (AttachedDocument $doc) => [
                    'id' => $doc->id, 'name' => $doc->file_name,
                    'url' => route('clinic.patients.documents.download', [$order->patient_id, $doc->id]),
                ])->values(),
                'links' => [
                    'collect' => route('clinic.orders.items.collect', [$order, $item]),
                    'result' => route('clinic.orders.items.result', [$order, $item]),
                    'review' => route('clinic.orders.items.review', [$order, $item]),
                    'cancel' => route('clinic.orders.items.cancel', [$order, $item]),
                ],
            ])->values(),
            'links' => [
                'index' => route('clinic.orders.index'),
                'upload' => route('clinic.patients.documents.store', $order->patient_id),
            ],
            'can' => [
                'fulfil' => $user->can('fulfil', $order),
                'review' => $user->can('review', $order),
                'upload' => $user->can('fulfil', $order) && $user->hasClinicPermission(ClinicPermission::DocumentsManage),
            ],
        ]);
    }

    public function store(PlaceOrderRequest $request, ClinicalEncounter $encounter): RedirectResponse
    {
        $this->authorize('place', [ClinicalOrder::class, $encounter]);

        return $this->attempt(function () use ($request, $encounter) {
            $order = $this->orders->placeOrder(
                $encounter,
                $request->safe()->only(['priority', 'clinical_instructions']),
                $request->validated('items'),
                $this->clinicContext->currentEmployee(),
            );

            return back()->with('success', "Order {$order->order_number} placed.");
        });
    }

    public function collect(Request $request, ClinicalOrder $order, ClinicalOrderItem $item): RedirectResponse
    {
        $this->authorize('fulfil', $order);
        $validated = $request->validate(['note' => ['nullable', 'string', 'max:500']]);

        return $this->attempt(function () use ($item, $validated) {
            $this->orders->markCollected($item, $this->clinicContext->currentEmployee(), $validated['note'] ?? null);

            return back()->with('success', "{$item->item_name} marked ".Str::lower(ClinicalOrderItem::CATEGORIES[$item->category]['collect'] ?? 'collected').'.');
        });
    }

    public function result(RecordResultRequest $request, ClinicalOrder $order, ClinicalOrderItem $item): RedirectResponse
    {
        $this->authorize('fulfil', $order);

        return $this->attempt(function () use ($request, $item) {
            $this->orders->recordResult($item, $this->clinicContext->currentEmployee(), $request->validated());

            return back()->with('success', "Result for {$item->item_name} saved. It is ready for review.");
        });
    }

    public function review(Request $request, ClinicalOrder $order, ClinicalOrderItem $item): RedirectResponse
    {
        $this->authorize('review', $order);
        $validated = $request->validate(['note' => ['nullable', 'string', 'max:2000']]);

        return $this->attempt(function () use ($item, $validated) {
            $this->orders->review($item, $this->clinicContext->currentEmployee(), $validated['note'] ?? null);

            return back()->with('success', "{$item->item_name} reviewed.");
        });
    }

    public function cancel(Request $request, ClinicalOrder $order, ClinicalOrderItem $item): RedirectResponse
    {
        $this->authorize('fulfil', $order);
        $validated = $request->validate(['reason' => ['required', 'string', 'max:255']], ['reason.required' => 'Say why the investigation is cancelled.']);

        return $this->attempt(function () use ($item, $validated) {
            $this->orders->cancelItem($item, $this->clinicContext->currentEmployee(), $validated['reason']);

            return back()->with('success', "{$item->item_name} cancelled.");
        });
    }

    /**
     * The clinic's investigation list, most-ordered first, for the picker.
     */
    public function catalogue(Request $request): JsonResponse
    {
        $clinic = $this->clinicContext->clinic() ?? abort(403);
        $term = Str::limit(trim((string) $request->query('q', '')), 60, '');

        return response()->json(['data' => ClinicInvestigation::query()
            ->where('clinic_id', $clinic->id)
            ->where('is_active', true)
            ->when($term !== '', fn (Builder $query) => $query->where(fn (Builder $match) => $match
                ->where('name', 'like', $term.'%')->orWhere('name', 'like', '% '.$term.'%')->orWhere('code', 'like', $term.'%')))
            ->orderByDesc('use_count')->orderBy('name')
            ->limit(15)
            ->get(['id', 'name', 'code', 'category'])]);
    }

    /**
     * @param  Closure(): RedirectResponse  $action
     */
    private function attempt(Closure $action): RedirectResponse
    {
        try {
            return $action();
        } catch (DomainException|InvalidArgumentException $e) {
            return back()->withErrors(['investigation' => $e->getMessage()]);
        }
    }
}
