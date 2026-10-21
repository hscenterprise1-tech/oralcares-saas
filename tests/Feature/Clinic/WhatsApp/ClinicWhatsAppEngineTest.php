<?php

use App\Jobs\DispatchDuePatientMessages;
use App\Jobs\SendClinicWhatsAppMessage;
use App\Models\ClinicWhatsAppMessage;
use App\Models\ClinicWhatsAppSettings;
use App\Models\ConsentRecord;
use App\Models\Notification;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppTemplate;
use App\PaymentMethod;
use App\Services\BillingService;
use App\Services\Communication\PatientMessaging;
use App\Services\Marketing\WhatsAppManager;
use App\Services\NotificationService;
use App\Services\PaymentService;
use App\Services\WhatsApp\ClinicWhatsApp;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Tests\Support\ReceptionFixture;

/**
 * Patient WhatsApp for clinics: `PatientMessaging` decides what to tell a
 * patient; WhatsApp carries it when the clinic has switched that automation
 * on, on the number its mode selects, and only to a patient who agreed.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-07 10:00', 'Asia/Kolkata'));
    // What Meta answers; a test may change it.
    $this->meta = ['messages' => [['id' => 'wamid.REAL1']]];
    $this->metaStatus = 200;
    Http::fake(['graph.facebook.com/*' => fn () => Http::response($this->meta, $this->metaStatus)]);
    $this->f = ReceptionFixture::make('wa-clinic');
    $this->shared = WhatsAppConnection::create(['mode' => WhatsAppConnection::SHARED, 'label' => 'OralCares common', 'display_phone_number' => '919000000001',
        'phone_number_id' => 'PN-SHARED', 'business_account_id' => 'WABA-1', 'access_token' => 'EAA-shared', 'status' => 'active']);
    $this->template = fn (WhatsAppConnection $connection, string $code) => WhatsAppTemplate::create([
        'whatsapp_connection_id' => $connection->id, 'automation_key' => $code, 'name' => "oc_{$code}", 'body' => ClinicWhatsApp::catalog()[$code]['body'], 'status' => 'approved',
    ]);
    ($this->template)($this->shared, 'appointment_confirmed');
    $this->enable = fn (array $automations, string $mode = 'common', bool $optIn = true) => ClinicWhatsAppSettings::query()->withoutGlobalScopes()->updateOrCreate(
        ['clinic_id' => $this->f->clinic->id],
        ['enabled' => true, 'mode' => $mode, 'automations' => array_fill_keys($automations, true), 'require_opt_in' => $optIn],
    );
    $this->agree = fn ($patient) => ConsentRecord::query()->withoutGlobalScopes()->create([
        'clinic_id' => $patient->clinic_id, 'patient_id' => $patient->id, 'consent_type' => 'communication', 'status' => ConsentRecord::GRANTED,
        'capture_method' => 'verbal', 'captured_at' => now(), 'signed_at' => now(),
    ]);
    $this->book = function ($patient) {
        $appointment = $this->f->appointment($patient, $this->f->doctorA, CarbonImmutable::parse('2026-10-09 17:30'));
        app(PatientMessaging::class)->appointmentsBooked([$appointment]);

        return $appointment;
    };
    $this->dispatch = fn () => (new DispatchDuePatientMessages)->handle(app(NotificationService::class));
});

test('a confirmation goes out on the common number, naming the clinic and nothing clinical', function () {
    ($this->enable)(['appointment_confirmed']);
    $patient = $this->f->patient('Asha', 'Rao', ['phone' => '98111 00001']);
    ($this->agree)($patient);

    ($this->book)($patient);
    ($this->dispatch)();

    $message = ClinicWhatsAppMessage::query()->withoutGlobalScopes()->sole();
    expect($message->status)->toBe('sent')
        ->and($message->whatsapp_connection_id)->toBe($this->shared->id)
        ->and($message->recipient_phone)->toBe('919811100001')
        ->and($message->provider_message_id)->toBe('wamid.REAL1')
        ->and($message->preview)->toStartWith($this->f->clinic->name.': Hello Asha')
        ->and(Notification::query()->find($message->notification_id)->status)->toBe('sent');
    Http::assertSent(fn ($request) => str_contains($request->url(), '/PN-SHARED/messages')
        && $request['template']['name'] === 'oc_appointment_confirmed'
        && $request['template']['components'][0]['parameters'][0]['text'] === $this->f->clinic->name);
});

test('an event handled twice sends once', function () {
    ($this->enable)(['appointment_confirmed']);
    $patient = $this->f->patient('Asha', 'Rao');
    ($this->agree)($patient);
    $appointment = ($this->book)($patient);

    app(PatientMessaging::class)->appointmentsBooked([$appointment]);
    ($this->dispatch)();
    ($this->dispatch)();

    expect(ClinicWhatsAppMessage::query()->withoutGlobalScopes()->count())->toBe(1);
    Http::assertSentCount(1);
});

test('without the patient\'s agreement nothing is sent, and the reason is kept', function () {
    ($this->enable)(['appointment_confirmed']);
    ($this->book)($this->f->patient('Asha', 'Rao'));
    ($this->dispatch)();

    $message = ClinicWhatsAppMessage::query()->withoutGlobalScopes()->sole();
    expect($message->status)->toBe('skipped')->and($message->failure_reason)->toBe('The patient has not agreed to WhatsApp messages.');
    Http::assertNothingSent();
});

test('an automation the owner left off does not use WhatsApp at all', function () {
    ($this->enable)([]);
    $patient = $this->f->patient('Asha', 'Rao');
    ($this->agree)($patient);
    ($this->book)($patient);
    ($this->dispatch)();

    expect(ClinicWhatsAppMessage::query()->withoutGlobalScopes()->count())->toBe(0)
        ->and(Notification::query()->where('clinic_id', $this->f->clinic->id)->first()->channel)->not->toBe('whatsapp');
});

test('own mode uses only the clinic\'s own number once OralCares activates it, never another clinic\'s', function () {
    ($this->enable)(['appointment_confirmed'], mode: 'own');
    $other = ReceptionFixture::make('wa-other');
    $theirs = WhatsAppConnection::create(['mode' => WhatsAppConnection::OWNED, 'clinic_id' => $other->clinic->id, 'label' => 'Other clinic',
        'phone_number_id' => 'PN-OTHER', 'access_token' => 'EAA-other', 'status' => 'active']);
    ($this->template)($theirs, 'appointment_confirmed');
    $patient = $this->f->patient('Asha', 'Rao');
    ($this->agree)($patient);

    // Not activated yet: the clinic's messages do not go out on any number.
    expect(app(ClinicWhatsApp::class)->connectionFor($this->f->clinic))->toBeNull();
    ($this->book)($patient);
    ($this->dispatch)();
    expect(ClinicWhatsAppMessage::query()->withoutGlobalScopes()->count())->toBe(0);

    $own = WhatsAppConnection::create(['mode' => WhatsAppConnection::OWNED, 'clinic_id' => $this->f->clinic->id, 'label' => 'Clinic own',
        'phone_number_id' => 'PN-OWN', 'access_token' => 'EAA-own', 'status' => 'active']);
    ($this->template)($own, 'appointment_confirmed');
    $ravi = $this->f->patient('Ravi', 'K');
    ($this->agree)($ravi);
    ($this->book)($ravi);
    ($this->dispatch)();

    $sent = ClinicWhatsAppMessage::query()->withoutGlobalScopes()->where('patient_id', $ravi->id)->sole();
    expect($sent->whatsapp_connection_id)->toBe($own->id)->and($sent->status)->toBe('sent');
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'PN-OTHER') || str_contains($request->url(), 'PN-SHARED'));
});

test('a provider error retries, then fails with its reason after the last attempt', function () {
    $this->meta = ['error' => ['message' => 'Template paused']];
    $this->metaStatus = 400;
    ($this->enable)(['appointment_confirmed']);
    $patient = $this->f->patient('Asha', 'Rao');
    ($this->agree)($patient);
    ($this->book)($patient);
    ($this->dispatch)();

    $message = ClinicWhatsAppMessage::query()->withoutGlobalScopes()->sole();
    // The sync queue runs each retry at once: three attempts, then failed.
    foreach (range(2, 3) as $attempt) {
        if ($message->fresh()->status === 'queued') {
            (new SendClinicWhatsAppMessage($message->id))->handle(app(WhatsAppManager::class));
        }
    }

    expect($message->fresh())->status->toBe('failed')->attempts->toBe(3)->failure_reason->toBe('Template paused')
        ->and(Notification::query()->find($message->notification_id)->status)->toBe('failed');
});

test('physiotherapy automations are not offered to a dental clinic', function () {
    $this->f->clinic->update(['specialties' => ['dental']]);
    $available = array_keys(app(ClinicWhatsApp::class)->availableAutomations($this->f->clinic->fresh()));

    expect($available)->toContain('appointment_confirmed', 'dental_treatment_followup', 'bill_invoice')
        ->not->toContain('physio_session_reminder', 'physio_package_balance', 'physio_package_expiry');
});

test('a bill and its payment tell the patient: the invoice, the payment with the balance, and the receipt', function () {
    ($this->enable)(['bill_invoice', 'payment_received', 'payment_receipt']);
    foreach (['bill_invoice', 'payment_received', 'payment_receipt'] as $code) {
        ($this->template)($this->shared, $code);
    }
    $patient = $this->f->patient('Asha', 'Rao');
    ($this->agree)($patient);

    $bill = app(BillingService::class)->createBill($this->f->clinic, $patient, $this->f->branch, [['description' => 'Consultation', 'quantity' => 1, 'unit_price' => 500]]);
    app(PaymentService::class)->recordPayment($bill->fresh(), PaymentMethod::Cash, 200);
    ($this->dispatch)();

    $sent = ClinicWhatsAppMessage::query()->withoutGlobalScopes()->orderBy('id')->get()->keyBy('automation_key');
    expect($sent->keys()->all())->toBe(['bill_invoice', 'payment_received', 'payment_receipt'])
        ->and($sent['bill_invoice']->preview)->toContain($bill->billing_number)->toContain('₹500')
        ->and($sent['payment_received']->preview)->toContain('₹200')->toContain('Balance due: ₹300');
});
