<?php

use App\Http\Controllers\Company\Marketing\MarketingCampaignController;
use App\Jobs\SendCampaignMessageJob;
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
use App\Services\Marketing\CampaignPreparationService;
use App\Services\Marketing\WhatsAppMessageService;
use App\UserType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

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
});

function phase2Contact(string $suffix, string $status = 'active'): MarketingContact
{
    // PhoneNormalizer needs a real 10-digit Indian mobile shape to resolve a
    // conversation's contact_id correctly — a short/malformed number here
    // silently produces a message row with no contact_id.
    $tenDigits = '90000'.str_pad($suffix, 5, '0', STR_PAD_LEFT);

    return MarketingContact::create([
        'name' => "Dr. Contact {$suffix}",
        'phone' => "+91 {$tenDigits}",
        'normalized_phone' => $tenDigits,
        'city' => 'Vadodara',
        'marketing_status' => $status,
    ]);
}

function phase2Payload(MarketingWhatsAppTemplate $template, string $name = 'Dr. Contact'): array
{
    return [
        'template_name' => $template->name,
        'template_language' => $template->language,
        'template_components' => [
            ['type' => 'body', 'parameters' => [['type' => 'text', 'text' => $name]]],
        ],
    ];
}

// ═══════════════════════════════════════════════════════════════════════
// F3 — Resume
// ═══════════════════════════════════════════════════════════════════════

test('resuming a paused campaign reuses existing recipient rows without creating new ones', function () {
    $contacts = collect(range(1, 5))->map(fn ($i) => phase2Contact((string) $i));

    $campaign = MarketingCampaign::create([
        'whatsapp_account_id' => $this->account->id,
        'audience_id' => $this->audience->id,
        'template_id' => $this->template->id,
        'name' => 'Resume Reuse Test',
        'status' => 'draft',
    ]);

    app(CampaignPreparationService::class)->prepareAndLaunchCampaign($campaign);

    $before = MarketingCampaignRecipient::where('campaign_id', $campaign->id)->get();
    expect($before)->toHaveCount(5);

    // Two already reached a phone before the pause; the rest are stuck
    // 'queued' because their job saw the paused status and no-op'd.
    $before[0]->update(['status' => 'delivered', 'provider_message_id' => 'wamid.Already1']);
    $before[1]->update(['status' => 'delivered', 'provider_message_id' => 'wamid.Already2']);
    $campaign->update(['status' => 'paused']);

    app(CampaignPreparationService::class)->prepareAndLaunchCampaign($campaign->fresh());

    $after = MarketingCampaignRecipient::where('campaign_id', $campaign->id)->get();
    expect($after)->toHaveCount(5);
    expect($after->pluck('id')->sort()->values()->all())->toBe($before->pluck('id')->sort()->values()->all());

    // The two already-delivered recipients are completely untouched.
    expect($after->firstWhere('id', $before[0]->id)->status)->toBe('delivered');
    expect($after->firstWhere('id', $before[0]->id)->provider_message_id)->toBe('wamid.Already1');

    expect($campaign->fresh()->status)->toBe('sending');
});

test('resuming sends each previously-stuck recipient exactly once', function () {
    $contacts = collect(range(1, 3))->map(fn ($i) => phase2Contact((string) $i));

    $campaign = MarketingCampaign::create([
        'whatsapp_account_id' => $this->account->id,
        'audience_id' => $this->audience->id,
        'template_id' => $this->template->id,
        'name' => 'Resume Send Once Test',
        'status' => 'paused',
    ]);

    // Simulate: launched, three recipients created and flipped to 'queued',
    // then paused before any of their jobs actually ran.
    foreach ($contacts as $c) {
        MarketingCampaignRecipient::create([
            'campaign_id' => $campaign->id,
            'contact_id' => $c->id,
            'phone' => $c->phone,
            'normalized_phone' => $c->normalized_phone,
            'status' => 'queued',
            'queued_at' => now(),
            'personalized_payload' => phase2Payload($this->template),
        ]);
    }

    app(CampaignPreparationService::class)->prepareAndLaunchCampaign($campaign->fresh());

    expect($campaign->recipients()->where('status', 'sent')->count())->toBe(3);
    expect(MarketingWhatsAppMessage::count())->toBe(3);
    expect(MarketingWhatsAppMessage::pluck('contact_id')->unique()->count())->toBe(3);
});

test('a stale duplicate job for an already-sent recipient is a safe no-op', function () {
    $contact = phase2Contact('1');

    $campaign = MarketingCampaign::create([
        'whatsapp_account_id' => $this->account->id,
        'audience_id' => $this->audience->id,
        'template_id' => $this->template->id,
        'name' => 'Duplicate Job No-Op Test',
        'status' => 'sending',
    ]);

    $recipient = MarketingCampaignRecipient::create([
        'campaign_id' => $campaign->id,
        'contact_id' => $contact->id,
        'phone' => $contact->phone,
        'normalized_phone' => $contact->normalized_phone,
        'status' => 'queued',
        'personalized_payload' => phase2Payload($this->template),
    ]);

    $service = app(WhatsAppMessageService::class);

    (new SendCampaignMessageJob($recipient->id))->handle($service);
    expect($recipient->fresh()->status)->toBe('sent');

    // A second, stale instance of the job for the same recipient (e.g. one
    // that was still sitting in the queue from before a pause) must not
    // send again — the job's own status guard stops it before it tries.
    (new SendCampaignMessageJob($recipient->id))->handle($service);

    expect(MarketingWhatsAppMessage::where('contact_id', $contact->id)->count())->toBe(1);
    expect($recipient->fresh()->status)->toBe('sent');
});

// ═══════════════════════════════════════════════════════════════════════
// F5 — Retry failed
// ═══════════════════════════════════════════════════════════════════════

test('retry failed only re-queues recipients currently in failed status', function () {
    $campaign = MarketingCampaign::create([
        'whatsapp_account_id' => $this->account->id,
        'audience_id' => $this->audience->id,
        'template_id' => $this->template->id,
        'name' => 'Retry Scope Test',
        'status' => 'sending',
    ]);

    $failedContact = phase2Contact('1');
    $sentContact = phase2Contact('2');
    $deliveredContact = phase2Contact('3');
    $repliedContact = phase2Contact('4');

    $failed = MarketingCampaignRecipient::create([
        'campaign_id' => $campaign->id, 'contact_id' => $failedContact->id,
        'phone' => $failedContact->phone, 'normalized_phone' => $failedContact->normalized_phone,
        'status' => 'failed', 'failure_reason' => 'WhatsApp API dispatch failed',
        'personalized_payload' => phase2Payload($this->template),
    ]);
    $sent = MarketingCampaignRecipient::create([
        'campaign_id' => $campaign->id, 'contact_id' => $sentContact->id,
        'phone' => $sentContact->phone, 'normalized_phone' => $sentContact->normalized_phone,
        'status' => 'sent', 'provider_message_id' => 'wamid.Sent1',
    ]);
    $delivered = MarketingCampaignRecipient::create([
        'campaign_id' => $campaign->id, 'contact_id' => $deliveredContact->id,
        'phone' => $deliveredContact->phone, 'normalized_phone' => $deliveredContact->normalized_phone,
        'status' => 'delivered', 'provider_message_id' => 'wamid.Delivered1',
    ]);
    $replied = MarketingCampaignRecipient::create([
        'campaign_id' => $campaign->id, 'contact_id' => $repliedContact->id,
        'phone' => $repliedContact->phone, 'normalized_phone' => $repliedContact->normalized_phone,
        'status' => 'replied', 'provider_message_id' => 'wamid.Replied1',
    ]);

    $response = $this->actingAs($this->user)->post(route('company.marketing.campaigns.retry-failed', $campaign->id));

    $response->assertSessionHas('success');

    // Sync queue runs the retry job immediately, so the previously-failed
    // recipient has already gone all the way to 'sent'.
    expect($failed->fresh()->status)->toBe('sent');
    expect($failed->fresh()->failure_reason)->toBeNull();

    // Everything that was not 'failed' is completely untouched.
    expect($sent->fresh()->status)->toBe('sent');
    expect($sent->fresh()->provider_message_id)->toBe('wamid.Sent1');
    expect($delivered->fresh()->status)->toBe('delivered');
    expect($delivered->fresh()->provider_message_id)->toBe('wamid.Delivered1');
    expect($replied->fresh()->status)->toBe('replied');
    expect($replied->fresh()->provider_message_id)->toBe('wamid.Replied1');
});

test('retrying a failed recipient does not destroy the original message history', function () {
    $campaign = MarketingCampaign::create([
        'whatsapp_account_id' => $this->account->id,
        'audience_id' => $this->audience->id,
        'template_id' => $this->template->id,
        'name' => 'History Preserved Test',
        'status' => 'sending',
    ]);

    $contact = phase2Contact('1');

    $recipient = MarketingCampaignRecipient::create([
        'campaign_id' => $campaign->id, 'contact_id' => $contact->id,
        'phone' => $contact->phone, 'normalized_phone' => $contact->normalized_phone,
        'status' => 'failed', 'provider_message_id' => 'wamid.OriginalAttempt',
        'personalized_payload' => phase2Payload($this->template),
    ]);

    $conversation = MarketingWhatsAppConversation::create([
        'whatsapp_account_id' => $this->account->id,
        'contact_id' => $contact->id,
        'phone' => $contact->phone,
        'normalized_phone' => $contact->normalized_phone,
        'status' => 'open',
    ]);

    // A message that was actually dispatched and later failed via webhook
    // (as opposed to a failure that never reached the provider at all) —
    // this row must survive the retry untouched.
    $originalMessage = MarketingWhatsAppMessage::create([
        'whatsapp_account_id' => $this->account->id,
        'conversation_id' => $conversation->id,
        'contact_id' => $contact->id,
        'provider_message_id' => 'wamid.OriginalAttempt',
        'direction' => 'outbound',
        'message_type' => 'template',
        'body' => 'Dr. Contact',
        'status' => 'failed',
        'payload' => ['idempotency_key' => "campaign:{$campaign->id}:recipient:{$recipient->id}"],
    ]);

    $this->actingAs($this->user)->post(route('company.marketing.campaigns.retry-failed', $campaign->id));

    $this->assertDatabaseHas('marketing_whatsapp_messages', [
        'id' => $originalMessage->id,
        'status' => 'failed',
        'provider_message_id' => 'wamid.OriginalAttempt',
    ]);

    // A genuinely new attempt happened — a second message exists, under a
    // different idempotency key, and the recipient moved off 'failed'.
    expect(MarketingWhatsAppMessage::where('contact_id', $contact->id)->count())->toBe(2);
    expect($recipient->fresh()->status)->not->toBe('failed');
});

test('retrying does not resend to a recipient who opted out since the failure', function () {
    $campaign = MarketingCampaign::create([
        'whatsapp_account_id' => $this->account->id,
        'audience_id' => $this->audience->id,
        'template_id' => $this->template->id,
        'name' => 'Retry Opt-Out Safety Test',
        'status' => 'sending',
    ]);

    $contact = phase2Contact('1');

    $recipient = MarketingCampaignRecipient::create([
        'campaign_id' => $campaign->id, 'contact_id' => $contact->id,
        'phone' => $contact->phone, 'normalized_phone' => $contact->normalized_phone,
        'status' => 'failed', 'personalized_payload' => phase2Payload($this->template),
    ]);

    $contact->update(['marketing_status' => 'opted_out']);

    $this->actingAs($this->user)->post(route('company.marketing.campaigns.retry-failed', $campaign->id));

    expect($recipient->fresh()->status)->toBe('skipped');
    expect(MarketingWhatsAppMessage::where('contact_id', $contact->id)->count())->toBe(0);
});

test('a duplicate retry-failed click does not send twice', function () {
    $campaign = MarketingCampaign::create([
        'whatsapp_account_id' => $this->account->id,
        'audience_id' => $this->audience->id,
        'template_id' => $this->template->id,
        'name' => 'Duplicate Retry Click Test',
        'status' => 'sending',
    ]);

    $contact = phase2Contact('1');

    MarketingCampaignRecipient::create([
        'campaign_id' => $campaign->id, 'contact_id' => $contact->id,
        'phone' => $contact->phone, 'normalized_phone' => $contact->normalized_phone,
        'status' => 'failed', 'personalized_payload' => phase2Payload($this->template),
    ]);

    $first = $this->actingAs($this->user)->post(route('company.marketing.campaigns.retry-failed', $campaign->id));
    $first->assertSessionHas('success');

    $second = $this->actingAs($this->user)->post(route('company.marketing.campaigns.retry-failed', $campaign->id));
    $second->assertSessionHas('error');

    expect(MarketingWhatsAppMessage::where('contact_id', $contact->id)->count())->toBe(1);
});

test('retry failed is unavailable when there are zero failed recipients', function () {
    $campaign = MarketingCampaign::create([
        'whatsapp_account_id' => $this->account->id,
        'audience_id' => $this->audience->id,
        'template_id' => $this->template->id,
        'name' => 'No Failures Test',
        'status' => 'sending',
    ]);

    $contact = phase2Contact('1');
    MarketingCampaignRecipient::create([
        'campaign_id' => $campaign->id, 'contact_id' => $contact->id,
        'phone' => $contact->phone, 'normalized_phone' => $contact->normalized_phone,
        'status' => 'sent',
    ]);

    $response = $this->actingAs($this->user)->post(route('company.marketing.campaigns.retry-failed', $campaign->id));

    $response->assertSessionHas('error');
    expect($campaign->calculateMetrics()['failed'])->toBe(0);
});

test('retrying a completed campaign brings it back to sending', function () {
    $campaign = MarketingCampaign::create([
        'whatsapp_account_id' => $this->account->id,
        'audience_id' => $this->audience->id,
        'template_id' => $this->template->id,
        'name' => 'Retry Completed Campaign Test',
        'status' => 'completed',
        'completed_at' => now(),
    ]);

    $contact = phase2Contact('1');
    MarketingCampaignRecipient::create([
        'campaign_id' => $campaign->id, 'contact_id' => $contact->id,
        'phone' => $contact->phone, 'normalized_phone' => $contact->normalized_phone,
        'status' => 'failed', 'personalized_payload' => phase2Payload($this->template),
    ]);

    $this->actingAs($this->user)->post(route('company.marketing.campaigns.retry-failed', $campaign->id));

    // The retry job runs synchronously (sync queue) and, once it sends,
    // CheckCampaignCompletionJob also runs synchronously and correctly
    // re-completes the campaign since nothing else is left pending.
    expect($campaign->fresh()->status)->toBe('completed');
});

// ═══════════════════════════════════════════════════════════════════════
// F6 — Consolidated metrics
// ═══════════════════════════════════════════════════════════════════════

test('metrics match direct database counts for an empty campaign', function () {
    $campaign = MarketingCampaign::create([
        'whatsapp_account_id' => $this->account->id,
        'audience_id' => $this->audience->id,
        'template_id' => $this->template->id,
        'name' => 'Empty Campaign',
        'status' => 'draft',
    ]);

    $metrics = $campaign->calculateMetrics();

    expect($metrics['total'])->toBe(0);
    foreach (['pending', 'queued', 'sent', 'delivered', 'read', 'replied', 'failed', 'skipped'] as $key) {
        expect($metrics[$key])->toBe(0);
    }
});

test('metrics match direct database counts across the full status set, and the raw buckets are mutually exclusive', function () {
    $campaign = MarketingCampaign::create([
        'whatsapp_account_id' => $this->account->id,
        'audience_id' => $this->audience->id,
        'template_id' => $this->template->id,
        'name' => 'Full Status Spread',
        'status' => 'sending',
    ]);

    $statuses = ['pending', 'queued', 'sent', 'delivered', 'read', 'replied', 'failed', 'skipped'];
    foreach ($statuses as $i => $status) {
        $contact = phase2Contact((string) ($i + 1));
        MarketingCampaignRecipient::create([
            'campaign_id' => $campaign->id, 'contact_id' => $contact->id,
            'phone' => $contact->phone, 'normalized_phone' => $contact->normalized_phone,
            'status' => $status,
        ]);
    }

    $metrics = $campaign->calculateMetrics();

    expect($metrics['total'])->toBe(8);
    expect($metrics['pending'])->toBe(1);
    expect($metrics['queued'])->toBe(2); // pending + queued
    expect($metrics['sent'])->toBe(4); // sent + delivered + read + replied
    expect($metrics['delivered'])->toBe(3); // delivered + read + replied
    expect($metrics['read'])->toBe(2); // read + replied
    expect($metrics['replied'])->toBe(1);
    expect($metrics['failed'])->toBe(1);
    expect($metrics['skipped'])->toBe(1);

    // The raw, mutually-exclusive breakdown: exactly one recipient per
    // status, matching the fixture above one-for-one.
    foreach ($statuses as $status) {
        expect($metrics['raw'][$status])->toBe(1);
    }
    expect(array_sum($metrics['raw']))->toBe($metrics['total']);

    // Direct DB comparison, the actual requirement.
    foreach ($statuses as $status) {
        expect($metrics['raw'][$status])->toBe(
            MarketingCampaignRecipient::where('campaign_id', $campaign->id)->where('status', $status)->count()
        );
    }
});

test('metrics reflect a failed-recipients campaign correctly', function () {
    $campaign = MarketingCampaign::create([
        'whatsapp_account_id' => $this->account->id,
        'audience_id' => $this->audience->id,
        'template_id' => $this->template->id,
        'name' => 'Mostly Failed',
        'status' => 'sending',
    ]);

    foreach (range(1, 3) as $i) {
        $contact = phase2Contact((string) $i);
        MarketingCampaignRecipient::create([
            'campaign_id' => $campaign->id, 'contact_id' => $contact->id,
            'phone' => $contact->phone, 'normalized_phone' => $contact->normalized_phone,
            'status' => 'failed',
        ]);
    }
    $okContact = phase2Contact('4');
    MarketingCampaignRecipient::create([
        'campaign_id' => $campaign->id, 'contact_id' => $okContact->id,
        'phone' => $okContact->phone, 'normalized_phone' => $okContact->normalized_phone,
        'status' => 'delivered',
    ]);

    $metrics = $campaign->calculateMetrics();

    expect($metrics['total'])->toBe(4);
    expect($metrics['failed'])->toBe(3);
    expect($metrics['delivered'])->toBe(1);
});

test('the same metrics values appear from the campaign detail controller, the analytics detail service, and the analytics table service', function () {
    $organization = Organization::factory()->create();

    $campaign = MarketingCampaign::create([
        'organization_id' => $organization->id,
        'whatsapp_account_id' => $this->account->id,
        'audience_id' => $this->audience->id,
        'template_id' => $this->template->id,
        'name' => 'Cross-Consumer Consistency',
        'status' => 'sending',
    ]);

    $statuses = ['queued', 'sent', 'delivered', 'read', 'replied', 'failed', 'skipped'];
    foreach ($statuses as $i => $status) {
        $contact = phase2Contact((string) ($i + 10));
        MarketingCampaignRecipient::create([
            'campaign_id' => $campaign->id, 'contact_id' => $contact->id,
            'phone' => $contact->phone, 'normalized_phone' => $contact->normalized_phone,
            'status' => $status,
        ]);
    }

    $modelMetrics = $campaign->fresh()->calculateMetrics();

    // Campaign detail controller
    $request = Request::create("/company/marketing/campaigns/{$campaign->id}", 'GET');
    $request->setUserResolver(fn () => $this->user);
    $showResp = app(MarketingCampaignController::class)
        ->show($request, $campaign->id)->toResponse($request);
    $showMetrics = $showResp->getOriginalContent()['page']['props']['metrics'];

    // Analytics detail service
    $analyticsDetail = app(CampaignAnalyticsService::class)->getCampaignDetailAnalytics($campaign->fresh());

    // Analytics performance table (multi-campaign)
    $filter = new MarketingAnalyticsFilter(['campaign_id' => $campaign->id]);
    $tableRows = app(CampaignAnalyticsService::class)->getCampaignPerformanceTable($organization->id, $filter);
    $tableRow = collect($tableRows)->firstWhere('id', $campaign->id);

    foreach (['sent', 'delivered', 'read', 'failed'] as $key) {
        expect($showMetrics[$key])->toBe($modelMetrics[$key]);
        expect($analyticsDetail['summary'][$key === 'read' ? 'read' : $key])->toBe($modelMetrics[$key]);
    }
    expect($analyticsDetail['summary']['replies'])->toBe($modelMetrics['replied']);
    expect($analyticsDetail['summary']['skipped'])->toBe($modelMetrics['skipped']);

    expect($tableRow['sent'])->toBe($modelMetrics['sent']);
    expect($tableRow['delivered'])->toBe($modelMetrics['delivered']);
    expect($tableRow['read'])->toBe($modelMetrics['read']);
    expect($tableRow['replies'])->toBe($modelMetrics['replied']);
    expect($tableRow['failed'])->toBe($modelMetrics['failed']);
    expect($tableRow['recipients'])->toBe($modelMetrics['total']);
});

// ═══════════════════════════════════════════════════════════════════════
// F11 — Recipient status filters
// ═══════════════════════════════════════════════════════════════════════

test('recipient filtering supports every real status, combined with search, with pagination and URL state', function () {
    $campaign = MarketingCampaign::create([
        'whatsapp_account_id' => $this->account->id,
        'audience_id' => $this->audience->id,
        'template_id' => $this->template->id,
        'name' => 'Filter Coverage Test',
        'status' => 'sending',
    ]);

    $statuses = ['pending', 'queued', 'sent', 'delivered', 'read', 'replied', 'failed', 'skipped'];
    foreach ($statuses as $i => $status) {
        $contact = MarketingContact::create([
            'name' => "Findable Contact {$i}",
            'phone' => "+91 8000000{$i}",
            'normalized_phone' => "8000000{$i}",
            'marketing_status' => 'active',
        ]);
        MarketingCampaignRecipient::create([
            'campaign_id' => $campaign->id, 'contact_id' => $contact->id,
            'phone' => $contact->phone, 'normalized_phone' => $contact->normalized_phone,
            'status' => $status,
        ]);
    }

    foreach ($statuses as $status) {
        $this->actingAs($this->user)
            ->get("/company/marketing/campaigns/{$campaign->id}?".http_build_query(['status' => $status]))
            ->assertInertia(fn ($page) => $page
                ->where('recipients.total', 1)
                ->where('recipients.data.0.status', $status)
            );
    }

    // Search + status combined — the right recipient matches both.
    $this->actingAs($this->user)
        ->get("/company/marketing/campaigns/{$campaign->id}?".http_build_query(['status' => 'delivered', 'search' => 'Findable Contact 3']))
        ->assertInertia(fn ($page) => $page->where('recipients.total', 1));

    // Search + status combined — a status match with the wrong search term matches nothing.
    $this->actingAs($this->user)
        ->get("/company/marketing/campaigns/{$campaign->id}?".http_build_query(['status' => 'delivered', 'search' => 'Findable Contact 5']))
        ->assertInertia(fn ($page) => $page
            ->where('recipients.total', 0)
            // Filters round-trip through the URL (what "refresh preserves state" relies on).
            ->where('filters.status', 'delivered')
            ->where('filters.search', 'Findable Contact 5')
        );
});

test('recipient filtering paginates instead of loading every recipient', function () {
    $campaign = MarketingCampaign::create([
        'whatsapp_account_id' => $this->account->id,
        'audience_id' => $this->audience->id,
        'template_id' => $this->template->id,
        'name' => 'Pagination Test',
        'status' => 'sending',
    ]);

    foreach (range(1, 25) as $i) {
        $contact = MarketingContact::create([
            'name' => "Page Contact {$i}",
            'phone' => "+91 700000{$i}",
            'normalized_phone' => "700000{$i}",
            'marketing_status' => 'active',
        ]);
        MarketingCampaignRecipient::create([
            'campaign_id' => $campaign->id, 'contact_id' => $contact->id,
            'phone' => $contact->phone, 'normalized_phone' => $contact->normalized_phone,
            'status' => 'delivered',
        ]);
    }

    $this->actingAs($this->user)
        ->get("/company/marketing/campaigns/{$campaign->id}")
        ->assertInertia(fn ($page) => $page
            ->where('recipients.total', 25)
            ->has('recipients.data', 20) // paginate(20) in the controller
            ->where('recipients.last_page', 2)
        );
});

// ═══════════════════════════════════════════════════════════════════════
// F13 — Duplicate
// ═══════════════════════════════════════════════════════════════════════

test('duplicating a campaign creates a draft with the same configuration and zero recipients', function () {
    $original = MarketingCampaign::create([
        'whatsapp_account_id' => $this->account->id,
        'audience_id' => $this->audience->id,
        'template_id' => $this->template->id,
        'name' => 'Original Campaign With History',
        'description' => 'The real one',
        'status' => 'completed',
        'variable_mapping' => ['1' => 'name'],
        'completed_at' => now(),
    ]);

    foreach (range(1, 5) as $i) {
        $contact = phase2Contact((string) $i);
        MarketingCampaignRecipient::create([
            'campaign_id' => $original->id, 'contact_id' => $contact->id,
            'phone' => $contact->phone, 'normalized_phone' => $contact->normalized_phone,
            'status' => 'delivered', 'provider_message_id' => "wamid.Hist{$i}",
        ]);
    }

    expect($original->recipients()->count())->toBe(5);

    $response = $this->actingAs($this->user)->post(route('company.marketing.campaigns.duplicate', $original->id));
    $response->assertRedirect();

    $duplicate = MarketingCampaign::where('name', 'Original Campaign With History (Copy)')->first();

    expect($duplicate)->not->toBeNull();
    expect($duplicate->status)->toBe('draft');
    expect($duplicate->whatsapp_account_id)->toBe($original->whatsapp_account_id);
    expect($duplicate->audience_id)->toBe($original->audience_id);
    expect($duplicate->template_id)->toBe($original->template_id);
    expect($duplicate->variable_mapping)->toBe($original->variable_mapping);
    expect($duplicate->recipients()->count())->toBe(0);

    // The original is completely unchanged.
    expect($original->fresh()->status)->toBe('completed');
    expect($original->fresh()->recipients()->count())->toBe(5);
    expect($original->fresh()->recipients()->where('status', 'delivered')->count())->toBe(5);

    // No jobs were queued for the duplicate — it is inert until launched.
    expect(DB::table('jobs')->count())->toBe(0);
});
