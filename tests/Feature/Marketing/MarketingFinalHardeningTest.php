<?php

namespace Tests\Feature\Marketing;

use App\Jobs\SendCampaignMessageJob;
use App\Models\MarketingContact;
use App\Models\MarketingTag;
use App\Models\MarketingWhatsAppAccount;
use App\Models\MarketingWhatsAppTemplate;
use App\Models\Organization;
use App\Models\User;
use App\Services\Marketing\MarketingSettingsService;
use Database\Seeders\MarketingPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class MarketingFinalHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $orgA;

    protected Organization $orgB;

    protected User $userA;

    protected User $userB;

    protected MarketingWhatsAppAccount $accountA;

    protected MarketingWhatsAppTemplate $templateA;

    protected MarketingContact $contact1;

    protected MarketingContact $contact2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orgA = Organization::factory()->create(['name' => 'Tenant A']);
        $this->orgB = Organization::factory()->create(['name' => 'Tenant B']);

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
            'display_name' => 'Account A',
            'display_phone_number' => '+919876543210',
            'access_token_encrypted' => encrypt('token'),
            'webhook_verify_token_encrypted' => encrypt('verify'),
            'status' => 'connected',
        ]);

        $this->templateA = MarketingWhatsAppTemplate::create([
            'organization_id' => $this->orgA->id,
            'whatsapp_account_id' => $this->accountA->id,
            'name' => 'welcome_template',
            'language' => 'en',
            'category' => 'MARKETING',
            'status' => 'approved',
            'body' => 'Hello',
            'components' => [],
        ]);

        $this->contact1 = MarketingContact::create([
            'organization_id' => $this->orgA->id,
            'name' => 'Dr. Contact One',
            'phone' => '+919876543211',
            'normalized_phone' => '919876543211',
            'marketing_status' => 'active',
        ]);

        $this->contact2 = MarketingContact::create([
            'organization_id' => $this->orgA->id,
            'name' => 'Dr. Contact Two',
            'phone' => '+919876543212',
            'normalized_phone' => '919876543212',
            'marketing_status' => 'active',
        ]);
    }

    public function test_spatie_marketing_permissions_seeder_registers_all_governance_permissions()
    {
        $this->seed(MarketingPermissionsSeeder::class);

        $this->assertTrue(Permission::where('name', 'view-marketing-settings')->exists());
        $this->assertTrue(Permission::where('name', 'manage-marketing-settings')->exists());
        $this->assertTrue(Permission::where('name', 'emergency-marketing-killswitch')->exists());
    }

    public function test_user_can_bulk_add_tag_to_selected_contacts()
    {
        $response = $this->actingAs($this->userA)
            ->post(route('company.marketing.contacts.bulk-tag'), [
                'contact_ids' => [$this->contact1->id, $this->contact2->id],
                'tag_name' => 'VIP Doctor',
                'action' => 'add',
            ]);

        $response->assertRedirect();

        $tag = MarketingTag::where('organization_id', $this->orgA->id)->where('name', 'VIP Doctor')->first();
        $this->assertNotNull($tag);
        $this->assertTrue($this->contact1->tags->contains($tag->id));
        $this->assertTrue($this->contact2->tags->contains($tag->id));
    }

    public function test_user_can_bulk_remove_tag_from_selected_contacts()
    {
        $tag = MarketingTag::create(['organization_id' => $this->orgA->id, 'name' => 'Temporary', 'slug' => 'temporary']);
        $this->contact1->tags()->attach($tag->id);

        $response = $this->actingAs($this->userA)
            ->post(route('company.marketing.contacts.bulk-tag'), [
                'contact_ids' => [$this->contact1->id],
                'tag_name' => 'Temporary',
                'action' => 'remove',
            ]);

        $response->assertRedirect();
        $this->contact1->refresh();
        $this->assertFalse($this->contact1->tags->contains($tag->id));
    }

    public function test_cross_tenant_bulk_tagging_is_blocked()
    {
        $contactB = MarketingContact::create([
            'organization_id' => $this->orgB->id,
            'name' => 'Tenant B Contact',
            'phone' => '+919999911111',
            'normalized_phone' => '919999911111',
            'marketing_status' => 'active',
        ]);

        // User A attempts to tag Tenant B contact
        $response = $this->actingAs($this->userA)
            ->post(route('company.marketing.contacts.bulk-tag'), [
                'contact_ids' => [$contactB->id],
                'tag_name' => 'Unauthorized Tag',
                'action' => 'add',
            ]);

        $response->assertRedirect();
        $contactB->refresh();
        $this->assertCount(0, $contactB->tags);
    }

    public function test_send_campaign_message_job_configures_exponential_backoff_strategy()
    {
        $job = new SendCampaignMessageJob(1);

        $this->assertEquals(3, $job->tries);
        $this->assertEquals([60, 180, 300], $job->backoff);
    }

    public function test_campaign_builder_receives_quiet_hours_active_prop()
    {
        app(MarketingSettingsService::class)->updateSettings($this->orgA->id, [
            'quiet_hours_enabled' => true,
            'quiet_hours_start' => '00:00',
            'quiet_hours_end' => '23:59',
        ]);

        $response = $this->actingAs($this->userA)
            ->get(route('company.marketing.campaigns.create'));

        $response->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Company/Marketing/Campaigns/Builder')
                ->where('quiet_hours_active', true)
            );
    }
}
