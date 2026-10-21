<?php

use App\BillingStatus;
use App\ClinicRole;
use App\Models\ClinicalEncounter;
use App\Models\ClinicalProcedure;
use App\Models\ClinicalProcedurePrice;
use App\Models\ClinicEmployee;
use App\Models\PatientBilling;
use App\QueueItemStatus;
use App\Services\Clinical\EncounterWorkspace;
use App\Services\Reception\ReceptionCheckIn;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\ReceptionFixture;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-02 10:00', 'Asia/Kolkata'));
    $this->f = ReceptionFixture::make();
    [$this->doctorUser, $this->doctorSession] = $this->f->memberWithRole(ClinicRole::Doctor);
    $this->me = $this->f->doctor('Dr. Me', $this->doctorUser);
    $this->me->practitionerProfile->update(['consultation_fee' => 500]);
    [$this->deskUser, $this->deskSession] = $this->f->memberWithRole(ClinicRole::Receptionist);
    ClinicEmployee::create(['clinic_id' => $this->f->clinic->id, 'user_id' => $this->deskUser->id, 'name' => 'Front Desk', 'email' => 'desk@example.com', 'status' => 'active']);
    $this->scaling = ClinicalProcedure::create(['clinic_id' => $this->f->clinic->id, 'code' => 'SCL', 'name' => 'Scaling', 'category' => 'Preventive', 'is_active' => true]);
    ClinicalProcedurePrice::create(['clinic_id' => $this->f->clinic->id, 'branch_id' => null, 'clinical_procedure_id' => $this->scaling->id, 'price' => 1200, 'is_available' => true]);
    $this->patient = $this->f->patient('Rahul', 'Sharma');
    $this->asDoctor = fn () => $this->actingAs($this->doctorUser)->withSession($this->doctorSession);
    $this->asDesk = fn () => $this->actingAs($this->deskUser)->withSession($this->deskSession);
});

/** Arrival → queue → doctor → consultation with one procedure, signed. */
function seenAndSigned(object $test): ClinicalEncounter
{
    $item = app(ReceptionCheckIn::class)->checkIn($test->f->clinic, $test->f->branch, $test->patient, doctor: $test->me);
    ($test->asDoctor)()->post('/clinic/queue/call-next')->assertSessionHasNoErrors();
    ($test->asDoctor)()->post("/clinic/consultations/queue/{$item->id}")->assertRedirect();
    $encounter = ClinicalEncounter::withoutGlobalScopes()->where('patient_id', $test->patient->id)->sole();
    ($test->asDoctor)()->patchJson("/clinic/consultations/{$encounter->id}/draft", ['lock_version' => 0, 'chief_complaint' => 'Bleeding gums', 'follow_up_on' => '2026-10-16'])->assertOk();
    ($test->asDoctor)()->post("/clinic/consultations/{$encounter->id}/procedures", ['clinical_procedure_id' => $test->scaling->id])->assertSessionHasNoErrors();
    ($test->asDoctor)()->post("/clinic/consultations/{$encounter->id}/finalize")->assertSessionHasNoErrors();

    expect($item->fresh()->status)->toBe(QueueItemStatus::Completed);

    return $encounter->fresh();
}

test('a signed consultation reaches the billing desk, priced from the clinic\'s own lists', function () {
    $encounter = seenAndSigned($this);

    ($this->asDesk)()->get('/clinic/billing')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('ClinicPortal/Billing/Index', false)->where('ready.0.id', $encounter->id));

    ($this->asDesk)()->get("/clinic/billing/checkout/{$encounter->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->component('ClinicPortal/Billing/Checkout', false)
            ->where('lines.0.key', 'consultation')
            ->where('lines.0.unit_price', 500)
            ->where('lines.1.description', 'Scaling')
            ->where('lines.1.unit_price', 1200)
            ->where('encounter.follow_up_on', '2026-10-16'));
});

test('checkout raises one bill, never bills a service twice, and takes the payment', function () {
    $encounter = seenAndSigned($this);
    $procedure = $encounter->procedures()->sole();

    ($this->asDesk)()->post("/clinic/billing/checkout/{$encounter->id}", ['prices' => ['consultation' => 500, "procedure:{$procedure->id}" => 1200]])->assertSessionHasNoErrors();

    $bill = PatientBilling::withoutGlobalScopes()->sole();
    expect((float) $bill->final_amount)->toBe(1700.0)
        ->and($bill->encounter_id)->toBe($encounter->id)
        ->and($bill->items()->count())->toBe(2);

    ($this->asDesk)()->post("/clinic/billing/checkout/{$encounter->id}", ['prices' => ["procedure:{$procedure->id}" => 1200]])
        ->assertSessionHasErrors(['checkout' => 'There is nothing left to bill for this consultation.']);
    expect(PatientBilling::withoutGlobalScopes()->count())->toBe(1);

    ($this->asDesk)()->post("/clinic/billing/bills/{$bill->id}/payments", ['method' => 'upi', 'amount' => 1700, 'reference' => 'UPI-1'])->assertSessionHasNoErrors();
    expect($bill->fresh()->status)->toBe(BillingStatus::Paid)
        ->and((float) $bill->fresh()->outstanding_balance)->toBe(0.0);

    ($this->asDesk)()->get('/clinic/billing')->assertInertia(fn (Assert $page) => $page->has('ready', 0)->has('unpaid', 0));
});

test('an unsigned consultation is not billed, and a discount needs its own permission', function () {
    $workspace = app(EncounterWorkspace::class);
    $this->actingAs($this->doctorUser);
    $open = $workspace->startForPatient($this->f->patient('Other', 'Patient'), $this->f->branch, $this->me);

    ($this->asDesk)()->post("/clinic/billing/checkout/{$open->id}", ['prices' => ['consultation' => 500]])
        ->assertSessionHasErrors(['checkout' => 'Bill a consultation once the doctor has signed it.']);

    $encounter = seenAndSigned($this);
    ($this->asDesk)()->post("/clinic/billing/checkout/{$encounter->id}", ['prices' => ['consultation' => 500], 'discount' => 100])->assertForbidden();
});

test('a doctor without billing rights cannot bill or take payment', function () {
    $encounter = seenAndSigned($this);

    ($this->asDoctor)()->post("/clinic/billing/checkout/{$encounter->id}", ['prices' => ['consultation' => 500]])->assertForbidden();
});

test('another clinic cannot check out this clinic\'s consultation', function () {
    $encounter = seenAndSigned($this);
    $other = ReceptionFixture::make('other-clinic');
    [$theirDesk, $theirSession] = $other->memberWithRole(ClinicRole::Receptionist);
    app()->instance('enforce_tenant_scope_in_tests', true);

    $this->actingAs($theirDesk)->withSession($theirSession)->get("/clinic/billing/checkout/{$encounter->id}")->assertNotFound();
    $this->actingAs($theirDesk)->withSession($theirSession)->post("/clinic/billing/checkout/{$encounter->id}", ['prices' => ['consultation' => 1]])->assertNotFound();
});
