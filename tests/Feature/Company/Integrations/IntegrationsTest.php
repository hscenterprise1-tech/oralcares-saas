<?php

use App\Models\MarketingWhatsAppAccount;
use App\Models\MarketingWhatsAppWebhookEvent;
use App\Models\User;
use App\Services\Integrations\IntegrationsService;
use App\Services\Marketing\WebhookSignatureVerifier;
use App\UserType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

/**
 * Integrations.
 *
 * Two things are pinned here. The first is the hole: `POST /webhooks/whatsapp`
 * is public and unauthenticated, processing a payload creates conversations,
 * messages and contacts, and nothing checked that the payload came from Meta —
 * so anything able to reach the server could post one. The second is the
 * surface built to notice when a connection stops working, which is read-only
 * and must never carry a token.
 */
function integrationsUser(array $permissions = []): User
{
    foreach ($permissions as $name) {
        Permission::findOrCreate($name, 'web');
    }

    $user = User::factory()->create(['user_type' => UserType::CompanyUser]);

    if ($permissions !== []) {
        $user->givePermissionTo($permissions);
    }

    return $user->fresh();
}

function whatsappAccount(array $attributes = []): MarketingWhatsAppAccount
{
    $account = new MarketingWhatsAppAccount(array_merge([
        'provider' => 'meta',
        'business_account_id' => 'waba-1',
        'phone_number_id' => 'pn-'.fake()->unique()->numerify('######'),
        'display_phone_number' => '+919000000001',
        'verified_name' => 'OralCares',
        'status' => 'connected',
        'connection_status' => 'active',
        'last_connected_at' => now()->subDay(),
        'last_webhook_at' => now()->subMinutes(5),
    ], $attributes));

    $account->save();

    return $account->fresh();
}

/** A payload Meta's parser accepts, so the signature is the only variable. */
function whatsappPayload(string $phoneNumberId, string $messageId = 'wamid.SigTest1'): array
{
    return [
        'entry' => [[
            'changes' => [[
                'value' => [
                    'metadata' => ['phone_number_id' => $phoneNumberId],
                    'messages' => [[
                        'id' => $messageId,
                        'from' => '919876543210',
                        'timestamp' => (string) time(),
                        'type' => 'text',
                        'text' => ['body' => 'hello'],
                    ]],
                ],
            ]],
        ]],
    ];
}

beforeEach(function () {
    setPermissionsTeamId(0);

    foreach (['view-integrations', 'manage-marketing-settings'] as $name) {
        Permission::findOrCreate($name, 'web');
    }

    $this->admin = User::factory()->create(['user_type' => UserType::MasterAdmin]);
});

/* ══════════════════ the hole: an unsigned webhook was accepted ═════════════ */

it('refuses a webhook whose signature does not match the app secret', function () {
    config(['services.meta_whatsapp.app_secret' => 'the-app-secret']);

    $account = whatsappAccount();
    $payload = whatsappPayload($account->phone_number_id);

    $this->postJson('/webhooks/whatsapp', $payload, [
        'X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', json_encode($payload), 'a-different-secret'),
    ])->assertForbidden();

    /* Refused before anything was written, not after. */
    expect(MarketingWhatsAppWebhookEvent::count())->toBe(0);
});

it('refuses a webhook that carries no signature at all once a secret is set', function () {
    config(['services.meta_whatsapp.app_secret' => 'the-app-secret']);

    $account = whatsappAccount();

    $this->postJson('/webhooks/whatsapp', whatsappPayload($account->phone_number_id))
        ->assertForbidden();

    expect(MarketingWhatsAppWebhookEvent::count())->toBe(0);
});

it('accepts a webhook signed with the app secret and records that it was signed', function () {
    config(['services.meta_whatsapp.app_secret' => 'the-app-secret']);

    $account = whatsappAccount();
    $payload = whatsappPayload($account->phone_number_id);
    $body = json_encode($payload);

    $this->call(
        'POST',
        '/webhooks/whatsapp',
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'the-app-secret'),
        ],
        $body,
    )->assertOk();

    expect(MarketingWhatsAppWebhookEvent::first()->signature_result)
        ->toBe(WebhookSignatureVerifier::VERIFIED);
});

it('keeps accepting webhooks while no secret is configured, and says they were unchecked', function () {
    /*
     * Turning verification on by default would take inbound WhatsApp down for a
     * running system the moment it shipped. The event is processed and marked
     * unverified rather than silently treated as though it had been proven.
     */
    config(['services.meta_whatsapp.app_secret' => null]);

    $account = whatsappAccount();

    $this->postJson('/webhooks/whatsapp', whatsappPayload($account->phone_number_id))->assertOk();

    expect(MarketingWhatsAppWebhookEvent::first()->signature_result)
        ->toBe(WebhookSignatureVerifier::UNCONFIGURED);
});

it('verifies against the body exactly as received, not a re-encoding of it', function () {
    /*
     * Meta signs the raw bytes. Signing `json_encode($request->all())` would
     * produce a different string for the same request and never match.
     */
    config(['services.meta_whatsapp.app_secret' => 'the-app-secret']);

    $account = whatsappAccount();
    /* Spacing the sender would not reproduce by re-encoding. */
    $body = '{ "entry" : [ {"changes":[{"value":{"metadata":{"phone_number_id":"'
        .$account->phone_number_id.'"},"messages":[{"id":"wamid.Raw1","from":"919876543210","timestamp":"'
        .time().'","type":"text","text":{"body":"hi"}}]}}]} ] }';

    $this->call(
        'POST',
        '/webhooks/whatsapp',
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'the-app-secret'),
        ],
        $body,
    )->assertOk();

    expect(MarketingWhatsAppWebhookEvent::first()->signature_result)
        ->toBe(WebhookSignatureVerifier::VERIFIED);
});

/* ═══════════════ the other hole: a verify token written in source ══════════ */

it('refuses the hub challenge when no configured account holds the token', function () {
    /*
     * `handleChallenge` fell back to a literal written into the service, so
     * anyone reading the source could pass Meta's verification challenge
     * against a system with no account configured at all.
     */
    $this->get('/webhooks/whatsapp?hub_mode=subscribe&hub_verify_token=oralcares_webhook_token&hub_challenge=1234')
        ->assertForbidden();
});

it('names no verify token in the webhook service source', function () {
    $source = file_get_contents(app_path('Services/Marketing/WhatsAppWebhookService.php'));

    expect($source)->not->toContain('oralcares_webhook');
});

it('passes the hub challenge for a token an account actually holds', function () {
    $account = whatsappAccount();
    $account->webhook_verify_token = 'a-token-the-operator-chose';
    $account->save();

    $this->get('/webhooks/whatsapp?hub_mode=subscribe&hub_verify_token=a-token-the-operator-chose&hub_challenge=4321')
        ->assertOk()
        ->assertSee('4321');
});

/* ═════════════════════════ the surface ═════════════════════════════════════ */

it('refuses Integrations to a company user without the permission', function () {
    $this->actingAs(integrationsUser())->get('/company/integrations')->assertForbidden();
    $this->actingAs(integrationsUser(['view-integrations']))->get('/company/integrations')->assertOk();
});

it('lets the super admin open Integrations holding nothing', function () {
    expect($this->admin->getAllPermissions())->toBeEmpty();

    $this->actingAs($this->admin)->get('/company/integrations')->assertSuccessful();
});

it('exposes only GET on Integrations', function () {
    $verbs = collect(app('router')->getRoutes())
        ->filter(fn ($route) => str_starts_with((string) $route->uri(), 'company/integrations'))
        ->flatMap(fn ($route) => $route->methods())
        ->unique()
        ->sort()
        ->values();

    expect($verbs->all())->toBe(['GET', 'HEAD']);
});

it('never puts an access or verify token in the payload', function () {
    /*
     * Marketing settings shows the verify token because an operator has to
     * paste it into Meta. A health view has no reason to hold either secret,
     * so it reports only whether they are set.
     */
    $account = whatsappAccount();
    $account->access_token = 'EAAG-secret-access-token';
    $account->webhook_verify_token = 'secret-verify-token';
    $account->save();

    $connections = app(IntegrationsService::class)->connections();

    expect(json_encode($connections))
        ->not->toContain('EAAG-secret-access-token')
        ->not->toContain('secret-verify-token')
        ->and($connections[0]['has_access_token'])->toBeTrue()
        ->and($connections[0]['has_verify_token'])->toBeTrue();
});

it('calls a connection quiet when it has received nothing for a day', function () {
    /*
     * `status` records what was last saved, not what is arriving: a connection
     * reads `connected` while receiving nothing, which is the failure this page
     * exists to notice.
     */
    whatsappAccount(['last_webhook_at' => now()->subDays(3)]);
    whatsappAccount(['last_webhook_at' => now()->subMinutes(2)]);

    $connections = app(IntegrationsService::class)->connections();

    expect($connections[0]['status'])->toBe('connected')
        ->and($connections[0]['quiet'])->toBeTrue()
        ->and($connections[1]['quiet'])->toBeFalse();
});

it('separates events that arrived before verification existed from unchecked ones', function () {
    $account = whatsappAccount();

    MarketingWhatsAppWebhookEvent::create([
        'whatsapp_account_id' => $account->id,
        'provider' => 'meta',
        'provider_event_id' => 'evt-legacy',
        'event_type' => 'message.received',
        'payload' => [],
        'signature_result' => null,
        'processing_status' => 'processed',
    ]);

    MarketingWhatsAppWebhookEvent::create([
        'whatsapp_account_id' => $account->id,
        'provider' => 'meta',
        'provider_event_id' => 'evt-unconfigured',
        'event_type' => 'message.received',
        'payload' => [],
        'signature_result' => WebhookSignatureVerifier::UNCONFIGURED,
        'processing_status' => 'processed',
    ]);

    $inbound = app(IntegrationsService::class)->inbound(30);

    expect($inbound['by_signature']['unrecorded'])->toBe(1)
        ->and($inbound['by_signature'][WebhookSignatureVerifier::UNCONFIGURED])->toBe(1)
        ->and($inbound['unverified'])->toBe(2);
});

it('shows the reason a failed event gave, in full', function () {
    $account = whatsappAccount();

    MarketingWhatsAppWebhookEvent::create([
        'whatsapp_account_id' => $account->id,
        'provider' => 'meta',
        'provider_event_id' => 'evt-failed',
        'event_type' => 'message.received',
        'payload' => [],
        'processing_status' => 'failed',
        'error_message' => 'Contact could not be created: phone number failed normalisation',
    ]);

    $inbound = app(IntegrationsService::class)->inbound(30);

    expect($inbound['by_status']['failed'])->toBe(1)
        ->and($inbound['failures'][0]['error'])
        ->toBe('Contact could not be created: phone number failed normalisation');
});

it('reports what the Company side cannot do rather than offering it', function () {
    /*
     * `api_tokens.clinic_employee_id` is a non-nullable foreign key to
     * `clinic_employees`, so no company user can hold one. Company API access
     * is an architecture decision; a screen here would be a screen that cannot
     * work.
     */
    $unavailable = app(IntegrationsService::class)->unavailable();

    expect($unavailable['api_tokens']['scope'])->toBe('clinic_employee_id')
        ->and($unavailable['outbound_webhooks']['scope'])->toBe('clinic_id')
        ->and($unavailable['api_audit']['scope'])->toBe('clinic_employee_id');
});

it('creates no new permission for this surface', function () {
    /* `view-integrations` was already seeded and, until now, gated nothing. */
    $source = file_get_contents(app_path('Http/Controllers/Company/Integrations/IntegrationsController.php'));

    expect($source)->toContain("Gate::authorize('view-integrations')")
        ->and(Permission::where('name', 'manage-integrations')->exists())->toBeFalse();
});

it('mutates nothing when Integrations is opened', function () {
    whatsappAccount();

    $before = [
        'accounts' => MarketingWhatsAppAccount::count(),
        'events' => MarketingWhatsAppWebhookEvent::count(),
    ];

    $this->actingAs($this->admin)->get('/company/integrations')->assertOk();

    expect(MarketingWhatsAppAccount::count())->toBe($before['accounts'])
        ->and(MarketingWhatsAppWebhookEvent::count())->toBe($before['events']);
});
