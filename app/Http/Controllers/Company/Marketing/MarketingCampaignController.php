<?php

namespace App\Http\Controllers\Company\Marketing;

use App\Http\Controllers\Controller;
use App\Jobs\PrepareCampaignJob;
use App\Jobs\SendCampaignMessageJob;
use App\Models\MarketingAudience;
use App\Models\MarketingCampaign;
use App\Models\MarketingContact;
use App\Models\MarketingWhatsAppAccount;
use App\Models\MarketingWhatsAppTemplate;
use App\Services\Marketing\Analytics\CampaignAnalyticsService;
use App\Services\Marketing\AudienceQueryService;
use App\Services\Marketing\MarketingSettingsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class MarketingCampaignController extends Controller
{
    public function __construct(
        protected AudienceQueryService $audienceQueryService,
        protected CampaignAnalyticsService $campaignAnalyticsService
    ) {}

    /**
     * Move a campaign into `preparing` and queue the recipient-generation
     * job. The status transition is a single guarded UPDATE — it only
     * affects a row still in `draft`/`scheduled`/`paused` — so two requests
     * racing the same campaign (a double-click, a resend of the same form)
     * can never both queue a job: only one UPDATE can match, the other
     * affects zero rows and the job is never dispatched for it.
     */
    protected function initiateLaunch(MarketingCampaign $campaign): bool
    {
        $updated = MarketingCampaign::where('id', $campaign->id)
            ->whereIn('status', ['draft', 'scheduled', 'paused'])
            ->update(['status' => 'preparing']);

        if ($updated) {
            PrepareCampaignJob::dispatch($campaign->id);
        }

        return (bool) $updated;
    }

    /**
     * Display Campaigns Directory.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();
        $orgId = $user?->organization_id;

        $search = $request->input('search');
        $status = $request->input('status');

        $query = MarketingCampaign::query()
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->with(['whatsappAccount', 'audience', 'template'])
            ->when($search, fn ($q) => $q->where('name', 'like', "%{$search}%"))
            ->when($status, fn ($q) => $q->where('status', $status))
            ->latest();

        $campaigns = $query->paginate(15)->withQueryString();

        // Calculate aggregate metrics across campaigns
        $metrics = [
            'total' => MarketingCampaign::query()->when($orgId, fn ($q) => $q->where('organization_id', $orgId))->count(),
            'sending' => MarketingCampaign::query()->when($orgId, fn ($q) => $q->where('organization_id', $orgId))->whereIn('status', ['sending', 'preparing'])->count(),
            'completed' => MarketingCampaign::query()->when($orgId, fn ($q) => $q->where('organization_id', $orgId))->where('status', 'completed')->count(),
            'drafts' => MarketingCampaign::query()->when($orgId, fn ($q) => $q->where('organization_id', $orgId))->where('status', 'draft')->count(),
        ];

        return Inertia::render('Company/Marketing/Campaigns/Index', [
            'campaigns' => $campaigns,
            'metrics' => $metrics,
            'filters' => [
                'search' => $search ?? '',
                'status' => $status ?? '',
            ],
        ]);
    }

    /**
     * Multi-step Campaign Builder Wizard View.
     */
    public function create(Request $request): Response
    {
        $user = $request->user();
        $orgId = $user?->organization_id;
        $timezone = MarketingContact::resolveBusinessTimezone($user?->organization);

        $audiences = MarketingAudience::query()
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->orderBy('name')
            ->get();

        /*
         * Each Segment carries its live eligible count plus one real sample
         * contact — the same `previewContacts` a Segment's own detail page
         * uses. That sample is what lets the Content step's preview show an
         * actual matching recipient's name instead of a made-up placeholder.
         */
        $audiencesWithCounts = $audiences->map(function ($aud) use ($orgId, $timezone) {
            $rules = $aud->rules ?? [];
            $aud->eligible_contacts_count = $this->audienceQueryService->matchCount($rules, $orgId, $timezone);
            $aud->preview_contact = $this->audienceQueryService->previewContacts($rules, $orgId, 1, $timezone)[0] ?? null;

            return $aud;
        });

        $settings = $orgId ? app(MarketingSettingsService::class)->getSettings($orgId) : null;
        $today = now()->startOfDay();

        $accounts = MarketingWhatsAppAccount::query()
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->where('status', 'connected')
            ->get()
            ->map(function ($account) use ($settings, $today) {
                $sentToday = $account->messages()
                    ->where('direction', 'outbound')
                    ->where('created_at', '>=', $today)
                    ->count();

                return [
                    'id' => $account->id,
                    'display_phone_number' => $account->display_phone_number,
                    'verified_name' => $account->verified_name,
                    'status' => $account->status,
                    'connection_status' => $account->connection_status,
                    'last_webhook_at' => $account->last_webhook_at?->toIso8601String(),
                    'sent_today' => $sentToday,
                    'hourly_dispatch_rate' => $settings->hourly_dispatch_rate ?? null,
                    'max_daily_messages' => $settings->max_daily_messages ?? null,
                ];
            });

        $templates = MarketingWhatsAppTemplate::query()
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->approved()
            ->orderBy('name')
            ->get();

        /*
         * The organisation's configured default sender, offered as the
         * builder's starting selection.
         *
         * Only handed over when that account is still in the connected list
         * above — a default that has since been disconnected or deleted must
         * not be pre-selected, or a campaign would be set up to send through
         * a number that cannot send. In that case the builder simply starts
         * with no account chosen and the user picks one, which is also what
         * happens when no default is configured. Either way the choice stays
         * the user's: this only decides what is selected first.
         */
        $configuredDefaultId = $settings?->default_whatsapp_account_id;
        $defaultAccountId = $configuredDefaultId && $accounts->contains('id', $configuredDefaultId)
            ? $configuredDefaultId
            : null;

        return Inertia::render('Company/Marketing/Campaigns/Builder', [
            'audiences' => $audiencesWithCounts,
            'accounts' => $accounts,
            'templates' => $templates,
            'defaultAccountId' => $defaultAccountId,
            'quiet_hours_active' => $orgId ? app(MarketingSettingsService::class)->isInQuietHours($orgId) : false,
        ]);
    }

    /**
     * Store new Campaign (Draft or Launch).
     */
    public function store(Request $request)
    {
        $user = $request->user();
        $orgId = $user?->organization_id;

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'whatsapp_account_id' => 'required|exists:marketing_whatsapp_accounts,id',
            'audience_id' => 'required|exists:marketing_audiences,id',
            'template_id' => 'required|exists:marketing_whatsapp_templates,id',
            'variable_mapping' => 'nullable|array',
            'action' => 'required|in:save_draft,launch',
        ]);

        // Tenant Security Check
        $account = MarketingWhatsAppAccount::query()
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->findOrFail($validated['whatsapp_account_id']);

        $audience = MarketingAudience::query()
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->findOrFail($validated['audience_id']);

        $template = MarketingWhatsAppTemplate::query()
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->findOrFail($validated['template_id']);

        $campaign = MarketingCampaign::create([
            'organization_id' => $orgId,
            'whatsapp_account_id' => $account->id,
            'audience_id' => $audience->id,
            'template_id' => $template->id,
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'status' => 'draft',
            'variable_mapping' => $validated['variable_mapping'] ?? [],
            'created_by_user_id' => $user?->id,
        ]);

        if ($validated['action'] === 'launch') {
            $this->initiateLaunch($campaign);

            return redirect()->route('company.marketing.campaigns.show', $campaign->id)
                ->with('success', 'Campaign launch queued — recipients are being prepared.');
        }

        return redirect()->route('company.marketing.campaigns.index')
            ->with('success', 'Campaign draft saved.');
    }

    /**
     * Campaign Detail — the single operational home for one campaign:
     * Overview, Recipients, and Results all come from this one response.
     *
     * `recipients` and `analytics` are wrapped in closures so a partial
     * reload that only asks for `campaign`/`metrics` (see R4's live polling)
     * never runs the recipient or analytics queries at all — Inertia only
     * evaluates a closure prop when that key is actually requested.
     */
    public function show(Request $request, int $id): Response
    {
        $user = $request->user();
        $orgId = $user?->organization_id;

        $campaign = MarketingCampaign::query()
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->with(['whatsappAccount', 'audience', 'template'])
            ->findOrFail($id);

        $search = $request->input('search');
        $status = $request->input('status');

        return Inertia::render('Company/Marketing/Campaigns/Show', [
            'campaign' => $campaign,
            'metrics' => fn () => $campaign->calculateMetrics(),
            'recipients' => fn () => $campaign->recipients()
                ->with('contact')
                ->when($search, function ($q) use ($search) {
                    $q->where(function ($sub) use ($search) {
                        $sub->where('phone', 'like', "%{$search}%")
                            ->orWhereHas('contact', fn ($c) => $c->where('name', 'like', "%{$search}%"));
                    });
                })
                ->when($status, fn ($q) => $q->where('status', $status))
                ->orderBy('id')
                ->paginate(20)
                ->withQueryString(),
            'analytics' => fn () => $this->campaignAnalyticsService->getCampaignDetailAnalytics($campaign),
            'filters' => [
                'search' => $search ?? '',
                'status' => $status ?? '',
            ],
        ]);
    }

    /**
     * Launch an existing draft campaign.
     */
    public function launch(Request $request, int $id)
    {
        $user = $request->user();
        $orgId = $user?->organization_id;

        $campaign = MarketingCampaign::query()
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->findOrFail($id);

        if (! $this->initiateLaunch($campaign)) {
            return redirect()->back()->with('error', 'Campaign cannot be launched from its current status.');
        }

        return redirect()->back()->with('success', 'Campaign launch queued — recipients are being prepared.');
    }

    /**
     * Pause active campaign.
     */
    public function pause(Request $request, int $id)
    {
        $user = $request->user();
        $orgId = $user?->organization_id;

        $campaign = MarketingCampaign::query()
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->findOrFail($id);

        $campaign->update(['status' => 'paused']);

        return redirect()->back()->with('success', 'Campaign dispatch paused.');
    }

    /**
     * Cancel campaign.
     */
    public function cancel(Request $request, int $id)
    {
        $user = $request->user();
        $orgId = $user?->organization_id;

        $campaign = MarketingCampaign::query()
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->findOrFail($id);

        $campaign->update(['status' => 'cancelled']);

        return redirect()->back()->with('success', 'Campaign cancelled.');
    }

    /**
     * Re-attempt every currently-failed recipient, and only those — sent,
     * delivered, read, replied, pending, queued, and skipped recipients are
     * left exactly as they are.
     *
     * The `failed` rows are claimed with `lockForUpdate()` inside a
     * transaction so a duplicate click can't grab the same rows twice: the
     * second request's lock waits for the first to commit, then finds
     * nothing left in `failed` to claim. Each claimed recipient keeps its
     * row — nothing is recreated — and gets a fresh idempotency key, because
     * the original key already has a message attached (even a failed one)
     * and would otherwise short-circuit the retry as an already-sent no-op.
     * That original message is never touched, so the earlier failure stays
     * on the record.
     */
    public function retryFailed(Request $request, int $id)
    {
        $orgId = $request->user()?->organization_id;

        $campaign = MarketingCampaign::query()
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->findOrFail($id);

        if ($campaign->status === 'cancelled') {
            return redirect()->back()->with('error', 'A cancelled campaign cannot be retried.');
        }

        $retriedCount = DB::transaction(function () use ($campaign) {
            $failedRecipients = $campaign->recipients()
                ->where('status', 'failed')
                ->lockForUpdate()
                ->get();

            if ($failedRecipients->isEmpty()) {
                return 0;
            }

            // A campaign that had already finished (every recipient reached
            // a terminal state, including these failures) needs to be
            // "sending" again *before* any job dispatches below — a queue
            // connection that runs jobs inline would otherwise have
            // SendCampaignMessageJob's own campaign-status guard see the
            // still-'completed' campaign and refuse to process the very
            // recipients just re-queued.
            if (in_array($campaign->status, ['completed', 'failed'])) {
                $campaign->update(['status' => 'sending', 'completed_at' => null]);
            }

            foreach ($failedRecipients as $recipient) {
                $payload = $recipient->personalized_payload ?? [];
                $payload['idempotency_key'] = "campaign:{$campaign->id}:recipient:{$recipient->id}:retry:".now()->timestamp;

                $recipient->update([
                    'status' => 'queued',
                    'queued_at' => now(),
                    'failure_reason' => null,
                    'failed_at' => null,
                    'provider_message_id' => null,
                    'personalized_payload' => $payload,
                ]);

                SendCampaignMessageJob::dispatch($recipient->id);
            }

            return $failedRecipients->count();
        });

        if ($retriedCount === 0) {
            return redirect()->back()->with('error', 'No failed recipients to retry.');
        }

        return redirect()->back()->with('success', "Retrying {$retriedCount} failed recipient".($retriedCount === 1 ? '' : 's').'.');
    }

    /**
     * Duplicate campaign configuration into a new draft.
     */
    public function duplicate(Request $request, int $id)
    {
        $user = $request->user();
        $orgId = $user?->organization_id;

        $original = MarketingCampaign::query()
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->findOrFail($id);

        $duplicate = MarketingCampaign::create([
            'organization_id' => $orgId,
            'whatsapp_account_id' => $original->whatsapp_account_id,
            'audience_id' => $original->audience_id,
            'template_id' => $original->template_id,
            'name' => $original->name.' (Copy)',
            'description' => $original->description,
            'status' => 'draft',
            'variable_mapping' => $original->variable_mapping,
            'created_by_user_id' => $user?->id,
        ]);

        return redirect()->route('company.marketing.campaigns.show', $duplicate->id)
            ->with('success', 'Campaign duplicated as new draft.');
    }
}
