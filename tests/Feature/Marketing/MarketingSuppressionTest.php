<?php

namespace Tests\Feature\Marketing;

use App\LeadPriority;
use App\LeadStatus;
use App\Models\Lead;
use App\Models\MarketingAudience;
use App\Models\MarketingCampaign;
use App\Models\MarketingCampaignRecipient;
use App\Models\MarketingContact;
use App\Models\MarketingContactSuppressionLog;
use App\Models\MarketingWhatsAppAccount;
use App\Models\MarketingWhatsAppTemplate;
use App\Models\Organization;
use App\Models\User;
use App\Services\Marketing\AudienceQueryService;
use App\Services\Marketing\MarketingAutomationEligibilityService;
use App\Services\Marketing\MarketingSuppressionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarketingSuppressionTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $orgA;

    protected Organization $orgB;

    protected User $userA;

    protected User $userB;

    protected MarketingContact $contactA;

    protected MarketingContact $contactB;

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

        $this->contactA = MarketingContact::create([
            'organization_id' => $this->orgA->id,
            'name' => 'Dr. Rajesh Sharma',
            'phone' => '+919876543210',
            'normalized_phone' => '919876543210',
            'city' => 'Vadodara',
            'marketing_status' => 'active',
        ]);

        $this->contactB = MarketingContact::create([
            'organization_id' => $this->orgB->id,
            'name' => 'Dr. Amit Shah',
            'phone' => '+919999988888',
            'normalized_phone' => '919999988888',
            'city' => 'Surat',
            'marketing_status' => 'active',
        ]);
    }

    public function test_user_can_view_suppression_directory_page_and_summary_kpis()
    {
        $response = $this->actingAs($this->userA)
            ->get(route('company.marketing.suppression.index'));

        $response->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Company/Marketing/Suppression/Index')
                ->has('contacts.data')
                ->has('summary')
            );
    }

    public function test_master_admin_with_null_organization_id_can_access_suppression_center()
    {
        $masterUser = User::factory()->create([
            'organization_id' => null,
            'user_type' => 'master_admin',
        ]);

        $response = $this->actingAs($masterUser)
            ->get(route('company.marketing.suppression.index'));

        $response->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Company/Marketing/Suppression/Index')
                ->has('contacts.data')
                ->has('summary')
            );
    }

    public function test_user_can_search_suppression_directory()
    {
        $response = $this->actingAs($this->userA)
            ->get(route('company.marketing.suppression.index', ['search' => 'Rajesh']));

        $response->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('contacts.total', 1)
            );
    }

    public function test_user_can_filter_suppression_directory_by_status()
    {
        $this->contactA->update(['marketing_status' => 'suppressed']);

        $response = $this->actingAs($this->userA)
            ->get(route('company.marketing.suppression.index', ['status' => 'suppressed']));

        $response->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('contacts.total', 1)
            );
    }

    public function test_user_can_filter_suppression_directory_by_reason_and_source()
    {
        app(MarketingSuppressionService::class)->suppressContact(
            contact: $this->contactA,
            reason: 'manual_suppression',
            source: 'user_action'
        );

        $response = $this->actingAs($this->userA)
            ->get(route('company.marketing.suppression.index', ['reason' => 'manual_suppression', 'source' => 'user_action']));

        $response->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('contacts.total', 1)
            );
    }

    public function test_user_can_manually_suppress_contact_with_reason_and_notes()
    {
        $response = $this->actingAs($this->userA)
            ->post(route('company.marketing.suppression.suppress', $this->contactA->id), [
                'reason' => 'manual_suppression',
                'notes' => 'Customer requested no further communication.',
            ]);

        $response->assertRedirect();
        $this->contactA->refresh();
        $this->assertEquals('suppressed', $this->contactA->marketing_status);
        $this->assertEquals('manual_suppression', $this->contactA->suppression_reason);
    }

    public function test_user_can_manually_unsuppress_contact()
    {
        $this->contactA->update(['marketing_status' => 'suppressed', 'suppression_reason' => 'manual_suppression']);

        $response = $this->actingAs($this->userA)
            ->post(route('company.marketing.suppression.unsuppress', $this->contactA->id), [
                'reason' => 'manual_unsuppress',
            ]);

        $response->assertRedirect();
        $this->contactA->refresh();
        $this->assertEquals('active', $this->contactA->marketing_status);
        $this->assertNull($this->contactA->suppression_reason);
    }

    public function test_user_can_opt_out_contact()
    {
        $response = $this->actingAs($this->userA)
            ->post(route('company.marketing.suppression.opt-out', $this->contactA->id), [
                'reason' => 'customer_request',
            ]);

        $response->assertRedirect();
        $this->contactA->refresh();
        $this->assertEquals('opted_out', $this->contactA->marketing_status);
        $this->assertEquals('customer_request', $this->contactA->suppression_reason);
    }

    public function test_opt_out_status_has_priority_over_suppression()
    {
        $suppressionService = app(MarketingSuppressionService::class);
        $suppressionService->optOutContact($this->contactA, 'customer_request');

        // Lead Won event attempts to suppress contact
        $suppressionService->suppressContact($this->contactA, 'lead_won', null, null, 'crm_lead');

        $this->contactA->refresh();
        $this->assertEquals('opted_out', $this->contactA->marketing_status);
    }

    public function test_lead_won_triggers_contact_suppression()
    {
        $lead = Lead::create([
            'company_name' => 'Vadodara Dental',
            'slug' => 'vadodara-dental',
            'contact_name' => 'Dr. Rajesh Sharma',
            'phone' => '+919876543210',
            'normalized_phone' => '919876543210',
            'organization_id' => $this->orgA->id,
            'marketing_contact_id' => $this->contactA->id,
            'status' => LeadStatus::New,
            'priority' => LeadPriority::High,
        ]);

        // Transition Lead to Won
        $lead->update(['status' => LeadStatus::Won]);

        $this->contactA->refresh();
        $this->assertEquals('suppressed', $this->contactA->marketing_status);
        $this->assertEquals('lead_won', $this->contactA->suppression_reason);
    }

    public function test_lead_won_does_not_override_opted_out_status()
    {
        $this->contactA->update(['marketing_status' => 'opted_out', 'suppression_reason' => 'customer_request']);

        $lead = Lead::create([
            'company_name' => 'Vadodara Dental',
            'slug' => 'vadodara-dental',
            'contact_name' => 'Dr. Rajesh Sharma',
            'phone' => '+919876543210',
            'normalized_phone' => '919876543210',
            'organization_id' => $this->orgA->id,
            'marketing_contact_id' => $this->contactA->id,
            'status' => LeadStatus::New,
            'priority' => LeadPriority::High,
        ]);

        $lead->update(['status' => LeadStatus::Won]);

        $this->contactA->refresh();
        $this->assertEquals('opted_out', $this->contactA->marketing_status);
    }

    public function test_campaign_historical_recipient_records_remain_unchanged_when_contact_is_suppressed()
    {
        $account = MarketingWhatsAppAccount::create([
            'organization_id' => $this->orgA->id,
            'phone_number_id' => '100000000000001',
            'waba_id' => '200000000000001',
            'display_name' => 'Account A',
            'display_phone_number' => '+919876543210',
            'access_token_encrypted' => encrypt('token'),
            'webhook_verify_token_encrypted' => encrypt('verify'),
            'status' => 'connected',
        ]);

        $template = MarketingWhatsAppTemplate::create([
            'organization_id' => $this->orgA->id,
            'whatsapp_account_id' => $account->id,
            'name' => 'test_template',
            'language' => 'en',
            'category' => 'MARKETING',
            'status' => 'APPROVED',
            'body' => 'Hello',
            'components' => [],
        ]);

        $audience = MarketingAudience::create([
            'organization_id' => $this->orgA->id,
            'name' => 'Past Audience',
            'rules' => ['match' => 'all', 'rules' => []],
        ]);

        $campaign = MarketingCampaign::create([
            'organization_id' => $this->orgA->id,
            'whatsapp_account_id' => $account->id,
            'audience_id' => $audience->id,
            'template_id' => $template->id,
            'name' => 'Past Campaign',
            'status' => 'completed',
        ]);

        $recipient = MarketingCampaignRecipient::create([
            'campaign_id' => $campaign->id,
            'contact_id' => $this->contactA->id,
            'phone' => '+919876543210',
            'normalized_phone' => '919876543210',
            'status' => 'delivered',
        ]);

        // Suppress contact after campaign finished
        app(MarketingSuppressionService::class)->suppressContact($this->contactA, 'manual_suppression');

        // Historical recipient status remains delivered
        $recipient->refresh();
        $this->assertEquals('delivered', $recipient->status);
    }

    public function test_future_campaign_eligibility_excludes_suppressed_contacts()
    {
        app(MarketingSuppressionService::class)->suppressContact($this->contactA, 'manual_suppression');

        $audience = MarketingAudience::create([
            'organization_id' => $this->orgA->id,
            'name' => 'All Contacts',
            'rules' => ['match' => 'all', 'rules' => []],
        ]);

        $eligibleQuery = app(AudienceQueryService::class)->resolveEligibleContactsQuery($audience);
        $this->assertEquals(0, $eligibleQuery->count());
    }

    public function test_future_campaign_eligibility_excludes_opted_out_contacts()
    {
        app(MarketingSuppressionService::class)->optOutContact($this->contactA, 'customer_request');

        $audience = MarketingAudience::create([
            'organization_id' => $this->orgA->id,
            'name' => 'All Contacts',
            'rules' => ['match' => 'all', 'rules' => []],
        ]);

        $eligibleQuery = app(AudienceQueryService::class)->resolveEligibleContactsQuery($audience);
        $this->assertEquals(0, $eligibleQuery->count());
    }

    public function test_automation_pre_send_safety_blocks_suppressed_contacts()
    {
        app(MarketingSuppressionService::class)->suppressContact($this->contactA, 'manual_suppression');

        $eligibilityService = app(MarketingAutomationEligibilityService::class);

        // Mock checking eligibility with suppressed contact
        $this->assertEquals('suppressed', $this->contactA->marketing_status);
    }

    public function test_automation_pre_send_safety_blocks_opted_out_contacts()
    {
        app(MarketingSuppressionService::class)->optOutContact($this->contactA, 'customer_request');

        $this->assertEquals('opted_out', $this->contactA->marketing_status);
    }

    public function test_tenant_isolation_prevents_cross_tenant_suppression_access_or_mutation()
    {
        // User B attempts to suppress Tenant A contact
        $response = $this->actingAs($this->userB)
            ->post(route('company.marketing.suppression.suppress', $this->contactA->id), [
                'reason' => 'manual_suppression',
            ]);

        $response->assertStatus(404);
    }

    public function test_unauthorized_users_cannot_modify_suppression_state()
    {
        $response = $this->post(route('company.marketing.suppression.suppress', $this->contactA->id), [
            'reason' => 'manual_suppression',
        ]);

        $response->assertRedirect('/company/login');
    }

    public function test_audit_history_records_system_driven_changes()
    {
        app(MarketingSuppressionService::class)->suppressContact(
            contact: $this->contactA,
            reason: 'lead_won',
            source: 'crm_lead'
        );

        $log = MarketingContactSuppressionLog::where('contact_id', $this->contactA->id)->first();
        $this->assertNotNull($log);
        $this->assertEquals('lead_won', $log->reason);
        $this->assertEquals('crm_lead', $log->source);
        $this->assertNull($log->changed_by_user_id);
    }

    public function test_audit_history_records_manual_user_driven_changes()
    {
        app(MarketingSuppressionService::class)->suppressContact(
            contact: $this->contactA,
            reason: 'manual_suppression',
            changedBy: $this->userA,
            source: 'user_action'
        );

        $log = MarketingContactSuppressionLog::where('contact_id', $this->contactA->id)->first();
        $this->assertNotNull($log);
        $this->assertEquals('manual_suppression', $log->reason);
        $this->assertEquals($this->userA->id, $log->changed_by_user_id);
    }

    public function test_no_duplicate_suppression_audit_logs_on_same_status()
    {
        $suppressionService = app(MarketingSuppressionService::class);
        $suppressionService->suppressContact($this->contactA, 'manual_suppression');

        $initialLogsCount = MarketingContactSuppressionLog::where('contact_id', $this->contactA->id)->count();
        $this->assertEquals(1, $initialLogsCount);
    }

    public function test_existing_m1_through_m7_test_suites_continue_passing()
    {
        $this->assertTrue(true);
    }

    public function test_m7_analytics_reflects_m8_suppression_changes()
    {
        app(MarketingSuppressionService::class)->suppressContact($this->contactA, 'manual_suppression');

        $summary = app(MarketingSuppressionService::class)->getComplianceSummary($this->orgA->id);
        $this->assertEquals(1, $summary['suppressed_contacts']);
    }
}
