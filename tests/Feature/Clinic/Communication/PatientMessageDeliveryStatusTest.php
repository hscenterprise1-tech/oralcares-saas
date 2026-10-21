<?php

use App\Jobs\DispatchDuePatientMessages;
use App\Models\ClinicWhatsAppMessage;
use App\Models\ClinicWhatsAppSettings;
use App\Models\CommunicationPreference;
use App\Models\ConsentRecord;
use App\Models\Notification;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppTemplate;
use App\Services\Communication\PatientMessaging;
use App\Services\NotificationService;
use App\Services\WhatsApp\ClinicWhatsApp;
use Carbon\CarbonImmutable;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\Support\ReceptionFixture;

/**
 * A patient message is reported as it really went. With no provider for SMS,
 * e-mail or in-app, nothing is sent and the message says so; the log never
 * carries what the message said; and WhatsApp goes only to a patient whose
 * agreement the clinic's settings require.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-07 10:00', 'Asia/Kolkata'));
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.X']]])]);
    $this->f = ReceptionFixture::make('delivery-status-clinic');
    $this->patient = $this->f->patient('Meera', 'Kulkarni', ['phone' => '98111 00002']);
    $this->confirm = function () {
        $appointment = $this->f->appointment($this->patient, $this->f->doctorA, CarbonImmutable::parse('2026-10-09 17:30'));
        app(PatientMessaging::class)->appointmentsBooked([$appointment]);

        return Notification::query()->where('event_key', "appointment_confirmed:{$appointment->id}")->sole();
    };
    $this->dispatch = fn () => (new DispatchDuePatientMessages)->handle(app(NotificationService::class));
    $this->logged = collect();
    Event::listen(MessageLogged::class, fn (MessageLogged $entry) => $this->logged->push($entry));
});

test('a message on a channel with no provider is skipped with its reason, never delivered, and never picked up again', function (string $channel) {
    foreach (array_diff(['sms', 'email', 'in_app'], [$channel]) as $off) {
        CommunicationPreference::query()->create(['clinic_id' => $this->f->clinic->id, 'channel' => $off, 'is_enabled' => false]);
    }

    $message = ($this->confirm)();
    expect($message->channel)->toBe($channel);

    ($this->dispatch)();
    ($this->dispatch)();

    $message->refresh();
    expect($message->status)->toBe(NotificationService::SKIPPED)
        ->and($message->failed_reason)->toBe(NotificationService::NO_PROVIDER_REASON)
        ->and($message->sent_at)->toBeNull()
        ->and($message->retry_count)->toBe(0);
})->with(['sms', 'email', 'in_app']);

test('the log names the message by its identifiers only, never its text or the patient', function () {
    $message = ($this->confirm)();
    ($this->dispatch)();

    $entry = $this->logged->sole(fn (MessageLogged $entry) => str_contains($entry->message, 'Patient message skipped'));
    $everything = $this->logged->map(fn (MessageLogged $entry) => $entry->message.' '.json_encode($entry->context))->implode("\n");

    expect($entry->context)->toBe([
        'notification_id' => $message->id,
        'clinic_id' => $this->f->clinic->id,
        'channel' => $message->channel,
        'event_key' => $message->event_key,
    ])
        ->and($everything)->not->toContain('Meera')
        ->and($everything)->not->toContain('Kulkarni')
        ->and($everything)->not->toContain('9811100002')
        ->and($everything)->not->toContain($this->f->doctorA->name);
});

test('a withdrawn agreement stops WhatsApp even when the clinic does not require opt-in, with no fallback to another channel', function () {
    $connection = WhatsAppConnection::create(['mode' => WhatsAppConnection::SHARED, 'label' => 'OralCares common', 'display_phone_number' => '919000000001',
        'phone_number_id' => 'PN-SHARED', 'business_account_id' => 'WABA-1', 'access_token' => 'EAA-shared', 'status' => 'active']);
    WhatsAppTemplate::create(['whatsapp_connection_id' => $connection->id, 'automation_key' => 'appointment_confirmed', 'name' => 'oc_appointment_confirmed',
        'body' => ClinicWhatsApp::catalog()['appointment_confirmed']['body'], 'status' => 'approved']);
    ClinicWhatsAppSettings::query()->withoutGlobalScopes()->create(['clinic_id' => $this->f->clinic->id, 'enabled' => true, 'mode' => 'common',
        'automations' => ['appointment_confirmed' => true], 'require_opt_in' => false]);
    ConsentRecord::query()->withoutGlobalScopes()->create(['clinic_id' => $this->f->clinic->id, 'patient_id' => $this->patient->id, 'consent_type' => 'communication',
        'status' => ConsentRecord::REVOKED, 'capture_method' => 'verbal', 'captured_at' => now(), 'signed_at' => now(), 'revoked_at' => now()]);

    $message = ($this->confirm)();
    ($this->dispatch)();

    $delivery = ClinicWhatsAppMessage::query()->withoutGlobalScopes()->sole();
    expect($delivery->status)->toBe(ClinicWhatsAppMessage::SKIPPED)
        ->and($delivery->failure_reason)->toBe('The patient has not agreed to WhatsApp messages.')
        ->and($message->fresh()->channel)->toBe('whatsapp')
        ->and($message->fresh()->status)->toBe('failed')
        ->and($message->fresh()->sent_at)->toBeNull();
    Http::assertNothingSent();
});
