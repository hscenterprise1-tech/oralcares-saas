<?php

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
use App\Services\Marketing\MarketingSuppressionService;
use App\Services\Marketing\WhatsAppConversationService;
use App\Services\Marketing\WhatsAppMessageService;
use App\UserType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->org = Organization::factory()->provider()->create();
    $this->user = User::factory()->create(['user_type' => UserType::CompanyUser, 'organization_id' => $this->org->id]);
    grantMarketingPermissions($this->user);

    $this->account = MarketingWhatsAppAccount::create([
        'organization_id' => $this->org->id,
        'provider' => 'meta',
        'business_account_id' => '1029384756',
        'phone_number_id' => 'conv-compliance-acct-1',
        'display_phone_number' => '+91 9800000001',
        'verified_name' => 'Compliance Test WhatsApp',
        'access_token_encrypted' => Crypt::encryptString('test_token'),
        'status' => 'connected',
    ]);

    $this->contact = MarketingContact::create([
        'organization_id' => $this->org->id,
        'name' => 'Dr. Compliance Test',
        'phone' => '+91 9811111111',
        'normalized_phone' => '9811111111',
        'marketing_status' => 'active',
    ]);

    $this->conversation = MarketingWhatsAppConversation::create([
        'organization_id' => $this->org->id,
        'whatsapp_account_id' => $this->account->id,
        'contact_id' => $this->contact->id,
        'phone' => $this->contact->phone,
        'normalized_phone' => $this->contact->normalized_phone,
        'status' => 'open',
    ]);
});

// ═══════════════════════════════════════════════════════════════════════
// 1. Opt-out protection — manual replies
// ═══════════════════════════════════════════════════════════════════════

test('a manual reply to an opted-out contact is blocked with a clear reason', function () {
    $this->contact->update(['marketing_status' => 'opted_out']);

    $response = $this->actingAs($this->user)->post(
        route('company.marketing.conversations.reply', $this->conversation->id),
        ['message' => 'Hello again!']
    );

    $response->assertRedirect();
    $response->assertSessionHas('error');
    expect(session('error'))->toContain('opted out');

    expect(MarketingWhatsAppMessage::where('contact_id', $this->contact->id)->count())->toBe(0);
});

test('a manual reply to a suppressed contact is blocked with a clear reason', function () {
    $this->contact->update(['marketing_status' => 'suppressed']);

    $response = $this->actingAs($this->user)->post(
        route('company.marketing.conversations.reply', $this->conversation->id),
        ['message' => 'Hello again!']
    );

    $response->assertSessionHas('error');
    expect(session('error'))->toContain('suppressed');
    expect(MarketingWhatsAppMessage::where('contact_id', $this->contact->id)->count())->toBe(0);
});

test('a manual reply to an active contact sends normally', function () {
    $response = $this->actingAs($this->user)->post(
        route('company.marketing.conversations.reply', $this->conversation->id),
        ['message' => 'Hello!']
    );

    $response->assertSessionHas('success');
    expect(MarketingWhatsAppMessage::where('contact_id', $this->contact->id)->where('direction', 'outbound')->count())->toBe(1);
});

test('the same opted-out contact is blocked from both a campaign send and a manual reply, via the same rule', function () {
    $this->contact->update(['marketing_status' => 'opted_out']);

    // Campaign send path
    $template = MarketingWhatsAppTemplate::create([
        'whatsapp_account_id' => $this->account->id,
        'name' => 'compliance_test_template',
        'language' => 'en',
        'category' => 'MARKETING',
        'status' => 'approved',
        'body' => 'Hello {{1}}!',
        'variables' => ['1'],
    ]);
    $audience = MarketingAudience::create(['organization_id' => $this->org->id, 'name' => 'Compliance Segment', 'rules' => []]);
    $campaign = MarketingCampaign::create([
        'organization_id' => $this->org->id,
        'whatsapp_account_id' => $this->account->id,
        'audience_id' => $audience->id,
        'template_id' => $template->id,
        'name' => 'Compliance Campaign',
        'status' => 'sending',
    ]);
    $recipient = MarketingCampaignRecipient::create([
        'campaign_id' => $campaign->id,
        'contact_id' => $this->contact->id,
        'phone' => $this->contact->phone,
        'normalized_phone' => $this->contact->normalized_phone,
        'status' => 'queued',
        'personalized_payload' => ['template_name' => $template->name, 'template_language' => 'en', 'template_components' => []],
    ]);

    (new SendCampaignMessageJob($recipient->id))->handle(app(WhatsAppMessageService::class));
    expect($recipient->fresh()->status)->toBe('skipped');

    // Manual reply path
    $reply = $this->actingAs($this->user)->post(
        route('company.marketing.conversations.reply', $this->conversation->id),
        ['message' => 'Are you still interested?']
    );
    $reply->assertSessionHas('error');

    // Neither path produced an outbound message
    expect(MarketingWhatsAppMessage::where('contact_id', $this->contact->id)->where('direction', 'outbound')->count())->toBe(0);
});

test('reversing suppression through the existing suppression workflow restores manual reply eligibility', function () {
    $this->contact->update(['marketing_status' => 'suppressed', 'suppression_reason' => 'manual_suppression', 'suppression_source' => 'user_action']);

    $blocked = $this->actingAs($this->user)->post(
        route('company.marketing.conversations.reply', $this->conversation->id),
        ['message' => 'Hello!']
    );
    $blocked->assertSessionHas('error');

    // The existing, approved unsuppress workflow — not a bypass inside Conversations.
    app(MarketingSuppressionService::class)->unsuppressContact($this->contact->fresh(), changedBy: $this->user);

    $allowed = $this->actingAs($this->user)->post(
        route('company.marketing.conversations.reply', $this->conversation->id),
        ['message' => 'Hello!']
    );
    $allowed->assertSessionHas('success');
    expect(MarketingWhatsAppMessage::where('contact_id', $this->contact->id)->where('direction', 'outbound')->count())->toBe(1);
});

// ═══════════════════════════════════════════════════════════════════════
// 2. Multiple accounts — no cross-attribution
// ═══════════════════════════════════════════════════════════════════════

test('the same contact has separate conversations per WhatsApp account with no cross-attribution', function () {
    $secondAccount = MarketingWhatsAppAccount::create([
        'organization_id' => $this->org->id,
        'provider' => 'meta',
        'business_account_id' => '2029384756',
        'phone_number_id' => 'conv-compliance-acct-2',
        'display_phone_number' => '+91 9800000002',
        'verified_name' => 'Second Number',
        'access_token_encrypted' => Crypt::encryptString('test_token'),
        'status' => 'connected',
    ]);

    $secondConversation = app(WhatsAppConversationService::class)
        ->resolveConversation($secondAccount, $this->contact->phone);

    expect($secondConversation->id)->not->toBe($this->conversation->id);
    expect($secondConversation->contact_id)->toBe($this->contact->id);
    expect($secondConversation->whatsapp_account_id)->toBe($secondAccount->id);
    expect($this->conversation->whatsapp_account_id)->toBe($this->account->id);

    // Both conversations resolve to the same contact but stay distinct rows.
    expect(MarketingWhatsAppConversation::where('contact_id', $this->contact->id)->count())->toBe(2);
});

// ═══════════════════════════════════════════════════════════════════════
// 4. Stats — real totals, not page-scoped
// ═══════════════════════════════════════════════════════════════════════

test('conversation stats reflect the true total across every visible conversation, not just the first page', function () {
    // 25 unread, unclaimed conversations — more than the 20-per-page list.
    foreach (range(1, 25) as $i) {
        MarketingWhatsAppConversation::create([
            'organization_id' => $this->org->id,
            'whatsapp_account_id' => $this->account->id,
            'phone' => "+91 98000{$i}",
            'normalized_phone' => "98000{$i}",
            'status' => 'open',
            'unread_count' => 1,
        ]);
    }

    $response = $this->actingAs($this->user)->get(route('company.marketing.conversations.index'));

    $response->assertInertia(fn ($page) => $page
        ->where('conversations.data', fn ($data) => count($data) === 20)
        // Stats are computed before the auto-mark-read side effect below
        // runs, so all 25 count here even though opening the inbox with no
        // conversation_id auto-selects and marks read the top conversation.
        ->where('stats.unread', 25)
    );
});

test('mine and unclaimed stats are computed server-side across all visible conversations', function () {
    $otherUser = User::factory()->create(['user_type' => UserType::CompanyUser, 'organization_id' => $this->org->id]);
    grantMarketingPermissions($otherUser);

    foreach (range(1, 22) as $i) {
        MarketingWhatsAppConversation::create([
            'organization_id' => $this->org->id,
            'whatsapp_account_id' => $this->account->id,
            'phone' => "+91 97000{$i}",
            'normalized_phone' => "97000{$i}",
            'status' => 'open',
            'assigned_user_id' => $i % 2 === 0 ? $this->user->id : null,
        ]);
    }

    $response = $this->actingAs($this->user)->get(route('company.marketing.conversations.index'));

    $props = $response->viewData('page')['props'];
    expect($props['stats']['mine'])->toBe(11); // 11 even-numbered i's assigned to $this->user
    expect($props['stats']['unclaimed'])->toBe(11 + 1); // 11 odd-numbered + the beforeEach conversation
});

// ═══════════════════════════════════════════════════════════════════════
// Message pagination — recent-first, older loadable, nothing lost
// ═══════════════════════════════════════════════════════════════════════

test('the conversation view loads only the most recent 50 messages, oldest of that window first', function () {
    foreach (range(1, 60) as $i) {
        // forceCreate: 'created_at' isn't mass-assignable, and every row
        // needs an explicit, distinct timestamp to make ordering deterministic.
        MarketingWhatsAppMessage::forceCreate([
            'organization_id' => $this->org->id,
            'whatsapp_account_id' => $this->account->id,
            'conversation_id' => $this->conversation->id,
            'contact_id' => $this->contact->id,
            'provider_message_id' => "wamid.compliance.{$i}",
            'direction' => $i % 2 === 0 ? 'inbound' : 'outbound',
            'message_type' => 'text',
            'body' => "Message {$i}",
            'status' => 'sent',
            'created_at' => now()->subMinutes(60 - $i),
        ]);
    }

    $response = $this->actingAs($this->user)->get(route('company.marketing.conversations.index', ['conversation_id' => $this->conversation->id]));

    $response->assertInertia(fn ($page) => $page
        ->where('messages.data', fn ($data) => count($data) === 50)
        ->where('messages.current_page', 1)
        ->where('messages.last_page', 2)
        // Oldest-first within the loaded window: message 11 (the 50th most recent) first, message 60 (newest) last.
        ->where('messages.data.0.body', 'Message 11')
        ->where('messages.data.49.body', 'Message 60')
    );
});

test('older messages remain fully accessible via the load-earlier endpoint, and nothing is lost', function () {
    foreach (range(1, 60) as $i) {
        MarketingWhatsAppMessage::forceCreate([
            'organization_id' => $this->org->id,
            'whatsapp_account_id' => $this->account->id,
            'conversation_id' => $this->conversation->id,
            'contact_id' => $this->contact->id,
            'provider_message_id' => "wamid.older.{$i}",
            'direction' => 'inbound',
            'message_type' => 'text',
            'body' => "Message {$i}",
            'status' => 'received',
            'created_at' => now()->subMinutes(60 - $i),
        ]);
    }

    $response = $this->actingAs($this->user)->getJson(
        route('company.marketing.conversations.messages', $this->conversation->id).'?message_page=2'
    );

    $response->assertOk();
    $json = $response->json();
    expect($json['data'])->toHaveCount(10);
    expect($json['data'][0]['body'])->toBe('Message 1'); // oldest of all
    expect($json['data'][9]['body'])->toBe('Message 10');
    expect($json['current_page'])->toBe(2);
    expect($json['last_page'])->toBe(2);

    // Total history is intact — nothing was deleted or lost by paginating.
    expect(MarketingWhatsAppMessage::where('conversation_id', $this->conversation->id)->count())->toBe(60);
});

test('a rep cannot load message pages for a conversation assigned to someone else', function () {
    $otherUser = User::factory()->create(['user_type' => UserType::CompanyUser, 'organization_id' => $this->org->id]);
    grantMarketingPermissions($otherUser);
    $this->conversation->update(['assigned_user_id' => $otherUser->id]);

    $response = $this->actingAs($this->user)->getJson(
        route('company.marketing.conversations.messages', $this->conversation->id).'?message_page=1'
    );

    $response->assertNotFound();
});

// ═══════════════════════════════════════════════════════════════════════
// 3. Conversation context — campaign attribution, scoped per account
// ═══════════════════════════════════════════════════════════════════════

test('campaign context is shown only for the account it was actually sent from', function () {
    $template = MarketingWhatsAppTemplate::create([
        'whatsapp_account_id' => $this->account->id,
        'name' => 'context_test_template',
        'language' => 'en',
        'category' => 'MARKETING',
        'status' => 'approved',
        'body' => 'Hello!',
        'variables' => [],
    ]);
    $audience = MarketingAudience::create(['organization_id' => $this->org->id, 'name' => 'Context Segment', 'rules' => []]);
    $campaign = MarketingCampaign::create([
        'organization_id' => $this->org->id,
        'whatsapp_account_id' => $this->account->id,
        'audience_id' => $audience->id,
        'template_id' => $template->id,
        'name' => 'Diwali Outreach',
        'status' => 'sending',
    ]);
    MarketingCampaignRecipient::create([
        'campaign_id' => $campaign->id,
        'contact_id' => $this->contact->id,
        'phone' => $this->contact->phone,
        'normalized_phone' => $this->contact->normalized_phone,
        'status' => 'sent',
    ]);

    // A second account with no campaign history for this contact.
    $secondAccount = MarketingWhatsAppAccount::create([
        'organization_id' => $this->org->id,
        'provider' => 'meta',
        'business_account_id' => '3029384756',
        'phone_number_id' => 'conv-compliance-acct-3',
        'display_phone_number' => '+91 9800000003',
        'verified_name' => 'Third Number',
        'access_token_encrypted' => Crypt::encryptString('test_token'),
        'status' => 'connected',
    ]);
    $secondConversation = MarketingWhatsAppConversation::create([
        'organization_id' => $this->org->id,
        'whatsapp_account_id' => $secondAccount->id,
        'contact_id' => $this->contact->id,
        'phone' => $this->contact->phone,
        'normalized_phone' => $this->contact->normalized_phone,
        'status' => 'open',
        'last_message_at' => now(),
    ]);

    $response = $this->actingAs($this->user)->get(route('company.marketing.conversations.index'));

    $props = $response->viewData('page')['props'];
    $rows = collect($props['conversations']['data']);

    $withCampaign = $rows->firstWhere('id', $this->conversation->id);
    $withoutCampaign = $rows->firstWhere('id', $secondConversation->id);

    expect($withCampaign['campaign_context']['name'])->toBe('Diwali Outreach');
    expect($withoutCampaign['campaign_context'])->toBeNull();
});

// ═══════════════════════════════════════════════════════════════════════
// CRM handoff — unchanged
// ═══════════════════════════════════════════════════════════════════════

test('lead creation from a conversation still works', function () {
    $response = $this->actingAs($this->user)->post(
        route('company.marketing.conversations.create-lead', $this->conversation->id),
        ['company_name' => 'Sunrise Dental', 'contact_name' => 'Dr. Compliance Test']
    );

    $response->assertRedirect();
    expect($this->conversation->fresh()->crm_lead_id)->not->toBeNull();
});

// ═══════════════════════════════════════════════════════════════════════
// Filter tabs — count and filter use the same condition, so they agree
// ═══════════════════════════════════════════════════════════════════════

test('the unread filter and its tab count use the same condition', function () {
    $this->conversation->update(['unread_count' => 0]);

    foreach (range(1, 3) as $i) {
        MarketingWhatsAppConversation::create([
            'organization_id' => $this->org->id,
            'whatsapp_account_id' => $this->account->id,
            'phone' => "+91 9700000{$i}",
            'normalized_phone' => "970000{$i}",
            'status' => 'open',
            'unread_count' => 1,
        ]);
    }
    // A read conversation must not appear under "Unread".
    MarketingWhatsAppConversation::create([
        'organization_id' => $this->org->id,
        'whatsapp_account_id' => $this->account->id,
        'phone' => '+91 96000001',
        'normalized_phone' => '960000001',
        'status' => 'open',
        'unread_count' => 0,
    ]);

    $response = $this->actingAs($this->user)->get(
        route('company.marketing.conversations.index', ['filter' => 'unread'])
    );

    // Stats are computed before the auto-mark-read side effect, so the
    // "Unread" tab's own count matches exactly how many rows it lists.
    $response->assertInertia(fn ($page) => $page
        ->where('stats.unread', 3)
        ->where('conversations.data', fn ($data) => count($data) === 3)
        ->where('filters.filter', 'unread')
    );
});

test('the needs-reply filter matches conversations whose last message is inbound and unanswered', function () {
    // Needs reply: customer's message is the latest, nothing sent back since.
    $needsReply = MarketingWhatsAppConversation::create([
        'organization_id' => $this->org->id,
        'whatsapp_account_id' => $this->account->id,
        'phone' => '+91 95000001',
        'normalized_phone' => '950000001',
        'status' => 'open',
        'last_inbound_at' => now()->subMinutes(5),
        'last_outbound_at' => now()->subHours(1),
    ]);

    // Already answered: outbound came after the last inbound message.
    $alreadyAnswered = MarketingWhatsAppConversation::create([
        'organization_id' => $this->org->id,
        'whatsapp_account_id' => $this->account->id,
        'phone' => '+91 95000002',
        'normalized_phone' => '950000002',
        'status' => 'open',
        'last_inbound_at' => now()->subHours(2),
        'last_outbound_at' => now()->subMinutes(5),
    ]);

    // Never replied to at all (no outbound yet) — also needs a reply.
    $neverAnswered = MarketingWhatsAppConversation::create([
        'organization_id' => $this->org->id,
        'whatsapp_account_id' => $this->account->id,
        'phone' => '+91 95000003',
        'normalized_phone' => '950000003',
        'status' => 'open',
        'last_inbound_at' => now()->subMinutes(10),
        'last_outbound_at' => null,
    ]);

    $response = $this->actingAs($this->user)->get(
        route('company.marketing.conversations.index', ['filter' => 'needs_reply'])
    );

    $response->assertInertia(fn ($page) => $page->where('stats.needs_reply', 2));

    $ids = collect($response->viewData('page')['props']['conversations']['data'])->pluck('id');
    expect($ids)->toContain($needsReply->id, $neverAnswered->id);
    expect($ids)->not->toContain($alreadyAnswered->id);
});

test('switching filters does not lose the current search term', function () {
    $this->contact->update(['name' => 'Findable Patient']);

    $response = $this->actingAs($this->user)->get(
        route('company.marketing.conversations.index', ['search' => 'Findable', 'filter' => 'all'])
    );

    $response->assertInertia(fn ($page) => $page
        ->where('filters.search', 'Findable')
        ->where('filters.filter', 'all')
        ->where('conversations.data', fn ($data) => count($data) === 1)
    );
});
