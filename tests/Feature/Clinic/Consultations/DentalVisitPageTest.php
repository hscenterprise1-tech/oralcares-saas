<?php

use App\ClinicRole;
use App\Models\Appointment;
use App\Models\ClinicalProcedure;
use App\Models\PatientDentalChart;
use App\Models\PatientFollowUp;
use App\Models\PatientProcedure;
use App\Models\TreatmentPlan;
use App\Models\TreatmentRecord;
use App\Services\Clinical\EncounterWorkspace;
use App\Services\Patient\PatientAutomation;
use App\TreatmentStatus;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\ReceptionFixture;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-02 10:00', 'Asia/Kolkata'));
    $this->f = ReceptionFixture::make();
    $this->f->clinic->update(['specialties' => ['dental']]);
    [$this->doctorUser, $this->doctorSession] = $this->f->memberWithRole(ClinicRole::Doctor);
    $this->me = $this->f->doctor('Dr. Me', $this->doctorUser);
    $this->patient = $this->f->patient('Rahul', 'Sharma');
    $this->actingAs($this->doctorUser);
    $this->encounter = app(EncounterWorkspace::class)->startForPatient($this->patient, $this->f->branch, $this->me);
    $this->asDoctor = fn () => $this->actingAs($this->doctorUser)->withSession($this->doctorSession);
    $this->rct = ClinicalProcedure::create(['clinic_id' => $this->f->clinic->id, 'code' => 'RCT', 'name' => 'Root canal treatment', 'category' => 'Endodontic', 'is_active' => true]);
    $this->filling = ClinicalProcedure::create(['clinic_id' => $this->f->clinic->id, 'code' => 'FIL', 'name' => 'Composite filling', 'category' => 'Restorative', 'is_active' => true]);
});

test('a dental visit opens the dental workspace with the chart, services and history', function () {
    ($this->asDoctor)()->get("/clinic/consultations/{$this->encounter->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('ClinicPortal/Consultations/Dental')
            ->where('can_edit', true)
            ->has('dental.services', 2)
            ->has('dental.complaints')
            ->where('dental.finished', false));

    ($this->asDoctor)()->get("/clinic/consultations/{$this->encounter->id}?record=1")
        ->assertInertia(fn (Assert $page) => $page->component('ClinicPortal/Consultations/Workspace'));
});

test('finishing charts the findings, does today\'s work, plans the rest and signs the visit', function () {
    ($this->asDoctor)()->post("/clinic/consultations/{$this->encounter->id}/dental/finish", [
        'consultation' => ['complaint' => 'Pain, lower left', 'diagnosis' => 'Irreversible pulpitis'],
        'findings' => [['tooth' => 36, 'condition' => 'caries', 'surfaces' => ['O']]],
        'work' => [
            ['procedure_id' => $this->rct->id, 'tooth' => 36, 'done' => true, 'condition' => 'root_canal'],
            ['procedure_id' => $this->filling->id, 'tooth' => 37, 'surfaces' => ['O'], 'done' => false],
        ],
    ])->assertSessionHasNoErrors();

    $plan = TreatmentPlan::withoutGlobalScopes()->where('patient_id', $this->patient->id)->sole();
    $records = $plan->records()->orderBy('sort_order')->get();

    expect($records[0]->status)->toBe(TreatmentStatus::Completed)
        ->and($records[1]->status)->toBe(TreatmentStatus::Planned)
        ->and(PatientProcedure::withoutGlobalScopes()->where('encounter_id', $this->encounter->id)->count())->toBe(1)
        ->and(PatientDentalChart::withoutGlobalScopes()->where('patient_id', $this->patient->id)->pluck('tooth_number')->all())->toContain(36)
        ->and($this->encounter->fresh()->isFinalized())->toBeTrue()
        ->and($this->encounter->fresh()->chief_complaint)->toBe('Pain, lower left');
});

test('a planned item from an earlier visit is done in place, without a second plan', function () {
    ($this->asDoctor)()->post("/clinic/consultations/{$this->encounter->id}/dental/plans", [
        'title' => 'Restore', 'items' => [['clinical_procedure_id' => $this->filling->id, 'tooth_number' => 37, 'surfaces' => ['O']]],
    ])->assertSessionHasNoErrors();
    $item = TreatmentRecord::withoutGlobalScopes()->where('patient_id', $this->patient->id)->sole();

    ($this->asDoctor)()->post("/clinic/consultations/{$this->encounter->id}/dental/finish", [
        'consultation' => ['complaint' => 'Recall'],
        'work' => [['id' => $item->id, 'done' => true]],
    ])->assertSessionHasNoErrors();

    expect(TreatmentPlan::withoutGlobalScopes()->where('patient_id', $this->patient->id)->count())->toBe(1)
        ->and($item->fresh()->status)->toBe(TreatmentStatus::Completed);
});

test('an empty visit is refused and nothing is signed', function () {
    ($this->asDoctor)()->post("/clinic/consultations/{$this->encounter->id}/dental/finish", [])
        ->assertSessionHasErrors(['dental']);

    expect($this->encounter->fresh()->isFinalized())->toBeFalse();
});

test('a service that takes several sittings says so, in the list and on the plan', function () {
    ($this->asDoctor)()->get("/clinic/consultations/{$this->encounter->id}")->assertInertia(function (Assert $page) {
        $services = collect($page->toArray()['props']['dental']['services'])->keyBy('code');
        expect($services['RCT']['visits'])->toBe(3)->and($services['RCT']['step'])->toBe(5)->and($services['FIL']['visits'])->toBe(1);
    });
});

test('the next visits are booked at one time, every few days, when the visit is finished', function () {
    $slot = null;
    ($this->asDoctor)()->get("/clinic/consultations/{$this->encounter->id}")->assertInertia(function (Assert $page) use (&$slot) {
        $day = collect($page->toArray()['props']['dental']['booking']['days'])->first(fn ($day) => $day['times'] !== []);
        $slot = ['date' => $day['date'], 'time' => $day['times'][0]];
    });

    ($this->asDoctor)()->post("/clinic/consultations/{$this->encounter->id}/dental/finish", [
        'consultation' => ['complaint' => 'Root canal, visit 1'],
        'work' => [['procedure_id' => $this->rct->id, 'tooth' => 36, 'done' => false]],
        'booking' => [...$slot, 'step' => 5, 'count' => 3],
    ])->assertSessionHasNoErrors();

    $dates = Appointment::withoutGlobalScopes()->where('patient_id', $this->patient->id)->orderBy('scheduled_at')->get()
        ->map(fn (Appointment $a) => $a->scheduled_at->format('Y-m-d H:i'))->all();

    expect($dates)->toHaveCount(3)
        ->and($dates[0])->toBe($slot['date'].' '.$slot['time'])
        ->and(CarbonImmutable::parse($dates[0])->diffInDays(CarbonImmutable::parse($dates[1])))->toBeGreaterThanOrEqual(5);
});

test('finishing the last treatment of a plan books the six-month recall', function () {
    ($this->asDoctor)()->post("/clinic/consultations/{$this->encounter->id}/dental/finish", [
        'consultation' => ['complaint' => 'Filling'],
        'work' => [['procedure_id' => $this->filling->id, 'tooth' => 37, 'surfaces' => ['O'], 'done' => true]],
    ])->assertSessionHasNoErrors();

    $recall = PatientFollowUp::withoutGlobalScopes()->where('patient_id', $this->patient->id)->where('reason', PatientAutomation::RECALL_REASON)->sole();

    expect($recall->due_on->toDateString())->toBe('2027-04-02');
});

test('a plan left open books no recall yet', function () {
    ($this->asDoctor)()->post("/clinic/consultations/{$this->encounter->id}/dental/finish", [
        'consultation' => ['complaint' => 'Filling'],
        'work' => [['procedure_id' => $this->filling->id, 'tooth' => 37, 'surfaces' => ['O'], 'done' => false]],
    ])->assertSessionHasNoErrors();

    expect(PatientFollowUp::withoutGlobalScopes()->where('patient_id', $this->patient->id)->count())->toBe(0);
});

test('a plan prints as an estimate for the dentist and the desk, at its own prices, and never under another patient', function () {
    ($this->asDoctor)()->post("/clinic/consultations/{$this->encounter->id}/dental/finish", [
        'consultation' => ['complaint' => 'Pain'],
        'work' => [['procedure_id' => $this->rct->id, 'tooth' => 36, 'done' => false], ['procedure_id' => $this->filling->id, 'tooth' => 37, 'surfaces' => ['O'], 'done' => false]],
    ])->assertSessionHasNoErrors();
    $plan = TreatmentPlan::withoutGlobalScopes()->where('patient_id', $this->patient->id)->sole();
    $url = route('clinic.patients.dental.plans.estimate', [$this->patient->id, $plan->id]);

    ($this->asDoctor)()->get($url)->assertOk()->assertSee('TREATMENT ESTIMATE')->assertSee('Root canal treatment')->assertSee('Composite filling')->assertSee('To be confirmed');

    [$desk, $deskSession] = $this->f->memberWithRole(ClinicRole::Receptionist);
    $this->actingAs($desk)->withSession($deskSession)->get($url)->assertOk();

    $other = $this->f->patient('Meera', 'Iyer');
    ($this->asDoctor)()->get(route('clinic.patients.dental.plans.estimate', [$other->id, $plan->id]))->assertNotFound();
});

test('the dental visit page carries the patient\'s root canal, implant, braces and lab work, with the address that saves each', function () {
    $id = $this->encounter->id;
    ($this->asDoctor)()->post("/clinic/consultations/{$id}/dental/endodontic", ['tooth_number' => 36, 'stage' => 'access', 'pulp_status' => 'necrotic', 'canals' => [['name' => 'MB', 'working_length' => 19.5]]])->assertSessionHasNoErrors();
    ($this->asDoctor)()->post("/clinic/consultations/{$id}/dental/implants", ['tooth_number' => 46, 'status' => 'planned', 'system' => 'Nobel'])->assertSessionHasNoErrors();
    ($this->asDoctor)()->post("/clinic/consultations/{$id}/dental/orthodontic", ['appliance' => 'fixed_metal', 'phase' => 'levelling', 'bracketed_teeth' => [13, 12]])->assertSessionHasNoErrors();
    ($this->asDoctor)()->post("/clinic/consultations/{$id}/dental/lab", ['teeth' => [36], 'restoration' => 'Zirconia crown'])->assertSessionHasNoErrors();

    ($this->asDoctor)()->get("/clinic/consultations/{$id}")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('dental.specialty.can_write', true)
        ->where('dental.specialty.endodontic.0.tooth', 36)
        ->where('dental.specialty.endodontic.0.canals.0.name', 'MB')
        ->where('dental.specialty.endodontic.0.url', fn ($url) => str_contains($url, "/clinic/consultations/{$id}/dental/endodontic/"))
        ->where('dental.specialty.implants.0.system', 'Nobel')
        ->where('dental.specialty.ortho.case.phase', 'levelling')
        ->where('dental.specialty.ortho.case.bracketed_teeth', [12, 13])
        ->where('dental.specialty.lab.0.restoration', 'Zirconia crown')
        ->where('dental.specialty.lab.0.status', 'draft')
        ->where('dental.specialty.lab.0.steps', fn ($steps) => collect($steps)->pluck('step')->contains('send'))
        ->has('dental.specialty.vocab.endodontic.stages')
        ->where('dental.specialty.links.lab', fn ($url) => str_ends_with($url, "/clinic/consultations/{$id}/dental/lab")));
});

test('once the visit is signed the specialty records can be read but not written from the page', function () {
    ($this->asDoctor)()->post("/clinic/consultations/{$this->encounter->id}/dental/finish", ['consultation' => ['complaint' => 'Checkup'], 'work' => []])->assertSessionHasNoErrors();

    ($this->asDoctor)()->get("/clinic/consultations/{$this->encounter->id}")->assertInertia(fn (Assert $page) => $page->where('dental.specialty.can_write', false));
});

test('the visit summary prints what the patient takes home: complaint, diagnosis, work done, advice', function () {
    ($this->asDoctor)()->post("/clinic/consultations/{$this->encounter->id}/dental/finish", [
        'consultation' => ['complaint' => 'Pain, lower left', 'diagnosis' => 'Irreversible pulpitis', 'advice' => 'Avoid chewing on the left for two days.'],
        'work' => [['procedure_id' => $this->rct->id, 'tooth' => 36, 'done' => true]],
    ])->assertSessionHasNoErrors();

    ($this->asDoctor)()->get("/clinic/consultations/{$this->encounter->id}/dental/summary")->assertOk()
        ->assertSee('Dental visit summary')->assertSee('Rahul Sharma')->assertSee('Pain, lower left')->assertSee('Irreversible pulpitis')
        ->assertSee('Root canal treatment')->assertSee('tooth 36')->assertSee('Avoid chewing on the left for two days.');

    [$billing, $session] = $this->f->memberWithRole(ClinicRole::BillingStaff);
    $this->actingAs($billing)->withSession($session)->get("/clinic/consultations/{$this->encounter->id}/dental/summary")->assertForbidden();
});
