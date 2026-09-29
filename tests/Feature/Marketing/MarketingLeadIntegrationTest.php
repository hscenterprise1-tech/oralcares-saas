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
use App\Models\MarketingWhatsAppTemplate;
use App\Models\Organization;
use App\Models\User;
use App\Services\Marketing\AudienceQueryService;
use App\Services\Marketing\WhatsAppLeadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarketingLeadIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $orgA;

    protected Organization $orgB;

    protected User $userA;

    protected User $userB;

    protected MarketingWhatsAppAccount $accountA;

    protected MarketingContact $contactA;

    protected MarketingWhatsAppConversation $conversationA;

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
            'waba_id' => '200000000000001',
            'display_name' => 'Tenant A WhatsApp',
            'display_phone_number' => '+919876543210',
            'access_token_encrypted' => encrypt('fake-token'),
            'webhook_verify_token_encrypted' => encrypt('fake-verify'),
            'status' => 'connected',
        ]);

        $this->contactA = MarketingContact::create([
            'organization_id' => $this->orgA->id,
            'name' => 'Dr. Rahul Patel',
            'phone' => '+919876543210',
            'normalized_phone' => '919876543210',
            'organization' => 'Vadodara Dental Clinic',
            'city' => 'Vadodara',
            'marketing_status' => 'active',
        ]);

        $this->conversationA = MarketingWhatsAppConversation::create([
            'organization_id' => $this->orgA->id,
            'whatsapp_account_id' => $this->accountA->id,
            'contact_id' => $this->contactA->id,
            'phone' => '+919876543210',
            'normalized_phone' => '919876543210',
            'status' => 'active',
            'unread_count' => 0,
        ]);
    }

    public function test_user_can_create_lead_from_whatsapp_conversation()
    {
        $response = $this->actingAs($this->userA)
            ->post(route('company.marketing.conversations.create-lead', $this->conversationA->id), [
                'company_name' => 'Vadodara Dental Clinic',
                'contact_name' => 'Dr. Rahul Patel',
                'email' => 'rahul@vadodara.com',
                'status' => 'new',
                'priority' => 'medium',
            ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('leads', [
            'company_name' => 'Vadodara Dental Clinic',
            'contact_name' => 'Dr. Rahul Patel',
            'email' => 'rahul@vadodara.com',
            'source' => 'whatsapp',
            'organization_id' => $this->orgA->id,
            'marketing_contact_id' => $this->contactA->id,
        ]);

        $this->conversationA->refresh();
        $this->assertNotNull($this->conversationA->crm_lead_id);
    }

    public function test_lead_creation_preserves_campaign_attribution_when_applicable()
    {
        $template = MarketingWhatsAppTemplate::create([
            'organization_id' => $this->orgA->id,
            'whatsapp_account_id' => $this->accountA->id,
            'name' => 'spring_promo',
            'language' => 'en',
            'category' => 'MARKETING',
            'status' => 'APPROVED',
            'body' => 'Hello {{1}}',
            'components' => [],
        ]);

        $audience = MarketingAudience::create([
            'organization_id' => $this->orgA->id,
            'name' => 'All Contacts',
            'rules' => ['tag_ids' => [], 'city' => 'all', 'marketing_status' => 'active', 'search_term' => ''],
        ]);

        $campaign = MarketingCampaign::create([
            'organization_id' => $this->orgA->id,
            'whatsapp_account_id' => $this->accountA->id,
            'audience_id' => $audience->id,
            'template_id' => $template->id,
            'name' => 'Spring Launch 2026',
            'status' => 'completed',
            'total_recipients' => 1,
        ]);

        $recipient = MarketingCampaignRecipient::create([
            'campaign_id' => $campaign->id,
            'contact_id' => $this->contactA->id,
            'phone' => $this->contactA->phone,
            'normalized_phone' => $this->contactA->normalized_phone,
            'status' => 'read',
        ]);

        $response = $this->actingAs($this->userA)
            ->post(route('company.marketing.conversations.create-lead', $this->conversationA->id), [
                'company_name' => 'Vadodara Dental Clinic',
                'contact_name' => 'Dr. Rahul Patel',
                'email' => 'rahul@vadodara.com',
            ]);

        $response->assertRedirect();

        $lead = Lead::where('marketing_contact_id', $this->contactA->id)->first();
        $this->assertNotNull($lead);
        $this->assertEquals($campaign->id, $lead->marketing_campaign_id);
        $this->assertEquals('whatsapp', $lead->source);
    }

    public function test_duplicate_lead_creation_returns_existing_lead()
    {
        $leadService = app(WhatsAppLeadService::class);

        $lead1 = $leadService->createLeadFromConversation($this->conversationA, [
            'company_name' => 'Vadodara Dental Clinic',
            'contact_name' => 'Dr. Rahul Patel',
        ], $this->userA);

        $lead2 = $leadService->createLeadFromConversation($this->conversationA, [
            'company_name' => 'Vadodara Dental Clinic',
            'contact_name' => 'Dr. Rahul Patel',
        ], $this->userA);

        $this->assertEquals($lead1->id, $lead2->id);
        $this->assertEquals(1, Lead::where('marketing_contact_id', $this->contactA->id)->count());
    }

    public function test_user_can_link_existing_lead_to_whatsapp_conversation()
    {
        $existingLead = Lead::create([
            'company_name' => 'Existing Clinic',
            'slug' => 'existing-clinic',
            'contact_name' => 'Dr. Existing',
            'phone' => '+919876543210',
            'normalized_phone' => '919876543210',
            'organization_id' => $this->orgA->id,
            'status' => LeadStatus::New,
            'priority' => LeadPriority::Medium,
            'source' => 'whatsapp',
        ]);

        $response = $this->actingAs($this->userA)
            ->post(route('company.marketing.conversations.link-lead', $this->conversationA->id), [
                'lead_id' => $existingLead->id,
            ]);

        $response->assertRedirect();

        $this->conversationA->refresh();
        $this->assertEquals($existingLead->id, $this->conversationA->crm_lead_id);
    }

    public function test_lead_status_won_marks_marketing_contact_as_suppressed()
    {
        $lead = Lead::create([
            'company_name' => 'Vadodara Dental Clinic',
            'slug' => 'vadodara-dental',
            'contact_name' => 'Dr. Rahul Patel',
            'phone' => '+919876543210',
            'normalized_phone' => '919876543210',
            'organization_id' => $this->orgA->id,
            'marketing_contact_id' => $this->contactA->id,
            'status' => LeadStatus::New,
            'priority' => LeadPriority::Medium,
            'source' => 'whatsapp',
        ]);

        $this->assertEquals('active', $this->contactA->fresh()->marketing_status);

        // Update Lead status to Won
        $lead->update(['status' => LeadStatus::Won]);

        $this->assertEquals('suppressed', $this->contactA->fresh()->marketing_status);
    }

    public function test_lead_status_won_preserves_opted_out_status_if_already_opted_out()
    {
        $this->contactA->update(['marketing_status' => 'opted_out']);

        $lead = Lead::create([
            'company_name' => 'Vadodara Dental Clinic',
            'slug' => 'vadodara-dental-optout',
            'contact_name' => 'Dr. Rahul Patel',
            'phone' => '+919876543210',
            'normalized_phone' => '919876543210',
            'organization_id' => $this->orgA->id,
            'marketing_contact_id' => $this->contactA->id,
            'status' => LeadStatus::New,
            'priority' => LeadPriority::Medium,
            'source' => 'whatsapp',
        ]);

        // Transition lead stage to Won
        $lead->update(['status' => LeadStatus::Won]);

        // Rule 26: Opt-out must be preserved!
        $this->assertEquals('opted_out', $this->contactA->fresh()->marketing_status);
    }

    public function test_suppressed_contact_is_excluded_from_future_audience_eligibility()
    {
        $this->contactA->update(['marketing_status' => 'suppressed']);

        $audience = MarketingAudience::create([
            'organization_id' => $this->orgA->id,
            'name' => 'Active Contacts Only',
            'rules' => ['tag_ids' => [], 'city' => 'all', 'marketing_status' => 'active', 'search_term' => ''],
        ]);

        $queryService = app(AudienceQueryService::class);
        $eligibleQuery = $queryService->resolveEligibleContactsQuery($audience);

        $this->assertEquals(0, $eligibleQuery->count());
    }

    public function test_cross_tenant_lead_linking_is_blocked()
    {
        $leadB = Lead::create([
            'company_name' => 'Tenant B Clinic',
            'slug' => 'tenant-b-clinic',
            'contact_name' => 'Dr. B',
            'phone' => '+919999999999',
            'organization_id' => $this->orgB->id,
            'status' => LeadStatus::New,
            'priority' => LeadPriority::Medium,
            'source' => 'whatsapp',
        ]);

        // Tenant A user tries linking Tenant A conversation to Tenant B lead
        $response = $this->actingAs($this->userA)
            ->post(route('company.marketing.conversations.link-lead', $this->conversationA->id), [
                'lead_id' => $leadB->id,
            ]);

        $response->assertStatus(404);
    }
}
