<?php

namespace App\Http\Controllers\Company\Marketing;

use App\Http\Controllers\Controller;
use App\Models\MarketingAudience;
use App\Models\MarketingCampaign;
use App\Models\MarketingContact;
use App\Models\MarketingWhatsAppAccount;
use App\Models\Organization;
use App\Models\User;
use App\Services\Marketing\Analytics\CampaignAnalyticsService;
use App\Services\Marketing\Analytics\MarketingAnalyticsFilter;
use App\Services\Marketing\Analytics\MarketingAnalyticsService;
use App\Services\Marketing\Analytics\MarketingJourneyService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MarketingAnalyticsController extends Controller
{
    public function __construct(
        protected MarketingAnalyticsService $analyticsService,
        protected CampaignAnalyticsService $campaignAnalyticsService,
        protected MarketingJourneyService $journeyService
    ) {}

    /**
     * Main Marketing Analytics & Executive Overview Dashboard.
     */
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $orgId = $user->resolveOrganizationId();

        // The same organization the data itself is scoped to — not
        // $user->organization, which is null for a master admin, whose
        // requests still resolve to a real org via resolveOrganizationId().
        $filter = MarketingAnalyticsFilter::fromRequest($request, Organization::find($orgId));
        $analytics = $this->analyticsService->getExecutiveOverview($orgId, $filter);

        return Inertia::render('Company/Marketing/Analytics/Index', [
            'analytics' => $analytics,
            // Option lists for the drill-down filters — the filter values
            // themselves already round-trip through analytics.filter.
            'filterOptions' => [
                'campaigns' => MarketingCampaign::where('organization_id', $orgId)->orderBy('name')->get(['id', 'name']),
                'audiences' => MarketingAudience::where('organization_id', $orgId)->orderBy('name')->get(['id', 'name']),
                'whatsappAccounts' => MarketingWhatsAppAccount::where('organization_id', $orgId)->orderBy('verified_name')->get(['id', 'verified_name as name']),
            ],
        ]);
    }

    /**
     * The standalone per-campaign analytics page no longer exists — Results
     * is now a tab on Campaign Detail itself, reading from the same
     * `getCampaignDetailAnalytics()` this route used to render directly.
     * Kept as a redirect (not removed) so an old bookmark or link still
     * lands somewhere useful instead of a 404; the org-scoped lookup that
     * used to happen here is `campaigns.show`'s own job now.
     */
    public function campaign(int $id)
    {
        return redirect()->route('company.marketing.campaigns.show', $id);
    }

    /**
     * Audience Performance Drill-down View using Historical Recipient Snapshots.
     */
    public function audience(Request $request, int $id): Response
    {
        /** @var User $user */
        $user = $request->user();
        $orgId = $user->resolveOrganizationId();

        $audience = MarketingAudience::query()
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->findOrFail($id);

        $campaigns = MarketingCampaign::where('organization_id', $orgId)
            ->where('audience_id', $audience->id)
            ->with(['whatsappAccount'])
            ->latest()
            ->get();

        $rows = [];
        $totalRecipients = 0;
        $totalReplies = 0;

        foreach ($campaigns as $camp) {
            $recCount = $camp->recipients()->count();
            $replies = $camp->recipients()->where('status', 'replied')->count();
            $totalRecipients += $recCount;
            $totalReplies += $replies;

            $rows[] = [
                'id' => $camp->id,
                'name' => $camp->name,
                'status' => $camp->status,
                'recipients' => $recCount,
                'replies' => $replies,
                'created_at' => $camp->created_at?->toIso8601String(),
            ];
        }

        return Inertia::render('Company/Marketing/Analytics/Audience', [
            'audience' => $audience,
            'campaigns' => $rows,
            'summary' => [
                'campaigns_count' => count($campaigns),
                'total_recipients' => $totalRecipients,
                'total_replies' => $totalReplies,
            ],
        ]);
    }

    /**
     * Individual Customer Journey Timeline.
     */
    public function journey(Request $request, int $contactId): Response
    {
        /** @var User $user */
        $user = $request->user();
        $orgId = $user->resolveOrganizationId();

        $contact = MarketingContact::query()
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->with(['tags', 'crmLeads'])
            ->findOrFail($contactId);

        $timeline = $this->journeyService->buildContactTimeline($contact);

        return Inertia::render('Company/Marketing/Analytics/Journey', [
            'contact' => $contact,
            'timeline' => $timeline,
        ]);
    }

    /**
     * Tenant-Isolated CSV Analytics Export.
     */
    public function export(Request $request): StreamedResponse
    {
        /** @var User $user */
        $user = $request->user();
        $orgId = $user->resolveOrganizationId();
        $type = $request->input('type', 'campaigns');

        $filter = MarketingAnalyticsFilter::fromRequest($request, Organization::find($orgId));

        $filename = "marketing_{$type}_export_".now()->format('Y-m-d').'.csv';

        return response()->stream(function () use ($orgId, $filter, $type) {
            $handle = fopen('php://output', 'w');

            if ($type === 'campaigns') {
                fputcsv($handle, ['Campaign ID', 'Campaign Name', 'Status', 'Audience', 'Recipients', 'Sent', 'Delivered', 'Read', 'Replies', 'Leads', 'Won', 'Win Rate %']);
                $data = $this->campaignAnalyticsService->getCampaignPerformanceTable($orgId, $filter);
                foreach ($data as $r) {
                    fputcsv($handle, [$r['id'], $r['name'], $r['status'], $r['audience_name'], $r['recipients'], $r['sent'], $r['delivered'], $r['read'], $r['replies'], $r['leads'], $r['won'], $r['win_rate']]);
                }
            } else {
                fputcsv($handle, ['Contact ID', 'Name', 'Phone', 'City', 'Marketing Status', 'Created At']);
                $contacts = MarketingContact::where('organization_id', $orgId)->latest()->take(5000)->get();
                foreach ($contacts as $c) {
                    fputcsv($handle, [$c->id, $c->name, $c->phone, $c->city, $c->marketing_status, $c->created_at?->toIso8601String()]);
                }
            }

            fclose($handle);
        }, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }
}
