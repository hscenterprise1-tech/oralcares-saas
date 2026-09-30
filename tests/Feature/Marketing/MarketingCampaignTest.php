<?php

use App\Jobs\SendCampaignMessageJob;
use App\Models\MarketingAudience;
use App\Models\MarketingCampaign;
use App\Models\MarketingCampaignRecipient;
use App\Models\MarketingContact;
use App\Models\MarketingWhatsAppAccount;
use App\Models\MarketingWhatsAppMessage;
use App\Models\MarketingWhatsAppTemplate;
use App\Models\User;
use App\Services\Marketing\CampaignPreparationService;
use App\Services\Marketing\WhatsAppMessageService;
use App\Services\Marketing\WhatsAppTemplateService;
use App\Services\Marketing\WhatsAppWebhookService;
use App\UserType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create([
        'user_type' => UserType::CompanyUser,
    ]);
    grantMarketingPermissions($this->user);

    $this->account = MarketingWhatsAppAccount::create([
        'provider' => 'meta',
        'business_account_id' => '1029384756',
        'phone_number_id' => '9876543210123',
        'display_phone_number' => '+91 9876543210',
        'verified_name' => 'OralCares WhatsApp',
        'access_token_encrypted' => Crypt::encryptString('test_token'),
        'status' => 'connected',
    ]);

    $this->template = MarketingWhatsAppTemplate::create([
        'whatsapp_account_id' => $this->account->id,
        'name' => 'oralcares_intro_demo',
        'language' => 'en',
        'category' => 'MARKETING',
        'status' => 'approved',
        'body' => 'Hello {{1}}, welcome to OralCares software!',
        'variables' => ['1'],
    ]);

    $this->audience = MarketingAudience::create([
        'name' => 'Vadodara Dentists',
        'rules' => ['city' => 'Vadodara'],
    ]);

    $this->contact = MarketingContact::create([
        'name' => 'Dr. Rahul Patel',
        'phone' => '+91 9876543210',
        'normalized_phone' => '9876543210',
        'city' => 'Vadodara',
        'marketing_status' => 'active',
    ]);
});

test('user can sync templates from whatsapp provider', function () {
    $service = app(WhatsAppTemplateService::class);
    $res = $service->syncTemplates($this->account);

    expect($res['total'])->toBeGreaterThan(0);
    $this->assertDatabaseHas('marketing_whatsapp_templates', [
        'whatsapp_account_id' => $this->account->id,
        'name' => 'oralcares_intro_demo',
        'status' => 'approved',
    ]);
});

test('user can create campaign draft and variable mapping', function () {
    $response = $this->actingAs($this->user)->post(route('company.marketing.campaigns.store'), [
        'name' => 'Spring Dentist Promo 2026',
        'whatsapp_account_id' => $this->account->id,
        'audience_id' => $this->audience->id,
        'template_id' => $this->template->id,
        'variable_mapping' => ['1' => 'name'],
        'action' => 'save_draft',
    ]);

    $response->assertRedirect();

    $this->assertDatabaseHas('marketing_campaigns', [
        'name' => 'Spring Dentist Promo 2026',
        'status' => 'draft',
    ]);
});

test('campaign launch creates deterministic recipient snapshot and excludes suppressed contacts', function () {
    Queue::fake();

    // Create eligible contact
    $eligible = MarketingContact::create([
        'name' => 'Dr. Amit Shah',
        'phone' => '+91 9999911111',
        'normalized_phone' => '9999911111',
        'city' => 'Vadodara',
        'marketing_status' => 'active',
    ]);

    // Create opted-out contact (MUST BE EXCLUDED)
    $optedOut = MarketingContact::create([
        'name' => 'Dr. Opted Out',
        'phone' => '+91 9999922222',
        'normalized_phone' => '9999922222',
        'city' => 'Vadodara',
        'marketing_status' => 'opted_out',
    ]);

    $campaign = MarketingCampaign::create([
        'whatsapp_account_id' => $this->account->id,
        'audience_id' => $this->audience->id,
        'template_id' => $this->template->id,
        'name' => 'Targeted Vadodara Campaign',
        'status' => 'draft',
        'variable_mapping' => ['1' => 'name'],
    ]);

    $service = app(CampaignPreparationService::class);
    $res = $service->prepareAndLaunchCampaign($campaign);

    expect($res['jobs_dispatched'])->toBe(2); // $this->contact + $eligible

    // Recipient snapshot created
    expect(MarketingCampaignRecipient::where('campaign_id', $campaign->id)->count())->toBe(2);

    // Opted out contact was NOT included in snapshot
    $this->assertDatabaseMissing('marketing_campaign_recipients', [
        'campaign_id' => $campaign->id,
        'contact_id' => $optedOut->id,
    ]);

    Queue::assertPushed(SendCampaignMessageJob::class, 2);
});

test('send campaign message job executes dispatch and handles late contact opt-out safety', function () {
    $campaign = MarketingCampaign::create([
        'whatsapp_account_id' => $this->account->id,
        'audience_id' => $this->audience->id,
        'template_id' => $this->template->id,
        'name' => 'Live Job Test',
        'status' => 'sending',
    ]);

    $recipient = MarketingCampaignRecipient::create([
        'campaign_id' => $campaign->id,
        'contact_id' => $this->contact->id,
        'phone' => $this->contact->phone,
        'normalized_phone' => $this->contact->normalized_phone,
        'status' => 'queued',
        'personalized_payload' => [
            'template_name' => $this->template->name,
            'template_language' => $this->template->language,
            'rendered_body' => 'Hello Dr. Rahul Patel, welcome to OralCares software!',
            'variable_mapping' => ['1' => 'name'],
            'template_components' => [
                ['type' => 'body', 'parameters' => [['type' => 'text', 'text' => 'Dr. Rahul Patel']]],
            ],
        ],
    ]);

    // Dispatch job synchronously
    $job = new SendCampaignMessageJob($recipient->id);
    $job->handle(app(WhatsAppMessageService::class));

    $recipient->refresh();
    expect($recipient->status)->toBe('sent');
    expect($recipient->provider_message_id)->not->toBeNull();

    // Verify WhatsApp message record created via the approved-template API,
    // not the free-text session-window method.
    $this->assertDatabaseHas('marketing_whatsapp_messages', [
        'direction' => 'outbound',
        'contact_id' => $this->contact->id,
        'message_type' => 'template',
        'body' => 'Dr. Rahul Patel',
    ]);

    $message = MarketingWhatsAppMessage::where('contact_id', $this->contact->id)->first();
    expect($message->payload['idempotency_key'] ?? null)->toBe("campaign:{$campaign->id}:recipient:{$recipient->id}");
});

test('webhook delivery and read events update campaign recipient metrics', function () {
    $campaign = MarketingCampaign::create([
        'whatsapp_account_id' => $this->account->id,
        'audience_id' => $this->audience->id,
        'template_id' => $this->template->id,
        'name' => 'Webhook Metrics Test',
        'status' => 'sending',
    ]);

    $recipient = MarketingCampaignRecipient::create([
        'campaign_id' => $campaign->id,
        'contact_id' => $this->contact->id,
        'phone' => $this->contact->phone,
        'normalized_phone' => $this->contact->normalized_phone,
        'status' => 'sent',
        'provider_message_id' => 'wamid.HBgLWebhookCorr1001',
    ]);

    $webhookService = app(WhatsAppWebhookService::class);

    // 1. Delivered webhook
    $webhookService->handlePayload([
        'entry' => [
            [
                'changes' => [
                    [
                        'value' => [
                            'metadata' => ['phone_number_id' => '9876543210123'],
                            'statuses' => [
                                [
                                    'id' => 'wamid.HBgLWebhookCorr1001',
                                    'status' => 'delivered',
                                    'recipient_id' => '9876543210',
                                    'timestamp' => time(),
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ]);

    expect($recipient->fresh()->status)->toBe('delivered');

    // 2. Read webhook
    $webhookService->handlePayload([
        'entry' => [
            [
                'changes' => [
                    [
                        'value' => [
                            'metadata' => ['phone_number_id' => '9876543210123'],
                            'statuses' => [
                                [
                                    'id' => 'wamid.HBgLWebhookCorr1001',
                                    'status' => 'read',
                                    'recipient_id' => '9876543210',
                                    'timestamp' => time(),
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ]);

    expect($recipient->fresh()->status)->toBe('read');
});

test('inbound reply marks campaign recipient as replied', function () {
    $campaign = MarketingCampaign::create([
        'whatsapp_account_id' => $this->account->id,
        'audience_id' => $this->audience->id,
        'template_id' => $this->template->id,
        'name' => 'Reply Tracking Test',
        'status' => 'sending',
    ]);

    $recipient = MarketingCampaignRecipient::create([
        'campaign_id' => $campaign->id,
        'contact_id' => $this->contact->id,
        'phone' => $this->contact->phone,
        'normalized_phone' => $this->contact->normalized_phone,
        'status' => 'read',
        'provider_message_id' => 'wamid.HBgLReplyTest999',
    ]);

    $webhookService = app(WhatsAppWebhookService::class);

    // Inbound reply payload from contact
    $webhookService->handlePayload([
        'entry' => [
            [
                'changes' => [
                    [
                        'value' => [
                            'metadata' => ['phone_number_id' => '9876543210123'],
                            'messages' => [
                                [
                                    'from' => '919876543210',
                                    'id' => 'wamid.inc.ReplyReceived123',
                                    'timestamp' => time(),
                                    'type' => 'text',
                                    'text' => ['body' => 'I would like a demo!'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ]);

    expect($recipient->fresh()->status)->toBe('replied');
    expect($recipient->fresh()->replied_at)->not->toBeNull();
});

test('user can duplicate campaign configuration into a new draft', function () {
    $original = MarketingCampaign::create([
        'whatsapp_account_id' => $this->account->id,
        'audience_id' => $this->audience->id,
        'template_id' => $this->template->id,
        'name' => 'Original Campaign',
        'status' => 'completed',
    ]);

    MarketingCampaignRecipient::create([
        'campaign_id' => $original->id,
        'contact_id' => $this->contact->id,
        'phone' => $this->contact->phone,
        'normalized_phone' => $this->contact->normalized_phone,
        'status' => 'read',
    ]);

    $response = $this->actingAs($this->user)->post(route('company.marketing.campaigns.duplicate', $original->id));

    $response->assertRedirect();

    $duplicate = MarketingCampaign::where('name', 'Original Campaign (Copy)')->first();
    expect($duplicate)->not->toBeNull();
    expect($duplicate->status)->toBe('draft');
    expect($duplicate->recipients()->count())->toBe(0); // 0 recipients copied!
});
