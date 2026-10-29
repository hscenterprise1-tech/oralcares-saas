<?php

use App\ClinicRole;
use App\Models\PatientBillingAdjustment;
use App\Models\SecurityAuditLog;
use App\PaymentMethod;
use App\Services\BillingService;
use App\Services\PaymentService;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\ReceptionFixture;

/**
 * Bills are never edited after they are issued. What is still due can be
 * adjusted (a concession or a write-off) by someone allowed to give
 * discounts, with a reason, never beyond the balance; every bill is listed
 * with its history.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00', 'Asia/Kolkata'));
    $this->f = ReceptionFixture::make('bill-adjust');
    // Adjusting a balance is the owner's Owner Mode action.
    $this->asOwner = fn () => $this->actingAs($this->f->owner)->withSession(withOwnerMode($this->f->session));
    $this->bill = app(BillingService::class)->createBill($this->f->clinic, $this->f->patient('Adj', 'Ust'), $this->f->branch, [['description' => 'Filling', 'quantity' => 1, 'unit_price' => 2000]]);
    app(PaymentService::class)->recordPayment($this->bill->fresh(), PaymentMethod::Cash, 1500);
});

test('a balance is adjusted with a reason; the bill lines stay as issued, and it is audited', function () {
    ($this->asOwner)()->post("/clinic/billing/bills/{$this->bill->id}/adjustments", ['amount' => 600, 'reason' => 'Senior citizen'])->assertSessionHasErrors('billing');
    ($this->asOwner)()->post("/clinic/billing/bills/{$this->bill->id}/adjustments", ['amount' => 500, 'reason' => 'Senior citizen concession'])->assertSessionHasNoErrors();

    $bill = $this->bill->fresh();
    expect((float) $bill->final_amount)->toEqual(2000)->and((float) $bill->adjusted_amount)->toEqual(500)
        ->and((float) $bill->outstanding_balance)->toEqual(0)->and($bill->status->value)->toBe('paid')
        ->and($bill->items()->count())->toBe(1)
        ->and(PatientBillingAdjustment::query()->withoutGlobalScopes()->sole()->reason)->toBe('Senior citizen concession')
        ->and(SecurityAuditLog::query()->where('event', 'billing.adjusted')->exists())->toBeTrue();
});

test('only those allowed to give discounts may adjust; another clinic\'s bill is not found', function () {
    [$doctor, $session] = $this->f->memberWithRole(ClinicRole::Doctor);
    $this->actingAs($doctor)->withSession($session)->post("/clinic/billing/bills/{$this->bill->id}/adjustments", ['amount' => 100, 'reason' => 'x'])->assertForbidden();

    $other = ReceptionFixture::make('bill-adjust-other');
    $this->actingAs($other->owner)->withSession($other->session)->post("/clinic/billing/bills/{$this->bill->id}/adjustments", ['amount' => 100, 'reason' => 'x'])->assertNotFound();
    expect((float) $this->bill->fresh()->outstanding_balance)->toEqual(500);
});

test('every bill is listed, newest first, by status and number', function () {
    ($this->asOwner)()->get('/clinic/billing?status=partially_paid')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('bills.data.0.number', $this->bill->billing_number)
        ->where('bills.data.0.outstanding', 500)
        ->where('can.adjust', true));
    ($this->asOwner)()->get('/clinic/billing?status=paid')->assertInertia(fn (Assert $page) => $page->where('bills.data', []));
    ($this->asOwner)()->get('/clinic/billing?q=NOPE')->assertInertia(fn (Assert $page) => $page->where('bills.data', []));
});
