<?php

use App\ClinicRole;
use App\Models\ClinicalEncounter;
use App\Models\ClinicalProcedure;
use App\Models\ClinicalProcedurePrice;
use App\Models\ClinicEmployee;
use App\Models\PatientBilling;
use App\Models\PatientBillingPayment;
use App\Services\Reception\ReceptionCheckIn;
use Carbon\CarbonImmutable;
use Tests\Support\ReceptionFixture;

/*
 * A gateway or insurance settlement is never typed in at the front desk: the
 * server refuses it on every route that records a payment, whatever the form
 * showed. Billing staff, who read the ledger, may record an insurance settlement.
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

test('a member who cannot read the ledger, such as the treating doctor, cannot record a gateway or insurance payment', function (string $method) {
    $asDoctor = fn () => $this->actingAs($this->doctorUser)->withSession($this->doctorSession);

    $asDoctor()->post(route('clinic.billing.payments.store', $this->bill), ['method' => $method, 'amount' => 500, 'reference' => 'TYPED-BY-HAND'])->assertSessionHasErrors('method');
    $asDoctor()->postJson(route('clinic.billing.payments.store', $this->bill), ['method' => $method, 'amount' => 500])->assertStatus(422);

    expect(PatientBillingPayment::withoutGlobalScopes()->count())->toBe(0);

    $asDoctor()->post(route('clinic.billing.payments.store', $this->bill), ['method' => 'cash', 'amount' => 500])->assertSessionHasNoErrors();
    expect(PatientBillingPayment::withoutGlobalScopes()->count())->toBe(1);
})->with(['gateway', 'insurance']);

test('billing staff, who run the ledger, may record an insurance settlement', function () {
    ($this->asBiller)()->post(route('clinic.billing.payments.store', $this->bill), ['method' => 'insurance', 'amount' => 500, 'reference' => 'CLAIM-77'])->assertSessionHasNoErrors();

    expect(PatientBillingPayment::withoutGlobalScopes()->sole()->payment_method->value)->toBe('insurance');
});

test('the desk\x27s quick collection refuses a gateway or insurance method before it bills anything', function (string $method) {
    ($this->asDesk)()->post(route('clinic.reception.billing.collect', $this->encounter), ['method' => $method, 'amount' => 500])
        ->assertSessionHasErrors(['billing' => 'Choose how the patient is paying: that method is not recorded at the desk.']);
})->with(['gateway', 'insurance']);

test('every payment on a bill carries the link that reprints its receipt', function () {
    ($this->asDesk)()->post(route('clinic.billing.payments.store', $this->bill), ['method' => 'cash', 'amount' => 500])->assertSessionHasNoErrors();
    $payment = PatientBillingPayment::withoutGlobalScopes()->sole();

    $url = route('clinic.billing.bills.show', $this->bill->id);
    ($this->asDesk)()->getJson($url)->assertOk()
        ->assertJsonPath('payments.0.receipt_url', route('clinic.billing.bills.print', ['bill' => $this->bill->id, 'layout' => 'receipt', 'payment' => $payment->id]));
    ($this->asDesk)()->get(route('clinic.billing.bills.print', ['bill' => $this->bill->id, 'layout' => 'receipt', 'payment' => $payment->id]))->assertOk();
});
