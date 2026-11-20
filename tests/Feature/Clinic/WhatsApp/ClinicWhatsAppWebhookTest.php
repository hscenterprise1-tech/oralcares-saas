<?php

use App\Models\ClinicWhatsAppMessage;
use App\Models\WhatsAppConnection;
use Tests\Support\ReceptionFixture;

/**
 * Meta's callbacks for clinic numbers: delivery state moves only forward,
 * only on the number the message went out on; a reply belongs to the clinic
 * that wrote to that phone, or to no clinic at all.
 */
beforeEach(function () {
    config(['services.meta_whatsapp.app_secret' => 'app-secret']);
    $this->a = ReceptionFixture::make('hook-a');
    $this->b = ReceptionFixture::make('hook-b');
    $this->shared = WhatsAppConnection::create(['mode' => WhatsAppConnection::SHARED, 'label' => 'Common', 'phone_number_id' => 'PN-SHARED', 'status' => 'active']);
    $this->own = WhatsAppConnection::create(['mode' => WhatsAppConnection::OWNED, 'clinic_id' => $this->b->clinic->id, 'label' => 'B own', 'phone_number_id' => 'PN-B', 'status' => 'active']);
    $this->sent = fn ($fixture, WhatsAppConnection $connection, string $wamid, string $phone = '919811100001') => ClinicWhatsAppMessage::query()->withoutGlobalScopes()->create([
        'clinic_id' => $fixture->clinic->id, 'whatsapp_connection_id' => $connection->id, 'direction' => 'outbound', 'automation_key' => 'appointment_confirmed',
        'recipient_phone' => $phone, 'status' => 'sent', 'provider_message_id' => $wamid, 'idempotency_key' => "test:{$wamid}",
    ]);
    $this->post = function (string $phoneNumberId, array $value) {
        $body = json_encode(['entry' => [['changes' => [['value' => ['metadata' => ['phone_number_id' => $phoneNumberId], ...$value]]]]]]);

        return $this->call('POST', '/webhooks/clinic-whatsapp', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'app-secret'),
        ], $body);
    };
    $this->status = fn (string $wamid, string $status) => ['statuses' => [['id' => $wamid, 'status' => $status, 'recipient_id' => '919811100001', 'timestamp' => time()]]];
});

test('delivery and read move a message forward; a late "sent" never moves it back', function () {
    $message = ($this->sent)($this->a, $this->shared, 'wamid.A1');

    ($this->post)('PN-SHARED', ($this->status)('wamid.A1', 'delivered'))->assertOk();
    ($this->post)('PN-SHARED', ($this->status)('wamid.A1', 'read'))->assertOk();
    ($this->post)('PN-SHARED', ($this->status)('wamid.A1', 'sent'))->assertOk();

    expect($message->fresh())->status->toBe('read')->delivered_at->not->toBeNull()->read_at->not->toBeNull();
});

test('a status naming another number cannot touch a message sent on this one', function () {
    $message = ($this->sent)($this->a, $this->shared, 'wamid.A2');

    ($this->post)('PN-B', ($this->status)('wamid.A2', 'failed'))->assertOk();
    ($this->post)('PN-UNKNOWN', ($this->status)('wamid.A2', 'failed'))->assertOk();

    expect($message->fresh()->status)->toBe('sent');
});

test('a reply on a clinic\'s own number is that clinic\'s; on the common number, the clinic that last wrote to that phone', function () {
    ($this->sent)($this->a, $this->shared, 'wamid.A3', '919811100009');
    $reply = fn (string $from, string $id) => ['messages' => [['from' => $from, 'id' => $id, 'type' => 'text', 'text' => ['body' => 'Thank you'], 'timestamp' => time()]]];

    ($this->post)('PN-SHARED', $reply('919811100009', 'wamid.IN1'))->assertOk();
    ($this->post)('PN-B', $reply('919800000000', 'wamid.IN2'))->assertOk();
    // Nobody wrote to this phone on the common number: the reply is shown to no clinic.
    ($this->post)('PN-SHARED', $reply('919877777777', 'wamid.IN3'))->assertOk();

    $inbound = ClinicWhatsAppMessage::query()->withoutGlobalScopes()->where('direction', 'inbound')->get()->keyBy('provider_message_id');
    expect($inbound->keys()->all())->toBe(['wamid.IN1', 'wamid.IN2'])
        ->and($inbound['wamid.IN1']->clinic_id)->toBe($this->a->clinic->id)
        ->and($inbound['wamid.IN2']->clinic_id)->toBe($this->b->clinic->id);
});

test('an unsigned or wrongly signed callback is refused, and a replayed one is not processed twice', function () {
    $message = ($this->sent)($this->a, $this->shared, 'wamid.A4');
    $body = json_encode(['entry' => [['changes' => [['value' => ['metadata' => ['phone_number_id' => 'PN-SHARED'], ...($this->status)('wamid.A4', 'delivered')]]]]]]);

    $this->call('POST', '/webhooks/clinic-whatsapp', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256=forged'], $body)->assertForbidden();
    expect($message->fresh()->status)->toBe('sent');

    ($this->post)('PN-SHARED', ($this->status)('wamid.A4', 'delivered'))->assertOk()->assertJson(['processed' => 1]);
    ($this->post)('PN-SHARED', ($this->status)('wamid.A4', 'delivered'))->assertOk()->assertJson(['processed' => 0, 'ignored' => 1]);
});

test('Meta\'s verification challenge is answered only for OralCares\' token', function () {
    config(['services.meta_whatsapp.clinic_verify_token' => 'verify-me']);

    $this->get('/webhooks/clinic-whatsapp?hub.mode=subscribe&hub.verify_token=verify-me&hub.challenge=42')->assertOk()->assertSee('42');
    $this->get('/webhooks/clinic-whatsapp?hub.mode=subscribe&hub.verify_token=wrong&hub.challenge=42')->assertForbidden();
});

test('in production a webhook with no app secret configured is refused, never trusted', function () {
    config(['services.meta_whatsapp.app_secret' => null]);
    $message = ($this->sent)($this->a, $this->shared, 'wamid.P1');
    $body = json_encode(['entry' => [['changes' => [['value' => ['metadata' => ['phone_number_id' => 'PN-SHARED'], ...($this->status)('wamid.P1', 'failed')]]]]]]);
    $send = fn () => $this->call('POST', '/webhooks/clinic-whatsapp', [], [], [], ['CONTENT_TYPE' => 'application/json'], $body);

    $this->app['env'] = 'production';
    $send()->assertForbidden();
    expect($message->fresh()->status)->toBe('sent');

    // Local work and tests may run unverified, as before.
    $this->app['env'] = 'testing';
    $send()->assertOk();
});

test('a wrong signature is refused everywhere', function () {
    $this->call('POST', '/webhooks/clinic-whatsapp', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256=bad'], '{}')->assertForbidden();
});
