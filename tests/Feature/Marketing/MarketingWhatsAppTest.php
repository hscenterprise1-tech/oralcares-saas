<?php

use App\Models\MarketingContact;
use App\Models\MarketingWhatsAppAccount;
use App\Models\MarketingWhatsAppConversation;
use App\Models\MarketingWhatsAppMessage;
use App\Models\MarketingWhatsAppWebhookEvent;
use App\Models\User;
use App\UserType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create([
        'user_type' => UserType::CompanyUser,
    ]);
    grantMarketingPermissions($this->user);
});

test('user can connect whatsapp account and tokens are encrypted at rest', function () {
    $response = $this->actingAs($this->user)->post(route('company.marketing.whatsapp.store'), [
        'business_account_id' => '1029384756',
        'phone_number_id' => '9876543210123',
        'display_phone_number' => '+91 9876543210',
        'verified_name' => 'OralCares WhatsApp',
        'access_token' => 'secret_meta_access_token_12345',
        'webhook_verify_token' => 'my_secret_webhook_verify_token',
    ]);

    $response->assertRedirect();

    $account = MarketingWhatsAppAccount::first();
    expect($account)->not->toBeNull();
    expect($account->business_account_id)->toBe('1029384756');

    // Access token and verify token must be encrypted in database column
    expect($account->access_token_encrypted)->not->toBe('secret_meta_access_token_12345');
    expect(Crypt::decryptString($account->access_token_encrypted))->toBe('secret_meta_access_token_12345');
    expect(Crypt::decryptString($account->webhook_verify_token_encrypted))->toBe('my_secret_webhook_verify_token');

    // Decrypted accessor works correctly
    expect($account->access_token)->toBe('secret_meta_access_token_12345');
    expect($account->webhook_verify_token)->toBe('my_secret_webhook_verify_token');
});

test('inertia page props mask access token securely', function () {
    MarketingWhatsAppAccount::create([
        'provider' => 'meta',
        'business_account_id' => '1029384756',
        'phone_number_id' => '9876543210123',
        'display_phone_number' => '+91 9876543210',
        'access_token_encrypted' => Crypt::encryptString('secret_token'),
        'webhook_verify_token_encrypted' => Crypt::encryptString('secret_verify'),
        'status' => 'connected',
    ]);

    $response = $this->actingAs($this->user)->get(route('company.marketing.whatsapp.index'));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Company/Marketing/WhatsApp/Index')
            ->where('account.masked_token', '••••••••••••••••')
            ->missing('account.access_token')
            ->missing('account.access_token_encrypted')
        );
});

test('public webhook verification challenge succeeds with correct verify token', function () {
    MarketingWhatsAppAccount::create([
        'provider' => 'meta',
        'business_account_id' => '1029384756',
        'phone_number_id' => '9876543210123',
        'webhook_verify_token_encrypted' => Crypt::encryptString('valid_challenge_token'),
        'status' => 'connected',
    ]);

    $response = $this->get(route('webhooks.whatsapp.verify', [
        'hub_mode' => 'subscribe',
        'hub_verify_token' => 'valid_challenge_token',
        'hub_challenge' => 'challenge_code_9988',
    ]));

    $response->assertOk()
        ->assertSee('challenge_code_9988');
});

test('incoming webhook ingests message and resolves conversation via PhoneNormalizer', function () {
    $account = MarketingWhatsAppAccount::create([
        'provider' => 'meta',
        'business_account_id' => '1029384756',
        'phone_number_id' => '9876543210123',
        'status' => 'connected',
    ]);

    // Pre-existing contact in DB
    $contact = MarketingContact::create([
        'name' => 'Rahul Patel',
        'phone' => '+91 9876543210',
        'normalized_phone' => '9876543210',
    ]);

    $payload = [
        'entry' => [
            [
                'changes' => [
                    [
                        'value' => [
                            'metadata' => ['phone_number_id' => '9876543210123'],
                            'messages' => [
                                [
                                    'from' => '919876543210', // Normalized to '9876543210'
                                    'id' => 'wamid.HBgL11223344',
                                    'timestamp' => time(),
                                    'type' => 'text',
                                    'text' => ['body' => 'Hello OralCares team!'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ];

    $response = $this->postJson(route('webhooks.whatsapp.handle'), $payload);

    $response->assertOk()
        ->assertJson(['status' => 'success', 'processed' => 1]);

    // Verify conversation resolved contact correctly
    $conversation = MarketingWhatsAppConversation::first();
    expect($conversation)->not->toBeNull();
    expect($conversation->whatsapp_account_id)->toBe($account->id);
    expect($conversation->contact_id)->toBe($contact->id);
    expect($conversation->normalized_phone)->toBe('9876543210');

    // Verify message recorded
    $message = MarketingWhatsAppMessage::first();
    expect($message)->not->toBeNull();
    expect($message->direction)->toBe('inbound');
    expect($message->status)->toBe('received');
    expect($message->body)->toBe('Hello OralCares team!');
});

test('unknown whatsapp contact keeps contact_id null without polluting contact database', function () {
    $account = MarketingWhatsAppAccount::create([
        'provider' => 'meta',
        'phone_number_id' => '9876543210123',
        'status' => 'connected',
    ]);

    $payload = [
        'entry' => [
            [
                'changes' => [
                    [
                        'value' => [
                            'metadata' => ['phone_number_id' => '9876543210123'],
                            'messages' => [
                                [
                                    'from' => '919999988888', // Unknown phone number
                                    'id' => 'wamid.HBgLUnknown999',
                                    'timestamp' => time(),
                                    'type' => 'text',
                                    'text' => ['body' => 'Inquiry from stranger'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ];

    $this->postJson(route('webhooks.whatsapp.handle'), $payload);

    $conversation = MarketingWhatsAppConversation::first();
    expect($conversation->contact_id)->toBeNull();
    expect(MarketingContact::count())->toBe(0); // No contact created automatically
});

test('webhook idempotency skips duplicate provider event ids', function () {
    $account = MarketingWhatsAppAccount::create([
        'provider' => 'meta',
        'phone_number_id' => '9876543210123',
        'status' => 'connected',
    ]);

    $payload = [
        'entry' => [
            [
                'changes' => [
                    [
                        'value' => [
                            'metadata' => ['phone_number_id' => '9876543210123'],
                            'messages' => [
                                [
                                    'from' => '919876543210',
                                    'id' => 'wamid.HBgLIdempotent101',
                                    'timestamp' => time(),
                                    'type' => 'text',
                                    'text' => ['body' => 'Idempotency test'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ];

    // First ingestion
    $res1 = $this->postJson(route('webhooks.whatsapp.handle'), $payload);
    $res1->assertOk()->assertJson(['processed' => 1, 'skipped' => 0]);

    // Second ingestion (duplicate payload)
    $res2 = $this->postJson(route('webhooks.whatsapp.handle'), $payload);
    $res2->assertOk()->assertJson(['processed' => 0, 'skipped' => 1]);

    expect(MarketingWhatsAppMessage::count())->toBe(1);
    expect(MarketingWhatsAppWebhookEvent::count())->toBe(1);
});

test('webhook status transition updates message status and respects state machine', function () {
    $account = MarketingWhatsAppAccount::create([
        'provider' => 'meta',
        'phone_number_id' => '9876543210123',
        'status' => 'connected',
    ]);

    $conversation = MarketingWhatsAppConversation::create([
        'whatsapp_account_id' => $account->id,
        'phone' => '9876543210',
        'normalized_phone' => '9876543210',
    ]);

    $message = MarketingWhatsAppMessage::create([
        'whatsapp_account_id' => $account->id,
        'conversation_id' => $conversation->id,
        'provider_message_id' => 'wamid.HBgLStatusTest',
        'direction' => 'outbound',
        'status' => 'sent',
        'body' => 'Test status message',
    ]);

    // 1. Delivered event
    $payloadDelivered = [
        'entry' => [
            [
                'changes' => [
                    [
                        'value' => [
                            'metadata' => ['phone_number_id' => '9876543210123'],
                            'statuses' => [
                                [
                                    'id' => 'wamid.HBgLStatusTest',
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
    ];

    $this->postJson(route('webhooks.whatsapp.handle'), $payloadDelivered);
    expect($message->fresh()->status)->toBe('delivered');
    expect($message->fresh()->delivered_at)->not->toBeNull();

    // 2. Read event
    $payloadRead = [
        'entry' => [
            [
                'changes' => [
                    [
                        'value' => [
                            'metadata' => ['phone_number_id' => '9876543210123'],
                            'statuses' => [
                                [
                                    'id' => 'wamid.HBgLStatusTest',
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
    ];

    $this->postJson(route('webhooks.whatsapp.handle'), $payloadRead);
    expect($message->fresh()->status)->toBe('read');
    expect($message->fresh()->read_at)->not->toBeNull();

    // 3. Invalid Transition check: Cannot transition to 'failed' after 'read'
    $payloadFailed = [
        'entry' => [
            [
                'changes' => [
                    [
                        'value' => [
                            'metadata' => ['phone_number_id' => '9876543210123'],
                            'statuses' => [
                                [
                                    'id' => 'wamid.HBgLStatusTest',
                                    'status' => 'failed',
                                    'recipient_id' => '9876543210',
                                    'timestamp' => time(),
                                    'errors' => [['title' => 'Late Error']],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ];

    $this->postJson(route('webhooks.whatsapp.handle'), $payloadFailed);
    expect($message->fresh()->status)->toBe('read'); // Status remains 'read'
});

test('user can send single outbound reply in conversation thread', function () {
    $account = MarketingWhatsAppAccount::create([
        'provider' => 'meta',
        'phone_number_id' => '9876543210123',
        'access_token_encrypted' => Crypt::encryptString('test_token'),
        'status' => 'connected',
    ]);

    $conversation = MarketingWhatsAppConversation::create([
        'whatsapp_account_id' => $account->id,
        'phone' => '9876543210',
        'normalized_phone' => '9876543210',
    ]);

    $response = $this->actingAs($this->user)->post(route('company.marketing.conversations.reply', $conversation->id), [
        'message' => 'Thank you for reaching out to OralCares!',
    ]);

    $response->assertRedirect();

    $this->assertDatabaseHas('marketing_whatsapp_messages', [
        'conversation_id' => $conversation->id,
        'direction' => 'outbound',
        'body' => 'Thank you for reaching out to OralCares!',
        'status' => 'sent',
    ]);
});
