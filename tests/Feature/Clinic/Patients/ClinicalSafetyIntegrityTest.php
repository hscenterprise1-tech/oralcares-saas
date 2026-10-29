<?php

use App\ClinicRole;
use App\Models\PatientAlert;
use App\Models\PatientClinicalEntry;
use App\Models\Prescription;
use App\Services\Clinical\EncounterWorkspace;
use App\Services\Clinical\PrescriptionAllergyCheck;
use App\Services\MedicalHistoryService;
use App\Services\Patient\PatientBranchActivityRecorder;
use App\Services\Patient\PatientClinicalEntries;
use App\Services\Reception\PatientFlowConfigService;
use App\Services\TreatmentPlanningService;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Tests\Support\ReceptionFixture;

/**
 * Three integrity rules around Patient 360's clinical safety information:
 *
 * 1. Conditions: a clinic can keep them to its own list. Typed names off the
 *    list are refused (what the patient already has stays); the clinic's own
 *    conditions are added through Settings, by members who manage settings.
 * 2. Prescribing: where the patient has documented allergies, a clinician
 *    must review the current list for the draft before it is signed — on
 *    every signing path. The review is stored server-side; a changed list
 *    voids it. "Not recorded" is kept as unknown, never as "none".
 * 3. Alerts: who added one is kept, and removing one resolves it, never
 *    deletes it.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-26 10:00', 'Asia/Kolkata'));
    $this->f = ReceptionFixture::make();
    [$this->doctorUser, $this->doctorSession] = $this->f->memberWithRole(ClinicRole::Doctor);
    $this->me = $this->f->doctor('Dr. Me', $this->doctorUser);
    $this->patient = $this->f->patient('Meera', 'Iyer');
    app(PatientBranchActivityRecorder::class)->record($this->f->clinic->id, $this->patient->id, $this->f->branch->id, now(), 'registration');
    $this->entries = app(PatientClinicalEntries::class);
    $this->asDoctor = fn () => $this->actingAs($this->doctorUser)->withSession($this->doctorSession);
    $this->asOwner = fn () => $this->actingAs($this->f->owner)->withSession($this->f->session);
    $this->conditionsUrl = route('clinic.patients.clinical-entries.update', [$this->patient, 'condition']);
    $this->enforce = fn (bool $on = true) => app(PatientFlowConfigService::class)->updateQuickCare($this->f->clinic, ['conditions_from_catalogue' => $on], $this->f->owner);
    $this->allergy = fn (string $label) => $this->entries->add($this->patient, 'allergy', $this->entries->prepare($this->f->clinic->id, 'allergy', [['label' => $label]], 'a'), 'registration', null);
    $this->line = ['drug_name' => 'Amoxicillin', 'strength' => '500 mg', 'form' => 'Capsule', 'dosage' => '1 capsule', 'frequency' => 'TDS', 'duration' => '5 days', 'timing' => 'after_food', 'route' => 'oral'];
    $this->draft = function (): Prescription {
        $this->actingAs($this->doctorUser);
        $encounter = app(EncounterWorkspace::class)->startForPatient($this->patient, $this->f->branch, $this->me);
        ($this->asDoctor)()->post("/clinic/consultations/{$encounter->id}/prescription")->assertSessionHasNoErrors();
        $rx = Prescription::withoutGlobalScopes()->where('encounter_id', $encounter->id)->sole();
        ($this->asDoctor)()->post("/clinic/prescriptions/{$rx->id}/items", $this->line)->assertSessionHasNoErrors();

        return $rx;
    };
});

/* ---------------------------------------------------------------- conditions */

test('with the catalogue enforced, listed conditions are accepted and typed names off the list are refused', function () {
    ($this->enforce)();

    ($this->asDoctor)()->putJson($this->conditionsUrl, ['entries' => [['key' => 'diabetes'], ['label' => 'hypertension']]])->assertOk();
    ($this->asDoctor)()->putJson($this->conditionsUrl, ['entries' => [['key' => 'diabetes'], ['label' => 'Gout of the left toe']]])
        ->assertUnprocessable()->assertJsonValidationErrors('entries.1');

    expect(PatientClinicalEntry::withoutGlobalScopes()->where('patient_id', $this->patient->id)->where('status', 'active')->pluck('catalogue_key')->sort()->values()->all())
        ->toBe(['diabetes', 'hypertension']);
});

test('without enforcement a typed condition is recorded as before', function () {
    ($this->asDoctor)()->putJson($this->conditionsUrl, ['entries' => [['label' => 'Gout of the left toe']]])->assertOk();

    expect(PatientClinicalEntry::withoutGlobalScopes()->where('patient_id', $this->patient->id)->sole())
        ->label->toBe('Gout of the left toe')->catalogue_key->toBeNull()->source->toBe('clinical')->recorded_by->toBe($this->doctorUser->id);
});

test('turning enforcement on keeps what the patient already has and changes no existing entry', function () {
    ($this->asDoctor)()->putJson($this->conditionsUrl, ['entries' => [['label' => 'Gout of the left toe']]])->assertOk();
    $before = PatientClinicalEntry::withoutGlobalScopes()->where('patient_id', $this->patient->id)->sole()->only(['label', 'status', 'source', 'recorded_by', 'recorded_at']);
    ($this->enforce)();

    // Saving the list unchanged, plus a listed condition, is fine; the typed one stays as recorded.
    ($this->asDoctor)()->putJson($this->conditionsUrl, ['entries' => [['label' => 'Gout of the left toe'], ['key' => 'asthma']]])->assertOk();

    expect(PatientClinicalEntry::withoutGlobalScopes()->where('patient_id', $this->patient->id)->whereNull('catalogue_key')->sole()->only(['label', 'status', 'source', 'recorded_by', 'recorded_at']))->toEqual($before);
});

test('a custom condition is added to the clinic list by settings managers only, then can be recorded', function () {
    ($this->enforce)();

    ($this->asDoctor)()->postJson('/clinic/admin/settings/catalogue/condition', ['name' => 'Sickle cell trait'])->assertForbidden();
    ($this->asOwner)()->postJson('/clinic/admin/settings/catalogue/condition', ['name' => 'Sickle cell trait'])->assertCreated();

    ($this->asDoctor)()->putJson($this->conditionsUrl, ['entries' => [['label' => 'Sickle cell trait']]])->assertOk();
    expect(PatientClinicalEntry::withoutGlobalScopes()->where('patient_id', $this->patient->id)->sole()->catalogue_key)->not->toBeNull();
});

test('registration is held to the list too, but history imports are never refused', function () {
    ($this->enforce)();

    expect(fn () => $this->entries->prepare($this->f->clinic->id, 'condition', [['label' => 'Gout of the left toe']], 'conditions'))->toThrow(ValidationException::class)
        ->and($this->entries->prepare($this->f->clinic->id, 'condition', [['label' => 'Gout of the left toe']], 'conditions', import: true))->toHaveCount(1);
});

test('another clinic can neither read nor change this patient\'s conditions, and reception cannot change them', function () {
    $other = ReceptionFixture::make('other-clinic');
    $this->actingAs($other->owner)->withSession($other->session)->putJson($this->conditionsUrl, ['entries' => [['key' => 'diabetes']]])->assertNotFound();

    [$reception, $session] = $this->f->memberWithRole(ClinicRole::Receptionist);
    $this->actingAs($reception)->withSession($session)->putJson($this->conditionsUrl, ['entries' => [['key' => 'diabetes']]])->assertForbidden();

    expect(PatientClinicalEntry::withoutGlobalScopes()->where('patient_id', $this->patient->id)->count())->toBe(0);
});

/* ---------------------------------------------------------------- prescribing */

test('with documented allergies a prescription is signed only after a clinician reviews the current list', function () {
    ($this->allergy)('Penicillin');
    $rx = ($this->draft)();

    ($this->asDoctor)()->post("/clinic/prescriptions/{$rx->id}/finalize")->assertSessionHasErrors(['prescription' => 'Review the patient\'s documented allergies before finalising: Penicillin.']);
    ($this->asDoctor)()->post("/clinic/prescriptions/{$rx->id}/allergy-review", ['fingerprint' => str_repeat('a', 64)])->assertSessionHasErrors('prescription');

    $state = app(PrescriptionAllergyCheck::class)->stateFor($this->patient);
    ($this->asDoctor)()->post("/clinic/prescriptions/{$rx->id}/allergy-review", ['fingerprint' => $state['fingerprint']])->assertSessionHasNoErrors();
    ($this->asDoctor)()->post("/clinic/prescriptions/{$rx->id}/finalize")->assertSessionHasNoErrors();

    $rx->refresh();
    expect($rx->status)->toBe(Prescription::FINALIZED)
        ->and($rx->allergy_reviewed_by)->toBe($this->doctorUser->id)
        ->and($rx->allergy_review)->toMatchArray(['status' => 'documented', 'allergies' => [['label' => 'Penicillin', 'severity' => 'high', 'reaction' => null]]])
        ->and($this->patient->timelineEvents()->where('event_type', 'prescription_allergies_reviewed')->exists())->toBeTrue()
        ->and($this->patient->timelineEvents()->where('event_type', 'prescription_issued')->sole()->context['allergy_status'])->toBe('documented');
});

test('a review made against an older allergy list does not count', function () {
    ($this->allergy)('Penicillin');
    $rx = ($this->draft)();
    $state = app(PrescriptionAllergyCheck::class)->stateFor($this->patient);
    ($this->asDoctor)()->post("/clinic/prescriptions/{$rx->id}/allergy-review", ['fingerprint' => $state['fingerprint']])->assertSessionHasNoErrors();

    ($this->allergy)('Sulfonamides');

    ($this->asDoctor)()->post("/clinic/prescriptions/{$rx->id}/finalize")->assertSessionHasErrors('prescription');
    expect($rx->fresh()->status)->toBe(Prescription::DRAFT);
});

test('signing the consultation and the one-step path are held to the same check', function () {
    ($this->allergy)('Penicillin');
    $rx = ($this->draft)();
    $encounter = $rx->encounter()->withoutGlobalScopes()->first();
    $encounter = app(EncounterWorkspace::class)->autosave($encounter, ['chief_complaint' => 'Toothache'], (int) $encounter->lock_version);

    expect(fn () => app(EncounterWorkspace::class)->finalize($encounter->fresh(), $this->me))->toThrow(DomainException::class, 'Review the patient\'s documented allergies')
        ->and(fn () => app(TreatmentPlanningService::class)->requestPrescription($encounter->fresh(), $this->me, [$this->line], null))->toThrow(DomainException::class, 'Review the patient\'s documented allergies')
        ->and($rx->fresh()->status)->toBe(Prescription::DRAFT);
});

test('without documented allergies signing is not blocked, and what was known is kept: none known, or not recorded', function () {
    $rx = ($this->draft)();
    ($this->asDoctor)()->post("/clinic/prescriptions/{$rx->id}/finalize")->assertSessionHasNoErrors();
    expect($rx->fresh()->allergy_review['status'])->toBe('not_recorded')->and($rx->fresh()->allergy_reviewed_at)->toBeNull();

    $this->patient->profile()->create(['clinic_id' => $this->f->clinic->id, 'organization_id' => $this->f->clinic->organization_id, 'allergy_status' => 'none_known']);
    $second = ($this->draft)();
    ($this->asDoctor)()->post("/clinic/prescriptions/{$second->id}/finalize")->assertSessionHasNoErrors();
    expect($second->fresh()->allergy_review['status'])->toBe('none_known');

    // Nothing to acknowledge is refused, so a review cannot be recorded against an empty list.
    $third = ($this->draft)();
    ($this->asDoctor)()->post("/clinic/prescriptions/{$third->id}/allergy-review", ['fingerprint' => app(PrescriptionAllergyCheck::class)->stateFor($this->patient)['fingerprint']])->assertSessionHasErrors('prescription');
});

test('only a member who may edit the draft can review its allergies; another clinic is refused', function () {
    ($this->allergy)('Penicillin');
    $rx = ($this->draft)();
    $fingerprint = app(PrescriptionAllergyCheck::class)->stateFor($this->patient)['fingerprint'];

    [$reception, $session] = $this->f->memberWithRole(ClinicRole::Receptionist);
    $this->actingAs($reception)->withSession($session)->post("/clinic/prescriptions/{$rx->id}/allergy-review", ['fingerprint' => $fingerprint])->assertForbidden();
    $other = ReceptionFixture::make('other-clinic');
    $this->actingAs($other->owner)->withSession($other->session)->post("/clinic/prescriptions/{$rx->id}/allergy-review", ['fingerprint' => $fingerprint])->assertForbidden();

    expect($rx->fresh()->allergy_reviewed_at)->toBeNull();
});

test('the consultation shows the prescriber the allergies to review on the draft', function () {
    ($this->allergy)('Penicillin');
    $rx = ($this->draft)();

    ($this->asDoctor)()->get("/clinic/consultations/{$rx->encounter_id}")->assertOk()->assertInertia(fn ($page) => $page
        ->where('prescriptions.0.allergies.status', 'documented')
        ->where('prescriptions.0.allergies.review_required', true)
        ->where('prescriptions.0.allergies.reviewed', false)
        ->where('prescriptions.0.allergies.allergies.0.label', 'Penicillin')
        ->where('prescriptions.0.links.allergy_review', route('clinic.prescriptions.allergy-review', $rx)));
});

/* ---------------------------------------------------------------- alerts */

test('a removed alert is resolved with who and when, a new one records its author, and only current alerts show', function () {
    $this->actingAs($this->f->owner);
    $service = app(MedicalHistoryService::class);
    $service->syncManualAlerts($this->patient, ['Bleeding risk: on aspirin', 'Prefers morning appointments']);
    $service->syncManualAlerts($this->patient, ['Bleeding risk: on aspirin']);

    $removed = PatientAlert::where('patient_id', $this->patient->id)->where('description', 'Prefers morning appointments')->sole();
    expect($removed->resolved_at)->not->toBeNull()->and($removed->resolved_by)->toBe($this->f->owner->id)->and($removed->created_by)->toBe($this->f->owner->id)
        ->and($this->patient->fresh()->alerts->pluck('description')->all())->toBe(['Bleeding risk: on aspirin']);

    // Restoring the text adds a new current alert; the resolved one stays as history.
    $service->syncManualAlerts($this->patient, ['Bleeding risk: on aspirin', 'Prefers morning appointments']);
    expect(PatientAlert::where('patient_id', $this->patient->id)->where('description', 'Prefers morning appointments')->count())->toBe(2)
        ->and($this->patient->fresh()->alerts)->toHaveCount(2);
});

test('the consultation shows the clinician the allergies (or that none are recorded) and the medical conditions', function () {
    $rx = ($this->draft)();
    ($this->asDoctor)()->get("/clinic/consultations/{$rx->encounter_id}")->assertOk()->assertInertia(fn ($page) => $page
        ->component('ClinicPortal/Consultations/Workspace')
        ->where('patient.allergies.status', 'not_recorded')
        ->where('patient.conditions', []));

    ($this->allergy)('Penicillin');
    $this->entries->add($this->patient, 'condition', $this->entries->prepare($this->f->clinic->id, 'condition', [['key' => 'diabetes']], 'c'), 'clinical', null);

    ($this->asDoctor)()->get("/clinic/consultations/{$rx->encounter_id}")->assertOk()->assertInertia(fn ($page) => $page
        ->where('patient.allergies.status', 'documented')
        ->where('patient.allergies.allergies.0.label', 'Penicillin')
        ->where('patient.conditions', ['Diabetes'])
        ->missing('patient.allergies.fingerprint'));
});

test('clinical staff add and remove safety alerts from Patient 360; who and when is kept, and removal never deletes', function () {
    ($this->asDoctor)()->post(route('clinic.patients.alerts.store', $this->patient), ['description' => 'Bleeding risk: on warfarin', 'severity' => 'high'])->assertSessionHasNoErrors();
    $alert = PatientAlert::where('patient_id', $this->patient->id)->sole();
    expect($alert)->type->toBe('manual')->created_by->toBe($this->doctorUser->id);

    ($this->asDoctor)()->post(route('clinic.patients.alerts.resolve', [$this->patient, $alert->id]), ['reason' => 'Warfarin stopped'])->assertSessionHasNoErrors();
    expect($alert->fresh())->resolved_by->toBe($this->doctorUser->id)->resolved_at->not->toBeNull()
        ->and($this->patient->fresh()->alerts)->toHaveCount(0)
        ->and($this->patient->timelineEvents()->whereIn('event_type', ['safety_alert_added', 'safety_alert_resolved'])->count())->toBe(2);

    // The copies kept of allergies follow their record: they cannot be resolved here.
    ($this->allergy)('Penicillin');
    $copy = PatientAlert::where('patient_id', $this->patient->id)->where('type', 'allergy')->sole();
    ($this->asDoctor)()->post(route('clinic.patients.alerts.resolve', [$this->patient, $copy->id]))->assertNotFound();
});

test('notes are saved with who and when, and the previous text is kept in the timeline', function () {
    $this->patient->medicalHistory()->create(['clinic_id' => $this->f->clinic->id, 'organization_id' => $this->f->clinic->organization_id, 'notes' => 'Anxious about injections']);

    ($this->asDoctor)()->put(route('clinic.patients.medical-notes.update', $this->patient), ['notes' => 'Anxious about injections; topical anaesthetic first'])->assertSessionHasNoErrors();

    $history = $this->patient->medicalHistory()->first();
    expect($history)->notes->toBe('Anxious about injections; topical anaesthetic first')->notes_updated_by->toBe($this->doctorUser->id)
        ->and($this->patient->timelineEvents()->where('event_type', 'medical_notes_updated')->sole()->context['previous'])->toBe('Anxious about injections');
});

test('reception and other clinics cannot change alerts or notes', function () {
    [$reception, $session] = $this->f->memberWithRole(ClinicRole::Receptionist);
    $this->actingAs($reception)->withSession($session)->post(route('clinic.patients.alerts.store', $this->patient), ['description' => 'X', 'severity' => 'low'])->assertForbidden();
    $this->actingAs($reception)->withSession($session)->put(route('clinic.patients.medical-notes.update', $this->patient), ['notes' => 'X'])->assertForbidden();

    $other = ReceptionFixture::make('other-clinic');
    $this->actingAs($other->owner)->withSession($other->session)->post(route('clinic.patients.alerts.store', $this->patient), ['description' => 'X', 'severity' => 'low'])->assertNotFound();

    expect(PatientAlert::where('patient_id', $this->patient->id)->count())->toBe(0);
});
