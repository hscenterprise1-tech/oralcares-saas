<?php

use App\BillingStatus;
use App\ClinicPermission;
use App\ClinicRole;
use App\Models\ClinicalEncounter;
use App\Models\ClinicalProcedure;
use App\Models\ClinicalProcedurePrice;
use App\Models\ClinicEmployee;
use App\Models\PatientBilling;
use App\Models\PatientBillingPayment;
use App\Services\Reception\PatientFlowConfigService;
use App\Services\Reception\ReceptionCheckIn;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\ReceptionFixture;

/*
 * Checkout: a signed consultation billed at the clinic's own amounts, with
 * the payment taken in the same request (a refused payment leaves no bill),
 * or raised to be paid later. It is billing work, not the ledger: the desk
 * (billing.create + payment.collect, no billing.view since OD-9) may do it,
 * a practitioner may not, and nothing is ever billed twice.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-15 10:00', 'Asia/Kolkata'));
    $this->f = ReceptionFixture::make();
    [$this->doctorUser, $this->doctorSession] = $this->f->memberWithRole(ClinicRole::Doctor);
    $this->me = $this->f->doctor('Dr. Me', $this->doctorUser);
    $this->me->practitionerProfile()->withoutGlobalScopes()->update(['consultation_fee' => 500]);
    [$this->deskUser, $this->deskSession] = $this->f->memberWithRole(ClinicRole::Receptionist);
    ClinicEmployee::create(['clinic_id' => $this->f->clinic->id, 'user_id' => $this->deskUser->id, 'name' => 'Front Desk', 'email' => 'desk@example.com', 'status' => 'active']);
    $this->scaling = ClinicalProcedure::create(['clinic_id' => $this->f->clinic->id, 'code' => 'SCL', 'name' => 'Scaling', 'category' => 'Preventive', 'is_active' => true]);
    ClinicalProcedurePrice::create(['clinic_id' => $this->f->clinic->id, 'branch_id' => null, 'clinical_procedure_id' => $this->scaling->id, 'price' => 1200, 'is_available' => true]);
    $this->patient = $this->f->patient('Rahul', 'Sharma');
    $this->asDoctor = fn () => $this->actingAs($this->doctorUser)->withSession($this->doctorSession);
    $this->asDesk = fn () => $this->actingAs($this->deskUser)->withSession($this->deskSession);

    // Checked in, seen from Patient 360 (through the queue entry), one procedure, signed.
    $this->signed = function (bool $alreadyCheckedIn = false): ClinicalEncounter {
        if (! $alreadyCheckedIn) {
            app(ReceptionCheckIn::class)->checkIn($this->f->clinic, $this->f->branch, $this->patient, doctor: $this->me);
        }
        ($this->asDoctor)()->post(route('clinic.consultations.start-for-patient', $this->patient))->assertSessionHasNoErrors();
        $encounter = ClinicalEncounter::withoutGlobalScopes()->where('patient_id', $this->patient->id)->latest('id')->first();
        ($this->asDoctor)()->patchJson("/clinic/consultations/{$encounter->id}/draft", ['lock_version' => 0, 'chief_complaint' => 'Bleeding gums'])->assertOk();
        ($this->asDoctor)()->post("/clinic/consultations/{$encounter->id}/procedures", ['clinical_procedure_id' => $this->scaling->id])->assertSessionHasNoErrors();
        ($this->asDoctor)()->post("/clinic/consultations/{$encounter->id}/finalize")->assertSessionHasNoErrors();

        return $encounter->fresh();
    };
    $this->lines = function (ClinicalEncounter $encounter): array {
        $lines = [];
        ($this->asDesk)()->get(route('clinic.billing.checkout', $encounter))->assertOk()->assertInertia(function (Assert $page) use (&$lines) {
            $page->component('ClinicPortal/Billing/Checkout', false);
            $lines = $page->toArray()['props']['lines'];
        });

        return $lines;
    };
    $this->prices = fn (array $lines) => collect($lines)->mapWithKeys(fn (array $line) => [$line['key'] => $line['unit_price']])->all();
});

test('the desk bills a signed consultation and takes the payment in one step, with a receipt', function () {
    $encounter = ($this->signed)();
    $lines = ($this->lines)($encounter);
    expect(collect($lines)->pluck('kind')->all())->toBe(['consultation', 'procedure']);

    ($this->asDesk)()->post(route('clinic.billing.checkout.store', $encounter), [
        'prices' => ($this->prices)($lines), 'payment' => ['method' => 'upi', 'amount' => 1700, 'reference' => 'UPI-1'], 'idempotency_key' => 'checkout-test-0001',
    ])->assertSessionHasNoErrors()->assertSessionHas('success', fn (string $message) => str_contains($message, 'receipt'));

    $bill = PatientBilling::withoutGlobalScopes()->sole();
    expect((float) $bill->final_amount)->toBe(1700.0)
        ->and($bill->status)->toBe(BillingStatus::Paid)
        ->and(PatientBillingPayment::withoutGlobalScopes()->sole()->receipt_number)->not->toBeNull()
        ->and(($this->lines)($encounter))->toBe([]);
    // The receipt prints for the desk that took it.
    ($this->asDesk)()->get(route('clinic.billing.bills.print', $bill))->assertOk();
});

test('a refused payment leaves no bill and no receipt: too much, an unaccepted method, or a desk-only rule', function (array $payment) {
    $encounter = ($this->signed)();
    $lines = ($this->lines)($encounter);

    ($this->asDesk)()->post(route('clinic.billing.checkout.store', $encounter), ['prices' => ($this->prices)($lines), 'payment' => $payment])->assertSessionHasErrors();

    expect(PatientBilling::withoutGlobalScopes()->count())->toBe(0)
        ->and(PatientBillingPayment::withoutGlobalScopes()->count())->toBe(0)
        ->and(($this->lines)($encounter))->toHaveCount(2);
})->with([
    'more than is owed' => [['method' => 'cash', 'amount' => 5000]],
    'not a method' => [['method' => 'bitcoin', 'amount' => 1700]],
    'gateway at the desk' => [['method' => 'gateway', 'amount' => 1700]],
    'insurance at the desk' => [['method' => 'insurance', 'amount' => 1700]],
]);

test('Pay later raises the bill with the balance owed, and it is paid afterwards from the same page', function () {
    $encounter = ($this->signed)();
    $lines = ($this->lines)($encounter);

    ($this->asDesk)()->post(route('clinic.billing.checkout.store', $encounter), ['prices' => ($this->prices)($lines)])->assertSessionHasNoErrors();
    $bill = PatientBilling::withoutGlobalScopes()->sole();
    expect($bill->status)->toBe(BillingStatus::Pending)->and((float) $bill->outstanding_balance)->toBe(1700.0)
        ->and(PatientBillingPayment::withoutGlobalScopes()->count())->toBe(0);

    ($this->asDesk)()->post(route('clinic.billing.payments.store', $bill), ['method' => 'cash', 'amount' => 1700])->assertSessionHasNoErrors();
    expect($bill->fresh()->status)->toBe(BillingStatus::Paid);
});

test('a replayed checkout bills once; a second attempt for the same consultation finds nothing left', function () {
    $encounter = ($this->signed)();
    $lines = ($this->lines)($encounter);
    $send = fn () => ($this->asDesk)()->post(route('clinic.billing.checkout.store', $encounter), ['prices' => ($this->prices)($lines), 'payment' => ['method' => 'cash', 'amount' => 1700], 'idempotency_key' => 'checkout-test-0002']);

    $send()->assertSessionHasNoErrors();
    $send()->assertSessionHasNoErrors();
    ($this->asDesk)()->post(route('clinic.billing.checkout.store', $encounter), ['prices' => ($this->prices)($lines)])
        ->assertSessionHasErrors(['checkout' => 'There is nothing left to bill for this consultation.']);

    expect(PatientBilling::withoutGlobalScopes()->count())->toBe(1)->and(PatientBillingPayment::withoutGlobalScopes()->count())->toBe(1);
});

test('a consultation fee taken at check-in is never charged again at checkout', function () {
    app(PatientFlowConfigService::class)->updateCheckIn($this->f->clinic, ['payment' => 'at_check_in'], $this->f->owner);
    $this->actingAs($this->f->owner)->withSession($this->f->session)->postJson('/clinic/reception/check-in', [
        'patient_id' => $this->patient->id, 'practitioner_id' => $this->me->id, 'expected_charge' => 500, 'payment' => ['method' => 'cash', 'amount' => 500],
    ])->assertCreated();

    $encounter = ($this->signed)(alreadyCheckedIn: true);

    expect(collect(($this->lines)($encounter))->pluck('key')->all())->not->toContain('consultation')
        ->and(collect(($this->lines)($encounter))->pluck('kind')->all())->toBe(['procedure']);
});

test('a practitioner cannot open or use a checkout; the desk without billing.create cannot either', function () {
    $encounter = ($this->signed)();

    withoutDoctorBillingRights();
    ($this->asDoctor)()->get(route('clinic.billing.checkout', $encounter))->assertForbidden();
    ($this->asDoctor)()->post(route('clinic.billing.checkout.store', $encounter), ['prices' => ['consultation' => 500]])->assertForbidden();

    setPermissionsTeamId(null);
    Role::findByName(ClinicRole::Receptionist->value, 'web')->revokePermissionTo(ClinicPermission::BillingCreate->value);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->deskUser->unsetRelation('roles')->unsetRelation('permissions');

    ($this->asDesk)()->get(route('clinic.billing.checkout', $encounter))->assertForbidden();
    expect(PatientBilling::withoutGlobalScopes()->count())->toBe(0);
});

test('the checkout shows the desk this consultation and the patient\'s open bills, never the clinic\'s ledger', function () {
    // A clinic that keeps the ledger away from the desk (the default desk has it since 2026-11-05).
    withoutDeskLedger();
    $encounter = ($this->signed)();
    // Another patient's open bill at the branch: not on this page.
    $other = $this->f->patient('Someone', 'Else');
    PatientBilling::withoutGlobalScopes()->create([
        'clinic_id' => $this->f->clinic->id, 'branch_id' => $this->f->branch->id, 'patient_id' => $other->id, 'billing_number' => 'B-OTHER',
        'status' => 'pending', 'total_amount' => 900, 'final_amount' => 900, 'outstanding_balance' => 900, 'issued_at' => now(),
    ]);

    ($this->asDesk)()->get(route('clinic.billing.checkout', $encounter))->assertInertia(fn (Assert $page) => $page
        ->has('open_bills', 0)
        ->where('links.index', null)
        ->where('can.collect', true)
        ->where('payment_methods', fn ($methods) => collect($methods)->pluck('value')->intersect(['gateway', 'insurance'])->isEmpty()));
    ($this->asDesk)()->get('/clinic/billing')->assertForbidden();
});
