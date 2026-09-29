<?php

use App\Models\MarketingAutomation;
use App\Models\MarketingAutomationEvent;
use App\Models\MarketingContact;
use App\Models\MarketingWhatsAppAccount;
use App\Models\MarketingWhatsAppConversation;
use App\Models\MarketingWhatsAppTemplate;
use App\Models\Organization;
use App\Models\User;
use App\Services\Marketing\MarketingAutomationEventService;
use App\Services\Marketing\WhatsAppMessageService;
use App\Services\Marketing\WhatsAppTemplateService;
use App\UserType;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->org = Organization::factory()->provider()->create();

    $this->rep = User::factory()->create([
        'user_type' => UserType::CompanyUser,
        'organization_id' => $this->org->id,
    ]);
    grantMarketingPermissions($this->rep);

    $this->account = MarketingWhatsAppAccount::create([
        'organization_id' => $this->org->id,
        'provider' => 'meta',
        'phone_number_id' => '9990001111',
        'status' => 'connected',
    ]);

    $this->contact = MarketingContact::create([
        'organization_id' => $this->org->id,
        'name' => 'Dr. Rahul Patel',
        'phone' => '9876543210',
        'normalized_phone' => '9876543210',
        'organization' => 'Patel Dental Clinic',
        'marketing_status' => 'active',
    ]);

    $this->conversation = MarketingWhatsAppConversation::create([
        'organization_id' => $this->org->id,
        'whatsapp_account_id' => $this->account->id,
        'contact_id' => $this->contact->id,
        'phone' => '9876543210',
        'normalized_phone' => '9876543210',
        'assigned_user_id' => $this->rep->id,
    ]);
});

test('inbound webhook button tap is captured and fires a distinct button_reply trigger event', function () {
    $payload = [
        'entry' => [[
            'changes' => [[
                'value' => [
                    'metadata' => ['phone_number_id' => '9990001111'],
                    'messages' => [[
                        'from' => '919876543210',
                        'id' => 'wamid.ButtonTap1',
                        'timestamp' => time(),
                        'type' => 'interactive',
                        'interactive' => [
                            'type' => 'button_reply',
                            'button_reply' => ['id' => 'interested', 'title' => 'Interested'],
                        ],
                    ]],
                ],
            ]],
        ]],
    ];

    $response = $this->postJson(route('webhooks.whatsapp.handle'), $payload);
    $response->assertOk()->assertJson(['processed' => 1]);

    $event = MarketingAutomationEvent::where('event_type', 'whatsapp.button_reply')->first();
    expect($event)->not->toBeNull();
    expect($event->payload['button_id'])->toBe('interested');
    expect($event->payload['button_title'])->toBe('Interested');

    // The generic reply events still fire alongside the specific button event.
    expect(MarketingAutomationEvent::where('event_type', 'whatsapp.reply_received')->exists())->toBeTrue();
});

test('tapping Interested creates a CRM lead attributed to the conversation\'s assigned rep', function () {
    $automation = MarketingAutomation::create([
        'organization_id' => $this->org->id,
        'name' => 'Interested -> Lead',
        'status' => 'active',
        'trigger_type' => 'whatsapp.button_reply',
        'definition' => [
            'version' => 1,
            'trigger' => ['type' => 'whatsapp.button_reply'],
            'steps' => [
                [
                    'id' => 's1',
                    'type' => 'condition',
                    'conditions' => [
                        'match' => 'all',
                        'rules' => [['field' => 'event.button_id', 'operator' => 'is', 'value' => 'interested']],
                    ],
                ],
                ['id' => 's2', 'type' => 'create_lead'],
            ],
        ],
    ]);

    app(MarketingAutomationEventService::class)->dispatchTriggerEvent(
        organizationId: $this->org->id,
        eventType: 'whatsapp.button_reply',
        eventKey: 'whatsapp:wamid.Btn1:button',
        payload: ['button_id' => 'interested', 'button_title' => 'Interested'],
        contact: $this->contact,
        conversation: $this->conversation
    );

    $this->assertDatabaseHas('leads', [
        'marketing_contact_id' => $this->contact->id,
        'assigned_user_id' => $this->rep->id,
        'source' => 'whatsapp_automation',
    ]);

    $execution = $automation->fresh()->executions()->first();
    expect($execution->status)->toBe('completed');
});

test('tapping Not Interested auto-suppresses the contact and creates no lead', function () {
    MarketingAutomation::create([
        'organization_id' => $this->org->id,
        'name' => 'Not Interested -> Suppress',
        'status' => 'active',
        'trigger_type' => 'whatsapp.button_reply',
        'definition' => [
            'version' => 1,
            'trigger' => ['type' => 'whatsapp.button_reply'],
            'steps' => [
                [
                    'id' => 's1',
                    'type' => 'condition',
                    'conditions' => [
                        'match' => 'all',
                        'rules' => [['field' => 'event.button_id', 'operator' => 'is', 'value' => 'not_interested']],
                    ],
                ],
                ['id' => 's2', 'type' => 'suppress_contact'],
            ],
        ],
    ]);

    app(MarketingAutomationEventService::class)->dispatchTriggerEvent(
        organizationId: $this->org->id,
        eventType: 'whatsapp.button_reply',
        eventKey: 'whatsapp:wamid.Btn2:button',
        payload: ['button_id' => 'not_interested', 'button_title' => 'Not Interested'],
        contact: $this->contact,
        conversation: $this->conversation
    );

    expect($this->contact->fresh()->marketing_status)->toBe('suppressed');
    expect($this->contact->fresh()->crmLeads()->count())->toBe(0);
});

test('a condition on the wrong button stops the automation without acting', function () {
    $automation = MarketingAutomation::create([
        'organization_id' => $this->org->id,
        'name' => 'Interested only',
        'status' => 'active',
        'trigger_type' => 'whatsapp.button_reply',
        'definition' => [
            'version' => 1,
            'trigger' => ['type' => 'whatsapp.button_reply'],
            'steps' => [
                [
                    'id' => 's1',
                    'type' => 'condition',
                    'conditions' => [
                        'match' => 'all',
                        'rules' => [['field' => 'event.button_id', 'operator' => 'is', 'value' => 'interested']],
                    ],
                ],
                ['id' => 's2', 'type' => 'create_lead'],
            ],
        ],
    ]);

    app(MarketingAutomationEventService::class)->dispatchTriggerEvent(
        organizationId: $this->org->id,
        eventType: 'whatsapp.button_reply',
        eventKey: 'whatsapp:wamid.Btn3:button',
        payload: ['button_id' => 'not_interested', 'button_title' => 'Not Interested'],
        contact: $this->contact,
        conversation: $this->conversation
    );

    $execution = $automation->fresh()->executions()->first();
    expect($execution->status)->toBe('stopped');
    expect($execution->stop_reason)->toBe('condition_not_met');
    expect($this->contact->fresh()->crmLeads()->count())->toBe(0);
});

test('sendOutboundTemplateMessage sends once and is idempotent on repeat calls with the same key', function () {
    $result1 = app(WhatsAppMessageService::class)->sendOutboundTemplateMessage(
        $this->account,
        $this->contact->phone,
        'oralcares_intro_demo',
        'en',
        [['type' => 'body', 'parameters' => [['type' => 'text', 'text' => 'Rahul']]]],
        'idem-key-1'
    );

    $result2 = app(WhatsAppMessageService::class)->sendOutboundTemplateMessage(
        $this->account,
        $this->contact->phone,
        'oralcares_intro_demo',
        'en',
        [['type' => 'body', 'parameters' => [['type' => 'text', 'text' => 'Rahul']]]],
        'idem-key-1'
    );

    expect($result1['success'])->toBeTrue();
    expect($result2['success'])->toBeTrue();
    expect($result2['message_id'])->toBe($result1['message_id']);

    $this->assertDatabaseCount('marketing_whatsapp_messages', 1);
    $this->assertDatabaseHas('marketing_whatsapp_messages', [
        'message_type' => 'template',
        'direction' => 'outbound',
    ]);
});

test('template sync captures quick-reply buttons from the sandbox provider', function () {
    app(WhatsAppTemplateService::class)->syncTemplates($this->account);

    $template = MarketingWhatsAppTemplate::where('name', 'oralcares_intro_demo')->first();
    expect($template)->not->toBeNull();
    expect($template->quick_reply_labels)->toBe(['Interested', 'Not Interested']);
});
