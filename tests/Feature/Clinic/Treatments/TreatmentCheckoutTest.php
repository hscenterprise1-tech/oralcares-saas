<?php

use App\ClinicRole;
use App\Models\PatientBilling;
use App\Models\PatientBillingItem;
use App\Models\SecurityAuditLog;
use App\Services\Clinical\EncounterWorkspace;
use App\Services\Treatment\PatientTreatments;
use Carbon\CarbonImmutable;
use Tests\Support\TreatmentFixture;

/**
 * Billing treatment charges outside a consultation: upfront treatments,
 * completed stages and procedures from signed sittings — scoped to the
 * patient, billed once, at the pinned price.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-03 10:00', 'Asia/Kolkata'));
    $this->t = TreatmentFixture::make();
    $this->f = $this->t->f;
    $this->patient = $this->f->patient('Rajesh', 'Patel');
    $this->treatments = app(PatientTreatments::class);
    $this->tooth = fn (int $n) => ['targets' => ['tooth' => [['tooth_number' => $n]]]];
    $this->as = function (ClinicRole $role) {
        [$user, $session] = $this->f->memberWithRole($role);

        return fn () => $this->actingAs($user)->withSession($session);
    };
    $this->charges = "/clinic/patients/{$this->patient->id}/treatment-charges";
});

test('an upfront treatment started without a consultation is billed at reception, once, at its pinned price', function () {
    $owner = $this->t->actAsOwner();
    $treatment = $this->treatments->start($this->patient, $this->t->rct('upfront', price: 8000), $this->f->doctorA, $this->f->branch, $owner, ($this->tooth)(16));
    $reception = ($this->as)(ClinicRole::Receptionist);

    $reception()->getJson($this->charges)->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.key', "treatment:{$treatment->id}")
        ->assertJsonPath('data.0.unit_price', 8000)
        ->assertJsonMissingPath('data.0.source');

    $billId = $reception()->postJson($this->charges, ['charges' => ["treatment:{$treatment->id}"]])->assertCreated()->json('data.id');

    $bill = PatientBilling::find($billId);
    expect($bill)->encounter_id->toBeNull()->branch_id->toBe($this->f->branch->id)->patient_id->toBe($this->patient->id)
        ->and(PatientBillingItem::where('patient_billing_id', $billId)->sole())
        ->unit_price->toBe('8000.00')->patient_treatment_id->toBe($treatment->id)->price_version_id->toBe($treatment->price_version_id)
        ->and(SecurityAuditLog::where('event', 'treatment.charge.billed')->count())->toBe(1);

    // Billed once: it is no longer due, and billing it again is refused.
    $reception()->getJson($this->charges)->assertOk()->assertJsonCount(0, 'data');
    $reception()->postJson($this->charges, ['charges' => ["treatment:{$treatment->id}"]])->assertUnprocessable();
    expect(PatientBilling::count())->toBe(1);
});

test('a per-stage treatment is charged for each completed stage only', function () {
    $owner = $this->t->actAsOwner();
    $treatment = $this->treatments->start($this->patient, $this->t->rct('per_stage'), $this->f->doctorA, $this->f->branch, $owner, ($this->tooth)(26));
    [$first, $second] = $treatment->stages->all();
    $billing = ($this->as)(ClinicRole::BillingStaff);

    $billing()->getJson($this->charges)->assertOk()->assertJsonCount(0, 'data');

    $owner = $this->t->actAsOwner();
    $this->treatments->completeStage($first, $owner, $this->f->doctorA);
    $billing()->getJson($this->charges)->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.key', "stage:{$first->id}")->assertJsonPath('data.0.unit_price', 3000);

    // A stage that is not done cannot be billed.
    $billing()->postJson($this->charges, ['charges' => ["stage:{$second->id}"]])->assertUnprocessable();
    $billing()->postJson($this->charges, ['charges' => ["stage:{$first->id}"], 'discount' => 500])->assertCreated();

    expect(PatientBilling::sole())->discount_amount->toBe('500.00')
        ->and(PatientBillingItem::sole())->patient_treatment_stage_id->toBe($first->id);
});

test('per-procedure charges are due once their sitting is signed', function () {
    $owner = $this->t->actAsOwner();
    $treatment = $this->treatments->start($this->patient, $this->t->rct('per_procedure'), $this->f->doctorA, $this->f->branch, $owner, ($this->tooth)(36));
    $workspace = app(EncounterWorkspace::class);
    $encounter = $workspace->startForPatient($this->patient, $this->f->branch, $this->f->doctorA);
    $done = $this->treatments->recordProcedure($treatment->stages->first(), $this->t->procedures['ACC'], $encounter, $this->f->doctorA, $owner);
    $reception = ($this->as)(ClinicRole::Receptionist);

    $reception()->getJson($this->charges)->assertOk()->assertJsonCount(0, 'data');

    $workspace->autosave($encounter, ['chief_complaint' => 'Pain in 36'], $encounter->fresh()->lock_version);
    $workspace->finalize($encounter, $this->f->doctorA);

    $reception()->getJson($this->charges)->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.key', "procedure:{$done->id}")
        ->assertJsonPath('data.0.unit_price', 2000)
        ->assertJsonPath('data.0.description', 'Access opening (tooth 36)');
});

test('only another patient\'s own charges are ever listed or billable', function () {
    $owner = $this->t->actAsOwner();
    $other = $this->f->patient('Meena', 'Shah');
    $theirs = $this->treatments->start($other, $this->t->rct(), $this->f->doctorA, $this->f->branch, $owner, ($this->tooth)(11));
    $reception = ($this->as)(ClinicRole::Receptionist);

    $reception()->getJson($this->charges)->assertOk()->assertJsonCount(0, 'data');
    $reception()->postJson($this->charges, ['charges' => ["treatment:{$theirs->id}"]])->assertUnprocessable();
    $reception()->getJson("/clinic/patients/{$other->id}/treatment-charges")->assertOk()->assertJsonCount(1, 'data');
    expect(PatientBilling::count())->toBe(0);
});

test('billing needs billing.create, a discount needs discount.apply, and another clinic gets nothing', function () {
    $owner = $this->t->actAsOwner();
    $treatment = $this->treatments->start($this->patient, $this->t->rct(), $this->f->doctorA, $this->f->branch, $owner, ($this->tooth)(16));
    $charge = ['charges' => ["treatment:{$treatment->id}"]];

    // A doctor whose clinic keeps billing at the desk (the default Doctor role may bill).
    $doctor = ($this->as)(ClinicRole::Doctor);
    withoutDoctorBillingRights();
    $doctor()->postJson($this->charges, $charge)->assertForbidden();
    // A desk whose clinic keeps discounts away from it (the default desk may discount since 2026-11-05).
    $desk = ($this->as)(ClinicRole::Receptionist);
    withoutDeskLedger();
    $desk()->postJson($this->charges, [...$charge, 'discount' => 100])->assertForbidden();

    $other = TreatmentFixture::make('other-clinic');
    $asOther = fn () => $this->actingAs($other->f->owner)->withSession($other->f->session);
    $asOther()->getJson($this->charges)->assertNotFound();
    $asOther()->postJson($this->charges, $charge)->assertNotFound();

    expect(PatientBilling::count())->toBe(0);
});
