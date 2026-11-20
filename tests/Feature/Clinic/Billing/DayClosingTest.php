<?php

use App\ClinicRole;
use App\Models\ClinicalEncounter;
use App\Models\ClinicalProcedure;
use App\Models\ClinicalProcedurePrice;
use App\Models\ClinicEmployee;
use App\Models\DailyCashClosing;
use App\Models\PatientBilling;
use App\Models\PatientBillingPayment;
use App\Services\PaymentService;
use App\Services\Reception\ReceptionCheckIn;
use Carbon\CarbonImmutable;
use Tests\Support\ReceptionFixture;

/*
 * Closing the day at the desk: the ledger's figures by method (received less returned), the cash counted against what it
 * expects, and the reason when they differ. One closing per branch per day; it records, it never moves money.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-15 10:00', 'Asia/Kolkata'));
    $this->f = ReceptionFixture::make();
    [$this->doctorUser, $this->doctorSession] = $this->f->memberWithRole(ClinicRole::Doctor);
    $this->me = $this->f->doctor('Dr. Me', $this->doctorUser);
    [$this->deskUser, $this->deskSession] = $this->f->memberWithRole(ClinicRole::Receptionist);
    ClinicEmployee::create(['clinic_id' => $this->f->clinic->id, 'user_id' => $this->deskUser->id, 'name' => 'Front Desk', 'email' => 'desk@example.com', 'status' => 'active']);
    [$this->billerUser, $this->billerSession] = $this->f->memberWithRole(ClinicRole::BillingStaff);
    ClinicEmployee::create(['clinic_id' => $this->f->clinic->id, 'user_id' => $this->billerUser->id, 'name' => 'Biller', 'email' => 'biller@example.com', 'status' => 'active']);
    $scaling = ClinicalProcedure::create(['clinic_id' => $this->f->clinic->id, 'code' => 'SCL', 'name' => 'Scaling', 'category' => 'Preventive', 'is_active' => true]);
    ClinicalProcedurePrice::create(['clinic_id' => $this->f->clinic->id, 'branch_id' => null, 'clinical_procedure_id' => $scaling->id, 'price' => 1200, 'is_available' => true]);
    $patient = $this->f->patient('Rahul', 'Sharma');
    $asDoctor = fn () => $this->actingAs($this->doctorUser)->withSession($this->doctorSession);
    $this->asDesk = fn () => $this->actingAs($this->deskUser)->withSession($this->deskSession);
    $this->asBiller = fn () => $this->actingAs($this->billerUser)->withSession($this->billerSession);

    // A signed consultation, billed to pay later: a bill of 1200 owed.
    app(ReceptionCheckIn::class)->checkIn($this->f->clinic, $this->f->branch, $patient, doctor: $this->me);
    $asDoctor()->post(route('clinic.consultations.start-for-patient', $patient))->assertSessionHasNoErrors();
    $this->encounter = ClinicalEncounter::withoutGlobalScopes()->where('patient_id', $patient->id)->latest('id')->first();
    $asDoctor()->patchJson("/clinic/consultations/{$this->encounter->id}/draft", ['lock_version' => 0, 'chief_complaint' => 'Bleeding gums'])->assertOk();
    $asDoctor()->post("/clinic/consultations/{$this->encounter->id}/procedures", ['clinical_procedure_id' => $scaling->id])->assertSessionHasNoErrors();
    $asDoctor()->post("/clinic/consultations/{$this->encounter->id}/finalize")->assertSessionHasNoErrors();
    $lines = [];
    ($this->asDesk)()->get(route('clinic.billing.checkout', $this->encounter))->assertInertia(function ($page) use (&$lines) {
        $lines = $page->toArray()['props']['lines'];
    });
    ($this->asDesk)()->post(route('clinic.billing.checkout.store', $this->encounter), ['prices' => collect($lines)->mapWithKeys(fn (array $line) => [$line['key'] => $line['unit_price']])->all()])->assertSessionHasNoErrors();
    $this->bill = PatientBilling::withoutGlobalScopes()->sole();
});

beforeEach(function () {
    $this->pay = fn (string $method, float $amount) => ($this->asDesk)()->post(route('clinic.billing.payments.store', $this->bill), ['method' => $method, 'amount' => $amount])->assertSessionHasNoErrors();
    $this->day = fn () => ($this->asDesk)()->getJson(route('clinic.reception.day-closing', ['date' => '2026-10-15']))->assertOk();
    $this->close = fn (array $data) => ($this->asDesk)()->postJson(route('clinic.reception.day-closing.store'), ['date' => '2026-10-15', ...$data]);
});

test('the day\x27s figures are the ledger\x27s: received by method, less what was returned', function () {
    ($this->pay)('cash', 500);
    ($this->pay)('upi', 300);
    $payment = PatientBillingPayment::withoutGlobalScopes()->where('payment_method', 'cash')->sole();
    app(PaymentService::class)->recordRefund($this->bill->fresh(), 100, 'Returned', null, $payment);

    $summary = ($this->day)()->json();

    expect(collect($summary['methods'])->pluck('net', 'method')->all())->toEqual(['cash' => 400, 'upi' => 300])
        ->and($summary['expected_cash'])->toEqual(400)
        ->and($summary['totals']['collected'])->toEqual(800)
        ->and($summary['totals']['returned'])->toEqual(100)
        ->and($summary['closing'])->toBeNull();
});

test('a matching count closes the day once, and a second close is refused', function () {
    ($this->pay)('cash', 500);

    ($this->close)(['counted_cash' => 500])->assertCreated()->assertJsonPath('closing.difference', 0)->assertJsonPath('summary.closing.counted_cash', 500);
    ($this->close)(['counted_cash' => 500])->assertStatus(422)->assertJsonPath('message', 'This day is already closed.');

    expect(DailyCashClosing::withoutGlobalScopes()->count())->toBe(1);
});

test('a difference needs a reason, and is recorded with it', function () {
    ($this->pay)('cash', 500);

    ($this->close)(['counted_cash' => 450])->assertStatus(422)->assertJsonPath('message', 'Say why the cash counted does not match.');
    expect(DailyCashClosing::withoutGlobalScopes()->count())->toBe(0);

    ($this->close)(['counted_cash' => 450, 'notes' => 'Change given from the drawer'])->assertCreated();
    $closing = DailyCashClosing::withoutGlobalScopes()->sole();
    expect((float) $closing->difference)->toBe(-50.0)->and((float) $closing->expected_cash)->toBe(500.0)->and($closing->notes)->toBe('Change given from the drawer');
});

test('a payment recorded after the close is flagged, and a future or too-old day cannot be closed', function () {
    ($this->pay)('cash', 500);
    ($this->close)(['counted_cash' => 500])->assertCreated();
    $this->travelTo(CarbonImmutable::parse('2026-10-15 18:00', 'Asia/Kolkata'));
    ($this->pay)('cash', 100);

    expect(($this->day)()->json('after_closing'))->toBe(1);

    ($this->close)(['date' => '2026-10-16', 'counted_cash' => 0])->assertStatus(422);
    ($this->close)(['date' => '2026-10-01', 'counted_cash' => 0])->assertStatus(422);
});
