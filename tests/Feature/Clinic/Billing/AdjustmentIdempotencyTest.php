<?php

use App\Models\PatientBillingAdjustment;
use App\Models\SecurityAuditLog;
use App\PaymentMethod;
use App\Services\BillingService;
use App\Services\PaymentService;
use Carbon\CarbonImmutable;
use Tests\Support\ReceptionFixture;

/**
 * A balance adjustment (a concession or write-off) sent twice — a double
 * click, a retried request — is recorded once. The request key belongs to one
 * clinic, user and bill; a refused adjustment frees its key for the corrected
 * retry; a new key is a new adjustment. Without a key it still runs, as
 * payments and refunds do.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-22 10:00', 'Asia/Kolkata'));
    $this->f = ReceptionFixture::make('adjust-idempotency');
    // 2000 billed, 1500 collected: 500 still due.
    $this->bill = app(BillingService::class)->createBill($this->f->clinic, $this->f->patient('Adj', 'Key'), $this->f->branch, [['description' => 'Crown', 'quantity' => 1, 'unit_price' => 2000]]);
    app(PaymentService::class)->recordPayment($this->bill->fresh(), PaymentMethod::Cash, 1500);
    $this->adjust = fn (float $amount, ?string $key) => $this->actingAs($this->f->owner)->withSession(withOwnerMode($this->f->session))
        ->post("/clinic/billing/bills/{$this->bill->id}/adjustments", array_filter(['amount' => $amount, 'reason' => 'Senior citizen concession', 'idempotency_key' => $key]));
    $this->adjustments = fn () => PatientBillingAdjustment::query()->withoutGlobalScopes()->where('patient_billing_id', $this->bill->id)->count();
});

test('an adjustment sent twice with one key is recorded once', function () {
    ($this->adjust)(200, 'adjust-key-0001')->assertSessionHasNoErrors()->assertSessionHas('success', 'Balance adjusted.');
    ($this->adjust)(200, 'adjust-key-0001')->assertSessionHasNoErrors()->assertSessionHas('success', 'This adjustment was already recorded; it was not recorded twice.');

    expect(($this->adjustments)())->toBe(1)
        ->and((float) $this->bill->fresh()->outstanding_balance)->toEqual(300)
        ->and(SecurityAuditLog::query()->where('event', 'billing.adjusted')->count())->toBe(1);
});

test('a refused adjustment frees its key for the corrected retry; a new key is a new adjustment', function () {
    ($this->adjust)(600, 'adjust-key-0002')->assertSessionHasErrors(['billing' => 'The adjustment is more than the amount due.']);
    expect(($this->adjustments)())->toBe(0);

    ($this->adjust)(200, 'adjust-key-0002')->assertSessionHasNoErrors()->assertSessionHas('success', 'Balance adjusted.');
    ($this->adjust)(100, 'adjust-key-0003')->assertSessionHasNoErrors()->assertSessionHas('success', 'Balance adjusted.');

    expect(($this->adjustments)())->toBe(2)
        ->and((float) $this->bill->fresh()->outstanding_balance)->toEqual(200);
});

test('the same key from another clinic is that clinic\'s own adjustment, never this one\'s result', function () {
    $other = ReceptionFixture::make('adjust-idempotency-other');
    $theirs = app(BillingService::class)->createBill($other->clinic, $other->patient('Their', 'Bill'), $other->branch, [['description' => 'Scaling', 'quantity' => 1, 'unit_price' => 900]]);

    ($this->adjust)(200, 'shared-key-0001')->assertSessionHasNoErrors();
    $this->actingAs($other->owner)->withSession(withOwnerMode($other->session))
        ->post("/clinic/billing/bills/{$theirs->id}/adjustments", ['amount' => 100, 'reason' => 'Their own concession', 'idempotency_key' => 'shared-key-0001'])
        ->assertSessionHasNoErrors()->assertSessionHas('success', 'Balance adjusted.');

    expect(($this->adjustments)())->toBe(1)
        ->and(PatientBillingAdjustment::query()->withoutGlobalScopes()->where('patient_billing_id', $theirs->id)->count())->toBe(1);
});

test('an adjustment without a key still runs', function () {
    ($this->adjust)(150, null)->assertSessionHasNoErrors()->assertSessionHas('success', 'Balance adjusted.');

    expect(($this->adjustments)())->toBe(1);
});
