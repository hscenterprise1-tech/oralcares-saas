<?php

use App\Models\PatientBilling;
use App\Models\PatientBillingPayment;
use App\Models\PatientBillingRefund;
use App\Models\PatientSessionPackage;
use App\Models\PatientTreatment;
use App\Services\BillingService;
use App\Services\Clinical\EncounterWorkspace;
use App\Services\Communication\PatientMessaging;
use App\Services\Reception\IntakeSubmissions;
use App\Services\Treatment\PatientTreatments;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Mockery\MockInterface;
use Tests\Support\TreatmentFixture;

/**
 * Money requests sent twice — a double click, Enter pressed twice, a retried
 * request — take the money, refund it or sell the package once. A request key
 * belongs to one clinic, user and operation; a new key is a new operation.
 * Keys are optional for now: a request without one still runs, and is logged.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-21 10:00', 'Asia/Kolkata'));
    $this->t = TreatmentFixture::make();
    $this->f = $this->t->f;
    // The owner also practises physiotherapy here: the staff record that bills, sells and treats.
    $this->me = $this->f->doctor('Dr. Owner', $this->f->owner);
    $this->me->practitionerProfile()->withoutGlobalScopes()->update(['specialty' => 'Physiotherapist', 'consultation_fee' => 500]);
    $this->asOwner = fn () => $this->actingAs($this->f->owner)->withSession($this->f->session);
    $this->patient = $this->f->patient('Asha', 'Rao');
    $this->bill = fn (float $amount = 500) => app(BillingService::class)->createBill($this->f->clinic, $this->patient, $this->f->branch,
        [['description' => 'Consultation', 'quantity' => 1, 'unit_price' => $amount]], doctor: $this->me);
    $this->payForm = fn (PatientBilling $bill, float $amount, ?string $key) => ($this->asOwner)()
        ->post(route('clinic.billing.payments.store', $bill), array_filter(['method' => 'cash', 'amount' => $amount, 'idempotency_key' => $key]));
    $this->treatment = function (): PatientTreatment {
        $this->t->actAsOwner();

        return app(PatientTreatments::class)->startAdHoc($this->patient, 'physiotherapy', 'Knee Rehabilitation', $this->me, $this->f->branch, $this->f->owner, ['sessions_planned' => 10]);
    };
});

// ---------------------------------------------------------------- Payments

test('a payment form sent twice with one key records one payment; a new key takes the next part payment', function () {
    $bill = ($this->bill)(500);

    ($this->payForm)($bill, 200, 'form-key-0001')->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('success', fn (string $message) => str_starts_with($message, 'Payment received'));
    ($this->payForm)($bill, 200, 'form-key-0001')->assertRedirect()->assertSessionHas('success', 'This payment was already recorded; it was not taken twice.');

    expect(PatientBillingPayment::query()->withoutGlobalScopes()->count())->toBe(1)
        ->and((float) $bill->fresh()->outstanding_balance)->toBe(300.0);

    ($this->payForm)($bill, 200, 'form-key-0002')->assertSessionHasNoErrors();

    expect(PatientBillingPayment::query()->withoutGlobalScopes()->count())->toBe(2)
        ->and((float) $bill->fresh()->outstanding_balance)->toBe(100.0);
});

test('a refused JSON payment frees its key for the retry, and a different key is a separate payment', function () {
    $bill = ($this->bill)(500);
    $url = route('clinic.billing.payments.store', $bill);

    ($this->asOwner)()->postJson($url, ['method' => 'cash', 'amount' => 900, 'idempotency_key' => 'json-key-0001'])
        ->assertStatus(422)->assertJsonPath('message', 'The payment is more than the amount due.');
    ($this->asOwner)()->postJson($url, ['method' => 'cash', 'amount' => 200, 'idempotency_key' => 'json-key-0001'])
        ->assertCreated()->assertJsonMissingPath('replayed');
    ($this->asOwner)()->postJson($url, ['method' => 'cash', 'amount' => 300, 'idempotency_key' => 'json-key-0002'])
        ->assertCreated()->assertJsonMissingPath('replayed');

    expect(PatientBillingPayment::query()->withoutGlobalScopes()->pluck('amount')->map(fn ($amount) => (float) $amount)->all())->toBe([200.0, 300.0])
        ->and((float) $bill->fresh()->outstanding_balance)->toBe(0.0);
});

test('a second request arriving while the first still holds the key is told to wait, and takes no payment', function () {
    $bill = ($this->bill)(500);
    // The first request has claimed the key and is still running (the claim has no result yet).
    DB::table('intake_submissions')->insert([
        'clinic_id' => $this->f->clinic->id, 'user_id' => $this->f->owner->id, 'idempotency_key' => 'race-key-0001',
        'action' => "payform:{$bill->id}", 'created_at' => now(), 'updated_at' => now(),
    ]);

    ($this->payForm)($bill, 500, 'race-key-0001')->assertRedirect()->assertSessionHasErrors(['idempotency_key' => 'This is still being saved. Wait a moment.']);

    expect(PatientBillingPayment::query()->withoutGlobalScopes()->exists())->toBeFalse();
});

test('the same key in another clinic is that clinic\'s own new payment, never this clinic\'s result', function () {
    $bill = ($this->bill)(500);
    ($this->payForm)($bill, 500, 'shared-key-01')->assertSessionHasNoErrors();

    $elsewhere = TreatmentFixture::make('elsewhere');
    $theirBill = app(BillingService::class)->createBill($elsewhere->f->clinic, $elsewhere->f->patient('Ravi', 'Kumar'), $elsewhere->f->branch,
        [['description' => 'Consultation', 'quantity' => 1, 'unit_price' => 300]], doctor: $elsewhere->physio);

    $this->actingAs($elsewhere->f->owner)->withSession($elsewhere->f->session)
        ->post(route('clinic.billing.payments.store', $theirBill), ['method' => 'cash', 'amount' => 300, 'idempotency_key' => 'shared-key-01'])
        ->assertSessionHasNoErrors()->assertSessionHas('success', fn (string $message) => str_starts_with($message, 'Payment received'));

    expect(PatientBillingPayment::query()->withoutGlobalScopes()->where('clinic_id', $elsewhere->f->clinic->id)->sole()->patient_billing_id)->toBe($theirBill->id)
        ->and(PatientBillingPayment::query()->withoutGlobalScopes()->where('clinic_id', $this->f->clinic->id)->count())->toBe(1);
});

test('a payment that is saved but then fails afterwards keeps its key, so the retry is not a second payment', function () {
    $bill = ($this->bill)(500);
    // The patient message after the commit fails once (the observer runs after the payment is saved).
    $this->partialMock(PatientMessaging::class, fn (MockInterface $mock) => $mock->shouldReceive('paymentRecorded')
        ->andReturnUsing(fn () => throw new RuntimeException('Messaging is unavailable.'), fn () => null));

    ($this->payForm)($bill, 200, 'after-key-0001')->assertServerError();
    expect(PatientBillingPayment::query()->withoutGlobalScopes()->count())->toBe(1);

    ($this->payForm)($bill, 200, 'after-key-0001')->assertRedirect()->assertSessionHas('success', 'This payment was already recorded; it was not taken twice.');

    expect(PatientBillingPayment::query()->withoutGlobalScopes()->count())->toBe(1)
        ->and((float) $bill->fresh()->outstanding_balance)->toBe(300.0);
});

test('a JSON payment that is saved but then fails afterwards replays the saved payment on retry', function () {
    $bill = ($this->bill)(500);
    $this->partialMock(PatientMessaging::class, fn (MockInterface $mock) => $mock->shouldReceive('paymentRecorded')
        ->andReturnUsing(fn () => throw new RuntimeException('Messaging is unavailable.'), fn () => null));
    $pay = fn () => ($this->asOwner)()->postJson(route('clinic.billing.payments.store', $bill), ['method' => 'cash', 'amount' => 200, 'idempotency_key' => 'after-key-0002']);

    $pay()->assertServerError();
    $pay()->assertCreated()->assertJsonPath('replayed', true)
        ->assertJsonPath('billing.receipt.id', PatientBillingPayment::query()->withoutGlobalScopes()->sole()->id);

    expect(PatientBillingPayment::query()->withoutGlobalScopes()->count())->toBe(1);
});

// ---------------------------------------------------------------- Refunds

test('a refund sent twice with one key is recorded once; the collected-amount cap holds and a refused refund frees its key', function () {
    $bill = ($this->bill)(500);
    ($this->payForm)($bill, 500, 'pay-key-0001')->assertSessionHasNoErrors();
    $refund = fn (float $amount, string $key) => ($this->asOwner)()
        ->post(route('clinic.billing.refunds.store', $bill), ['amount' => $amount, 'reason' => 'Overcharged', 'method' => 'cash', 'idempotency_key' => $key]);

    $refund(900, 'refund-key-01')->assertSessionHasErrors(['checkout' => 'The refund is more than the amount collected on this bill.']);
    $refund(100, 'refund-key-01')->assertSessionHasNoErrors()->assertSessionHas('success', fn (string $message) => str_starts_with($message, 'Refund '));
    $refund(100, 'refund-key-01')->assertSessionHas('success', 'This refund was already recorded; it was not recorded twice.');

    expect(PatientBillingRefund::query()->withoutGlobalScopes()->count())->toBe(1);

    $refund(450, 'refund-key-02')->assertSessionHasErrors(['checkout' => 'The refund is more than the amount collected on this bill.']);
    $refund(400, 'refund-key-02')->assertSessionHasNoErrors();

    expect(PatientBillingRefund::query()->withoutGlobalScopes()->count())->toBe(2)
        ->and((float) $bill->fresh()->refunded_amount)->toBe(500.0);
});

// ---------------------------------------------------------------- Collection at the desk

test('a desk collection sent twice with one key raises one bill and takes one payment', function () {
    $this->t->actAsOwner();
    $workspace = app(EncounterWorkspace::class);
    $encounter = $workspace->startForPatient($this->patient, $this->f->branch, $this->me, null, 'physiotherapy');
    $workspace->autosave($encounter, ['chief_complaint' => 'Knee pain'], 0);
    $workspace->finalize($encounter->fresh(), $this->me);
    $collect = fn () => ($this->asOwner)()
        ->post(route('clinic.reception.billing.collect', $encounter), ['method' => 'cash', 'amount' => 500, 'idempotency_key' => 'desk-key-0001']);

    $collect()->assertSessionHasNoErrors()->assertSessionHas('success', fn (string $message) => str_starts_with($message, 'Payment received'));
    $collect()->assertSessionHasNoErrors()->assertSessionHas('success', 'This payment was already recorded; it was not taken twice.');

    $bill = PatientBilling::query()->withoutGlobalScopes()->where('encounter_id', $encounter->id)->sole();
    expect(PatientBillingPayment::query()->withoutGlobalScopes()->where('patient_billing_id', $bill->id)->count())->toBe(1)
        ->and((float) $bill->outstanding_balance)->toBe(0.0);
});

// ---------------------------------------------------------------- Package sales

test('a package sold twice from the patient\'s page with one key is one package and one bill', function () {
    $treatment = ($this->treatment)();
    $sell = fn () => ($this->asOwner)()->post("/clinic/patients/{$this->patient->id}/session-packages", [
        'name' => '10 physio sessions', 'sessions_total' => 10, 'price' => 6000, 'patient_treatment_id' => $treatment->id, 'idempotency_key' => 'pkg-key-00001',
    ]);

    $sell()->assertSessionHasNoErrors()->assertSessionHas('success', '10 physio sessions sold.');
    $sell()->assertSessionHasNoErrors()->assertSessionHas('success', 'This package was already sold; it was not sold twice.');

    expect(PatientSessionPackage::query()->withoutGlobalScopes()->sole()->patient_billing_id)->toBe(PatientBilling::query()->withoutGlobalScopes()->sole()->id);
});

test('a package sold twice for a treatment with one key is one package and one bill, and a new key is a second purchase', function () {
    $treatment = ($this->treatment)();
    $sell = fn (string $key) => ($this->asOwner)()->postJson("/clinic/treatments/{$treatment->id}/packages", [
        'name' => 'Knee 10', 'sessions_total' => 10, 'price' => 5000, 'idempotency_key' => $key,
    ]);

    $first = $sell('pkg-key-00001')->assertCreated();
    $sell('pkg-key-00001')->assertCreated()->assertJsonPath('replayed', true)->assertJsonPath('data.id', $first->json('data.id'));

    expect(PatientSessionPackage::query()->withoutGlobalScopes()->count())->toBe(1)
        ->and(PatientBilling::query()->withoutGlobalScopes()->count())->toBe(1);

    $sell('pkg-key-00002')->assertCreated()->assertJsonMissingPath('replayed');

    expect(PatientSessionPackage::query()->withoutGlobalScopes()->count())->toBe(2)
        ->and(PatientBilling::query()->withoutGlobalScopes()->count())->toBe(2);
});

test('a package that is saved but then fails afterwards replays the saved package on retry, with one bill', function () {
    $treatment = ($this->treatment)();
    // The bill message after the commit fails once.
    $this->partialMock(PatientMessaging::class, fn (MockInterface $mock) => $mock->shouldReceive('billIssued')
        ->andReturnUsing(fn () => throw new RuntimeException('Messaging is unavailable.'), fn () => null));
    $sell = fn () => ($this->asOwner)()->postJson("/clinic/treatments/{$treatment->id}/packages", [
        'name' => 'Knee 10', 'sessions_total' => 10, 'price' => 5000, 'idempotency_key' => 'pkg-after-0001',
    ]);

    $sell()->assertServerError();
    $sell()->assertCreated()->assertJsonPath('replayed', true)->assertJsonPath('data.id', PatientSessionPackage::query()->withoutGlobalScopes()->sole()->id);

    expect(PatientBilling::query()->withoutGlobalScopes()->count())->toBe(1);
});

test('a treatment started twice from a consultation with one key is one treatment, one package and one bill', function () {
    $this->t->actAsOwner();
    $encounter = app(EncounterWorkspace::class)->startForPatient($this->patient, $this->f->branch, $this->me, null, 'physiotherapy');
    $start = fn () => ($this->asOwner)()->postJson("/clinic/consultations/{$encounter->id}/treatments", [
        'mode' => 'adhoc', 'specialty_key' => 'physiotherapy', 'title' => 'Back Rehabilitation', 'open_session' => false,
        'package' => ['name' => 'Back 10', 'sessions_total' => 10, 'price' => 5000], 'idempotency_key' => 'care-key-0001',
    ]);

    $id = $start()->assertCreated()->json('data.id');
    $start()->assertCreated()->assertJsonPath('replayed', true)->assertJsonPath('data.id', $id);

    expect(PatientTreatment::query()->withoutGlobalScopes()->count())->toBe(1)
        ->and(PatientSessionPackage::query()->withoutGlobalScopes()->sole()->patient_treatment_id)->toBe($id)
        ->and(PatientBilling::query()->withoutGlobalScopes()->count())->toBe(1);
});

// ---------------------------------------------------------------- Requests without a key

test('a payment without a key still runs, and the log names only the entry point and the clinic', function () {
    Log::spy();
    $bill = ($this->bill)(500);

    ($this->payForm)($bill, 200, null)->assertSessionHasNoErrors();

    expect(PatientBillingPayment::query()->withoutGlobalScopes()->count())->toBe(1);
    Log::shouldHaveReceived('warning')->once()->with('Request without an idempotency key: it is not protected against a repeat.', [
        'entry_point' => 'checkout.pay.form',
        'clinic_id' => $this->f->clinic->id,
    ]);
});

test('an action name too long for its column is refused before any work runs', function () {
    $ran = false;

    expect(fn () => app(IntakeSubmissions::class)->once($this->f->clinic, $this->f->owner, 'long-key-0001', str_repeat('a', 21), function () use (&$ran) {
        $ran = true;

        return response()->json([]);
    }))->toThrow(LogicException::class);
    expect($ran)->toBeFalse();
});
