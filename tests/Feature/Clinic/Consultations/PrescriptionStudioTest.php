<?php

use App\ClinicRole;
use App\Models\Clinic;
use App\Models\ClinicalEncounter;
use App\Models\ClinicMedicine;
use App\Models\Prescription;
use App\Models\SecurityAuditLog;
use App\Services\Clinical\EncounterWorkspace;
use App\Services\ClinicalPrescriptionService;
use App\Services\TreatmentPlanningService;
use Carbon\CarbonImmutable;
use Tests\Support\ReceptionFixture;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-02 10:00', 'Asia/Kolkata'));
    $this->f = ReceptionFixture::make();
    [$this->doctorUser, $this->doctorSession] = $this->f->memberWithRole(ClinicRole::Doctor);
    $this->me = $this->f->doctor('Dr. Me', $this->doctorUser);
    $this->patient = $this->f->patient('Rahul', 'Sharma');
    $this->actingAs($this->doctorUser);
    $this->encounter = app(EncounterWorkspace::class)->startForPatient($this->patient, $this->f->branch, $this->me);
    $this->asDoctor = fn () => $this->actingAs($this->doctorUser)->withSession($this->doctorSession);
    $this->line = ['drug_name' => 'Amoxicillin', 'strength' => '500 mg', 'form' => 'Capsule', 'dosage' => '1 capsule', 'frequency' => 'TDS', 'duration' => '5 days', 'timing' => 'after_food', 'route' => 'oral'];
});

function draftPrescription(object $test): Prescription
{
    ($test->asDoctor)()->post("/clinic/consultations/{$test->encounter->id}/prescription")->assertSessionHasNoErrors();

    return Prescription::withoutGlobalScopes()->where('encounter_id', $test->encounter->id)->where('status', 'draft')->sole();
}

test('a prescription is drafted without a number, then numbered, signed and remembered when finalised', function () {
    $rx = draftPrescription($this);
    expect($rx->prescription_number)->toBeNull()
        ->and($rx->clinic_id)->toBe($this->f->clinic->id)
        ->and($rx->prescriber_id)->toBe($this->me->id);

    ($this->asDoctor)()->post("/clinic/prescriptions/{$rx->id}/items", $this->line)->assertSessionHasNoErrors();
    ($this->asDoctor)()->post("/clinic/prescriptions/{$rx->id}/finalize", ['notes' => 'Complete the course.'])->assertSessionHasNoErrors();

    $rx->refresh();
    expect($rx->prescription_number)->toBe('RX-2627-000001')
        ->and($rx->status)->toBe(Prescription::FINALIZED)
        ->and($rx->notes)->toBe('Complete the course.')
        ->and($rx->finalized_by)->toBe($this->me->id)
        ->and($rx->signature_hash)->toHaveLength(64)
        ->and($rx->items()->sole()->clinic_id)->toBe($this->f->clinic->id)
        ->and($this->patient->timelineEvents()->where('event_type', 'prescription_issued')->exists())->toBeTrue()
        ->and(SecurityAuditLog::where('event', 'prescription.finalized')->where('subject_id', $rx->id)->exists())->toBeTrue();

    $remembered = ClinicMedicine::where('clinic_id', $this->f->clinic->id)->sole();
    expect($remembered->name)->toBe('Amoxicillin')
        ->and($remembered->strength)->toBe('500 mg')
        ->and($remembered->default_frequency)->toBe('TDS')
        ->and($remembered->use_count)->toBe(1)
        ->and($rx->items()->sole()->medicine_id)->toBe($remembered->id);
});

test('prescribing a formulary medicine fills it in and counts another use', function () {
    $medicine = ClinicMedicine::create(['clinic_id' => $this->f->clinic->id, 'name' => 'Ibuprofen', 'strength' => '400 mg', 'form' => 'Tablet', 'lookup_key' => ClinicMedicine::lookupKey('Ibuprofen', '400 mg', 'Tablet'), 'use_count' => 3]);
    $rx = draftPrescription($this);

    ($this->asDoctor)()->post("/clinic/prescriptions/{$rx->id}/items", ['medicine_id' => $medicine->id, 'dosage' => '1 tablet', 'frequency' => 'SOS', 'duration' => '3 days'])
        ->assertSessionHasNoErrors();
    app(ClinicalPrescriptionService::class)->finalize($rx, $this->me);

    expect($rx->items()->sole()->only(['drug_name', 'strength', 'form']))->toBe(['drug_name' => 'Ibuprofen', 'strength' => '400 mg', 'form' => 'Tablet'])
        ->and($medicine->fresh()->use_count)->toBe(4)
        ->and(ClinicMedicine::count())->toBe(1);
});

test('another clinic\'s formulary medicine cannot be prescribed', function () {
    $foreign = ClinicMedicine::withoutGlobalScopes()->create(['clinic_id' => Clinic::factory()->create()->id, 'name' => 'Theirs', 'lookup_key' => 'theirs||']);
    $rx = draftPrescription($this);

    ($this->asDoctor)()->post("/clinic/prescriptions/{$rx->id}/items", ['medicine_id' => $foreign->id, 'dosage' => '1', 'frequency' => 'OD', 'duration' => '1 day'])
        ->assertSessionHasErrors(['prescription' => 'That medicine is not in this clinic\'s formulary.']);

    expect($rx->items()->count())->toBe(0);
});

test('a line needs a medicine, a dose, how often and for how long', function () {
    $rx = draftPrescription($this);

    ($this->asDoctor)()->post("/clinic/prescriptions/{$rx->id}/items", ['drug_name' => 'Paracetamol'])
        ->assertSessionHasErrors(['dosage', 'frequency', 'duration']);
    ($this->asDoctor)()->post("/clinic/prescriptions/{$rx->id}/finalize")
        ->assertSessionHasErrors(['prescription' => 'Add at least one medicine before finalising the prescription.']);
});

test('a finalised prescription is never edited', function () {
    $rx = draftPrescription($this);
    ($this->asDoctor)()->post("/clinic/prescriptions/{$rx->id}/items", $this->line);
    app(ClinicalPrescriptionService::class)->finalize($rx, $this->me);
    $line = $rx->items()->sole();

    ($this->asDoctor)()->post("/clinic/prescriptions/{$rx->id}/items", $this->line)->assertForbidden();
    ($this->asDoctor)()->put("/clinic/prescriptions/{$rx->id}/items/{$line->id}", [...$this->line, 'dosage' => '2 capsules'])->assertForbidden();
    ($this->asDoctor)()->delete("/clinic/prescriptions/{$rx->id}/items/{$line->id}")->assertForbidden();

    expect($line->fresh()->dosage)->toBe('1 capsule');
});

test('numbers follow finalisation order, not drafting order', function () {
    $first = draftPrescription($this);
    ($this->asDoctor)()->post("/clinic/prescriptions/{$first->id}/items", $this->line);

    $other = app(EncounterWorkspace::class)->startForPatient($this->f->patient('Second', 'Patient'), $this->f->branch, $this->me);
    $second = app(ClinicalPrescriptionService::class)->issuePrescription($other, [], [$this->line], $this->me);
    app(ClinicalPrescriptionService::class)->finalize($first, $this->me);

    expect($second->prescription_number)->toBe('RX-2627-000001')
        ->and($first->fresh()->prescription_number)->toBe('RX-2627-000002');
});

test('only the prescriber cancels, with a reason, and the prescription stays on the record', function () {
    $rx = app(ClinicalPrescriptionService::class)->issuePrescription($this->encounter, [], [$this->line], $this->me);
    [$colleagueUser, $colleagueSession] = $this->f->memberWithRole(ClinicRole::Doctor);
    $this->f->doctor('Dr. Colleague', $colleagueUser);

    $this->actingAs($colleagueUser)->withSession($colleagueSession)->post("/clinic/prescriptions/{$rx->id}/cancel", ['reason' => 'Not mine'])->assertForbidden();
    ($this->asDoctor)()->post("/clinic/prescriptions/{$rx->id}/cancel", ['reason' => ''])->assertSessionHasErrors('reason');
    ($this->asDoctor)()->post("/clinic/prescriptions/{$rx->id}/cancel", ['reason' => 'Wrong strength'])->assertSessionHasNoErrors();

    $rx->refresh();
    expect($rx->status)->toBe(Prescription::CANCELLED)
        ->and($rx->cancellation_reason)->toBe('Wrong strength')
        ->and($rx->cancelled_by)->toBe($this->me->id)
        ->and($rx->prescription_number)->toBe('RX-2627-000001')
        ->and(SecurityAuditLog::where('event', 'prescription.cancelled')->exists())->toBeTrue();
});

test('signing the consultation finalises its draft prescription and discards an empty one', function () {
    $rx = draftPrescription($this);
    ($this->asDoctor)()->post("/clinic/prescriptions/{$rx->id}/items", $this->line);
    $workspace = app(EncounterWorkspace::class);
    $workspace->autosave($this->encounter, ['chief_complaint' => 'Tooth pain'], 0);

    $signed = $workspace->finalize($this->encounter, $this->me);

    expect($rx->fresh()->status)->toBe(Prescription::FINALIZED)
        ->and($workspace->signatureIsIntact($signed->fresh()))->toBeTrue();

    $other = $workspace->startForPatient($this->f->patient('Empty', 'Draft'), $this->f->branch, $this->me);
    $empty = app(ClinicalPrescriptionService::class)->draftFor($other, $this->me);
    $workspace->autosave($other, ['chief_complaint' => 'Review'], 0);
    $workspace->finalize($other, $this->me);

    expect(Prescription::withoutGlobalScopes()->whereKey($empty->id)->exists())->toBeFalse();
});

test('no prescription is started on a signed consultation', function () {
    $workspace = app(EncounterWorkspace::class);
    $workspace->autosave($this->encounter, ['chief_complaint' => 'Review'], 0);
    $workspace->finalize($this->encounter, $this->me);

    ($this->asDoctor)()->post("/clinic/consultations/{$this->encounter->id}/prescription")->assertForbidden();
});

test('a line from another prescription cannot be changed through this one', function () {
    $rx = draftPrescription($this);
    $other = app(EncounterWorkspace::class)->startForPatient($this->f->patient('Other', 'Patient'), $this->f->branch, $this->me);
    $theirs = app(ClinicalPrescriptionService::class)->draftFor($other, $this->me);
    $theirLine = app(ClinicalPrescriptionService::class)->addItem($theirs, $this->line);

    ($this->asDoctor)()->delete("/clinic/prescriptions/{$rx->id}/items/{$theirLine->id}")->assertNotFound();

    expect($theirLine->fresh())->not->toBeNull();
});

test('a finalised prescription prints and downloads; a draft does not', function () {
    $draft = draftPrescription($this);
    ($this->asDoctor)()->get("/clinic/prescriptions/{$draft->id}/print")->assertForbidden();

    ($this->asDoctor)()->post("/clinic/prescriptions/{$draft->id}/items", $this->line);
    app(ClinicalPrescriptionService::class)->finalize($draft, $this->me);

    ($this->asDoctor)()->get("/clinic/prescriptions/{$draft->id}/print")
        ->assertOk()
        ->assertSee('RX-2627-000001')
        ->assertSee('Amoxicillin')
        ->assertSee('Three times a day')
        ->assertSee('Dr. Me');
    ($this->asDoctor)()->get("/clinic/prescriptions/{$draft->id}/print?download=1")
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');

    expect(SecurityAuditLog::where('event', 'prescription.printed')->exists())->toBeTrue()
        ->and(SecurityAuditLog::where('event', 'prescription.downloaded')->exists())->toBeTrue();
});

test('reception cannot open prescriptions and another clinic cannot find them', function () {
    $rx = app(ClinicalPrescriptionService::class)->issuePrescription($this->encounter, [], [$this->line], $this->me);
    [$receptionUser, $receptionSession] = $this->f->memberWithRole(ClinicRole::Receptionist);
    $this->actingAs($receptionUser)->withSession($receptionSession)->get("/clinic/prescriptions/{$rx->id}/print")->assertForbidden();

    $other = ReceptionFixture::make('other-clinic');
    [$otherUser, $otherSession] = $other->memberWithRole(ClinicRole::Doctor);
    $other->doctor('Dr. Elsewhere', $otherUser);
    app()->instance('enforce_tenant_scope_in_tests', true);

    $this->actingAs($otherUser)->withSession($otherSession)->get("/clinic/prescriptions/{$rx->id}/print")->assertNotFound();
});

test('the medicine search offers only this clinic\'s formulary, most used first', function () {
    $make = fn (int $clinicId, string $name, int $uses) => ClinicMedicine::withoutGlobalScopes()->create(['clinic_id' => $clinicId, 'name' => $name, 'lookup_key' => strtolower($name).'||', 'use_count' => $uses]);
    $make($this->f->clinic->id, 'Amoxicillin', 2);
    $make($this->f->clinic->id, 'Amlodipine', 9);
    $make(Clinic::factory()->create()->id, 'Amikacin', 50);

    ($this->asDoctor)()->getJson('/clinic/medicines/search?q=am')
        ->assertOk()
        ->assertJsonPath('data.0.name', 'Amlodipine')
        ->assertJsonPath('data.1.name', 'Amoxicillin')
        ->assertJsonCount(2, 'data');
});

test('the consultation screen carries the draft prescription and what the doctor may do with it', function () {
    $rx = draftPrescription($this);
    ($this->asDoctor)()->post("/clinic/prescriptions/{$rx->id}/items", $this->line);

    ($this->asDoctor)()->get("/clinic/consultations/{$this->encounter->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('can.prescribe', true)
            ->where('prescriptions.0.status', 'draft')
            ->where('prescriptions.0.items.0.drug_name', 'Amoxicillin')
            ->where('prescriptions.0.can.edit', true)
            ->where('prescriptions.0.can.print', false)
            ->has('prescription_options.frequencies'));
});

test('a treatment plan prescription goes through the same writer', function () {
    $rx = app(TreatmentPlanningService::class)->requestPrescription($this->encounter, $this->me, [$this->line], 'After food');

    expect($rx->status)->toBe(Prescription::FINALIZED)
        ->and($rx->prescriber_id)->toBe($this->me->id)
        ->and($rx->prescription_number)->toBe('RX-2627-000001')
        ->and(ClinicalEncounter::withoutGlobalScopes()->find($this->encounter->id)->prescriptions()->count())->toBe(1);
});
