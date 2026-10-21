<?php

use App\ClinicRole;
use App\Models\Notification;
use App\Services\Clinical\EncounterWorkspace;
use App\Services\Physio\PhysioRecord;
use App\Services\Physio\SessionPackages;
use App\Services\QueueStateMachine;
use App\Services\Reception\ReceptionCheckIn;
use App\Services\Reception\VisitContext;
use App\Services\Treatment\PatientTreatments;
use Carbon\CarbonImmutable;
use Tests\Support\TreatmentFixture;

/**
 * The clinic events that tell a patient something, each once: check-in and
 * being called in, a signed visit, a dental treatment step, a completed
 * physiotherapy session and a package running low. Dental messages follow
 * only dental records, physiotherapy messages only physiotherapy ones.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-07 10:00', 'Asia/Kolkata'));
    $this->t = TreatmentFixture::make();
    $this->f = $this->t->f;
    [$this->therapistUser, $this->therapistSession] = $this->f->memberWithRole(ClinicRole::Doctor);
    $this->therapist = $this->f->doctor('Ms. Rehab', $this->therapistUser);
    $this->therapist->practitionerProfile()->withoutGlobalScopes()->update(['specialty' => 'Physiotherapist']);
    $this->t->actAs($this->therapistUser, $this->therapistSession);
    $this->patient = $this->f->patient('Rahul', 'Shah');
    $this->codes = fn () => Notification::query()->where('clinic_id', $this->f->clinic->id)->with('template:id,code')->orderBy('id')->get()->map(fn ($n) => $n->template->code)->all();
});

test('check-in tells the patient their token, and being called in tells them it is their turn', function () {
    $item = app(ReceptionCheckIn::class)->checkIn($this->f->clinic, $this->f->branch, $this->patient, doctor: $this->therapist,
        context: VisitContext::fromInput($this->f->clinic, ['visit_specialty' => 'physiotherapy']));
    app(QueueStateMachine::class)->call($item);
    // Called back a minute later: a new turn message.
    $this->travel(1)->minutes();
    app(QueueStateMachine::class)->recall($item->fresh());

    expect(($this->codes)())->toBe(['checkin_confirmation', 'queue_turn', 'queue_turn'])
        ->and(Notification::query()->where('clinic_id', $this->f->clinic->id)->first()->metadata['placeholders']['token'])->not->toBe('');
});

test('a signed physiotherapy visit asks for feedback only; a completed session gives the next step and the low balance', function () {
    $treatment = app(PatientTreatments::class)->startAdHoc($this->patient, 'physiotherapy', 'Knee Rehabilitation', $this->therapist, $this->f->branch, $this->therapistUser);
    $package = app(SessionPackages::class)->sell($treatment, $this->f->branch, ['name' => 'Knee 3-pack', 'sessions_total' => 3], $this->therapist);
    $encounter = app(EncounterWorkspace::class)->startForPatient($this->patient, $this->f->branch, $this->therapist, null, 'physiotherapy');
    $physio = app(PhysioRecord::class);
    $physio->completeSession($physio->openSession($treatment, $encounter, $this->therapist, $this->therapistUser), $package->id, $this->therapist, $this->therapistUser);

    app(EncounterWorkspace::class)->autosave($encounter->fresh(), ['chief_complaint' => 'Knee pain'], $encounter->fresh()->lock_version ?? 0);
    app(EncounterWorkspace::class)->finalize($encounter->fresh(), $this->therapist);

    $codes = ($this->codes)();
    // The session left 2 of 3 sessions.
    expect($codes)->toContain('physio_next_session', 'physio_package_balance', 'feedback_request')
        ->not->toContain('dental_procedure_followup', 'dental_treatment_followup');
    $balance = Notification::query()->where('event_key', "physio_balance:{$package->id}:2")->sole();
    expect($balance->metadata['placeholders']['remaining'])->toBe('2');
});

test('completing a dental treatment step asks the patient to book the next visit', function () {
    $this->t->actAsOwner();
    $rct = app(PatientTreatments::class)->start($this->patient, $this->t->rct(requiresTooth: false), $this->f->doctorA, $this->f->branch, $this->f->owner);
    $encounter = app(EncounterWorkspace::class)->startForPatient($this->patient, $this->f->branch, $this->f->doctorA, null, 'dental');
    $first = $rct->stages()->withoutGlobalScopes()->orderBy('sequence')->first();
    app(PatientTreatments::class)->completeStage($first, $this->f->owner, $this->f->doctorA, $encounter);

    expect(Notification::query()->where('event_key', "dental_stage:{$first->id}")->exists())->toBeTrue();
});
