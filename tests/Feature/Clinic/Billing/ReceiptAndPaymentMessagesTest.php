<?php

use App\ClinicRole;
use App\Jobs\DispatchDuePatientMessages;
use App\Models\ClinicWhatsAppMessage;
use App\Models\ClinicWhatsAppSettings;
use App\Models\ConsentRecord;
use App\Models\Notification;
use App\Models\PatientBilling;
use App\Models\PatientBillingPayment;
use App\Models\SecurityAuditLog;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppTemplate;
use App\Services\BillingService;
use App\Services\NotificationService;
use App\Services\WhatsApp\ClinicWhatsApp;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Tests\Support\ReceptionFixture;

/**
 * After a payment: the thermal roll receipt (the existing bill print route,
 * browser-printed) and where the patient's payment messages stand, with a
 * retry for a failed WhatsApp delivery that never makes a second message.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-17 10:00', 'Asia/Kolkata'));
    $this->metaStatus = 200;
    Http::fake(['graph.facebook.com/*' => fn () => Http::response($this->metaStatus === 200 ? ['messages' => [['id' => 'wamid.R1']]] : ['error' => ['message' => 'Temporarily unavailable']], $this->metaStatus)]);
    $this->f = ReceptionFixture::make('receipt-clinic');
    $this->asOwner = fn () => $this->actingAs($this->f->owner)->withSession($this->f->session);
    $this->patient = $this->f->patient('Asha', 'Rao', ['phone' => '98111 00001']);
    $this->bill = fn (float $amount = 500) => app(BillingService::class)->createBill($this->f->clinic, $this->patient, $this->f->branch,
        [['description' => 'Consultation', 'quantity' => 1, 'unit_price' => $amount]], doctor: $this->f->doctorA);
    $this->pay = fn (PatientBilling $bill, float $amount, string $key = 'pay-key-0001') => ($this->asOwner)()
        ->postJson(route('clinic.billing.payments.store', $bill), ['method' => 'upi', 'amount' => $amount, 'reference' => 'UPI-1', 'idempotency_key' => $key]);
    $this->whatsApp = function () {
        $shared = WhatsAppConnection::create(['mode' => WhatsAppConnection::SHARED, 'label' => 'OralCares common', 'display_phone_number' => '919000000001',
            'phone_number_id' => 'PN-SHARED', 'business_account_id' => 'WABA-1', 'access_token' => 'EAA-shared', 'status' => 'active']);
        foreach (['payment_received', 'payment_receipt'] as $code) {
            WhatsAppTemplate::create(['whatsapp_connection_id' => $shared->id, 'automation_key' => $code, 'name' => "oc_{$code}", 'body' => ClinicWhatsApp::catalog()[$code]['body'], 'status' => 'approved']);
        }
        ClinicWhatsAppSettings::query()->withoutGlobalScopes()->updateOrCreate(['clinic_id' => $this->f->clinic->id],
            ['enabled' => true, 'mode' => 'common', 'automations' => ['payment_received' => true, 'payment_receipt' => true], 'require_opt_in' => true]);
        ConsentRecord::query()->withoutGlobalScopes()->create(['clinic_id' => $this->f->clinic->id, 'patient_id' => $this->patient->id, 'consent_type' => 'communication',
            'status' => ConsentRecord::GRANTED, 'capture_method' => 'verbal', 'captured_at' => now(), 'signed_at' => now()]);
    };
    $this->dispatch = fn () => (new DispatchDuePatientMessages)->handle(app(NotificationService::class));
});

// ---------------------------------------------------------------- Thermal receipt

test('the payment response links the thermal receipt for that payment, and it prints the receipt facts on a roll', function () {
    $bill = ($this->bill)(500);
    $paid = ($this->pay)($bill, 500)->assertCreated();
    $payment = PatientBillingPayment::query()->withoutGlobalScopes()->sole();

    expect($paid->json('billing.receipt.print_url'))->toBe(route('clinic.billing.bills.print', ['bill' => $bill->id, 'layout' => 'receipt', 'payment' => $payment->id]))
        ->and($paid->json('billing.receipt.messages_url'))->toBe(route('clinic.billing.payments.messages', $payment->id));

    ($this->asOwner)()->get($paid->json('billing.receipt.print_url').'&width=58')->assertOk()
        ->assertSee('size: 58mm auto', false)
        ->assertSee('PAYMENT RECEIPT')
        ->assertSee($payment->receipt_number)
        ->assertSee($bill->billing_number)
        ->assertSee($this->f->clinic->name)
        ->assertSee('Asha Rao')
        ->assertSee('UPI')
        ->assertSee('₹500.00')
        ->assertSee('Balance due')
        ->assertSee('17/10/26');
    ($this->asOwner)()->get(route('clinic.billing.bills.print', ['bill' => $bill->id, 'layout' => 'receipt']))->assertOk()->assertSee('size: 80mm auto', false)->assertSee('17 Oct 2026');
});

test('a part payment prints its own amount and the balance still due', function () {
    $bill = ($this->bill)(500);
    ($this->pay)($bill, 200)->assertCreated();
    $payment = PatientBillingPayment::query()->withoutGlobalScopes()->sole();

    $page = ($this->asOwner)()->get(route('clinic.billing.bills.print', ['bill' => $bill->id, 'layout' => 'receipt', 'payment' => $payment->id]))->assertOk();
    $page->assertSee('₹200.00')->assertSee('₹300.00')->assertSee('PAYMENT RECEIPT');
});

test('an unpaid bill ("collect later") prints as a bill, never as a receipt', function () {
    $bill = ($this->bill)(500);

    ($this->asOwner)()->get(route('clinic.billing.bills.print', ['bill' => $bill->id, 'layout' => 'receipt']))->assertOk()
        ->assertDontSee('PAYMENT RECEIPT')->assertSee('This is not a receipt.')->assertSee('₹500.00');
    expect(PatientBillingPayment::query()->withoutGlobalScopes()->count())->toBe(0);
});

test('reprinting writes nothing but its audit entry', function () {
    $bill = ($this->bill)(500);
    ($this->pay)($bill, 500)->assertCreated();
    $payment = PatientBillingPayment::query()->withoutGlobalScopes()->sole();
    $url = route('clinic.billing.bills.print', ['bill' => $bill->id, 'layout' => 'receipt', 'payment' => $payment->id]);
    $notifications = Notification::query()->count();

    ($this->asOwner)()->get($url)->assertOk();
    ($this->asOwner)()->get($url)->assertOk();

    expect(PatientBilling::query()->withoutGlobalScopes()->count())->toBe(1)
        ->and(PatientBillingPayment::query()->withoutGlobalScopes()->count())->toBe(1)
        ->and(Notification::query()->count())->toBe($notifications)
        ->and(SecurityAuditLog::query()->where('event', 'billing.printed')->count())->toBe(2)
        ->and(SecurityAuditLog::query()->where('event', 'billing.printed')->latest('id')->first()->context['receipt_number'] ?? null)->toBe($payment->receipt_number);
});

test('a receipt is only for a payment of that bill, and only in its own clinic', function () {
    $bill = ($this->bill)(500);
    $other = ($this->bill)(300);
    ($this->pay)($other, 300)->assertCreated();
    $otherPayment = PatientBillingPayment::query()->withoutGlobalScopes()->sole();

    ($this->asOwner)()->get(route('clinic.billing.bills.print', ['bill' => $bill->id, 'layout' => 'receipt', 'payment' => $otherPayment->id]))->assertNotFound();

    $elsewhere = ReceptionFixture::make('receipt-elsewhere');
    $this->actingAs($elsewhere->owner)->withSession($elsewhere->session)
        ->get(route('clinic.billing.bills.print', ['bill' => $bill->id, 'layout' => 'receipt']))->assertNotFound();
    $this->actingAs($elsewhere->owner)->withSession($elsewhere->session)
        ->getJson(route('clinic.billing.payments.messages', $otherPayment->id))->assertNotFound();
});

// ---------------------------------------------------------------- Payment messages

test('without WhatsApp the status says plainly that nothing went on WhatsApp', function () {
    $bill = ($this->bill)(500);
    ($this->pay)($bill, 500)->assertCreated();
    $payment = PatientBillingPayment::query()->withoutGlobalScopes()->sole();

    ($this->asOwner)()->getJson(route('clinic.billing.payments.messages', $payment->id))->assertOk()
        ->assertJsonCount(2, 'messages')
        ->assertJsonPath('messages.0.kind', 'payment')->assertJsonPath('messages.0.state', 'not_whatsapp')
        ->assertJsonPath('messages.1.kind', 'receipt')->assertJsonPath('messages.1.can_retry', false);
});

test('a payment confirmation and receipt go on WhatsApp once, with the receipt\'s real figures; a replayed payment adds nothing', function () {
    ($this->whatsApp)();
    $bill = ($this->bill)(500);
    ($this->pay)($bill, 200, 'same-key-0001')->assertCreated();
    ($this->pay)($bill, 200, 'same-key-0001')->assertCreated();
    $payment = PatientBillingPayment::query()->withoutGlobalScopes()->sole();

    ($this->asOwner)()->getJson(route('clinic.billing.payments.messages', $payment->id))->assertJsonPath('messages.0.state', 'waiting');
    ($this->dispatch)();
    ($this->dispatch)();

    $sent = ClinicWhatsAppMessage::query()->withoutGlobalScopes()->get()->keyBy('automation_key');
    expect($sent->keys()->sort()->values()->all())->toBe(['payment_receipt', 'payment_received'])
        ->and($sent['payment_received']->preview)->toContain('₹200')->toContain('Balance due: ₹300')
        ->and($sent['payment_receipt']->preview)->toContain($payment->receipt_number);
    Http::assertSentCount(2);
    ($this->asOwner)()->getJson(route('clinic.billing.payments.messages', $payment->id))
        ->assertJsonPath('messages.0.state', 'sent')->assertJsonPath('messages.1.state', 'sent');
});

test('a WhatsApp failure leaves the payment intact; retry sends the same message again, once', function () {
    ($this->whatsApp)();
    $this->metaStatus = 500;
    $bill = ($this->bill)(500);
    ($this->pay)($bill, 500)->assertCreated();
    $payment = PatientBillingPayment::query()->withoutGlobalScopes()->sole();
    ($this->dispatch)();
    ClinicWhatsAppMessage::query()->withoutGlobalScopes()->update(['status' => ClinicWhatsAppMessage::FAILED, 'failure_reason' => 'Temporarily unavailable', 'failed_at' => now()]);

    expect($bill->fresh()->outstanding_balance)->toEqual(0)->and($payment->fresh())->not->toBeNull();
    ($this->asOwner)()->getJson(route('clinic.billing.payments.messages', $payment->id))
        ->assertJsonPath('messages.0.state', 'failed')->assertJsonPath('messages.0.detail', 'Temporarily unavailable')->assertJsonPath('messages.0.can_retry', true);

    // A doctor may see billing but not take payments: no retry.
    [$doctor, $doctorSession] = $this->f->memberWithRole(ClinicRole::Doctor);
    $this->actingAs($doctor)->withSession($doctorSession)->postJson(route('clinic.billing.payments.messages.retry', $payment->id))->assertForbidden();

    $this->metaStatus = 200;
    ($this->asOwner)()->postJson(route('clinic.billing.payments.messages.retry', $payment->id))->assertOk()
        ->assertJsonPath('messages.0.state', 'sent')->assertJsonPath('messages.1.state', 'sent');
    ($this->asOwner)()->postJson(route('clinic.billing.payments.messages.retry', $payment->id))->assertOk()->assertJsonPath('message', 'Nothing could be sent again. See each message for why.');

    expect(ClinicWhatsAppMessage::query()->withoutGlobalScopes()->count())->toBe(2)
        ->and(Notification::query()->whereIn('event_key', ["payment:{$payment->id}", "receipt:{$payment->id}"])->count())->toBe(2)
        ->and(SecurityAuditLog::query()->where('event', 'whatsapp.message_retried')->count())->toBe(2);
});

test('a retry after the patient withdrew agreement is skipped with the reason, never sent', function () {
    ($this->whatsApp)();
    $bill = ($this->bill)(500);
    ($this->pay)($bill, 500)->assertCreated();
    $payment = PatientBillingPayment::query()->withoutGlobalScopes()->sole();
    ($this->dispatch)();
    ClinicWhatsAppMessage::query()->withoutGlobalScopes()->update(['status' => ClinicWhatsAppMessage::FAILED, 'failure_reason' => 'Temporarily unavailable']);
    ConsentRecord::query()->withoutGlobalScopes()->update(['status' => ConsentRecord::REVOKED]);
    $sentBefore = count(Http::recorded());

    ($this->asOwner)()->postJson(route('clinic.billing.payments.messages.retry', $payment->id))->assertOk()
        ->assertJsonPath('messages.0.state', 'skipped')->assertJsonPath('messages.0.detail', 'The patient has not agreed to WhatsApp messages.');
    expect(count(Http::recorded()))->toBe($sentBefore);
});

test('no payment, no payment message: an unpaid bill and a refused payment queue nothing', function () {
    ($this->whatsApp)();
    $bill = ($this->bill)(500);
    ($this->pay)($bill, 900)->assertStatus(422);

    expect(Notification::query()->where('event_key', 'like', 'payment:%')->orWhere('event_key', 'like', 'receipt:%')->count())->toBe(0);
});
