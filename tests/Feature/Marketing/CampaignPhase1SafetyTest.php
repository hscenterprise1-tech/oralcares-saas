<?php

use App\Jobs\CheckCampaignCompletionJob;
use App\Jobs\PrepareCampaignJob;
use App\Jobs\SendCampaignMessageJob;
use App\Models\MarketingAudience;
use App\Models\MarketingCampaign;
use App\Models\MarketingCampaignRecipient;
use App\Models\MarketingContact;
use App\Models\MarketingWhatsAppAccount;
use App\Models\MarketingWhatsAppMessage;
use App\Models\MarketingWhatsAppTemplate;
use App\Models\Organization;
use App\Models\User;
use App\Services\Marketing\CampaignPreparationService;
use App\Services\Marketing\MarketingSettingsService;
use App\Services\Marketing\WhatsAppMessageService;
use App\Services\Marketing\WhatsAppWebhookService;
use App\UserType;
use Illuminate\Cache\RateLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create(['user_type' => UserType::CompanyUser]);
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

    $this->secondAccount = MarketingWhatsAppAccount::create([
        'provider' => 'meta',
        'business_account_id' => '2029384756',
        'phone_number_id' => '5551112222',
        'display_phone_number' => '+91 5551112222',
        'verified_name' => 'OralCares Support WhatsApp',
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

// R1 — HTTP launch never runs the recipient-generation loop inline.
test('launching a campaign over HTTP queues preparation instead of running it inline', function () {
    Queue::fake();

    $campaign = MarketingCampaign::create([
        'whatsapp_account_id' => $this->account->id,
        'audience_id' => $this->audience->id,
        'template_id' => $this->template->id,
        'name' => 'Queued Launch Test',
        'status' => 'draft',
    ]);

    $response = $this->actingAs($this->user)->post(route('company.marketing.campaigns.launch', $campaign->id));

    $response->assertRedirect();
    expect($campaign->fresh()->status)->toBe('preparing');

    // No recipients were generated on the request thread — that only
    // happens once the queued job actually runs.
    expect(MarketingCampaignRecipient::where('campaign_id', $campaign->id)->count())->toBe(0);

    Queue::assertPushed(PrepareCampaignJob::class, fn ($job) => $job->campaignId === $campaign->id);
});

// F4 — the atomic status guard, not just the job-level lock, is what actually
// stops a double-click: the second request must never queue a second job.
test('double-clicking launch only queues preparation once', function () {
    Queue::fake();

    $campaign = MarketingCampaign::create([
        'whatsapp_account_id' => $this->account->id,
        'audience_id' => $this->audience->id,
        'template_id' => $this->template->id,
        'name' => 'Double Click Test',
        'status' => 'draft',
    ]);

    $first = $this->actingAs($this->user)->post(route('company.marketing.campaigns.launch', $campaign->id));
    $second = $this->actingAs($this->user)->post(route('company.marketing.campaigns.launch', $campaign->id));

    $first->assertRedirect();
    $second->assertRedirect();
    $second->assertSessionHas('error');

    Queue::assertPushed(PrepareCampaignJob::class, 1);
});

// F4 — the same guard is what makes a concurrent "resume" safe: it reuses
// the identical launch path, so a paused campaign clicked twice queues once.
test('resuming a paused campaign concurrently only queues preparation once', function () {
    Queue::fake();

    $campaign = MarketingCampaign::create([
        'whatsapp_account_id' => $this->account->id,
        'audience_id' => $this->audience->id,
        'template_id' => $this->template->id,
        'name' => 'Concurrent Resume Test',
        'status' => 'paused',
    ]);

    $first = $this->actingAs($this->user)->post(route('company.marketing.campaigns.launch', $campaign->id));
    $first->assertSessionHas('success');

    $second = $this->actingAs($this->user)->post(route('company.marketing.campaigns.launch', $campaign->id));
    $second->assertSessionHas('error');

    expect($campaign->fresh()->status)->toBe('preparing');
    Queue::assertPushed(PrepareCampaignJob::class, 1);
});

// F4 — a launch attempt against a campaign that is already sending/completed
// must not be accepted, regardless of how it got there.
test('launch is rejected for a campaign that is not in a launchable status', function () {
    Queue::fake();

    $campaign = MarketingCampaign::create([
        'whatsapp_account_id' => $this->account->id,
        'audience_id' => $this->audience->id,
        'template_id' => $this->template->id,
        'name' => 'Already Sending',
        'status' => 'sending',
    ]);

    $response = $this->actingAs($this->user)->post(route('company.marketing.campaigns.launch', $campaign->id));

    $response->assertSessionHas('error');
    expect($campaign->fresh()->status)->toBe('sending');
    Queue::assertNotPushed(PrepareCampaignJob::class);
});

// R1 / F4 — PrepareCampaignJob itself does the real work, and accepts the
// `preparing` status the controller already put the campaign in.
test('PrepareCampaignJob resolves the segment and creates the recipient snapshot', function () {
    $campaign = MarketingCampaign::create([
        'organization_id' => Organization::factory()->create()->id,
        'whatsapp_account_id' => $this->account->id,
        'audience_id' => $this->audience->id,
        'template_id' => $this->template->id,
        'name' => 'Job Runs Prep',
        'status' => 'preparing',
    ]);

    (new PrepareCampaignJob($campaign->id))->handle(app(CampaignPreparationService::class));

    expect($campaign->fresh()->status)->toBe('sending');
    expect(MarketingCampaignRecipient::where('campaign_id', $campaign->id)->count())->toBe(1);
});

// F2 — the same idempotency key must survive a repeated/duplicate job
// execution for the same recipient and produce exactly one outbound message.
test('duplicate job execution for the same recipient sends only once', function () {
    $campaign = MarketingCampaign::create([
        'whatsapp_account_id' => $this->account->id,
        'audience_id' => $this->audience->id,
        'template_id' => $this->template->id,
        'name' => 'Idempotency Test',
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
            'template_components' => [
                ['type' => 'body', 'parameters' => [['type' => 'text', 'text' => 'Dr. Rahul Patel']]],
            ],
        ],
    ]);

    $service = app(WhatsAppMessageService::class);

    // Simulate: queue retry, worker restart, or a redelivered job — the same
    // recipient's job body runs twice.
    (new SendCampaignMessageJob($recipient->id))->handle($service);
    $firstProviderMessageId = $recipient->fresh()->provider_message_id;

    // Second run: recipient is already 'sent', but even if it were re-run
    // (e.g. a stale queued job double-delivered before status caught up),
    // the idempotency key must prevent a second real send.
    $result = $service->sendOutboundTemplateMessage(
        $this->account,
        $recipient->phone,
        $this->template->name,
        $this->template->language,
        [['type' => 'body', 'parameters' => [['type' => 'text', 'text' => 'Dr. Rahul Patel']]]],
        "campaign:{$campaign->id}:recipient:{$recipient->id}"
    );

    expect($result['success'])->toBeTrue();
    expect(MarketingWhatsAppMessage::where('contact_id', $this->contact->id)->count())->toBe(1);
    expect($result['message']->provider_message_id)->toBe($firstProviderMessageId);
});

// F10 — completion is only declared once, and only while the campaign is
// actually 'sending' — a redundant/late-arriving check must not re-fire it.
test('completion check is idempotent and only completes a sending campaign', function () {
    $campaign = MarketingCampaign::create([
        'whatsapp_account_id' => $this->account->id,
        'audience_id' => $this->audience->id,
        'template_id' => $this->template->id,
        'name' => 'Completion Once Test',
        'status' => 'sending',
    ]);

    MarketingCampaignRecipient::create([
        'campaign_id' => $campaign->id,
        'contact_id' => $this->contact->id,
        'phone' => $this->contact->phone,
        'normalized_phone' => $this->contact->normalized_phone,
        'status' => 'sent',
    ]);

    (new CheckCampaignCompletionJob($campaign->id))->handle();
    expect($campaign->fresh()->status)->toBe('completed');
    $completedAt = $campaign->fresh()->completed_at;

    // A second, redundant check (e.g. two SendCampaignMessageJob finishing
    // close together) must not re-run completion logic.
    (new CheckCampaignCompletionJob($campaign->id))->handle();
    expect($campaign->fresh()->status)->toBe('completed');
    expect($campaign->fresh()->completed_at->equalTo($completedAt))->toBeTrue();
});

test('completion check does nothing while recipients are still pending', function () {
    $campaign = MarketingCampaign::create([
        'whatsapp_account_id' => $this->account->id,
        'audience_id' => $this->audience->id,
        'template_id' => $this->template->id,
        'name' => 'Still Pending Test',
        'status' => 'sending',
    ]);

    MarketingCampaignRecipient::create([
        'campaign_id' => $campaign->id,
        'contact_id' => $this->contact->id,
        'phone' => $this->contact->phone,
        'normalized_phone' => $this->contact->normalized_phone,
        'status' => 'queued',
    ]);

    (new CheckCampaignCompletionJob($campaign->id))->handle();

    expect($campaign->fresh()->status)->toBe('sending');
});

// F7 — an unmatched webhook (no account matches the phone_number_id) must be
// dropped safely and never attributed to whichever account happens to exist.
test('a webhook event with an unrecognized phone_number_id is not attributed to any account', function () {
    $campaign = MarketingCampaign::create([
        'whatsapp_account_id' => $this->account->id,
        'audience_id' => $this->audience->id,
        'template_id' => $this->template->id,
        'name' => 'Unmatched Webhook Test',
        'status' => 'sending',
    ]);

    $recipient = MarketingCampaignRecipient::create([
        'campaign_id' => $campaign->id,
        'contact_id' => $this->contact->id,
        'phone' => $this->contact->phone,
        'normalized_phone' => $this->contact->normalized_phone,
        'status' => 'sent',
        'provider_message_id' => 'wamid.UnmatchedProbe1',
    ]);

    $result = app(WhatsAppWebhookService::class)->handlePayload([
        'entry' => [[
            'changes' => [[
                'value' => [
                    'metadata' => ['phone_number_id' => 'no-such-number-0000'],
                    'statuses' => [[
                        'id' => 'wamid.UnmatchedProbe1',
                        'status' => 'delivered',
                        'recipient_id' => '9876543210',
                        'timestamp' => time(),
                    ]],
                ],
            ]],
        ]],
    ]);

    expect($result['skipped'])->toBe(1);
    expect($result['processed'])->toBe(0);
    // The status update must never have been applied — it was never routed
    // to any account, let alone the wrong one.
    expect($recipient->fresh()->status)->toBe('sent');

    $this->assertDatabaseHas('marketing_whatsapp_webhook_events', [
        'provider_event_id' => 'evt_st_wamid.UnmatchedProbe1_delivered',
        'whatsapp_account_id' => null,
        'processing_status' => 'skipped',
    ]);
});

// F8 — a reply on Account B must never mark a recipient as replied for a
// campaign that was actually sent from Account A, even for the same contact.
test('a reply on one WhatsApp account does not mark a recipient replied on another account', function () {
    $campaignOnAccountA = MarketingCampaign::create([
        'whatsapp_account_id' => $this->account->id,
        'audience_id' => $this->audience->id,
        'template_id' => $this->template->id,
        'name' => 'Sent From Account A',
        'status' => 'sending',
    ]);

    $recipientOnAccountA = MarketingCampaignRecipient::create([
        'campaign_id' => $campaignOnAccountA->id,
        'contact_id' => $this->contact->id,
        'phone' => $this->contact->phone,
        'normalized_phone' => $this->contact->normalized_phone,
        'status' => 'read',
        'provider_message_id' => 'wamid.FromAccountA',
    ]);

    // Same contact replies, but the inbound webhook arrives on Account B's
    // phone_number_id — e.g. the contact texted the support number instead.
    app(WhatsAppWebhookService::class)->handlePayload([
        'entry' => [[
            'changes' => [[
                'value' => [
                    'metadata' => ['phone_number_id' => $this->secondAccount->phone_number_id],
                    'messages' => [[
                        'from' => '919876543210',
                        'id' => 'wamid.inc.CrossAccountReply',
                        'timestamp' => time(),
                        'type' => 'text',
                        'text' => ['body' => 'Hi, I have a question'],
                    ]],
                ],
            ]],
        ]],
    ]);

    // The Account-A campaign recipient must be untouched by a reply that
    // arrived on Account B.
    expect($recipientOnAccountA->fresh()->status)->toBe('read');
});

test('a reply on the correct account still marks the campaign recipient replied', function () {
    $campaign = MarketingCampaign::create([
        'whatsapp_account_id' => $this->account->id,
        'audience_id' => $this->audience->id,
        'template_id' => $this->template->id,
        'name' => 'Correct Account Reply',
        'status' => 'sending',
    ]);

    $recipient = MarketingCampaignRecipient::create([
        'campaign_id' => $campaign->id,
        'contact_id' => $this->contact->id,
        'phone' => $this->contact->phone,
        'normalized_phone' => $this->contact->normalized_phone,
        'status' => 'read',
        'provider_message_id' => 'wamid.FromCorrectAccount',
    ]);

    app(WhatsAppWebhookService::class)->handlePayload([
        'entry' => [[
            'changes' => [[
                'value' => [
                    'metadata' => ['phone_number_id' => $this->account->phone_number_id],
                    'messages' => [[
                        'from' => '919876543210',
                        'id' => 'wamid.inc.SameAccountReply',
                        'timestamp' => time(),
                        'type' => 'text',
                        'text' => ['body' => 'Yes I am interested'],
                    ]],
                ],
            ]],
        ]],
    ]);

    expect($recipient->fresh()->status)->toBe('replied');
});

// F9 — the registered rate limiter reads the org's own hourly_dispatch_rate
// rather than a hardcoded number, and keys by WhatsApp account.
test('the campaign-dispatch rate limiter is keyed by account and reads the configured hourly rate', function () {
    $organization = Organization::factory()->create();

    $campaign = MarketingCampaign::create([
        'organization_id' => $organization->id,
        'whatsapp_account_id' => $this->account->id,
        'audience_id' => $this->audience->id,
        'template_id' => $this->template->id,
        'name' => 'Rate Limit Config Test',
        'status' => 'sending',
    ]);

    $recipient = MarketingCampaignRecipient::create([
        'campaign_id' => $campaign->id,
        'contact_id' => $this->contact->id,
        'phone' => $this->contact->phone,
        'normalized_phone' => $this->contact->normalized_phone,
        'status' => 'queued',
    ]);

    app(MarketingSettingsService::class)->updateSettings($organization->id, [
        'hourly_dispatch_rate' => 42,
    ]);

    $limiter = app(RateLimiter::class)->limiter('campaign-dispatch');
    $limit = $limiter(new SendCampaignMessageJob($recipient->id));

    expect($limit->maxAttempts)->toBe(42);
    expect($limit->key)->toContain((string) $this->account->id);
});

// F1 — the campaign send job uses the approved-template API end to end,
// never the free-text session-window method.
test('campaign send job never calls the free-text outbound method', function () {
    $campaign = MarketingCampaign::create([
        'whatsapp_account_id' => $this->account->id,
        'audience_id' => $this->audience->id,
        'template_id' => $this->template->id,
        'name' => 'Template Method Test',
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
            'template_components' => [
                ['type' => 'body', 'parameters' => [['type' => 'text', 'text' => 'Dr. Rahul Patel']]],
            ],
        ],
    ]);

    (new SendCampaignMessageJob($recipient->id))->handle(app(WhatsAppMessageService::class));

    $message = MarketingWhatsAppMessage::where('contact_id', $this->contact->id)->first();
    expect($message->message_type)->toBe('template');
    expect($message->payload['idempotency_key'] ?? null)->toBe("campaign:{$campaign->id}:recipient:{$recipient->id}");
});
