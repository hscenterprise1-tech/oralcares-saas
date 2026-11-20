<?php

use App\ClinicRole;
use App\Services\BillingService;
use App\Support\AmountInWords;
use Carbon\CarbonImmutable;
use Tests\Support\ReceptionFixture;

/**
 * A bill that carries tax prints as a tax invoice (GSTIN, tax by rate split
 * into CGST and SGST, the amount in words); one without tax stays a plain bill.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-21 10:00', 'Asia/Kolkata'));
    $this->f = ReceptionFixture::make('tax-invoice-clinic');
    $this->asOwner = fn () => $this->actingAs($this->f->owner)->withSession($this->f->session);
    $this->patient = $this->f->patient('Asha', 'Rao');
});

test('a bill with tax prints as a tax invoice with GSTIN, CGST and SGST by rate, and the amount in words', function () {
    $this->f->clinic->update(['legal_name' => 'Smile Dental Care LLP']);
    $this->f->branch->forceFill(['gstin' => '27ABCDE1234F1Z5'])->save();
    $bill = app(BillingService::class)->createBill($this->f->clinic, $this->patient, $this->f->branch, [
        ['description' => 'Teeth whitening kit', 'quantity' => 1, 'unit_price' => 1000, 'tax_rate' => 18],
        ['description' => 'Consultation', 'quantity' => 1, 'unit_price' => 500],
    ], doctor: $this->f->doctorA);

    $page = ($this->asOwner)()->get(route('clinic.billing.bills.print', $bill))->assertOk();

    $page->assertSee('TAX INVOICE')->assertSee('GSTIN: ', false)->assertSee('27ABCDE1234F1Z5')->assertSee('Smile Dental Care LLP')
        ->assertSee('Tax summary')->assertSee('CGST')->assertSee('SGST')->assertSee('18%')
        // 18% of 1,000 is 180: 90 each, the whole bill 1,680.
        ->assertSee('₹90.00')->assertSee('₹180.00')->assertSee('Amount in words')
        ->assertSee('Rupees One Thousand Six Hundred Eighty Only')->assertSee('Authorised signatory');
});

test('a bill without tax stays a plain bill: no tax invoice, no tax summary', function () {
    $bill = app(BillingService::class)->createBill($this->f->clinic, $this->patient, $this->f->branch, [['description' => 'Consultation', 'quantity' => 1, 'unit_price' => 500]], doctor: $this->f->doctorA);

    ($this->asOwner)()->get(route('clinic.billing.bills.print', $bill))->assertOk()->assertSee('BILL')->assertDontSee('TAX INVOICE')->assertDontSee('Tax summary')->assertDontSee('Amount in words');
});

test('amounts read in words on the Indian system', function () {
    expect(AmountInWords::rupees(0))->toBe('Rupees Zero Only')
        ->and(AmountInWords::rupees(1))->toBe('Rupees One Only')
        ->and(AmountInWords::rupees(118))->toBe('Rupees One Hundred Eighteen Only')
        ->and(AmountInWords::rupees(125000.5))->toBe('Rupees One Lakh Twenty Five Thousand and Fifty Paise Only')
        ->and(AmountInWords::rupees(12345678))->toBe('Rupees One Crore Twenty Three Lakh Forty Five Thousand Six Hundred Seventy Eight Only')
        ->and(AmountInWords::rupees(2000))->toBe('Rupees Two Thousand Only');
});

test('the owner sets the clinic\'s tax and payment methods in Settings, and new bills follow', function () {
    ($this->asOwner)()->put(route('admin.settings.billing'), [
        'tax_mode' => 'exclusive', 'tax_percent' => 18, 'payment_methods' => ['cash', 'upi'], 'registration_fee' => 100, 'receipt_footer' => 'Get well soon.',
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect($this->f->clinic->fresh()->billing_config)->toMatchArray(['tax_mode' => 'exclusive', 'tax_percent' => 18.0, 'payment_methods' => ['cash', 'upi'], 'registration_fee' => 100.0, 'receipt_footer' => 'Get well soon.']);

    $bill = app(BillingService::class)->createBill($this->f->clinic->fresh(), $this->patient, $this->f->branch, [['description' => 'Whitening', 'quantity' => 1, 'unit_price' => 1000]], doctor: $this->f->doctorA);
    expect((float) $bill->tax_amount)->toBe(180.0);
    ($this->asOwner)()->get(route('clinic.billing.bills.print', $bill))->assertSee('TAX INVOICE')->assertSee('Get well soon.');
});

test('no tax means no rate is kept, and a method the clinic does not know is refused', function () {
    ($this->asOwner)()->put(route('admin.settings.billing'), ['tax_mode' => 'none', 'tax_percent' => 12, 'payment_methods' => ['cash']])->assertSessionHasNoErrors();
    expect($this->f->clinic->fresh()->billing_config['tax_percent'])->toBe(0);

    ($this->asOwner)()->put(route('admin.settings.billing'), ['tax_mode' => 'none', 'tax_percent' => 0, 'payment_methods' => ['barter']])->assertSessionHasErrors('payment_methods.0');
    ($this->asOwner)()->put(route('admin.settings.billing'), ['tax_mode' => 'none', 'tax_percent' => 0, 'payment_methods' => []])->assertSessionHasErrors('payment_methods');
});

test('only a member who manages settings changes the billing settings', function () {
    [$desk, $session] = $this->f->memberWithRole(ClinicRole::Receptionist);
    $this->actingAs($desk)->withSession($session)->put(route('admin.settings.billing'), ['tax_mode' => 'none', 'tax_percent' => 0, 'payment_methods' => ['cash']])->assertForbidden();
});
