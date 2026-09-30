<?php

namespace Tests\Feature\Marketing;

use App\Models\MarketingAudience;
use App\Models\MarketingCampaign;
use App\Models\MarketingContact;
use App\Models\MarketingWhatsAppAccount;
use App\Models\MarketingWhatsAppTemplate;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MarketingOverviewTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $org;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create([
            'name' => 'OralCares India',
        ]);

        $this->user = User::factory()->create([
            'organization_id' => $this->org->id,
            'email' => 'rahul.overview@oralcares.in',
            'user_type' => 'company_user',
        ]);
        grantMarketingPermissions($this->user);
    }

    public function test_can_access_marketing_overview_command_center(): void
    {
        // Seed some sample marketing operational data for org
        MarketingContact::create([
            'organization_id' => $this->org->id,
            'name' => 'Dr. Rajesh Sharma',
            'phone' => '+919876543210',
            'normalized_phone' => '919876543210',
            'marketing_status' => 'subscribed',
        ]);

        MarketingContact::create([
            'organization_id' => $this->org->id,
            'name' => 'Dr. Sunita Verma',
            'phone' => '+919876543211',
            'normalized_phone' => '919876543211',
            'marketing_status' => 'suppressed',
        ]);

        $account = MarketingWhatsAppAccount::create([
            'organization_id' => $this->org->id,
            'phone_number_id' => '100000000000001',
            'waba_id' => '200000000000001',
            'verified_name' => 'OralCares WhatsApp',
            'display_phone_number' => '+919876543210',
            'access_token_encrypted' => encrypt('fake-token'),
            'webhook_verify_token_encrypted' => encrypt('fake-verify'),
            'status' => 'connected',
        ]);

        $audience = MarketingAudience::create([
            'organization_id' => $this->org->id,
            'name' => 'Delhi Dental Specialists',
            'status' => 'active',
            'rules' => ['match' => 'all', 'rules' => []],
        ]);

        $template = MarketingWhatsAppTemplate::create([
            'organization_id' => $this->org->id,
            'whatsapp_account_id' => $account->id,
            'name' => 'diwali_outreach',
            'language' => 'en',
            'category' => 'MARKETING',
            'status' => 'APPROVED',
            'body' => 'Happy Diwali',
            'components' => [],
        ]);

        MarketingCampaign::create([
            'organization_id' => $this->org->id,
            'whatsapp_account_id' => $account->id,
            'audience_id' => $audience->id,
            'template_id' => $template->id,
            'name' => 'Diwali Oral Care Blast',
            'status' => 'sending',
            'recipients_count' => 50,
            'sent_count' => 45,
            'delivered_count' => 40,
            'replied_count' => 12,
        ]);

        $response = $this->actingAs($this->user)
            ->get(route('company.marketing.overview'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Company/Marketing/Overview/Index')
            ->has('operationalMetrics')
            ->has('todayActivity')
            ->has('activeCampaigns')
            ->has('needsAttention')
            ->has('recentImports')
            ->has('instagramStatus')
            ->has('safetyGovernance')
            ->where('operationalMetrics.contacts.total', 2)
            ->where('operationalMetrics.compliance.suppressed', 1)
            ->where('operationalMetrics.campaigns.total_active', 1)
            ->where('operationalMetrics.campaigns.sending', 1)
        );
    }

    public function test_overview_respects_tenant_isolation(): void
    {
        $orgOther = Organization::factory()->create();
        MarketingContact::create([
            'organization_id' => $orgOther->id,
            'name' => 'Other Org Contact',
            'phone' => '+919999999999',
            'normalized_phone' => '919999999999',
            'marketing_status' => 'subscribed',
        ]);

        $response = $this->actingAs($this->user)
            ->get(route('company.marketing.overview'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->where('kpis.contacts.total', 0)
        );
    }
}
