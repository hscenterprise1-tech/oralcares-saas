<?php

use App\ClinicRole;
use App\Models\ClinicEmployee;
use App\Models\ClinicRoom;
use App\Models\HealthcareSpecialtyMaster;
use App\Models\PatientBilling;
use App\Models\PatientSessionPackage;
use App\Models\PatientTreatment;
use App\Models\PhysioAssessment;
use App\Models\PhysioCarePlan;
use App\Models\PhysioSession;
use App\Models\SecurityAuditLog;
use App\Models\SessionPackageUsage;
use App\Services\Clinical\EncounterWorkspace;
use App\Services\Physio\PhysioRecord;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\ReceptionFixture;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-02 10:00', 'Asia/Kolkata'));
    HealthcareSpecialtyMaster::query()->firstOrCreate(['key' => 'physiotherapy'], ['name' => 'Physiotherapy', 'category' => 'rehabilitation', 'category_label' => 'Rehabilitation', 'is_active' => true]);
    $this->f = ReceptionFixture::make();
    $this->f->clinic->update(['specialties' => ['physiotherapy']]);
    [$this->therapistUser, $this->therapistSession] = $this->f->memberWithRole(ClinicRole::Doctor);
    $this->me = $this->f->doctor('Ms. Therapist', $this->therapistUser);
    // Treatment work needs a practitioner who practises the treatment's specialty.
    $this->me->practitionerProfile()->withoutGlobalScopes()->update(['specialty' => 'Physiotherapist']);
    $this->patient = $this->f->patient('Asha', 'Rao');
    $this->actingAs($this->therapistUser);
    $this->encounter = app(EncounterWorkspace::class)->startForPatient($this->patient, $this->f->branch, $this->me);
    $this->asTherapist = fn () => $this->actingAs($this->therapistUser)->withSession($this->therapistSession);
    $this->newSession = fn () => app(EncounterWorkspace::class)->startForPatient($this->patient, $this->f->branch, $this->me);
});

test('an assessment records body chart, pain, range of motion, strength and function on the consultation', function () {
    ($this->asTherapist)()->post("/clinic/consultations/{$this->encounter->id}/physio/assessment", [
        'presenting_problem' => 'Low back pain for 3 weeks',
        'pain_at_rest' => 4,
        'pain_on_activity' => 7,
        'body_chart' => [['region' => 'lower_back', 'side' => 'central', 'pain_type' => 'aching']],
        'range_of_motion' => [['joint' => 'Lumbar', 'movement' => 'Flexion', 'active' => 40, 'normal' => 60]],
        'strength' => [['muscle_group' => 'Hip extensors', 'side' => 'left', 'grade' => 4]],
        'functional_measures' => [['measure' => 'ODI', 'score' => 34]],
        'goals' => 'Return to work pain-free',
    ])->assertSessionHasNoErrors();

    $assessment = PhysioAssessment::withoutGlobalScopes()->sole();
    expect($assessment->clinic_id)->toBe($this->f->clinic->id)
        ->and($assessment->encounter_id)->toBe($this->encounter->id)
        ->and($assessment->pain_on_activity)->toBe(7)
        ->and($assessment->body_chart[0]['region'])->toBe('lower_back')
        ->and($assessment->range_of_motion[0]['active'])->toBe(40)
        ->and($assessment->functional_measures[0]['score'])->toBe(34)
        ->and(SecurityAuditLog::where('event', 'physio.assessment.created')->exists())->toBeTrue();
});

test('assessment scores are checked against their scales', function () {
    ($this->asTherapist)()->post("/clinic/consultations/{$this->encounter->id}/physio/assessment", ['pain_at_rest' => 11])->assertSessionHasErrors('pain_at_rest');
    ($this->asTherapist)()->post("/clinic/consultations/{$this->encounter->id}/physio/assessment", ['functional_measures' => [['measure' => 'NDI', 'score' => 60]]])
        ->assertSessionHasErrors(['physio' => 'Neck Disability Index is scored 0 to 50.']);
});

test('a plan of care starts its own treatment, one active plan per treatment, and edits make revisions', function () {
    ($this->asTherapist)()->post("/clinic/consultations/{$this->encounter->id}/physio/plans", [
        'title' => 'Lumbar rehab', 'planned_sessions' => 8, 'frequency' => '2x/week', 'interventions' => ['manual_therapy', 'tens'],
        'exercises' => [['name' => 'Bridging', 'sets' => 3, 'reps' => 10], ['name' => 'Bird dog', 'sets' => 2, 'reps' => 8, 'is_home' => true]],
    ])->assertSessionHasNoErrors();

    $plan = PhysioCarePlan::withoutGlobalScopes()->sole();
    $treatment = PatientTreatment::withoutGlobalScopes()->sole();
    expect($plan->exercises()->pluck('name')->all())->toBe(['Bridging', 'Bird dog'])
        ->and($plan->exercises()->first()->clinic_id)->toBe($this->f->clinic->id)
        ->and($plan->interventions)->toBe(['manual_therapy', 'tens'])
        ->and($plan->patient_treatment_id)->toBe($treatment->id)
        ->and($treatment)->source->toBe('adhoc')->specialty_key->toBe('physiotherapy')->title->toBe('Lumbar rehab');

    // A second plan for the same treatment is refused…
    ($this->asTherapist)()->post("/clinic/consultations/{$this->encounter->id}/physio/plans", ['title' => 'Second', 'patient_treatment_id' => $treatment->id])
        ->assertSessionHasErrors(['physio' => 'This treatment already has an active plan of care. Revise it instead.']);
    // …but another treatment for the same patient is not.
    ($this->asTherapist)()->post("/clinic/consultations/{$this->encounter->id}/physio/plans", ['title' => 'Shoulder rehab'])->assertSessionHasNoErrors();
    expect(PatientTreatment::withoutGlobalScopes()->count())->toBe(2);

    ($this->asTherapist)()->put("/clinic/consultations/{$this->encounter->id}/physio/plans/{$plan->id}/exercises", ['exercises' => [['name' => 'Plank', 'hold_seconds' => 30]]])
        ->assertSessionHasNoErrors();
    $revision = PhysioCarePlan::withoutGlobalScopes()->where('supersedes_id', $plan->id)->sole();
    expect($plan->fresh()->status)->toBe(PhysioCarePlan::SUPERSEDED)
        ->and($plan->exercises()->pluck('name')->all())->toBe(['Bridging', 'Bird dog'])
        ->and($revision)->revision->toBe(2)->status->toBe(PhysioCarePlan::ACTIVE)->patient_treatment_id->toBe($treatment->id)
        ->and($revision->exercises()->pluck('name')->all())->toBe(['Plank']);
});

test('sessions are numbered within their treatment and consume the treatment\'s package only when completed', function () {
    ($this->asTherapist)()->post("/clinic/consultations/{$this->encounter->id}/physio/plans", ['title' => 'Knee rehab', 'planned_sessions' => 2]);
    $treatment = PatientTreatment::withoutGlobalScopes()->sole();
    $package = app(PhysioRecord::class)->sellPackage($this->patient, $this->f->branch, ['name' => 'Knee 2-pack', 'sessions_total' => 2, 'patient_treatment_id' => $treatment->id], $this->me);
    $room = ClinicRoom::create(['branch_id' => $this->f->branch->id, 'name' => 'Bed 1', 'key' => 'bed-1', 'type' => 'treatment', 'is_active' => true]);
    $session = ['patient_treatment_id' => $treatment->id, 'package_id' => $package->id];

    // Saving a draft consumes nothing, even naming a package.
    ($this->asTherapist)()->post("/clinic/consultations/{$this->encounter->id}/physio/session", [...$session, 'subjective' => 'Knee better', 'pain_before' => 6, 'pain_after' => 3, 'modalities' => ['ift', 'exercise_therapy'], 'room_id' => $room->id])
        ->assertSessionHasNoErrors();
    expect($package->fresh()->sessions_used)->toBe(0)->and(PhysioSession::withoutGlobalScopes()->sole()->status)->toBe(PhysioSession::DRAFT);

    ($this->asTherapist)()->post("/clinic/consultations/{$this->encounter->id}/physio/session", [...$session, 'complete' => true])->assertSessionHasNoErrors();
    $second = ($this->newSession)();
    ($this->asTherapist)()->post("/clinic/consultations/{$second->id}/physio/session", [...$session, 'pain_before' => 4, 'complete' => true])->assertSessionHasNoErrors();

    $sessions = PhysioSession::withoutGlobalScopes()->orderBy('id')->get();
    expect($sessions->pluck('session_number')->all())->toBe([1, 2])
        ->and($sessions->pluck('status')->unique()->values()->all())->toBe([PhysioSession::COMPLETED])
        ->and($sessions[0]->patient_treatment_id)->toBe($treatment->id)
        ->and($sessions[0]->room_id)->toBe($room->id)
        ->and($package->fresh()->sessions_used)->toBe(2)
        ->and($package->fresh()->status)->toBe(PatientSessionPackage::EXHAUSTED)
        ->and(SessionPackageUsage::withoutGlobalScopes()->where('entry_type', 'consume')->count())->toBe(2)
        ->and($treatment->fresh()->sessions_completed)->toBe(2);

    $third = ($this->newSession)();
    ($this->asTherapist)()->post("/clinic/consultations/{$third->id}/physio/session", [...$session, 'complete' => true])
        ->assertSessionHasErrors(['physio' => 'That package has no sessions left.']);
    expect(PhysioSession::withoutGlobalScopes()->where('encounter_id', $third->id)->sole()->status)->toBe(PhysioSession::DRAFT);
});

test('the older form\'s exercise rows are kept as entered and become performed exercises without guessing the duration unit', function () {
    ($this->asTherapist)()->post("/clinic/consultations/{$this->encounter->id}/physio/plans", ['title' => 'Knee rehab']);
    $treatment = PatientTreatment::withoutGlobalScopes()->sole();

    ($this->asTherapist)()->post("/clinic/consultations/{$this->encounter->id}/physio/session", [
        'patient_treatment_id' => $treatment->id,
        'exercises_performed' => [
            ['name' => 'Quad sets', 'sets' => 3, 'reps' => 10, 'duration' => '10 min', 'intensity' => 'Moderate'],
            ['name' => 'Heel slides', 'duration' => '30'],
        ],
    ])->assertSessionHasNoErrors();

    $session = PhysioSession::withoutGlobalScopes()->sole();
    expect($session->exercises_performed)->toBe([
        ['name' => 'Quad sets', 'sets' => 3, 'reps' => 10, 'duration' => '10 min', 'intensity' => 'Moderate'],
        ['name' => 'Heel slides', 'duration' => '30'],
    ]);

    $performed = $session->performedExercises()->withoutGlobalScopes()->get();
    expect($performed)->toHaveCount(2)
        ->and($performed->pluck('physio_exercise_id')->filter()->all())->toBe([])
        ->and($performed->pluck('duration_seconds')->filter()->all())->toBe([])
        ->and($performed[0]->only(['name', 'status', 'sets', 'reps', 'intensity', 'notes']))->toBe(['name' => 'Quad sets', 'status' => 'performed', 'sets' => 3, 'reps' => 10, 'intensity' => 'Moderate', 'notes' => 'Duration: 10 min'])
        ->and($performed[1]->notes)->toBe('Duration: 30');
});

test('completing twice consumes once, and reversing gives the session back through the ledger', function () {
    ($this->asTherapist)()->post("/clinic/consultations/{$this->encounter->id}/physio/plans", ['title' => 'Back rehab']);
    $treatment = PatientTreatment::withoutGlobalScopes()->sole();
    $package = app(PhysioRecord::class)->sellPackage($this->patient, $this->f->branch, ['name' => '5-pack', 'sessions_total' => 5, 'patient_treatment_id' => $treatment->id], $this->me);
    $complete = ['patient_treatment_id' => $treatment->id, 'package_id' => $package->id, 'complete' => true];

    ($this->asTherapist)()->post("/clinic/consultations/{$this->encounter->id}/physio/session", [...$complete, 'subjective' => 'First'])->assertSessionHasNoErrors();
    ($this->asTherapist)()->post("/clinic/consultations/{$this->encounter->id}/physio/session", [...$complete, 'subjective' => 'Edited'])->assertSessionHasNoErrors();
    $session = PhysioSession::withoutGlobalScopes()->sole();
    expect($package->fresh()->sessions_used)->toBe(1)->and($session->subjective)->toBe('First');

    $this->withSession($this->therapistSession)->get('/clinic/consultations/'.$this->encounter->id); // establish the clinic context for a direct call
    app(PhysioRecord::class)->reverseSession($session, 'Recorded on the wrong day', $this->me, $this->therapistUser);
    expect($package->fresh()->sessions_used)->toBe(0)
        ->and(SessionPackageUsage::withoutGlobalScopes()->orderBy('id')->pluck('quantity', 'entry_type')->all())->toBe(['consume' => -1, 'reversal' => 1])
        ->and($session->fresh())->status->toBe(PhysioSession::REVERSED)->reversal_reason->toBe('Recorded on the wrong day');
});

test('another patient\'s package or treatment and another branch\'s bed are refused', function () {
    ($this->asTherapist)()->post("/clinic/consultations/{$this->encounter->id}/physio/plans", ['title' => 'Knee rehab']);
    $treatment = PatientTreatment::withoutGlobalScopes()->sole();
    $other = $this->f->patient('Other', 'Patient');
    $otherVisit = app(EncounterWorkspace::class)->startForPatient($other, $this->f->branch, $this->me);
    ($this->asTherapist)()->post("/clinic/consultations/{$otherVisit->id}/physio/plans", ['title' => 'Their knee']);
    $theirTreatment = PatientTreatment::withoutGlobalScopes()->where('patient_id', $other->id)->sole();
    $theirs = app(PhysioRecord::class)->sellPackage($other, $this->f->branch, ['name' => 'Theirs', 'sessions_total' => 3, 'patient_treatment_id' => $theirTreatment->id], $this->me);
    $elsewhere = ReceptionFixture::make('elsewhere');
    $foreignRoom = ClinicRoom::create(['branch_id' => $elsewhere->branch->id, 'name' => 'Bed X', 'key' => 'bed-x', 'type' => 'treatment', 'is_active' => true]);

    ($this->asTherapist)()->post("/clinic/consultations/{$this->encounter->id}/physio/session", ['patient_treatment_id' => $treatment->id, 'package_id' => $theirs->id, 'complete' => true])
        ->assertSessionHasErrors(['physio' => 'That package is not this patient\'s.']);
    ($this->asTherapist)()->post("/clinic/consultations/{$this->encounter->id}/physio/session", ['patient_treatment_id' => $theirTreatment->id])
        ->assertSessionHasErrors(['physio' => 'That treatment is not this patient\'s.']);
    ($this->asTherapist)()->post("/clinic/consultations/{$this->encounter->id}/physio/session", ['patient_treatment_id' => $treatment->id, 'room_id' => $foreignRoom->id])
        ->assertSessionHasErrors(['physio' => 'That bed or room is not at this branch.']);
    ($this->asTherapist)()->post("/clinic/consultations/{$this->encounter->id}/physio/session", ['subjective' => 'No treatment named'])
        ->assertSessionHasErrors('patient_treatment_id');
});

test('a sold package with a price is billed on the canonical clinic bill, against its treatment', function () {
    ($this->asTherapist)()->post("/clinic/consultations/{$this->encounter->id}/physio/plans", ['title' => 'Knee rehab']);
    $treatment = PatientTreatment::withoutGlobalScopes()->sole();
    [$receptionUser, $receptionSession] = $this->f->memberWithRole(ClinicRole::Receptionist);
    ClinicEmployee::create(['clinic_id' => $this->f->clinic->id, 'user_id' => $receptionUser->id, 'name' => 'Front Desk', 'email' => 'desk@example.com', 'status' => 'active']);

    $this->actingAs($receptionUser)->withSession($receptionSession)->post("/clinic/patients/{$this->patient->id}/session-packages", [
        'name' => '10 physio sessions', 'sessions_total' => 10, 'price' => 6000, 'valid_until' => '2027-01-31', 'patient_treatment_id' => $treatment->id,
    ])->assertRedirect()->assertSessionHasNoErrors();

    $package = PatientSessionPackage::withoutGlobalScopes()->sole();
    $bill = PatientBilling::withoutGlobalScopes()->findOrFail($package->patient_billing_id);
    expect($package->sessions_total)->toBe(10)
        ->and($package->patient_treatment_id)->toBe($treatment->id)
        ->and($bill->billing_number)->toBe('INV-2627-00001')
        ->and((float) $bill->final_amount)->toBe(6000.0)
        ->and($bill->patient_id)->toBe($this->patient->id);

    $this->actingAs($receptionUser)->withSession($receptionSession)->post("/clinic/patients/{$this->patient->id}/session-packages", ['name' => 'No treatment', 'sessions_total' => 5])
        ->assertSessionHasErrors('patient_treatment_id');
});

test('selling a package needs billing rights', function () {
    ($this->asTherapist)()->post("/clinic/patients/{$this->patient->id}/session-packages", ['name' => 'Pack', 'sessions_total' => 5, 'patient_treatment_id' => 1])->assertForbidden();
});

test('a signed session is fixed, and a dental clinic has no physio record', function () {
    ($this->asTherapist)()->post("/clinic/consultations/{$this->encounter->id}/physio/plans", ['title' => 'Knee rehab']);
    $treatment = PatientTreatment::withoutGlobalScopes()->sole();
    $workspace = app(EncounterWorkspace::class);
    $workspace->autosave($this->encounter, ['chief_complaint' => 'Knee pain'], 0);
    $workspace->finalize($this->encounter, $this->me);
    ($this->asTherapist)()->post("/clinic/consultations/{$this->encounter->id}/physio/session", ['patient_treatment_id' => $treatment->id, 'subjective' => 'Late'])->assertForbidden();

    $this->f->clinic->update(['specialties' => ['dental']]);
    $open = ($this->newSession)();
    ($this->asTherapist)()->post("/clinic/consultations/{$open->id}/physio/assessment", ['pain_at_rest' => 3])->assertForbidden();
    ($this->asTherapist)()->get("/clinic/consultations/{$open->id}")->assertInertia(fn (Assert $page) => $page->where('physio', null));
});

test('the session screen carries the physio record, each treatment\'s plan and session, packages and pain progress', function () {
    ($this->asTherapist)()->post("/clinic/consultations/{$this->encounter->id}/physio/plans", ['title' => 'Knee rehab']);
    $treatment = PatientTreatment::withoutGlobalScopes()->sole();
    ($this->asTherapist)()->post("/clinic/consultations/{$this->encounter->id}/physio/session", ['patient_treatment_id' => $treatment->id, 'pain_before' => 7, 'pain_after' => 4]);

    ($this->asTherapist)()->get("/clinic/consultations/{$this->encounter->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('encounter.label', 'Session')
            ->where('physio.can_write.soap_notes', true)
            ->where('physio.session.pain_before', 7)
            ->where('physio.session.patient_treatment_id', $treatment->id)
            ->where('physio.sessions.0.patient_treatment_id', $treatment->id)
            ->where('physio.plans.0.patient_treatment_id', $treatment->id)
            ->where('physio.progress.sessions.0.pain_after', 4)
            ->has('physio.vocabulary.modalities'));
});
