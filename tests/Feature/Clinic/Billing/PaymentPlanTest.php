<?php

use App\ClinicRole;
use App\Models\ClinicalEncounter;
use App\Models\ClinicalProcedure;
use App\Models\ClinicalProcedurePrice;
use App\Models\ClinicEmployee;
use App\Models\PatientBilling;
use App\Models\PatientBillingInstalment;
use App\Services\Reception\ReceptionCheckIn;
use Carbon\CarbonImmutable;
use Tests\Support\ReceptionFixture;

/*
 * A payment plan on a bill: the balance split into dated instalments that add up to it exactly, filled by
 * payments in order, and shown on the front desk with the next one due.
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

test('the desk splits a balance into instalments, and each payment fills the earliest first', function () {
    ($this->asDesk)()->post(route('clinic.billing.instalments.store', $this->bill), ['instalments' => [
        ['due_on' => '2026-10-20', 'amount' => 400], ['due_on' => '2026-11-20', 'amount' => 400], ['due_on' => '2026-12-20', 'amount' => 400],
    ]])->assertSessionHasNoErrors();

    ($this->asDesk)()->post(route('clinic.billing.payments.store', $this->bill), ['method' => 'cash', 'amount' => 500])->assertSessionHasNoErrors();

    $rows = PatientBillingInstalment::withoutGlobalScopes()->where('patient_billing_id', $this->bill->id)->orderBy('sequence')->get();
    expect($rows->pluck('paid_amount')->map(fn ($v) => (float) $v)->all())->toBe([400.0, 100.0, 0.0]);

    ($this->asDesk)()->getJson(route('clinic.billing.bills.show', $this->bill->id))->assertOk()
        ->assertJsonPath('instalments.0.state', 'paid')->assertJsonPath('instalments.1.state', 'partial')->assertJsonPath('instalments.1.remaining', 300)
        ->assertJsonPath('instalments.2.state', 'upcoming');
});

test('a plan that does not add up, falls in the past, or is out of order is refused', function (array $rows) {
    ($this->asDesk)()->post(route('clinic.billing.instalments.store', $this->bill), ['instalments' => $rows])->assertSessionHasErrors('instalments');

    expect(PatientBillingInstalment::withoutGlobalScopes()->count())->toBe(0);
})->with([
    'short of the balance' => [[['due_on' => '2026-11-20', 'amount' => 500], ['due_on' => '2026-12-20', 'amount' => 500]]],
    'more than the balance' => [[['due_on' => '2026-11-20', 'amount' => 1300]]],
    'in the past' => [[['due_on' => '2026-10-01', 'amount' => 1200]]],
    'out of order' => [[['due_on' => '2026-12-20', 'amount' => 600], ['due_on' => '2026-11-20', 'amount' => 600]]],
]);

test('the plan can be remade or removed, and the front desk shows the next instalment of an open bill', function () {
    $plan = fn (array $rows) => ($this->asDesk)()->post(route('clinic.billing.instalments.store', $this->bill), ['instalments' => $rows])->assertSessionHasNoErrors();
    $plan([['due_on' => '2026-11-20', 'amount' => 600], ['due_on' => '2026-12-20', 'amount' => 600]]);
    $plan([['due_on' => '2026-10-16', 'amount' => 1200]]);
    expect(PatientBillingInstalment::withoutGlobalScopes()->count())->toBe(1);

    ($this->asDesk)()->get(route('clinic.reception'))->assertInertia(function ($page) {
        $unpaid = collect($page->toArray()['props']['billing']['unpaid'])->firstWhere('id', $this->bill->id);
        expect($unpaid['plan']['remaining'])->toEqual(1200)->and($unpaid['plan']['overdue'])->toBeFalse();
    });

    ($this->asDesk)()->delete(route('clinic.billing.instalments.destroy', $this->bill))->assertSessionHasNoErrors();
    expect(PatientBillingInstalment::withoutGlobalScopes()->count())->toBe(0);
});
