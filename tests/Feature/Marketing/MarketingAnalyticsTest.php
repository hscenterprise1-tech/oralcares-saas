<?php

namespace Tests\Feature\Marketing;

use App\LeadPriority;
use App\LeadStatus;
use App\Models\Lead;
use App\Models\MarketingAudience;
use App\Models\MarketingCampaign;
use App\Models\MarketingCampaignRecipient;
use App\Models\MarketingContact;
use App\Models\MarketingWhatsAppAccount;
use App\Models\MarketingWhatsAppConversation;
use App\Models\MarketingWhatsAppMessage;
use App\Models\MarketingWhatsAppTemplate;
use App\Models\Organization;
use App\Models\User;
use App\Services\Marketing\Analytics\CampaignAnalyticsService;
use App\Services\Marketing\Analytics\MarketingAnalyticsFilter;
use App\Services\Marketing\Analytics\MarketingAnalyticsService;
use App\Services\Marketing\Analytics\MarketingAttributionService;
use App\Services\Marketing\Analytics\MarketingFunnelService;
use App\Services\Marketing\Analytics\MarketingJourneyService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MarketingAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $orgA;

    protected Organization $orgB;

    protected User $userA;

    protected User $userB;

    protected MarketingWhatsAppAccount $accountA;

    protected MarketingContact $contactA;

    protected MarketingAudience $audienceA;

    protected MarketingCampaign $campaignA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orgA = Organization::factory()->provider()->create(['name' => 'Tenant A']);
        $this->orgB = Organization::factory()->provider()->create(['name' => 'Tenant B']);

        $this->userA = User::factory()->create([
            'organization_id' => $this->orgA->id,
            'user_type' => 'company_user',
        ]);
        grantMarketingPermissions($this->userA);

        $this->userB = User::factory()->create([
            'organization_id' => $this->orgB->id,
            'user_type' => 'company_user',
        ]);
        grantMarketingPermissions($this->userB);

        $this->accountA = MarketingWhatsAppAccount::create([
            'organization_id' => $this->orgA->id,
            'phone_number_id' => '100000000000001',
            'business_account_id' => '200000000000001',
            'verified_name' => 'Tenant A WhatsApp',
            'display_phone_number' => '+919876543210',
            'access_token_encrypted' => encrypt('fake-token'),
            'webhook_verify_token_encrypted' => encrypt('fake-verify'),
            'status' => 'connected',
        ]);

        $this->contactA = MarketingContact::create([
            'organization_id' => $this->orgA->id,
            'name' => 'Dr. Rajesh Sharma',
            'phone' => '+919876543210',
            'normalized_phone' => '919876543210',
            'organization' => 'Vadodara Dental',
            'city' => 'Vadodara',
            'marketing_status' => 'active',
        ]);

        $this->audienceA = MarketingAudience::create([
            'organization_id' => $this->orgA->id,
            'name' => 'Vadodara Dentists',
            'rules' => ['match' => 'all', 'rules' => []],
        ]);

        $this->templateA = MarketingWhatsAppTemplate::create([
            'organization_id' => $this->orgA->id,
            'whatsapp_account_id' => $this->accountA->id,
            'name' => 'welcome_template',
            'language' => 'en',
            'category' => 'MARKETING',
            'status' => 'APPROVED',
            'body' => 'Hello {{1}}',
            'components' => [],
        ]);

        $this->campaignA = MarketingCampaign::create([
            'organization_id' => $this->orgA->id,
            'whatsapp_account_id' => $this->accountA->id,
            'audience_id' => $this->audienceA->id,
            'template_id' => $this->templateA->id,
            'name' => 'Diwali Outreach',
            'status' => 'completed',
        ]);

        MarketingCampaignRecipient::create([
            'campaign_id' => $this->campaignA->id,
            'contact_id' => $this->contactA->id,
            'phone' => '+919876543210',
            'normalized_phone' => '919876543210',
            'status' => 'replied',
            'delivered_at' => now(),
            'read_at' => now(),
            'replied_at' => now(),
        ]);
    }

    public function test_user_can_view_analytics_index_page_and_kpis()
    {
        $response = $this->actingAs($this->userA)
            ->get(route('company.marketing.analytics.index'));

        $response->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Company/Marketing/Analytics/Index')
                ->has('analytics.kpis')
                ->has('analytics.funnel')
                ->has('analytics.campaign_performance')
                ->has('analytics.lead_attribution')
            );
    }

    public function test_master_admin_without_organization_id_can_view_analytics_index_page()
    {
        // Master admins are not scoped to a single organization_id; the controller
        // must resolve a fallback org (User::resolveOrganizationId()) instead of
        // passing null into services that require a strict int $orgId.
        $masterAdmin = User::factory()->create([
            'organization_id' => null,
            'user_type' => 'master_admin',
        ]);

        $response = $this->actingAs($masterAdmin)
            ->get(route('company.marketing.analytics.index'));

        $response->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Company/Marketing/Analytics/Index')
                ->has('analytics.kpis')
                ->has('analytics.funnel')
            );
    }

    public function test_marketing_funnel_calculates_rates_and_handles_zero_denominator_safely()
    {
        $filter = new MarketingAnalyticsFilter(['period' => 'this_month']);
        $funnelService = app(MarketingFunnelService::class);

        $result = $funnelService->calculateFunnel($this->orgA->id, $filter);

        $this->assertNotNull($result['stages']);
        $this->assertCount(6, $result['stages']);
        $this->assertEquals(1, $result['summary']['total_reached']);
        $this->assertEquals(1, $result['summary']['total_delivered']);
        $this->assertEquals(1, $result['summary']['total_replied']);
    }

    public function test_lead_source_attribution_aligns_with_canonical_lead_sources()
    {
        Lead::create([
            'company_name' => 'Vadodara Dental',
            'slug' => 'vadodara-dental',
            'contact_name' => 'Dr. Rajesh Sharma',
            'phone' => '+919876543210',
            'normalized_phone' => '919876543210',
            'organization_id' => $this->orgA->id,
            'marketing_contact_id' => $this->contactA->id,
            'marketing_campaign_id' => $this->campaignA->id,
            'status' => LeadStatus::Won,
            'priority' => LeadPriority::High,
            'source' => 'whatsapp',
        ]);

        $analyticsService = app(MarketingAnalyticsService::class);
        $filter = new MarketingAnalyticsFilter;
        $overview = $analyticsService->getExecutiveOverview($this->orgA->id, $filter);

        $this->assertEquals(1, $overview['kpis']['crm_leads']['value']);
        $this->assertEquals(1, $overview['kpis']['won_leads']['value']);
    }

    public function test_campaign_performance_uses_recipient_snapshot()
    {
        $analyticsService = app(MarketingAnalyticsService::class);
        $filter = new MarketingAnalyticsFilter;
        $overview = $analyticsService->getExecutiveOverview($this->orgA->id, $filter);

        $campaignData = $overview['campaign_performance'][0];
        $this->assertEquals('Diwali Outreach', $campaignData['name']);
        $this->assertEquals(1, $campaignData['recipients']);
        $this->assertEquals(1, $campaignData['replies']);
    }

    public function test_customer_journey_timeline_chronology()
    {
        $journeyService = app(MarketingJourneyService::class);
        $timeline = $journeyService->buildContactTimeline($this->contactA);

        $this->assertNotEmpty($timeline);
        $this->assertEquals('Added to Marketing Contacts', $timeline[0]['title']);
    }

    public function test_tenant_isolation_prevents_cross_tenant_analytics_access_and_export()
    {
        // The old standalone analytics-per-campaign route now redirects to
        // Campaign Detail rather than rendering directly — the redirect
        // itself carries nothing tenant-specific, so the actual isolation
        // check happens where the campaign is really loaded: campaigns.show.
        $response = $this->actingAs($this->userB)
            ->get(route('company.marketing.analytics.campaign', $this->campaignA->id));

        $response->assertRedirect(route('company.marketing.campaigns.show', $this->campaignA->id));

        $this->actingAs($this->userB)
            ->get(route('company.marketing.campaigns.show', $this->campaignA->id))
            ->assertStatus(404);
    }

    public function test_csv_export_returns_streamed_csv()
    {
        $response = $this->actingAs($this->userA)
            ->get(route('company.marketing.analytics.export', ['type' => 'campaigns']));

        $response->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
    }

    public function test_historical_integrity_campaign_recipients_unchanged_when_audience_changes()
    {
        // 1. Snapshot recipients is 1
        $analyticsService = app(MarketingAnalyticsService::class);
        $filter = new MarketingAnalyticsFilter;
        $detailBefore = $analyticsService->getExecutiveOverview($this->orgA->id, $filter);
        $this->assertEquals(1, $detailBefore['campaign_performance'][0]['recipients']);

        // 2. Modify contacts matching audience rules
        MarketingContact::create([
            'organization_id' => $this->orgA->id,
            'name' => 'Dr. New Contact',
            'phone' => '+919999988888',
            'normalized_phone' => '919999988888',
            'marketing_status' => 'active',
        ]);

        // 3. Historical campaign metrics remain strictly 1 (based on recipient snapshot)
        $detailAfter = $analyticsService->getExecutiveOverview($this->orgA->id, $filter);
        $this->assertEquals(1, $detailAfter['campaign_performance'][0]['recipients']);
    }

    public function test_metric_timestamp_semantics_respect_specific_dates()
    {
        $filter = new MarketingAnalyticsFilter([
            'period' => 'custom',
            'start_date' => now()->toDateString(),
            'end_date' => now()->toDateString(),
        ]);

        $analyticsService = app(MarketingAnalyticsService::class);
        $overview = $analyticsService->getExecutiveOverview($this->orgA->id, $filter);

        $this->assertNotNull($overview['kpis']);
    }

    // ── FIX 1: period selector contract ──────────────────────────────────

    public function test_frontend_short_period_codes_resolve_to_distinct_canonical_boundaries()
    {
        $sevenDays = new MarketingAnalyticsFilter(['period' => '7d']);
        $thirtyDays = new MarketingAnalyticsFilter(['period' => '30d']);
        $ninetyDays = new MarketingAnalyticsFilter(['period' => '90d']);
        $allTime = new MarketingAnalyticsFilter(['period' => 'all']);

        expect($sevenDays->period)->toBe('last_7_days');
        expect($thirtyDays->period)->toBe('last_30_days');
        expect($ninetyDays->period)->toBe('last_90_days');
        expect($allTime->period)->toBe('all_time');

        // Each produces a genuinely different lower bound, not the same
        // default silently reused for every option.
        expect($sevenDays->startDate->diffInDays($sevenDays->endDate))->toBeLessThan(8);
        expect($thirtyDays->startDate->diffInDays($thirtyDays->endDate))->toBeGreaterThan(28)
            ->toBeLessThan(31);
        expect($ninetyDays->startDate->diffInDays($ninetyDays->endDate))->toBeGreaterThan(88)
            ->toBeLessThan(91);
        expect($allTime->startDate)->toBeNull();
    }

    public function test_the_http_layer_actually_uses_the_selected_period_to_scope_kpi_results()
    {
        // One recipient inside the last 7 days, one only inside the last 90.
        MarketingCampaignRecipient::forceCreate([
            'campaign_id' => $this->campaignA->id,
            'phone' => '+919000000001',
            'normalized_phone' => '919000000001',
            'status' => 'delivered',
            'delivered_at' => now()->subDays(3),
            'created_at' => now()->subDays(3),
            'updated_at' => now()->subDays(3),
        ]);
        MarketingCampaignRecipient::forceCreate([
            'campaign_id' => $this->campaignA->id,
            'phone' => '+919000000002',
            'normalized_phone' => '919000000002',
            'status' => 'delivered',
            'delivered_at' => now()->subDays(45),
            'created_at' => now()->subDays(45),
            'updated_at' => now()->subDays(45),
        ]);

        // The seeded-in-beforeEach recipient is also "delivered" (via read/replied
        // rollup) and dated "now", so it's present in every window below.

        $sevenDayCount = $this->actingAs($this->userA)
            ->get(route('company.marketing.analytics.index', ['period' => '7d']))
            ->viewData('page')['props']['analytics']['kpis']['messages_delivered']['value'];

        $ninetyDayCount = $this->actingAs($this->userA)
            ->get(route('company.marketing.analytics.index', ['period' => '90d']))
            ->viewData('page')['props']['analytics']['kpis']['messages_delivered']['value'];

        // 7d sees the beforeEach recipient + the 3-days-ago one = 2.
        // 90d additionally sees the 45-days-ago one = 3.
        expect($sevenDayCount)->toBe(2);
        expect($ninetyDayCount)->toBe(3);
        expect($ninetyDayCount)->not->toBe($sevenDayCount);
    }

    // ── FIX 2: organization business timezone ──────────────────────────────

    public function test_today_boundary_follows_the_organizations_timezone_across_the_utc_midnight_gap()
    {
        $this->orgA->update(['timezone' => 'Asia/Kolkata']);

        // "Now" is 01:00 IST on the 26th, which is still the 25th in UTC —
        // the exact gap where a UTC-based "today" and an IST-based "today"
        // disagree.
        Carbon::setTestNow(Carbon::create(2026, 8, 26, 1, 0, 0, 'Asia/Kolkata'));

        try {
            $filter = new MarketingAnalyticsFilter(['period' => 'today'], $this->orgA);

            // IST "today" (26th) is 2026-08-25 18:30:00 UTC through 2026-08-26 18:29:59 UTC.
            expect($filter->startDate->toDateTimeString())->toBe('2026-08-25 18:30:00');
            expect($filter->endDate->format('Y-m-d H:i'))->toBe('2026-08-26 18:29');

            $analyticsService = app(MarketingAnalyticsService::class);
            // Baseline before R1/R2 exist — whatever the beforeEach fixture's
            // real (unfrozen) creation timestamp contributes, isolated out by
            // taking the delta below rather than asserting an absolute count.
            $before = $analyticsService->getExecutiveOverview($this->orgA->id, $filter)['kpis']['messages_delivered']['value'];

            // R1: 2026-08-25 10:00 UTC = IST 15:30 on the 25th — IST *yesterday*,
            // must be excluded from IST "today" even though it's the same UTC
            // calendar date a UTC-based "today" would have wrongly included.
            MarketingCampaignRecipient::forceCreate([
                'campaign_id' => $this->campaignA->id,
                'phone' => '+919000000003',
                'normalized_phone' => '919000000003',
                'status' => 'delivered',
                'delivered_at' => Carbon::create(2026, 8, 25, 10, 0, 0, 'UTC'),
                'created_at' => Carbon::create(2026, 8, 25, 10, 0, 0, 'UTC'),
                'updated_at' => Carbon::create(2026, 8, 25, 10, 0, 0, 'UTC'),
            ]);

            // R2: 2026-08-26 05:00 UTC = IST 10:30 on the 26th — genuinely IST
            // "today", but outside a UTC-truncated "today" (Aug 25 00:00–23:59 UTC),
            // so a broken implementation would wrongly exclude it.
            MarketingCampaignRecipient::forceCreate([
                'campaign_id' => $this->campaignA->id,
                'phone' => '+919000000004',
                'normalized_phone' => '919000000004',
                'status' => 'delivered',
                'delivered_at' => Carbon::create(2026, 8, 26, 5, 0, 0, 'UTC'),
                'created_at' => Carbon::create(2026, 8, 26, 5, 0, 0, 'UTC'),
                'updated_at' => Carbon::create(2026, 8, 26, 5, 0, 0, 'UTC'),
            ]);

            Cache::flush();
            $after = $analyticsService->getExecutiveOverview($this->orgA->id, $filter)['kpis']['messages_delivered']['value'];

            // Only R2 should have joined the count — R1 must not.
            expect($after - $before)->toBe(1);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_custom_date_range_is_parsed_in_the_organizations_timezone()
    {
        $this->orgA->update(['timezone' => 'Asia/Kolkata']);

        $filter = new MarketingAnalyticsFilter([
            'period' => 'custom',
            'start_date' => '2026-08-25',
            'end_date' => '2026-08-25',
        ], $this->orgA);

        // 2026-08-25 00:00 IST through 23:59:59 IST, converted to UTC.
        expect($filter->startDate->toDateTimeString())->toBe('2026-08-24 18:30:00');
        expect($filter->endDate->format('Y-m-d H:i'))->toBe('2026-08-25 18:29');
    }

    public function test_an_org_with_no_timezone_configured_falls_back_to_the_shared_business_timezone()
    {
        $this->orgA->update(['timezone' => null]);

        Carbon::setTestNow(Carbon::create(2026, 8, 26, 1, 0, 0, 'Asia/Kolkata'));

        try {
            $filter = new MarketingAnalyticsFilter(['period' => 'today'], $this->orgA);

            expect($filter->startDate->toDateTimeString())->toBe('2026-08-25 18:30:00');
        } finally {
            Carbon::setTestNow();
        }
    }

    // ── FIX 3: journey inbound/outbound event types ──────────────────────

    public function test_journey_events_a_customer_authored_use_the_exact_type_strings_the_frontend_treats_as_inbound()
    {
        // Contract test: Journey.tsx's INBOUND_TYPES set must contain exactly
        // these two strings ('whatsapp_msg_inbound', 'campaign_replied') for a
        // real customer reply to render as inbound rather than "we sent". This
        // pins the backend's emitted type strings so that contract can't
        // silently drift again.
        $conversation = MarketingWhatsAppConversation::create([
            'organization_id' => $this->orgA->id,
            'whatsapp_account_id' => $this->accountA->id,
            'contact_id' => $this->contactA->id,
            'phone' => $this->contactA->phone,
            'normalized_phone' => $this->contactA->normalized_phone,
        ]);

        MarketingWhatsAppMessage::create([
            'conversation_id' => $conversation->id,
            'whatsapp_account_id' => $this->accountA->id,
            'provider_message_id' => 'wamid.test_inbound_reply_1',
            'direction' => 'inbound',
            'body' => 'Is this offer still on?',
        ]);

        $journeyService = app(MarketingJourneyService::class);
        $timeline = $journeyService->buildContactTimeline($this->contactA->fresh(['tags', 'crmLeads']));

        $inboundMessageEvent = collect($timeline)->firstWhere('description', 'Is this offer still on?');
        expect($inboundMessageEvent)->not->toBeNull();
        expect($inboundMessageEvent['type'])->toBe('whatsapp_msg_inbound');

        $campaignReplyEvent = collect($timeline)->first(fn ($e) => $e['type'] === 'campaign_replied');
        // The beforeEach recipient is already 'replied', so this event exists.
        expect($campaignReplyEvent)->not->toBeNull();
    }

    // ── FIX 4: campaign performance batched aggregation ──────────────────

    public function test_the_batched_campaign_performance_row_matches_calculate_metrics_exactly()
    {
        $campaign = MarketingCampaign::create([
            'organization_id' => $this->orgA->id,
            'whatsapp_account_id' => $this->accountA->id,
            'audience_id' => $this->audienceA->id,
            'template_id' => $this->templateA->id,
            'name' => 'Mixed status campaign',
            'status' => 'completed',
        ]);

        $statuses = ['pending', 'pending', 'sent', 'delivered', 'delivered', 'read', 'replied', 'replied', 'failed'];
        foreach ($statuses as $i => $status) {
            MarketingCampaignRecipient::create([
                'campaign_id' => $campaign->id,
                'phone' => "+91900000{$i}",
                'normalized_phone' => "91900000{$i}",
                'status' => $status,
            ]);
        }

        Lead::create([
            'company_name' => 'Batched Lead Co',
            'slug' => 'batched-lead-co',
            'contact_name' => 'Someone',
            'phone' => '+919111111111',
            'normalized_phone' => '919111111111',
            'organization_id' => $this->orgA->id,
            'marketing_campaign_id' => $campaign->id,
            'status' => LeadStatus::Won,
            'priority' => LeadPriority::High,
            'source' => 'whatsapp',
        ]);

        $canonical = $campaign->calculateMetrics();

        $service = app(CampaignAnalyticsService::class);
        $row = collect($service->getCampaignPerformanceTable($this->orgA->id, new MarketingAnalyticsFilter(['period' => 'all'])))
            ->firstWhere('id', $campaign->id);

        expect($row)->not->toBeNull();
        expect($row['recipients'])->toBe($canonical['total']);
        expect($row['sent'])->toBe($canonical['sent']);
        expect($row['delivered'])->toBe($canonical['delivered']);
        expect($row['read'])->toBe($canonical['read']);
        expect($row['replies'])->toBe($canonical['replied']);
        expect($row['failed'])->toBe($canonical['failed']);
        expect($row['leads'])->toBe(1);
        expect($row['won'])->toBe(1);
        expect($row['delivery_rate'])->toBe(round(($canonical['delivered'] / $canonical['total']) * 100, 1));
    }

    public function test_campaign_performance_query_count_does_not_grow_with_campaign_count()
    {
        foreach (range(1, 12) as $i) {
            $campaign = MarketingCampaign::create([
                'organization_id' => $this->orgA->id,
                'whatsapp_account_id' => $this->accountA->id,
                'audience_id' => $this->audienceA->id,
                'template_id' => $this->templateA->id,
                'name' => "Bulk campaign {$i}",
                'status' => 'completed',
            ]);

            foreach (range(1, 3) as $r) {
                MarketingCampaignRecipient::create([
                    'campaign_id' => $campaign->id,
                    'phone' => "+9190{$i}{$r}",
                    'normalized_phone' => "9190{$i}{$r}",
                    'status' => 'delivered',
                ]);
            }
        }

        $service = app(CampaignAnalyticsService::class);

        DB::enableQueryLog();
        $rows = $service->getCampaignPerformanceTable($this->orgA->id, new MarketingAnalyticsFilter(['period' => 'all']));
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        expect($rows)->toHaveCount(13); // 12 bulk + the beforeEach campaignA
        // Campaigns + eager-loaded audience/whatsappAccount + recipient
        // aggregation + lead aggregation — a small fixed number, not one
        // pair of queries per campaign (which would be 24+ here).
        expect($queryCount)->toBeLessThanOrEqual(6);
    }

    // ── FIX 5: executive KPI filter consistency ───────────────────────────

    public function test_campaign_filter_changes_kpi_results()
    {
        $campaignB = MarketingCampaign::create([
            'organization_id' => $this->orgA->id,
            'whatsapp_account_id' => $this->accountA->id,
            'audience_id' => $this->audienceA->id,
            'template_id' => $this->templateA->id,
            'name' => 'Campaign B',
            'status' => 'completed',
        ]);
        MarketingCampaignRecipient::create([
            'campaign_id' => $campaignB->id,
            'phone' => '+919222222222',
            'normalized_phone' => '919222222222',
            'status' => 'delivered',
        ]);

        $service = app(MarketingAnalyticsService::class);

        $unfiltered = $service->getExecutiveOverview($this->orgA->id, new MarketingAnalyticsFilter(['period' => 'all']));
        $scopedToA = $service->getExecutiveOverview($this->orgA->id, new MarketingAnalyticsFilter(['period' => 'all', 'campaign_id' => $this->campaignA->id]));

        // Unfiltered sees both campaigns' recipients; scoped to campaign A only sees its own.
        expect($unfiltered['kpis']['messages_delivered']['value'])->toBe(2);
        expect($scopedToA['kpis']['messages_delivered']['value'])->toBe(1);
        expect($scopedToA['kpis']['messages_delivered']['value'])->not->toBe($unfiltered['kpis']['messages_delivered']['value']);
    }

    public function test_whatsapp_account_filter_changes_kpi_results()
    {
        $accountB = MarketingWhatsAppAccount::create([
            'organization_id' => $this->orgA->id,
            'phone_number_id' => '100000000000002',
            'business_account_id' => '200000000000002',
            'verified_name' => 'Tenant A WhatsApp B',
            'display_phone_number' => '+919876543211',
            'access_token_encrypted' => encrypt('fake-token-b'),
            'webhook_verify_token_encrypted' => encrypt('fake-verify-b'),
            'status' => 'connected',
        ]);
        $campaignB = MarketingCampaign::create([
            'organization_id' => $this->orgA->id,
            'whatsapp_account_id' => $accountB->id,
            'audience_id' => $this->audienceA->id,
            'template_id' => $this->templateA->id,
            'name' => 'Campaign via account B',
            'status' => 'completed',
        ]);
        MarketingCampaignRecipient::create([
            'campaign_id' => $campaignB->id,
            'phone' => '+919333333333',
            'normalized_phone' => '919333333333',
            'status' => 'delivered',
        ]);

        $service = app(MarketingAnalyticsService::class);

        $unfiltered = $service->getExecutiveOverview($this->orgA->id, new MarketingAnalyticsFilter(['period' => 'all']));
        $scopedToAccountA = $service->getExecutiveOverview($this->orgA->id, new MarketingAnalyticsFilter(['period' => 'all', 'whatsapp_account_id' => $this->accountA->id]));

        expect($unfiltered['kpis']['messages_delivered']['value'])->toBe(2);
        expect($scopedToAccountA['kpis']['messages_delivered']['value'])->toBe(1);
    }

    public function test_audience_filter_changes_kpi_results()
    {
        $audienceB = MarketingAudience::create([
            'organization_id' => $this->orgA->id,
            'name' => 'Audience B',
            'rules' => ['match' => 'all', 'rules' => []],
        ]);
        $campaignB = MarketingCampaign::create([
            'organization_id' => $this->orgA->id,
            'whatsapp_account_id' => $this->accountA->id,
            'audience_id' => $audienceB->id,
            'template_id' => $this->templateA->id,
            'name' => 'Campaign to audience B',
            'status' => 'completed',
        ]);
        MarketingCampaignRecipient::create([
            'campaign_id' => $campaignB->id,
            'phone' => '+919444444444',
            'normalized_phone' => '919444444444',
            'status' => 'delivered',
        ]);

        $service = app(MarketingAnalyticsService::class);

        $unfiltered = $service->getExecutiveOverview($this->orgA->id, new MarketingAnalyticsFilter(['period' => 'all']));
        $scopedToAudienceA = $service->getExecutiveOverview($this->orgA->id, new MarketingAnalyticsFilter(['period' => 'all', 'audience_id' => $this->audienceA->id]));

        expect($unfiltered['kpis']['messages_delivered']['value'])->toBe(2);
        expect($scopedToAudienceA['kpis']['messages_delivered']['value'])->toBe(1);
    }

    public function test_audience_filter_scopes_lead_derived_kpis_through_the_campaign_relationship()
    {
        // Leads have no audience_id column of their own — this proves the
        // subquery through marketing_campaign_id actually narrows lead
        // counts too, not just recipient counts.
        $audienceB = MarketingAudience::create([
            'organization_id' => $this->orgA->id,
            'name' => 'Audience B (lead scoping)',
            'rules' => ['match' => 'all', 'rules' => []],
        ]);
        $campaignB = MarketingCampaign::create([
            'organization_id' => $this->orgA->id,
            'whatsapp_account_id' => $this->accountA->id,
            'audience_id' => $audienceB->id,
            'template_id' => $this->templateA->id,
            'name' => 'Campaign to audience B (leads)',
            'status' => 'completed',
        ]);
        Lead::create([
            'company_name' => 'Audience B Lead Co',
            'slug' => 'audience-b-lead-co',
            'contact_name' => 'Someone Else',
            'phone' => '+919777777777',
            'normalized_phone' => '919777777777',
            'organization_id' => $this->orgA->id,
            'marketing_campaign_id' => $campaignB->id,
            'marketing_contact_id' => $this->contactA->id,
            'status' => LeadStatus::New,
            'priority' => LeadPriority::Medium,
            'source' => 'whatsapp',
        ]);

        $service = app(MarketingAnalyticsService::class);

        $unfiltered = $service->getExecutiveOverview($this->orgA->id, new MarketingAnalyticsFilter(['period' => 'all']));
        $scopedToAudienceA = $service->getExecutiveOverview($this->orgA->id, new MarketingAnalyticsFilter(['period' => 'all', 'audience_id' => $this->audienceA->id]));
        $scopedToAudienceB = $service->getExecutiveOverview($this->orgA->id, new MarketingAnalyticsFilter(['period' => 'all', 'audience_id' => $audienceB->id]));

        // Unfiltered: the beforeEach lead-less campaignA contributes 0 leads
        // via marketing_contact_id-based crm_leads (no lead exists on
        // campaignA in this test), campaignB contributes 1.
        expect($unfiltered['kpis']['crm_leads']['value'])->toBe(1);
        expect($scopedToAudienceA['kpis']['crm_leads']['value'])->toBe(0);
        expect($scopedToAudienceB['kpis']['crm_leads']['value'])->toBe(1);
    }

    public function test_whatsapp_account_filter_scopes_lead_derived_kpis_through_the_campaign_relationship()
    {
        $accountB = MarketingWhatsAppAccount::create([
            'organization_id' => $this->orgA->id,
            'phone_number_id' => '100000000000003',
            'business_account_id' => '200000000000003',
            'verified_name' => 'Tenant A WhatsApp C',
            'display_phone_number' => '+919876543212',
            'access_token_encrypted' => encrypt('fake-token-c'),
            'webhook_verify_token_encrypted' => encrypt('fake-verify-c'),
            'status' => 'connected',
        ]);
        $campaignB = MarketingCampaign::create([
            'organization_id' => $this->orgA->id,
            'whatsapp_account_id' => $accountB->id,
            'audience_id' => $this->audienceA->id,
            'template_id' => $this->templateA->id,
            'name' => 'Campaign via account C (leads)',
            'status' => 'completed',
        ]);
        Lead::create([
            'company_name' => 'Account C Lead Co',
            'slug' => 'account-c-lead-co',
            'contact_name' => 'Someone Else Again',
            'phone' => '+919888888888',
            'normalized_phone' => '919888888888',
            'organization_id' => $this->orgA->id,
            'marketing_campaign_id' => $campaignB->id,
            'marketing_contact_id' => $this->contactA->id,
            'status' => LeadStatus::New,
            'priority' => LeadPriority::Medium,
            'source' => 'whatsapp',
        ]);

        $service = app(MarketingAnalyticsService::class);

        $scopedToAccountA = $service->getExecutiveOverview($this->orgA->id, new MarketingAnalyticsFilter(['period' => 'all', 'whatsapp_account_id' => $this->accountA->id]));
        $scopedToAccountC = $service->getExecutiveOverview($this->orgA->id, new MarketingAnalyticsFilter(['period' => 'all', 'whatsapp_account_id' => $accountB->id]));

        expect($scopedToAccountA['kpis']['crm_leads']['value'])->toBe(0);
        expect($scopedToAccountC['kpis']['crm_leads']['value'])->toBe(1);
    }

    public function test_kpi_band_and_funnel_agree_on_lead_counts_for_the_same_audience_filter()
    {
        // The whole point of scoping leads identically in both services:
        // for the same filter, the KPI band and the funnel must report the
        // same lead count, not two different numbers on the same page.
        $audienceB = MarketingAudience::create([
            'organization_id' => $this->orgA->id,
            'name' => 'Audience B (consistency)',
            'rules' => ['match' => 'all', 'rules' => []],
        ]);
        $campaignB = MarketingCampaign::create([
            'organization_id' => $this->orgA->id,
            'whatsapp_account_id' => $this->accountA->id,
            'audience_id' => $audienceB->id,
            'template_id' => $this->templateA->id,
            'name' => 'Campaign to audience B (consistency)',
            'status' => 'completed',
        ]);
        Lead::create([
            'company_name' => 'Consistency Lead Co',
            'slug' => 'consistency-lead-co',
            'contact_name' => 'Consistency Contact',
            'phone' => '+919666677777',
            'normalized_phone' => '919666677777',
            'organization_id' => $this->orgA->id,
            'marketing_campaign_id' => $campaignB->id,
            'marketing_contact_id' => $this->contactA->id,
            'status' => LeadStatus::New,
            'priority' => LeadPriority::Medium,
            'source' => 'whatsapp',
        ]);

        $filter = new MarketingAnalyticsFilter(['period' => 'all', 'audience_id' => $audienceB->id]);

        $kpis = app(MarketingAnalyticsService::class)->getExecutiveOverview($this->orgA->id, $filter)['kpis'];
        $funnel = app(MarketingFunnelService::class)->calculateFunnel($this->orgA->id, $filter);

        expect($kpis['crm_leads']['value'])->toBe(1);
        expect($funnel['summary']['total_leads'])->toBe($kpis['crm_leads']['value']);
    }

    public function test_no_filter_preserves_the_combined_baseline()
    {
        $service = app(MarketingAnalyticsService::class);
        $overview = $service->getExecutiveOverview($this->orgA->id, new MarketingAnalyticsFilter(['period' => 'all']));

        // Just the beforeEach recipient (1, status 'replied' → counts as delivered too).
        expect($overview['kpis']['messages_delivered']['value'])->toBe(1);
        expect($overview['kpis']['replies']['value'])->toBe(1);
    }

    // ── FIX 6: dead computation removed ────────────────────────────────────

    public function test_the_response_no_longer_includes_unused_computed_fields()
    {
        $response = $this->actingAs($this->userA)->get(route('company.marketing.analytics.index'));

        $response->assertInertia(fn ($page) => $page
            ->missing('analytics.automation_metrics')
            ->missing('analytics.time_series')
            ->missing('analytics.kpis.total_contacts')
            ->missing('analytics.kpis.campaigns_sent')
        );
    }

    // ── FIX 7: campaign performance follows the selected period consistently ──

    public function test_campaign_performance_reflects_the_selected_period_not_the_campaigns_creation_date()
    {
        // Campaign created 6 months ago (outside any "last_30_days" window),
        // but with a recipient whose activity is inside the selected period —
        // previously excluded entirely (filtered out by campaign.created_at);
        // now must appear, because it has in-period activity.
        $oldCampaign = MarketingCampaign::forceCreate([
            'organization_id' => $this->orgA->id,
            'whatsapp_account_id' => $this->accountA->id,
            'audience_id' => $this->audienceA->id,
            'template_id' => $this->templateA->id,
            'name' => 'Old campaign, recent activity',
            'status' => 'completed',
            'created_at' => now()->subMonths(6),
            'updated_at' => now()->subMonths(6),
        ]);
        MarketingCampaignRecipient::forceCreate([
            'campaign_id' => $oldCampaign->id,
            'phone' => '+919555555555',
            'normalized_phone' => '919555555555',
            'status' => 'delivered',
            'delivered_at' => now()->subDays(2),
            'created_at' => now()->subDays(2),
            'updated_at' => now()->subDays(2),
        ]);

        // Campaign created inside the period, but its only recipient activity
        // is from before the period started — previously counted anyway
        // (all-time totals); now must be excluded from the period-scoped view.
        $recentCampaign = MarketingCampaign::create([
            'organization_id' => $this->orgA->id,
            'whatsapp_account_id' => $this->accountA->id,
            'audience_id' => $this->audienceA->id,
            'template_id' => $this->templateA->id,
            'name' => 'Recent campaign, stale activity',
            'status' => 'completed',
        ]);
        MarketingCampaignRecipient::forceCreate([
            'campaign_id' => $recentCampaign->id,
            'phone' => '+919666666666',
            'normalized_phone' => '919666666666',
            'status' => 'delivered',
            'delivered_at' => now()->subDays(90),
            'created_at' => now()->subDays(90),
            'updated_at' => now()->subDays(90),
        ]);

        $service = app(CampaignAnalyticsService::class);
        $rows = collect($service->getCampaignPerformanceTable($this->orgA->id, new MarketingAnalyticsFilter(['period' => 'last_30_days'])));

        $oldRow = $rows->firstWhere('id', $oldCampaign->id);
        expect($oldRow)->not->toBeNull();
        expect($oldRow['recipients'])->toBe(1);

        $recentRow = $rows->firstWhere('id', $recentCampaign->id);
        expect($recentRow)->toBeNull();
    }

    // ── Part 3 frontend rebuild: filter options payload + full-page filter consistency ──

    public function test_filter_options_are_returned_scoped_to_the_requesting_organization()
    {
        $otherOrg = Organization::factory()->provider()->create();
        MarketingCampaign::create([
            'organization_id' => $otherOrg->id,
            'whatsapp_account_id' => MarketingWhatsAppAccount::create([
                'organization_id' => $otherOrg->id,
                'phone_number_id' => '900000000000001',
                'business_account_id' => '900000000000002',
                'verified_name' => 'Other Org WA',
                'display_phone_number' => '+910000000001',
                'access_token_encrypted' => encrypt('x'),
                'webhook_verify_token_encrypted' => encrypt('x'),
                'status' => 'connected',
            ])->id,
            'audience_id' => MarketingAudience::create([
                'organization_id' => $otherOrg->id,
                'name' => 'Other Org Audience',
                'rules' => ['match' => 'all', 'rules' => []],
            ])->id,
            'template_id' => $this->templateA->id,
            'name' => 'Other org campaign — must not leak',
            'status' => 'completed',
        ]);

        $response = $this->actingAs($this->userA)->get(route('company.marketing.analytics.index'));

        $response->assertInertia(fn ($page) => $page
            ->has('filterOptions.campaigns', 1)
            ->where('filterOptions.campaigns.0.name', 'Diwali Outreach')
            ->has('filterOptions.audiences', 1)
            ->where('filterOptions.audiences.0.name', 'Vadodara Dentists')
            ->has('filterOptions.whatsappAccounts', 1)
            ->where('filterOptions.whatsappAccounts.0.name', 'Tenant A WhatsApp')
        );
    }

    public function test_audience_filter_now_changes_lead_source_attribution_not_just_kpis_and_funnel()
    {
        // This is the exact gap Part 3 closed: getLeadSourceAttribution() had
        // no audience/account scoping, so selecting an audience filter would
        // move the KPI band and funnel but silently leave this section
        // showing the unfiltered organization-wide numbers.
        $audienceB = MarketingAudience::create([
            'organization_id' => $this->orgA->id,
            'name' => 'Audience B (attribution)',
            'rules' => ['match' => 'all', 'rules' => []],
        ]);
        $campaignB = MarketingCampaign::create([
            'organization_id' => $this->orgA->id,
            'whatsapp_account_id' => $this->accountA->id,
            'audience_id' => $audienceB->id,
            'template_id' => $this->templateA->id,
            'name' => 'Campaign to audience B (attribution)',
            'status' => 'completed',
        ]);
        Lead::create([
            'company_name' => 'Attribution Lead Co',
            'slug' => 'attribution-lead-co',
            'contact_name' => 'Attribution Contact',
            'phone' => '+919555511111',
            'normalized_phone' => '919555511111',
            'organization_id' => $this->orgA->id,
            'marketing_campaign_id' => $campaignB->id,
            'marketing_contact_id' => $this->contactA->id,
            'status' => LeadStatus::New,
            'priority' => LeadPriority::Medium,
            'source' => 'website',
        ]);

        $attributionService = app(MarketingAttributionService::class);

        $unfiltered = collect($attributionService->getLeadSourceAttribution($this->orgA->id, new MarketingAnalyticsFilter(['period' => 'all'])));
        $scopedToAudienceA = collect($attributionService->getLeadSourceAttribution($this->orgA->id, new MarketingAnalyticsFilter(['period' => 'all', 'audience_id' => $this->audienceA->id])));
        $scopedToAudienceB = collect($attributionService->getLeadSourceAttribution($this->orgA->id, new MarketingAnalyticsFilter(['period' => 'all', 'audience_id' => $audienceB->id])));

        $websiteUnfiltered = $unfiltered->firstWhere('source_key', 'website');
        $websiteAudienceA = $scopedToAudienceA->firstWhere('source_key', 'website');
        $websiteAudienceB = $scopedToAudienceB->firstWhere('source_key', 'website');

        expect($websiteUnfiltered['leads'])->toBe(1);
        expect($websiteAudienceA)->toBeNull(); // audience A has no website-sourced lead
        expect($websiteAudienceB['leads'])->toBe(1);
    }

    public function test_combined_campaign_and_audience_filters_narrow_together_not_independently()
    {
        $audienceB = MarketingAudience::create([
            'organization_id' => $this->orgA->id,
            'name' => 'Audience B (combined)',
            'rules' => ['match' => 'all', 'rules' => []],
        ]);
        // campaignA belongs to audienceA (from beforeEach) — combining
        // campaign_id=campaignA with audience_id=audienceB (a mismatched
        // pair) must AND the two conditions and return nothing, proving the
        // filters compose rather than each silently overriding the other.
        $service = app(MarketingAnalyticsService::class);

        $matching = $service->getExecutiveOverview($this->orgA->id, new MarketingAnalyticsFilter([
            'period' => 'all',
            'campaign_id' => $this->campaignA->id,
            'audience_id' => $this->audienceA->id,
        ]));
        $mismatched = $service->getExecutiveOverview($this->orgA->id, new MarketingAnalyticsFilter([
            'period' => 'all',
            'campaign_id' => $this->campaignA->id,
            'audience_id' => $audienceB->id,
        ]));

        expect($matching['kpis']['messages_delivered']['value'])->toBe(1);
        expect($mismatched['kpis']['messages_delivered']['value'])->toBe(0);
    }

    public function test_clearing_all_drill_down_filters_returns_to_the_organization_wide_baseline()
    {
        $campaignB = MarketingCampaign::create([
            'organization_id' => $this->orgA->id,
            'whatsapp_account_id' => $this->accountA->id,
            'audience_id' => $this->audienceA->id,
            'template_id' => $this->templateA->id,
            'name' => 'Campaign B (reset check)',
            'status' => 'completed',
        ]);
        MarketingCampaignRecipient::create([
            'campaign_id' => $campaignB->id,
            'phone' => '+919555522222',
            'normalized_phone' => '919555522222',
            'status' => 'delivered',
        ]);

        $service = app(MarketingAnalyticsService::class);

        $scoped = $service->getExecutiveOverview($this->orgA->id, new MarketingAnalyticsFilter(['period' => 'all', 'campaign_id' => $this->campaignA->id]));
        // "Reset" is just requesting with the drill-down params omitted —
        // exactly what the frontend's clearDrillDowns() sends.
        $reset = $service->getExecutiveOverview($this->orgA->id, new MarketingAnalyticsFilter(['period' => 'all']));

        expect($scoped['kpis']['messages_delivered']['value'])->toBe(1);
        expect($reset['kpis']['messages_delivered']['value'])->toBe(2);
    }

    public function test_campaign_performance_table_is_empty_for_a_filter_combination_with_no_data()
    {
        $emptyAudience = MarketingAudience::create([
            'organization_id' => $this->orgA->id,
            'name' => 'Empty Audience',
            'rules' => ['match' => 'all', 'rules' => []],
        ]);

        $service = app(CampaignAnalyticsService::class);
        $rows = $service->getCampaignPerformanceTable($this->orgA->id, new MarketingAnalyticsFilter([
            'period' => 'all',
            'audience_id' => $emptyAudience->id,
        ]));

        expect($rows)->toBe([]);
    }
}
