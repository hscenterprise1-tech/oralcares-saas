<?php

namespace App\Http\Controllers\Company\Marketing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Marketing\StoreAudienceRequest;
use App\Http\Requests\Marketing\UpdateAudienceRequest;
use App\Models\MarketingAudience;
use App\Models\MarketingCampaign;
use App\Models\MarketingContact;
use App\Models\MarketingContactImport;
use App\Models\MarketingTag;
use App\Services\Marketing\AudienceQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Segments — reusable targeting definitions, not a second contact database.
 *
 * Every page here answers a different question than Marketing Contacts does.
 * Contacts is where someone works a list; this is where someone names a slice
 * of it once so a campaign can point at it again next month without
 * rebuilding the same filters. The rules a Segment stores are the same shape
 * Contacts already filters by, resolved through the same scopes — see
 * `AudienceQueryService` for where that actually happens.
 */
class MarketingAudienceController extends Controller
{
    public function __construct(
        protected AudienceQueryService $audienceService
    ) {}

    /**
     * The Segments directory.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();
        $orgId = $user?->organization_id;
        $timezone = MarketingContact::resolveBusinessTimezone($user?->organization);

        $search = $request->input('search');
        $status = $request->input('status');

        $audiences = MarketingAudience::query()
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->search($search)
            ->filterStatus($status)
            ->withCount('campaigns')
            ->latest('updated_at')
            ->paginate(15)
            ->withQueryString();

        /*
         * "Last used" for every Segment on the page in one query rather than
         * one per row — a per-row `campaigns()->latest()->first()` would be
         * exactly the N+1 shape this rebuild exists to remove.
         */
        $lastUsedAt = $this->lastUsedAtByAudience($audiences->pluck('id'));

        $transformed = $audiences->through(function (MarketingAudience $audience) use ($orgId, $timezone, $lastUsedAt) {
            return [
                ...$audience->toArray(),
                /*
                 * One COUNT query per row is the one cost that cannot be
                 * batched away here — each Segment is a distinct WHERE clause,
                 * not a shared predicate a single query could group by. At the
                 * scale this module is for (tens of reusable Segments, not
                 * thousands), that is the acceptable side of "always live,
                 * never stale, never cached."
                 */
                'match_count' => $this->audienceService->matchCount($audience->rules ?? [], $orgId, $timezone),
                'campaigns_count' => $audience->campaigns_count,
                'last_used_at' => $lastUsedAt[$audience->id] ?? null,
            ];
        });

        $totalAudiences = MarketingAudience::query()->when($orgId, fn ($q) => $q->where('organization_id', $orgId))->count();
        $activeAudiences = MarketingAudience::query()->when($orgId, fn ($q) => $q->where('organization_id', $orgId))->where('status', 'active')->count();

        return Inertia::render('Company/Marketing/Audiences/Index', [
            'audiences' => $transformed,
            'filters' => [
                'search' => $search ?? '',
                'status' => $status ?? 'all',
            ],
            'metrics' => [
                'total_audiences' => $totalAudiences,
                'active_audiences' => $activeAudiences,
            ],
        ]);
    }

    /**
     * The Segment builder, for a new Segment.
     */
    public function create(Request $request): Response
    {
        $orgId = $request->user()?->organization_id;

        return Inertia::render('Company/Marketing/Audiences/Builder', [
            'audience' => null,
            ...$this->builderOptions($orgId, null, null),
        ]);
    }

    /**
     * Save a new Segment.
     */
    public function store(StoreAudienceRequest $request)
    {
        $user = $request->user();
        $orgId = $user?->organization_id;
        $validated = $request->validated();

        $audience = MarketingAudience::create([
            'organization_id' => $orgId,
            'name' => trim($validated['name']),
            'description' => ! empty($validated['description']) ? trim($validated['description']) : null,
            'rules' => $this->audienceService->sanitizeRules($this->cleanRules($validated['rules'] ?? [])),
            'status' => $validated['status'] ?? 'active',
            'created_by_user_id' => $user?->id,
            'updated_by_user_id' => $user?->id,
        ]);

        return redirect()->route('company.marketing.audiences.show', $audience->id)
            ->with('success', 'Segment created.');
    }

    /**
     * The Segment detail page — what it is, what it matches right now, and
     * where it has been used.
     */
    public function show(Request $request, int $id): Response
    {
        $user = $request->user();
        $orgId = $user?->organization_id;
        $timezone = MarketingContact::resolveBusinessTimezone($user?->organization);

        $audience = MarketingAudience::query()
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->withCount('campaigns')
            ->findOrFail($id);

        $rules = $audience->rules ?? [];

        $campaigns = $audience->campaigns()
            ->select(['id', 'name', 'status', 'created_at'])
            ->latest()
            ->get();

        return Inertia::render('Company/Marketing/Audiences/Show', [
            'audience' => [
                ...$audience->toArray(),
                'match_count' => $this->audienceService->matchCount($rules, $orgId, $timezone),
            ],
            'campaigns' => $campaigns,
            'preview' => $this->audienceService->previewContacts($rules, $orgId, 5, $timezone),
        ]);
    }

    /**
     * The Segment builder, editing an existing Segment.
     */
    public function edit(Request $request, int $id): Response
    {
        $orgId = $request->user()?->organization_id;

        $audience = MarketingAudience::query()
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->findOrFail($id);

        $rules = $audience->rules ?? [];

        return Inertia::render('Company/Marketing/Audiences/Builder', [
            'audience' => $audience,
            ...$this->builderOptions($orgId, $rules['state'] ?? null, $rules['city'] ?? null),
        ]);
    }

    /**
     * Update an existing Segment's rules.
     */
    public function update(UpdateAudienceRequest $request, int $id)
    {
        $user = $request->user();
        $orgId = $user?->organization_id;

        $audience = MarketingAudience::query()
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->findOrFail($id);

        $validated = $request->validated();

        $audience->update([
            'name' => trim($validated['name']),
            'description' => ! empty($validated['description']) ? trim($validated['description']) : null,
            'rules' => $this->audienceService->sanitizeRules($this->cleanRules($validated['rules'] ?? [])),
            'status' => $validated['status'],
            'updated_by_user_id' => $user?->id,
        ]);

        return redirect()->route('company.marketing.audiences.show', $audience->id)
            ->with('success', 'Segment updated.');
    }

    /**
     * Clone a Segment's rules under a new name, paused, so it can be adjusted
     * before it targets anyone.
     */
    public function duplicate(Request $request, int $id)
    {
        $user = $request->user();
        $orgId = $user?->organization_id;

        $original = MarketingAudience::query()
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->findOrFail($id);

        MarketingAudience::create([
            'organization_id' => $orgId,
            'name' => "{$original->name} (Copy)",
            'description' => $original->description,
            'rules' => $original->rules,
            'status' => 'paused',
            'created_by_user_id' => $user?->id,
            'updated_by_user_id' => $user?->id,
        ]);

        return redirect()->route('company.marketing.audiences.index')
            ->with('success', 'Segment duplicated.');
    }

    /**
     * Soft delete a Segment. Never touches a contact, never touches a
     * campaign that already used it — only the reusable definition goes.
     */
    public function destroy(Request $request, int $id)
    {
        $orgId = $request->user()?->organization_id;

        $audience = MarketingAudience::query()
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->findOrFail($id);

        $audience->delete();

        return redirect()->route('company.marketing.audiences.index')
            ->with('success', 'Segment deleted.');
    }

    /**
     * Live matching count, cascaded location options, and a small sample —
     * everything the builder needs to redraw itself as a rule changes,
     * without a full page reload and without ever sending contact rows down
     * in bulk.
     */
    public function previewCount(Request $request): JsonResponse
    {
        $user = $request->user();
        $orgId = $user?->organization_id;
        $timezone = MarketingContact::resolveBusinessTimezone($user?->organization);
        $rules = $this->audienceService->sanitizeRules((array) $request->input('rules', []));

        $locations = $this->audienceService->cascadedLocationOptions(
            $orgId,
            $rules['state'] ?? null,
            $rules['city'] ?? null,
        );

        return response()->json([
            'count' => $this->audienceService->matchCount($rules, $orgId, $timezone),
            'preview' => $this->audienceService->previewContacts($rules, $orgId, 3, $timezone),
            'cities' => $locations['cities'],
            'areas' => $locations['areas'],
        ]);
    }

    /**
     * The options the builder's dropdowns are made of: states (unfiltered —
     * there are only ever a handful), tags, completed import batches, and
     * cities/areas already cascaded to whatever state/city the Segment
     * currently has selected.
     */
    protected function builderOptions(?int $orgId, ?string $state, ?string $city): array
    {
        $states = MarketingContact::query()
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->whereNotNull('state')
            ->where('state', '!=', '')
            ->distinct()
            ->orderBy('state')
            ->pluck('state');

        $locations = $this->audienceService->cascadedLocationOptions($orgId, $state, $city);

        $tags = MarketingTag::query()
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'color']);

        $importBatches = MarketingContactImport::query()
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->where('status', 'completed')
            ->latest()
            ->get(['id', 'batch_name', 'original_filename', 'imported_count', 'created_at']);

        return [
            'states' => $states,
            'cities' => $locations['cities'],
            'areas' => $locations['areas'],
            'tags' => $tags,
            'importBatches' => $importBatches,
        ];
    }

    /**
     * When each Segment on the current page was last used by a campaign — one
     * grouped query for the whole page rather than one per row.
     *
     * @param  Collection<int, int>  $audienceIds
     * @return array<int, string>
     */
    protected function lastUsedAtByAudience($audienceIds): array
    {
        if ($audienceIds->isEmpty()) {
            return [];
        }

        return MarketingCampaign::query()
            ->whereIn('audience_id', $audienceIds)
            ->selectRaw('audience_id, MAX(created_at) as last_used_at')
            ->groupBy('audience_id')
            ->pluck('last_used_at', 'audience_id')
            ->all();
    }

    /**
     * Drop empty/"all" values before saving, so a Segment's stored rules only
     * ever list the constraints actually narrowing it — the same convention
     * Contacts' own filter state already keeps.
     */
    protected function cleanRules(array $rules): array
    {
        foreach ($rules as $key => $value) {
            $isEmptyTags = $key === 'tags' && is_array($value) && count(array_filter($value)) === 0;

            if ($value === null || $value === '' || $value === 'all' || $isEmptyTags) {
                unset($rules[$key]);
            }
        }

        return $rules;
    }
}
