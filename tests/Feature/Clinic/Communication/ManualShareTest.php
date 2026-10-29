<?php

use App\ClinicRole;
use App\Jobs\DispatchDuePatientMessages;
use App\Jobs\SendClinicWhatsAppMessage;
use App\Models\ClinicWhatsAppMessage;
use App\Models\ClinicWhatsAppSettings;
use App\Models\ConsentRecord;
use App\Models\Notification;
use App\Models\Prescription;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppTemplate;
use App\PaymentMethod;
use App\Services\BillingService;
use App\Services\Clinical\EncounterWorkspace;
use App\Services\ClinicalPrescriptionService;
use App\Services\NotificationService;
use App\Services\Patient\PatientBranchActivityRecorder;
use App\Services\PaymentService;
use App\Services\WhatsApp\ClinicWhatsApp;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\ReceptionFixture;

/**
 * Sending a patient a WhatsApp message by hand from Patient 360: only an
 * approved template, only about this patient's own records in a true state,
 * through the clinic's own sender (never another number), with consent, once
 * an hour per record — and each kind only by members allowed its subject.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-26 10:00', 'Asia/Kolkata'));
    Http::preventStrayRequests();
    Queue::fake([SendClinicWhatsAppMessage::class]);
    $this->f = ReceptionFixture::make('share-clinic');
    $this->connection = WhatsAppConnection::create(['mode' => WhatsAppConnection::SHARED, 'label' => 'OralCares common', 'display_phone_number' => '919000000001',
        'phone_number_id' => 'PN-SHARED', 'business_account_id' => 'WABA-1', 'access_token' => 'EAA-shared', 'status' => 'active']);
    $this->enable = function (array $codes, string $mode = 'common') {
        foreach ($codes as $code) {
            WhatsAppTemplate::firstOrCreate(['whatsapp_connection_id' => $this->connection->id, 'automation_key' => $code], ['name' => "oc_{$code}", 'body' => ClinicWhatsApp::catalog()[$code]['body'], 'status' => 'approved']);
        }
        ClinicWhatsAppSettings::query()->withoutGlobalScopes()->updateOrCreate(['clinic_id' => $this->f->clinic->id],
            ['enabled' => true, 'mode' => $mode, 'automations' => array_fill_keys($codes, true), 'require_opt_in' => true]);
    };
    $this->patient = $this->f->patient('Asha', 'Rao');
    app(PatientBranchActivityRecorder::class)->record($this->f->clinic->id, $this->patient->id, $this->f->branch->id, now(), 'registration');
    $this->agree = fn () => ConsentRecord::query()->withoutGlobalScopes()->create(['clinic_id' => $this->f->clinic->id, 'patient_id' => $this->patient->id, 'consent_type' => 'communication',
        'status' => ConsentRecord::GRANTED, 'capture_method' => 'verbal', 'captured_at' => now(), 'signed_at' => now()]);
    [$this->doctorUser, $this->doctorSession] = $this->f->memberWithRole(ClinicRole::Doctor);
    $this->me = $this->f->doctor('Dr. Me', $this->doctorUser);
    $this->actingAs($this->doctorUser);
    $encounter = app(EncounterWorkspace::class)->startForPatient($this->patient, $this->f->branch, $this->me);
    $rx = app(ClinicalPrescriptionService::class)->draftFor($encounter, $this->me);
    app(ClinicalPrescriptionService::class)->addItem($rx, ['drug_name' => 'Paracetamol', 'dosage' => '1 tablet', 'frequency' => 'TDS', 'duration' => '3 days']);
    $this->rx = app(ClinicalPrescriptionService::class)->finalize($rx->fresh(), $this->me);
    Notification::query()->delete(); // the automatic "prescription ready" is not what is being tested
    $this->asOwner = fn () => $this->actingAs($this->f->owner)->withSession($this->f->session);
    $this->share = fn ($as, string $kind, int $id) => $as()->post(route('clinic.patients.messages.share', $this->patient), ['kind' => $kind, 'id' => $id]);
});

test('a finalised prescription is shared on WhatsApp through the approved template, once an hour', function () {
    ($this->enable)(['prescription_ready']);
    ($this->agree)();

    ($this->share)($this->asOwner, 'prescription', $this->rx->id)->assertSessionHasNoErrors();
    $notification = Notification::query()->sole();
    expect($notification)->channel->toBe('whatsapp')->event_key->toStartWith("manual:prescription:{$this->rx->id}:");

    ($this->share)($this->asOwner, 'prescription', $this->rx->id)->assertSessionHasErrors(['share' => 'This was already sent in the last hour.']);
    expect(Notification::query()->count())->toBe(1);

    // It goes out through the clinic's number, with the template text.
    (new DispatchDuePatientMessages)->handle(app(NotificationService::class));
    $delivery = ClinicWhatsAppMessage::query()->withoutGlobalScopes()->sole();
    expect($delivery)->whatsapp_connection_id->toBe($this->connection->id)->status->toBe(ClinicWhatsAppMessage::QUEUED)
        ->and($delivery->preview)->toContain((string) $this->rx->prescription_number);
});

test('without the patient\'s agreement, or with WhatsApp off, nothing is queued and the reason is given', function () {
    ($this->enable)(['prescription_ready']);
    ($this->share)($this->asOwner, 'prescription', $this->rx->id)->assertSessionHasErrors(['share' => 'The patient has not agreed to WhatsApp messages.']);

    ClinicWhatsAppSettings::query()->withoutGlobalScopes()->where('clinic_id', $this->f->clinic->id)->update(['enabled' => false]);
    ($this->agree)();
    ($this->share)($this->asOwner, 'prescription', $this->rx->id)->assertSessionHasErrors(['share' => 'WhatsApp is off for this message.']);

    expect(Notification::query()->count())->toBe(0);
});

test('a clinic set to its own number never falls back to the company number', function () {
    ($this->enable)(['prescription_ready'], 'own');
    ($this->agree)();

    ($this->share)($this->asOwner, 'prescription', $this->rx->id)->assertSessionHasErrors('share');
    expect(Notification::query()->count())->toBe(0);
});

test('each kind follows its subject\'s permission; another clinic\'s or patient\'s record cannot be sent', function () {
    ($this->enable)(['prescription_ready', 'bill_invoice', 'payment_receipt']);
    ($this->agree)();
    $bill = app(BillingService::class)->createBill($this->f->clinic, $this->patient, $this->f->branch, [['description' => 'Filling', 'quantity' => 1, 'unit_price' => 900]]);
    app(PaymentService::class)->recordPayment($bill->fresh(), PaymentMethod::Cash, 900);
    Notification::query()->delete();

    [$billing, $session] = $this->f->memberWithRole(ClinicRole::BillingStaff);
    $asBilling = fn () => $this->actingAs($billing)->withSession($session);
    ($this->share)($asBilling, 'bill', $bill->id)->assertSessionHasNoErrors();
    ($this->share)($asBilling, 'prescription', $this->rx->id)->assertSessionHasErrors(['share' => 'That cannot be sent to this patient.']);

    $other = $this->f->patient('Other', 'Person');
    $otherBill = app(BillingService::class)->createBill($this->f->clinic, $other, $this->f->branch, [['description' => 'X', 'quantity' => 1, 'unit_price' => 100]]);
    ($this->share)($this->asOwner, 'bill', $otherBill->id)->assertSessionHasErrors('share');

    $elsewhere = ReceptionFixture::make('elsewhere');
    $this->actingAs($elsewhere->owner)->withSession($elsewhere->session)->postJson(route('clinic.patients.messages.share', $this->patient), ['kind' => 'bill', 'id' => $bill->id])->assertNotFound();

    expect(Notification::query()->pluck('event_key')->filter(fn ($key) => str_starts_with((string) $key, 'manual:'))->values()->all())->toHaveCount(1);
});

test('the Messages section lists what may be sent, with the text the patient would receive', function () {
    ($this->enable)(['prescription_ready']);

    $share = ($this->asOwner)()->getJson(route('clinic.patients.360.section', [$this->patient->id, 'communications']))->assertOk()->json('share');
    $rx = collect($share['options'])->firstWhere('kind', 'prescription');
    expect($rx)->toMatchArray(['id' => $this->rx->id, 'blocked' => 'The patient has not agreed to WhatsApp messages.'])
        ->and($rx['preview'])->toContain((string) $this->rx->prescription_number);
});
