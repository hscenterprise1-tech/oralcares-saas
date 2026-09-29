<?php

namespace App\Http\Controllers\Company\Marketing;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateInstagramPostJob;
use App\Jobs\PublishInstagramPostJob;
use App\Models\MarketingInstagramAccount;
use App\Models\MarketingInstagramPost;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class MarketingInstagramPostController extends Controller
{
    /**
     * Which post statuses belong to each content-pipeline tab.
     *
     * `generating` sits with `pending_approval` — it isn't actionable yet,
     * but it's still "on its way to needing a decision," not history.
     * `publishing` sits with the monitoring tab — it's a few seconds from
     * `published` and nothing the user does affects it, same as `approved`
     * and `scheduled` already waiting their turn.
     *
     * @var array<string, list<string>>
     */
    protected const TAB_STATUSES = [
        'needs_review' => ['generating', 'pending_approval'],
        'approved_scheduled' => ['approved', 'scheduled', 'publishing'],
        'history' => ['published', 'failed', 'rejected'],
    ];

    /**
     * Display the Instagram content queue: AI-drafted posts awaiting review, scheduled, and published.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();
        $orgId = $user?->organization_id;

        $tab = array_key_exists($request->input('tab'), self::TAB_STATUSES)
            ? $request->input('tab')
            : 'needs_review';

        $posts = MarketingInstagramPost::query()
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->whereIn('status', self::TAB_STATUSES[$tab])
            ->with('approvedBy:id,name')
            ->latest()
            ->paginate(20)
            ->withQueryString();

        $account = MarketingInstagramAccount::query()
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->first();

        // One grouped query for every pipeline count — never derived from
        // the current page's rows, so the headline numbers are the same
        // whether you're looking at page 1 or page 6 of any tab.
        $statusCounts = MarketingInstagramPost::query()
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $countFor = fn (array $statuses) => (int) collect($statuses)
            ->sum(fn ($status) => (int) ($statusCounts[$status] ?? 0));

        $counts = [
            'needs_review' => $countFor(self::TAB_STATUSES['needs_review']),
            'approved_scheduled' => $countFor(self::TAB_STATUSES['approved_scheduled']),
            'history' => $countFor(self::TAB_STATUSES['history']),
        ];

        return Inertia::render('Company/Marketing/Instagram/Index', [
            'posts' => $posts,
            'tab' => $tab,
            'counts' => $counts,
            'account' => $account ? [
                'username' => $account->username,
                'instagram_business_account_id' => $account->instagram_business_account_id,
                'logo_url' => $account->logo_path ? Storage::disk('public')->url($account->logo_path) : null,
                'status' => $account->status,
            ] : null,
            'hasConnectedAccount' => $account?->status === 'connected',
            'autoFillEnabled' => $account?->auto_fill_enabled ?? false,
            'bufferStatus' => [
                'ready' => $counts['needs_review'] + $counts['approved_scheduled'],
                'target' => $account?->buffer_target ?? 10,
            ],
        ]);
    }

    /**
     * Ask the AI to draft a new post (image + caption) for a given topic.
     * Runs in the background — image generation can take too long for one HTTP request.
     */
    public function generate(Request $request)
    {
        $user = $request->user();
        $orgId = $user?->organization_id;

        $validated = $request->validate([
            'topic' => 'required|string|max:500',
        ]);

        $account = MarketingInstagramAccount::query()
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->first();

        $post = MarketingInstagramPost::create([
            'organization_id' => $orgId,
            'instagram_account_id' => $account?->id,
            'topic' => trim($validated['topic']),
            'status' => 'generating',
            'created_by_user_id' => $user?->id,
        ]);

        GenerateInstagramPostJob::dispatch($post->id);

        return redirect()->route('company.marketing.instagram.index')
            ->with('success', 'Generating your post — it will appear below for review shortly.');
    }

    /**
     * Edit the AI-drafted caption before approving (the "edit before approve" step).
     * Restricted to pending_approval — once a post has moved on, editing the
     * caption here would silently drift from whatever (if anything) is
     * already live on Instagram.
     */
    public function update(Request $request, int $id)
    {
        $user = $request->user();
        $orgId = $user?->organization_id;

        $post = MarketingInstagramPost::query()
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->findOrFail($id);

        if ($post->status !== 'pending_approval') {
            return redirect()->back()->with('error', 'Only a draft awaiting review can be edited.');
        }

        $validated = $request->validate([
            'caption' => 'required|string|max:2200',
        ]);

        $post->update(['caption' => $validated['caption']]);

        return redirect()->back()->with('success', 'Caption updated.');
    }

    /**
     * Approve a drafted post — publishes immediately, or schedules it for a future time.
     *
     * The pending_approval -> approved/scheduled transition is done as one
     * atomic conditional UPDATE rather than a check-then-save: a double-click
     * or two near-simultaneous requests both reading pending_approval before
     * either commits must not both win and each dispatch their own publish
     * job for the same post. Only the request whose UPDATE actually matches
     * a row gets to dispatch.
     */
    public function approve(Request $request, int $id)
    {
        $user = $request->user();
        $orgId = $user?->organization_id;

        $post = MarketingInstagramPost::query()
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->findOrFail($id);

        $validated = $request->validate([
            'scheduled_at' => 'nullable|date|after:now',
        ]);

        $scheduledAt = ! empty($validated['scheduled_at']) ? Carbon::parse($validated['scheduled_at']) : null;

        $claimed = MarketingInstagramPost::where('id', $post->id)
            ->where('status', 'pending_approval')
            ->update([
                'status' => $scheduledAt ? 'scheduled' : 'approved',
                'scheduled_at' => $scheduledAt,
                'approved_at' => now(),
                'approved_by_user_id' => $user?->id,
            ]);

        if ($claimed === 0) {
            return redirect()->back()->with('error', 'Only posts pending approval can be approved.');
        }

        if ($scheduledAt) {
            PublishInstagramPostJob::dispatch($post->id)->delay($scheduledAt);
        } else {
            PublishInstagramPostJob::dispatch($post->id);
        }

        return redirect()->back()->with('success', $scheduledAt
            ? "Post approved and scheduled for {$scheduledAt->format('M j, g:i A')}."
            : 'Post approved — publishing now.');
    }

    /**
     * Reject a drafted post with a reason (does not publish).
     */
    public function reject(Request $request, int $id)
    {
        $user = $request->user();
        $orgId = $user?->organization_id;

        $post = MarketingInstagramPost::query()
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->findOrFail($id);

        $validated = $request->validate([
            'rejection_reason' => 'nullable|string|max:500',
        ]);

        $post->update([
            'status' => 'rejected',
            'rejection_reason' => $validated['rejection_reason'] ?? null,
        ]);

        return redirect()->back()->with('success', 'Post rejected.');
    }

    /**
     * Delete a rejected or failed draft. Deliberately restricted to those two
     * statuses — a published post's provider media ID and published_at are
     * the only record of what actually went out on Instagram, and must never
     * be removable through this action.
     */
    public function destroy(Request $request, int $id)
    {
        $user = $request->user();
        $orgId = $user?->organization_id;

        $post = MarketingInstagramPost::query()
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->findOrFail($id);

        if (! in_array($post->status, ['rejected', 'failed'], true)) {
            return redirect()->back()->with('error', 'Only a rejected or failed draft can be deleted.');
        }

        $post->delete();

        return redirect()->back()->with('success', 'Draft removed.');
    }
}
