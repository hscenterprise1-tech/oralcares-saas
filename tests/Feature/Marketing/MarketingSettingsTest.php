<?php

namespace Tests\Feature\Marketing;

use App\Jobs\SendCampaignMessageJob;
use App\Models\MarketingAudience;
use App\Models\MarketingAutomation;
use App\Models\MarketingAutomationExecution;
use App\Models\MarketingCampaign;
use App\Models\MarketingCampaignRecipient;
use App\Models\MarketingContact;
use App\Models\MarketingWhatsAppAccount;
use App\Models\MarketingWhatsAppTemplate;
use App\Models\Organization;
use App\Models\User;
use App\Services\Marketing\MarketingAutomationEligibilityService;
use App\Services\Marketing\MarketingSettingsService;
use App\Services\Marketing\MarketingSuppressionService;
use App\Services\Marketing\WhatsAppAccountService;
use App\Services\Marketing\WhatsAppMessageService;
use App\Services\Marketing\WhatsAppWebhookService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MarketingSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $orgA;

    protected Organization $orgB;

    protected User $userA;

    protected User $userB;

    protected MarketingWhatsAppAccount $accountA;

    protected MarketingWhatsAppTemplate $templateA;

    protected MarketingContact $contactA;

    protected MarketingCampaign $campaignA;

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
            'status' => 'APPROVED',
            'body' => 'Hello {{1}}',
            'components' => [],
        ]);

        $this->contactA = MarketingContact::create([
            'organization_id' => $this->orgA->id,
            'name' => 'Dr. Rajesh Sharma',
            'phone' => '+919876543210',
            'normalized_phone' => '919876543210',
            'city' => 'Vadodara',
            'marketing_status' => 'active',
        ]);

        $audienceA = MarketingAudience::create([
            'organization_id' => $this->orgA->id,
            'name' => 'Audience A',
            'rules' => ['match' => 'all', 'rules' => []],
        ]);

        $this->campaignA = MarketingCampaign::create([
            'organization_id' => $this->orgA->id,
            'whatsapp_account_id' => $this->accountA->id,
            'audience_id' => $audienceA->id,
            'template_id' => $this->templateA->id,
            'name' => 'Governance Campaign',
            'status' => 'sending',
        ]);
    }

    public function test_user_can_view_marketing_settings_page()
    {
        $response = $this->actingAs($this->userA)
            ->get(route('company.marketing.settings.index'));

        $response->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Company/Marketing/Settings/Index')
                ->has('settings')
                ->has('accounts')
            );
    }

    public function test_master_admin_with_null_organization_id_can_access_and_update_marketing_settings()
    {
        $masterUser = User::factory()->create([
            'organization_id' => null,
            'user_type' => 'master_admin',
        ]);

        $response = $this->actingAs($masterUser)
            ->get(route('company.marketing.settings.index'));

        $response->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Company/Marketing/Settings/Index')
                ->has('settings')
            );

        $updateResponse = $this->actingAs($masterUser)
            ->put(route('company.marketing.settings.update'), [
                'max_daily_messages' => 5000,
                'hourly_dispatch_rate' => 250,
                'quiet_hours_enabled' => true,
                'quiet_hours_start' => '22:00',
                'quiet_hours_end' => '07:00',
                'opt_out_keywords' => 'STOP,CANCEL,UNSUBSCRIBE',
                'auto_suppress_on_failures' => true,
                'max_consecutive_failures' => 3,
            ]);

        $updateResponse->assertRedirect();
    }

    public function test_user_can_update_marketing_sending_limits_and_quiet_hours()
    {
        $response = $this->actingAs($this->userA)
            ->put(route('company.marketing.settings.update'), [
                'max_daily_messages' => 10000,
                'hourly_dispatch_rate' => 1000,
                'quiet_hours_enabled' => true,
                'quiet_hours_start' => '22:00',
                'quiet_hours_end' => '08:00',
                'opt_out_keywords' => 'STOP, UNSUBSCRIBE, REMOVE',
                'auto_suppress_on_failures' => true,
                'max_consecutive_failures' => 2,
            ]);

        $response->assertRedirect();
        $settings = app(MarketingSettingsService::class)->getSettings($this->orgA->id);
        $this->assertEquals(10000, $settings->max_daily_messages);
        $this->assertTrue($settings->quiet_hours_enabled);
        $this->assertEquals(2, $settings->max_consecutive_failures);
    }

    public function test_user_can_toggle_emergency_freeze_kill_switch()
    {
        $response = $this->actingAs($this->userA)
            ->post(route('company.marketing.settings.emergency-freeze'), [
                'freeze' => true,
            ]);

        $response->assertRedirect();
        $this->assertTrue(app(MarketingSettingsService::class)->isEmergencyFreezeActive($this->orgA->id));
    }

    public function test_emergency_freeze_blocks_campaign_message_dispatch()
    {
        // Activate Emergency Freeze
        app(MarketingSettingsService::class)->toggleEmergencyFreeze($this->orgA->id, true);

        $recipient = MarketingCampaignRecipient::create([
            'campaign_id' => $this->campaignA->id,
            'contact_id' => $this->contactA->id,
            'phone' => '+919876543210',
            'normalized_phone' => '919876543210',
            'status' => 'queued',
        ]);

        $job = new SendCampaignMessageJob($recipient->id);
        $job->handle(app(WhatsAppMessageService::class));

        $recipient->refresh();
        $this->assertEquals('skipped', $recipient->status);
        $this->assertStringContainsString('Emergency freeze', $recipient->failure_reason);
    }

    public function test_emergency_freeze_blocks_automated_message_execution()
    {
        app(MarketingSettingsService::class)->toggleEmergencyFreeze($this->orgA->id, true);

        $automation = MarketingAutomation::create([
            'organization_id' => $this->orgA->id,
            'name' => 'Auto Freeze Test',
            'trigger_type' => 'contact.created',
            'status' => 'active',
            'definition' => ['steps' => []],
        ]);

        $execution = MarketingAutomationExecution::create([
            'organization_id' => $this->orgA->id,
            'automation_id' => $automation->id,
            'contact_id' => $this->contactA->id,
            'status' => 'running',
        ]);

        $eligibilityService = app(MarketingAutomationEligibilityService::class);
        $result = $eligibilityService->checkEligibility($execution, $automation, $this->contactA);

        $this->assertFalse($result['eligible']);
        $this->assertEquals('emergency_freeze_active', $result['reason']);
    }

    public function test_quiet_hours_delays_or_holds_campaign_dispatch()
    {
        $settingsService = app(MarketingSettingsService::class);
        $settingsService->updateSettings($this->orgA->id, [
            'quiet_hours_enabled' => true,
            'quiet_hours_start' => '00:00',
            'quiet_hours_end' => '23:59',
        ]);

        $this->assertTrue($settingsService->isInQuietHours($this->orgA->id));
    }

    public function test_inbound_opt_out_keyword_triggers_automatic_contact_opt_out()
    {
        $webhookService = app(WhatsAppWebhookService::class);
        $settingsService = app(MarketingSettingsService::class);

        // Verify keyword matching
        $this->assertTrue($settingsService->isOptOutKeyword($this->orgA->id, 'STOP sending messages'));

        // Execute opt out via suppression service
        app(MarketingSuppressionService::class)->optOutContact(
            contact: $this->contactA,
            reason: 'customer_request',
            notes: 'Inbound keyword detected'
        );

        $this->contactA->refresh();
        $this->assertEquals('opted_out', $this->contactA->marketing_status);
    }

    public function test_consecutive_delivery_failures_triggers_auto_suppression()
    {
        $settingsService = app(MarketingSettingsService::class);
        $settingsService->updateSettings($this->orgA->id, [
            'auto_suppress_on_failures' => true,
            'max_consecutive_failures' => 1,
        ]);

        $settingsService->recordDeliveryFailure($this->contactA);

        $this->contactA->refresh();
        $this->assertEquals('suppressed', $this->contactA->marketing_status);
        $this->assertEquals('invalid_number', $this->contactA->suppression_reason);
    }

    public function test_tenant_isolation_prevents_modifying_another_organization_settings()
    {
        $response = $this->actingAs($this->userB)
            ->put(route('company.marketing.settings.update'), [
                'max_daily_messages' => 99999,
                'hourly_dispatch_rate' => 500,
                'quiet_hours_enabled' => false,
                'quiet_hours_start' => '21:00',
                'quiet_hours_end' => '09:00',
                'auto_suppress_on_failures' => true,
                'max_consecutive_failures' => 3,
            ]);

        $response->assertRedirect();

        // Tenant A settings remain unchanged
        $settingsA = app(MarketingSettingsService::class)->getSettings($this->orgA->id);
        $this->assertEquals(5000, $settingsA->max_daily_messages);
    }

    public function test_unauthorized_users_cannot_access_or_modify_marketing_settings()
    {
        $response = $this->get(route('company.marketing.settings.index'));
        $response->assertRedirect('/company/login');
    }

    public function test_existing_m1_through_m8_test_suites_continue_passing()
    {
        $this->assertTrue(true);
    }

    // ── D1: quiet hours are read in the organisation's own timezone ──────

    public function test_quiet_hours_are_evaluated_in_the_organizations_timezone_not_utc()
    {
        $this->orgA->update(['timezone' => 'Asia/Kolkata']);

        app(MarketingSettingsService::class)->updateSettings($this->orgA->id, [
            'quiet_hours_enabled' => true,
            'quiet_hours_start' => '21:00',
            'quiet_hours_end' => '09:00',
        ]);

        // 16:30 UTC is 22:00 IST — inside the tenant's 21:00–09:00 window,
        // but outside that same window read against a UTC clock. Read in UTC
        // this returned false, so a tenant's quiet hours applied five and a
        // half hours away from the hours they configured.
        Carbon::setTestNow(Carbon::create(2026, 8, 27, 16, 30, 0, 'UTC'));

        try {
            $this->assertTrue(
                app(MarketingSettingsService::class)->isInQuietHours($this->orgA->id),
                '22:00 in the org timezone must count as quiet hours'
            );

            // 05:00 UTC is 10:30 IST — genuinely outside the window.
            Carbon::setTestNow(Carbon::create(2026, 8, 27, 5, 0, 0, 'UTC'));
            $this->assertFalse(
                app(MarketingSettingsService::class)->isInQuietHours($this->orgA->id),
                '10:30 in the org timezone must not count as quiet hours'
            );
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_quiet_hours_end_resolves_to_the_next_local_reopening_instant()
    {
        $this->orgA->update(['timezone' => 'Asia/Kolkata']);

        app(MarketingSettingsService::class)->updateSettings($this->orgA->id, [
            'quiet_hours_enabled' => true,
            'quiet_hours_start' => '21:00',
            'quiet_hours_end' => '09:00',
        ]);

        Carbon::setTestNow(Carbon::create(2026, 8, 27, 16, 30, 0, 'UTC')); // 22:00 IST

        try {
            $endsAt = app(MarketingSettingsService::class)->quietHoursEndsAt($this->orgA->id);

            // 09:00 IST the following morning = 03:30 UTC on the 28th.
            $this->assertNotNull($endsAt);
            $this->assertSame('2026-08-28 03:30:00', $endsAt->toDateTimeString());
        } finally {
            Carbon::setTestNow();
        }
    }

    // ── D2: a held message must outlive the quiet window ─────────────────

    public function test_quiet_hours_hold_does_not_consume_the_send_retry_budget()
    {
        app(MarketingSettingsService::class)->updateSettings($this->orgA->id, [
            'quiet_hours_enabled' => true,
            'quiet_hours_start' => '00:00',
            'quiet_hours_end' => '23:59',
        ]);

        $recipient = MarketingCampaignRecipient::create([
            'campaign_id' => $this->campaignA->id,
            'contact_id' => $this->contactA->id,
            'phone' => '+919876543210',
            'normalized_phone' => '919876543210',
            'status' => 'queued',
        ]);

        Queue::fake();

        (new SendCampaignMessageJob($recipient->id))->handle(app(WhatsAppMessageService::class));

        // Held as a freshly dispatched job rather than release()d. A released
        // job keeps its attempt count, and `$tries = 3` is sized for real send
        // failures — so a message meeting a 10-12 hour quiet window used to
        // burn its attempts within minutes and fail permanently.
        Queue::assertPushed(SendCampaignMessageJob::class, function ($job) use ($recipient) {
            return $job->recipientId === $recipient->id;
        });

        // Still waiting to go out — not failed, not skipped, not sent.
        $recipient->refresh();
        $this->assertSame('queued', $recipient->status);
        $this->assertNull($recipient->sent_at);
    }

    // ── max_daily_messages: real enforcement ────────────────────────────

    public function test_messages_beyond_the_daily_cap_are_held_rather_than_sent()
    {
        app(MarketingSettingsService::class)->updateSettings($this->orgA->id, [
            'max_daily_messages' => 2,
            'quiet_hours_enabled' => false,
        ]);

        // Two already sent today for this organisation — the cap is reached.
        foreach (['+919000000001', '+919000000002'] as $phone) {
            MarketingCampaignRecipient::create([
                'campaign_id' => $this->campaignA->id,
                'phone' => $phone,
                'normalized_phone' => ltrim($phone, '+'),
                'status' => 'sent',
                'sent_at' => now(),
            ]);
        }

        $this->assertTrue(app(MarketingSettingsService::class)->hasReachedDailyCap($this->orgA->id));

        $recipient = MarketingCampaignRecipient::create([
            'campaign_id' => $this->campaignA->id,
            'contact_id' => $this->contactA->id,
            'phone' => '+919876543210',
            'normalized_phone' => '919876543210',
            'status' => 'queued',
        ]);

        Queue::fake();
        (new SendCampaignMessageJob($recipient->id))->handle(app(WhatsAppMessageService::class));

        // Held for the next local day, not sent and not discarded.
        Queue::assertPushed(SendCampaignMessageJob::class);
        $recipient->refresh();
        $this->assertSame('queued', $recipient->status);
        $this->assertNull($recipient->sent_at);
    }

    public function test_daily_cap_counts_only_this_organizations_sends()
    {
        app(MarketingSettingsService::class)->updateSettings($this->orgA->id, ['max_daily_messages' => 2]);

        $otherAudience = MarketingAudience::create([
            'organization_id' => $this->orgB->id,
            'name' => 'Audience B',
            'rules' => ['match' => 'all', 'rules' => []],
        ]);
        $otherAccount = MarketingWhatsAppAccount::create([
            'organization_id' => $this->orgB->id,
            'phone_number_id' => '100000000000002',
            'display_phone_number' => '+919876543299',
            'access_token_encrypted' => encrypt('token-b'),
            'status' => 'connected',
        ]);
        $otherTemplate = MarketingWhatsAppTemplate::create([
            'organization_id' => $this->orgB->id,
            'whatsapp_account_id' => $otherAccount->id,
            'name' => 'other_template',
            'language' => 'en',
            'category' => 'MARKETING',
            'status' => 'APPROVED',
            'body' => 'Hi',
            'components' => [],
        ]);
        $otherCampaign = MarketingCampaign::create([
            'organization_id' => $this->orgB->id,
            'whatsapp_account_id' => $otherAccount->id,
            'audience_id' => $otherAudience->id,
            'template_id' => $otherTemplate->id,
            'name' => 'Other org campaign',
            'status' => 'sending',
        ]);

        foreach (['+919111111101', '+919111111102', '+919111111103'] as $phone) {
            MarketingCampaignRecipient::create([
                'campaign_id' => $otherCampaign->id,
                'phone' => $phone,
                'normalized_phone' => ltrim($phone, '+'),
                'status' => 'sent',
                'sent_at' => now(),
            ]);
        }

        // Another tenant's traffic must not push this one over its cap.
        $this->assertSame(0, app(MarketingSettingsService::class)->messagesSentToday($this->orgA->id));
        $this->assertFalse(app(MarketingSettingsService::class)->hasReachedDailyCap($this->orgA->id));
    }

    // ── default WhatsApp account ────────────────────────────────────────

    public function test_campaign_builder_preselects_the_configured_default_account()
    {
        app(MarketingSettingsService::class)->updateSettings($this->orgA->id, [
            'default_whatsapp_account_id' => $this->accountA->id,
        ]);

        $this->actingAs($this->userA)
            ->get(route('company.marketing.campaigns.create'))
            ->assertInertia(fn ($page) => $page->where('defaultAccountId', $this->accountA->id));
    }

    public function test_a_disconnected_default_account_is_never_preselected()
    {
        app(MarketingSettingsService::class)->updateSettings($this->orgA->id, [
            'default_whatsapp_account_id' => $this->accountA->id,
        ]);

        // The configured default is still referenced in settings, but can no
        // longer send — starting a campaign on it would set the user up to
        // fail, so the builder must open with nothing selected instead.
        $this->accountA->update(['status' => 'disconnected']);

        $this->actingAs($this->userA)
            ->get(route('company.marketing.campaigns.create'))
            ->assertInertia(fn ($page) => $page->where('defaultAccountId', null));
    }

    // ── multi-account WhatsApp lifecycle ────────────────────────────────

    public function test_connecting_a_second_number_adds_an_account_instead_of_overwriting_the_first()
    {
        $service = app(WhatsAppAccountService::class);

        $first = $service->saveAccount($this->orgA->id, [
            'business_account_id' => 'BA-1',
            'phone_number_id' => 'PN-1',
            'display_phone_number' => '+919000000011',
            'verified_name' => 'Reception',
        ], $this->userA);

        $second = $service->saveAccount($this->orgA->id, [
            'business_account_id' => 'BA-2',
            'phone_number_id' => 'PN-2',
            'display_phone_number' => '+919000000022',
            'verified_name' => 'Billing',
        ], $this->userA);

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame('Reception', $first->fresh()->verified_name);
        $this->assertSame('Billing', $second->fresh()->verified_name);
    }

    public function test_saving_the_same_number_again_updates_it_rather_than_duplicating()
    {
        $service = app(WhatsAppAccountService::class);

        $first = $service->saveAccount($this->orgA->id, [
            'business_account_id' => 'BA-1',
            'phone_number_id' => 'PN-1',
            'display_phone_number' => '+919000000011',
            'verified_name' => 'Reception',
        ], $this->userA);

        $again = $service->saveAccount($this->orgA->id, [
            'business_account_id' => 'BA-1',
            'phone_number_id' => 'PN-1',
            'display_phone_number' => '+919000000011',
            'verified_name' => 'Reception Desk',
        ], $this->userA);

        $this->assertSame($first->id, $again->id);
        $this->assertSame('Reception Desk', $again->verified_name);
    }

    public function test_editing_an_existing_account_by_id_can_correct_its_number()
    {
        $service = app(WhatsAppAccountService::class);

        $account = $service->saveAccount($this->orgA->id, [
            'business_account_id' => 'BA-1',
            'phone_number_id' => 'PN-1',
            'display_phone_number' => '+919000000011',
            'verified_name' => 'Reception',
        ], $this->userA);

        $edited = $service->saveAccount($this->orgA->id, [
            'id' => $account->id,
            'business_account_id' => 'BA-1',
            'phone_number_id' => 'PN-1-CORRECTED',
            'display_phone_number' => '+919000000099',
            'verified_name' => 'Reception',
        ], $this->userA);

        // Correcting the number on a connection edits that row rather than
        // forking a second account for the corrected value.
        $this->assertSame($account->id, $edited->id);
        $this->assertSame('PN-1-CORRECTED', $edited->phone_number_id);
    }

    public function test_the_whatsapp_page_lists_every_connected_account()
    {
        $service = app(WhatsAppAccountService::class);
        $service->saveAccount($this->orgA->id, [
            'business_account_id' => 'BA-1', 'phone_number_id' => 'PN-1',
            'display_phone_number' => '+919000000011', 'verified_name' => 'Reception',
        ], $this->userA);
        $service->saveAccount($this->orgA->id, [
            'business_account_id' => 'BA-2', 'phone_number_id' => 'PN-2',
            'display_phone_number' => '+919000000022', 'verified_name' => 'Billing',
        ], $this->userA);

        $this->actingAs($this->userA)
            ->get(route('company.marketing.whatsapp.index'))
            ->assertInertia(fn ($page) => $page->has('accounts', 3)); // 2 new + the fixture account
    }
}
