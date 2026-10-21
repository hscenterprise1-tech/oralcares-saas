<?php

use App\BranchStatus;
use App\ClinicRole;
use App\Models\Branch;
use App\Models\ClinicalEncounter;
use App\Models\ClinicQueueItem;
use App\Models\Patient;
use App\Models\PatientClinicalEntry;
use App\Services\Clinical\DoctorToday;
use App\Services\Clinical\EncounterWorkspace;
use App\Services\Reception\ReceptionCheckIn;
use App\Services\Reception\VisitContext;
use App\Services\Treatment\PatientTreatments;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\TreatmentFixture;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-06 10:00', 'Asia/Kolkata'));
    $this->t = TreatmentFixture::make();
    $this->f = $this->t->f;
    [$this->doctorUser, $this->doctorSession] = $this->f->memberWithRole(ClinicRole::Doctor);
    $this->me = $this->f->doctor('Ms. Rehab', $this->doctorUser);
    $this->me->practitionerProfile()->withoutGlobalScopes()->update(['specialty' => 'Physiotherapist']);
    $this->asDoctor = fn () => $this->actingAs($this->doctorUser)->withSession($this->doctorSession);
    $this->checkIn = function (Patient $patient, $doctor = 'me', ?string $reason = 'Knee pain', ?Branch $branch = null): ClinicQueueItem {
        $item = app(ReceptionCheckIn::class)->checkIn(
            $this->f->clinic, $branch ?? $this->f->branch, $patient, doctor: $doctor === 'me' ? $this->me : $doctor,
            context: VisitContext::fromInput($this->f->clinic, ['visit_specialty' => 'physiotherapy', 'visit_reason' => $reason]),
        );
        // A walk-in for "any doctor" (check-in otherwise picks a default doctor).
        if ($doctor === null) {
            $item->update(['doctor_id' => null]);
        }

        return $item->fresh();
    };
});

test('a patient Reception checks in appears on Today with why they came; Start visit opens the consultation; signing moves them to done', function () {
    $patient = $this->f->patient('Rahul', 'Patel');
    $item = ($this->checkIn)($patient);

    ($this->asDoctor)()->get('/clinic/consultations')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('ClinicPortal/Today/Index', false)
        ->where('is_doctor', true)
        ->has('today.waiting', 1)
        ->where('today.waiting.0.patient.name', 'Rahul Patel')
        ->where('today.waiting.0.visit.reason', 'Knee pain')
        ->where('today.waiting.0.visit.specialty_key', 'physiotherapy')
        ->where('today.waiting.0.links.start', route('clinic.consultations.start-from-queue', $item))
        ->has('today.in_consultation', 0)
        ->has('today.completed', 0));

    ($this->asDoctor)()->post("/clinic/consultations/queue/{$item->id}")->assertRedirect();
    $encounter = ClinicalEncounter::withoutGlobalScopes()->where('patient_id', $patient->id)->sole();
    // Checked in for physiotherapy at a dental + physiotherapy branch: the consultation is a physiotherapy one.
    expect($encounter->specialty)->toBe('physiotherapy');

    ($this->asDoctor)()->get('/clinic/consultations')->assertInertia(fn (Assert $page) => $page
        ->has('today.waiting', 0)
        ->has('today.in_consultation', 1)
        ->where('today.in_consultation.0.encounter.id', $encounter->id));

    ($this->asDoctor)()->get("/clinic/consultations/{$encounter->id}")->assertInertia(fn (Assert $page) => $page
        ->where('visit.reason', 'Knee pain')
        ->where('visit.specialty', 'Physiotherapy')
        // Only the visit's specialty's parts: the physiotherapy record, not the dental chart.
        ->where('dental', null)
        ->whereNot('physio', null));

    $this->actingAs($this->doctorUser);
    app(EncounterWorkspace::class)->autosave($encounter, ['chief_complaint' => 'Knee pain'], 0);
    ($this->asDoctor)()->post("/clinic/consultations/{$encounter->id}/finalize")->assertRedirect();

    ($this->asDoctor)()->get('/clinic/consultations')->assertInertia(fn (Assert $page) => $page
        ->has('today.in_consultation', 0)
        ->has('today.completed', 1)
        ->where('today.completed.0.encounter.status', 'signed'));
});

test('another doctor\'s patients, other branches and other clinics stay off my Today; a walk-in for any doctor goes to whoever starts it', function () {
    [$otherUser, $otherSession] = $this->f->memberWithRole(ClinicRole::Doctor);
    $other = $this->f->doctor('Dr. Other', $otherUser);
    $other->practitionerProfile()->withoutGlobalScopes()->update(['specialty' => 'Physiotherapist']);
    $asOther = fn () => $this->actingAs($otherUser)->withSession($otherSession);

    ($this->checkIn)($this->f->patient('Theirs', 'Only'), $other);
    $walkIn = ($this->checkIn)($this->f->patient('Any', 'Doctor'), null);
    $east = Branch::factory()->create(['clinic_id' => $this->f->clinic->id, 'name' => 'East Branch', 'status' => BranchStatus::Active]);
    ($this->checkIn)($this->f->patient('Seen', 'AtEast'), 'me', 'Back pain', $east);
    $elsewhere = TreatmentFixture::make('elsewhere');
    app(ReceptionCheckIn::class)->checkIn($elsewhere->f->clinic, $elsewhere->f->branch, $elsewhere->f->patient('Other', 'Clinic'));

    ($this->asDoctor)()->get('/clinic/consultations')->assertInertia(fn (Assert $page) => $page
        ->has('today.waiting', 1)
        ->where('today.waiting.0.patient.name', 'Any Doctor'));

    ($this->asDoctor)()->post("/clinic/consultations/queue/{$walkIn->id}")->assertRedirect();
    expect($walkIn->fresh()->doctor_id)->toBe($this->me->id);

    // Taken: it leaves the other doctor's list, and they cannot open it.
    $asOther()->get('/clinic/consultations')->assertInertia(fn (Assert $page) => $page
        ->has('today.waiting', 1)
        ->where('today.waiting.0.patient.name', 'Theirs Only')
        ->has('today.in_consultation', 0));
    $asOther()->post("/clinic/consultations/queue/{$walkIn->id}")->assertForbidden();
    expect(ClinicalEncounter::withoutGlobalScopes()->count())->toBe(1);
});

test('two doctors reaching for the same walk-in: the second is refused under the lock and lands on nothing', function () {
    [$otherUser] = $this->f->memberWithRole(ClinicRole::Doctor);
    $other = $this->f->doctor('Dr. Other', $otherUser);
    $walkIn = ($this->checkIn)($this->f->patient('Any', 'Doctor'), null);

    $this->actingAs($this->doctorUser);
    app(EncounterWorkspace::class)->startFromQueue($walkIn, $this->me);
    // The second doctor read the row before it was taken: the service re-reads it locked.
    expect(fn () => app(EncounterWorkspace::class)->startFromQueue($walkIn, $other))->toThrow(DomainException::class, 'Another doctor has already taken this patient in.');
});

test('Reception cannot open Today; a clinician\'s day list carries no clinical notes, only identity, visit, safety flags and treatment summaries', function () {
    [$receptionUser, $receptionSession] = $this->f->memberWithRole(ClinicRole::Receptionist);
    $this->actingAs($receptionUser)->withSession($receptionSession)->get('/clinic/consultations')->assertForbidden();

    $patient = $this->f->patient('Rahul', 'Patel');
    PatientClinicalEntry::withoutGlobalScopes()->create(['clinic_id' => $this->f->clinic->id, 'patient_id' => $patient->id, 'kind' => 'allergy', 'label' => 'Penicillin', 'severity' => 'high', 'status' => PatientClinicalEntry::ACTIVE, 'source' => 'clinical', 'recorded_at' => now(), 'dedupe_key' => 'allergy:penicillin']);
    ($this->checkIn)($patient);

    ($this->asDoctor)()->get('/clinic/consultations')->assertInertia(fn (Assert $page) => $page
        ->where('today.waiting.0.safety.0.label', 'Penicillin')
        ->where('today.waiting.0', fn ($row) => collect($row)->keys()->diff([
            'key', 'queue_item_id', 'status', 'priority', 'patient', 'case', 'visit', 'doctor', 'booked_for', 'checked_in_at', 'started_at',
            'completed_at', 'wait_minutes', 'safety', 'safety_more', 'treatments', 'treatments_more', 'encounter', 'links',
        ])->isEmpty()));
});

test('Today reads a fixed number of queries however many patients are on it', function () {
    $this->actingAs($this->doctorUser);
    $this->t->actAs($this->doctorUser, $this->doctorSession);
    $add = function (int $n) {
        foreach (range(1, $n) as $i) {
            $patient = $this->f->patient("P{$i}", 'Load'.random_int(1000, 9999));
            app(PatientTreatments::class)->startAdHoc($patient, 'physiotherapy', 'Knee rehab', $this->me, $this->f->branch, $this->doctorUser);
            PatientClinicalEntry::withoutGlobalScopes()->create(['clinic_id' => $this->f->clinic->id, 'patient_id' => $patient->id, 'kind' => 'allergy', 'label' => "Allergy {$i}", 'severity' => 'high', 'status' => PatientClinicalEntry::ACTIVE, 'source' => 'clinical', 'recorded_at' => now(), 'dedupe_key' => "allergy:{$i}:".$patient->id]);
            ($this->checkIn)($patient);
        }
    };
    $count = function () {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $today = app(DoctorToday::class)->build($this->f->clinic, $this->f->branch, $this->me);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return [$queries, count($today['waiting'])];
    };

    $add(2);
    [$few, $rowsFew] = $count();
    $add(6);
    [$many, $rowsMany] = $count();

    expect($rowsFew)->toBe(2)->and($rowsMany)->toBe(8)->and($many)->toBe($few);
});
