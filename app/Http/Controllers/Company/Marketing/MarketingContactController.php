<?php

namespace App\Http\Controllers\Company\Marketing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Marketing\StoreContactRequest;
use App\Http\Requests\Marketing\UpdateContactRequest;
use App\Models\MarketingContact;
use App\Models\MarketingContactImport;
use App\Models\MarketingTag;
use App\Services\Marketing\ContactWorkspaceCounts;
use App\Services\Marketing\PhoneNormalizer;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class MarketingContactController extends Controller
{
    /**
     * Display Marketing Contacts directory workspace.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();
        $orgId = $user?->organization_id;

        $search = $request->input('search');
        $selectedTags = $request->input('tags');
        $selectedCity = $request->input('city');
        $selectedState = $request->input('state');
        $selectedArea = $request->input('area');
        $selectedStatus = $request->input('status');
        $selectedImportBatch = $request->input('import_batch');
        $selectedOrganization = $request->input('organization');
        $importedFrom = $request->input('imported_from');
        $importedTo = $request->input('imported_to');

        /*
         * One timezone decides both what "added on the 24th" filters for and
         * what date each row is stamped with. Left to the browser, the two
         * would disagree for anyone whose local day differs from the stored
         * UTC one, and a shared filter URL would mean different things to
         * different people.
         */
        $timezone = $user?->organization?->timezone ?: MarketingContact::BUSINESS_TIMEZONE;

        $sortBy = $request->input('sort_by', 'created_at');
        $sortDir = strtolower($request->input('sort_dir', 'desc')) === 'asc' ? 'asc' : 'desc';

        // Validate allowed sort fields
        $allowedSorts = ['name', 'organization', 'city', 'state', 'created_at', 'marketing_status'];
        if (! in_array($sortBy, $allowedSorts)) {
            $sortBy = 'created_at';
        }

        $query = MarketingContact::query()
            ->withLastCampaignStatus()
            ->with(['tags', 'importBatch'])
            ->when($orgId, fn ($q) => $q->where('marketing_contacts.organization_id', $orgId))
            ->search($search)
            ->filterStatus($selectedStatus)
            ->filterCity($selectedCity)
            ->filterState($selectedState)
            ->filterArea($selectedArea)
            ->filterTags($selectedTags)
            ->filterImportBatch($selectedImportBatch)
            ->filterOrganization($selectedOrganization)
            ->filterImportedBetween($importedFrom, $importedTo, $timezone)
            ->orderBy($sortBy, $sortDir);

        $contacts = $query->paginate(25)->withQueryString();

        // Unique states for filter dropdown
        $states = MarketingContact::query()
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->whereNotNull('state')
            ->where('state', '!=', '')
            ->distinct()
            ->orderBy('state')
            ->pluck('state');

        // Unique cities for filter dropdown (scoped by selected state if any)
        $cities = MarketingContact::query()
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->whereNotNull('city')
            ->where('city', '!=', '')
            ->when($selectedState && $selectedState !== 'all', fn ($q) => $q->where('state', $selectedState))
            ->distinct()
            ->orderBy('city')
            ->pluck('city');

        // Unique areas (scoped by selected city if any)
        /*
         * Areas narrow by state as well as by city.
         *
         * Scoping only by city meant picking a state left every area in the
         * country on offer — so choosing "Gujarat" then an area from Rajasthan
         * produced an empty list with no explanation. The hierarchy has to hold
         * at every level, not just the last one.
         */
        $areas = MarketingContact::query()
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->whereNotNull('area')
            ->where('area', '!=', '')
            ->when($selectedState && $selectedState !== 'all', fn ($q) => $q->where('state', $selectedState))
            ->when($selectedCity && $selectedCity !== 'all', fn ($q) => $q->where('city', $selectedCity))
            ->distinct()
            ->orderBy('area')
            ->pluck('area');

        // All available tags for tenant
        $tags = MarketingTag::query()
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'color']);

        // Import batches for filter dropdown
        $importBatches = MarketingContactImport::query()
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->where('status', 'completed')
            ->latest()
            ->get(['id', 'batch_name', 'original_filename', 'imported_count', 'created_at']);

        /*
         * Where everyone currently stands.
         *
         * Each figure is produced by running the *same* `filterStatus` scope
         * the list itself uses, rather than by a parallel set of hand-written
         * counts. That is the point: a headline saying "412 never contacted"
         * above a list that returns 380 when you click it is the defect this
         * arrangement makes impossible.
         */
        /*
         * The counts arrive a beat after the list.
         *
         * They are the most expensive thing on the page — one grouped pass that
         * resolves every contact's latest campaign status — and they are also
         * the least urgent: nobody is blocked on "how many replied" while the
         * rows they asked for are still not on screen. Deferring them takes
         * roughly four hundred milliseconds off the wait before the list
         * appears at ten thousand contacts. The buttons render immediately with
         * a pulse where the number will land.
         */
        $lifecycle = Inertia::defer(fn () => MarketingContact::lifecycleCounts(
            MarketingContact::query()
                ->when($orgId, fn ($q) => $q->where('marketing_contacts.organization_id', $orgId))
                /*
                 * Every filter *except* status, so the strip always describes
                 * the list underneath it.
                 */
                ->search($search)
                ->filterCity($selectedCity)
                ->filterState($selectedState)
                ->filterArea($selectedArea)
                ->filterTags($selectedTags)
                ->filterImportBatch($selectedImportBatch)
                ->filterOrganization($selectedOrganization)
                ->filterImportedBetween($importedFrom, $importedTo, $timezone)
        ));

        // Summary metrics
        $metrics = [
            'total_contacts' => MarketingContact::query()->when($orgId, fn ($q) => $q->where('organization_id', $orgId))->count(),
            'active_contacts' => MarketingContact::query()->when($orgId, fn ($q) => $q->where('organization_id', $orgId))->where('marketing_status', 'active')->count(),
            'opted_out_contacts' => MarketingContact::query()->when($orgId, fn ($q) => $q->where('organization_id', $orgId))->where('marketing_status', 'opted_out')->count(),
            'suppressed_contacts' => MarketingContact::query()->when($orgId, fn ($q) => $q->where('organization_id', $orgId))->where('marketing_status', 'suppressed')->count(),
        ];

        return Inertia::render('Company/Marketing/Contacts/Index', [
            'contacts' => $contacts,
            'filters' => [
                'search' => $search ?? '',
                'tags' => $selectedTags ? (is_array($selectedTags) ? $selectedTags : explode(',', $selectedTags)) : [],
                'city' => $selectedCity ?? 'all',
                'state' => $selectedState ?? 'all',
                'area' => $selectedArea ?? 'all',
                'status' => $selectedStatus ?? 'all',
                'import_batch' => $selectedImportBatch ?? 'all',
                'organization' => $selectedOrganization ?? '',
                'imported_from' => $importedFrom ?? '',
                'imported_to' => $importedTo ?? '',
                'sort_by' => $sortBy,
                'sort_dir' => $sortDir,
            ],
            'states' => $states,
            'cities' => $cities,
            'areas' => $areas,
            'tags' => $tags,
            'importBatches' => $importBatches,
            'metrics' => $metrics,
            'lifecycle' => $lifecycle,
            'timezone' => $timezone,
            'workspaceCounts' => ContactWorkspaceCounts::for($orgId),
        ]);
    }

    /**
     * Store new Marketing Contact.
     */
    public function store(StoreContactRequest $request)
    {
        $user = $request->user();
        $orgId = $user?->organization_id;
        $validated = $request->validated();

        $normalizedPhone = PhoneNormalizer::normalize($validated['phone']);

        $contact = MarketingContact::create([
            'organization_id' => $orgId,
            'name' => trim($validated['name']),
            'phone' => trim($validated['phone']),
            'normalized_phone' => $normalizedPhone,
            'organization' => ! empty($validated['organization']) ? trim($validated['organization']) : null,
            'city' => ! empty($validated['city']) ? trim($validated['city']) : null,
            'marketing_status' => $validated['marketing_status'] ?? 'active',
            'created_by_user_id' => $user?->id,
        ]);

        if (! empty($validated['tags'])) {
            $tagIds = $this->syncTagNames($validated['tags'], $orgId);
            $contact->tags()->sync($tagIds);
        }

        return redirect()->back()->with('success', 'Marketing contact created successfully.');
    }

    /**
     * Display single Marketing Contact details & activity log.
     */
    public function show(Request $request, int $id): Response
    {
        $user = $request->user();
        $orgId = $user?->organization_id;

        $contact = MarketingContact::query()
            ->with(['tags', 'creator'])
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->findOrFail($id);

        // Activity timeline foundation
        $activities = [
            [
                'id' => 'act-1',
                'title' => 'Contact Created',
                'description' => "Contact {$contact->name} added to marketing directory.",
                'user' => $contact->creator?->name ?? 'System Admin',
                'timestamp' => $contact->created_at->format('d M Y, h:i A'),
                'type' => 'creation',
            ],
        ];

        if ($contact->updated_at->gt($contact->created_at)) {
            $activities[] = [
                'id' => 'act-2',
                'title' => 'Contact Updated',
                'description' => 'Contact details or tags were modified.',
                'user' => 'System Admin',
                'timestamp' => $contact->updated_at->format('d M Y, h:i A'),
                'type' => 'update',
            ];
        }

        $tags = MarketingTag::query()
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'color']);

        return Inertia::render('Company/Marketing/Contacts/Show', [
            'contact' => $contact,
            'activities' => $activities,
            'tags' => $tags,
        ]);
    }

    /**
     * Update existing Marketing Contact.
     */
    public function update(UpdateContactRequest $request, int $id)
    {
        $user = $request->user();
        $orgId = $user?->organization_id;

        $contact = MarketingContact::query()
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->findOrFail($id);

        $validated = $request->validated();
        $normalizedPhone = PhoneNormalizer::normalize($validated['phone']);

        $contact->update([
            'name' => trim($validated['name']),
            'phone' => trim($validated['phone']),
            'normalized_phone' => $normalizedPhone,
            'organization' => ! empty($validated['organization']) ? trim($validated['organization']) : null,
            'city' => ! empty($validated['city']) ? trim($validated['city']) : null,
            'marketing_status' => $validated['marketing_status'],
        ]);

        if (array_key_exists('tags', $validated)) {
            $tagIds = $this->syncTagNames($validated['tags'] ?? [], $orgId);
            $contact->tags()->sync($tagIds);
        }

        return redirect()->back()->with('success', 'Marketing contact updated successfully.');
    }

    /**
     * Soft delete Marketing Contact.
     */
    public function destroy(Request $request, int $id)
    {
        $user = $request->user();
        $orgId = $user?->organization_id;

        $contact = MarketingContact::query()
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->findOrFail($id);

        $contact->delete();

        return redirect()->route('company.marketing.contacts.index')->with('success', 'Contact deleted successfully.');
    }

    /**
     * Helper to resolve/create tags by name or ID.
     */
    protected function syncTagNames(array $tags, ?int $orgId = null): array
    {
        $tagIds = [];

        foreach ($tags as $tagInput) {
            $cleanName = trim((string) $tagInput);
            if (empty($cleanName)) {
                continue;
            }

            if (is_numeric($cleanName)) {
                $tagIds[] = (int) $cleanName;

                continue;
            }

            $slug = Str::slug($cleanName);
            $tag = MarketingTag::firstOrCreate(
                [
                    'organization_id' => $orgId,
                    'slug' => $slug,
                ],
                [
                    'name' => $cleanName,
                    'color' => 'neutral',
                ]
            );

            $tagIds[] = $tag->id;
        }

        return array_unique($tagIds);
    }

    /**
     * Bulk apply or remove tag from selected contacts.
     */
    public function bulkTag(Request $request)
    {
        $user = $request->user();
        $orgId = $user->resolveOrganizationId();

        $validated = $request->validate([
            'contact_ids' => 'required|array|min:1',
            'contact_ids.*' => 'required|integer',
            'tag_name' => 'required|string|max:50',
            'action' => 'required|in:add,remove',
        ]);

        $contacts = MarketingContact::query()
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->whereIn('id', $validated['contact_ids'])
            ->get();

        if ($contacts->isEmpty()) {
            return back()->with('error', 'No valid contacts selected for tag operation.');
        }

        $tagName = trim($validated['tag_name']);
        $tag = MarketingTag::firstOrCreate(
            ['organization_id' => $orgId, 'name' => $tagName],
            ['slug' => Str::slug($tagName), 'color' => '#00A8B5']
        );

        $count = 0;
        foreach ($contacts as $contact) {
            if ($validated['action'] === 'add') {
                $contact->tags()->syncWithoutDetaching([$tag->id]);
            } else {
                $contact->tags()->detach($tag->id);
            }
            $count++;
        }

        $actionWord = $validated['action'] === 'add' ? 'added to' : 'removed from';

        return back()->with('success', "Tag '{$tagName}' {$actionWord} {$count} contacts.");
    }
}
