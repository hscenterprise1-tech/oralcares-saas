<?php

use App\ClinicRole;
use App\Models\ClinicalProcedure;
use App\Models\PatientDentalChart;
use App\Models\PatientToothStatusHistory;
use App\Models\SecurityAuditLog;
use App\Models\TreatmentPlan;
use App\Models\TreatmentRecord;
use App\Services\Clinical\EncounterWorkspace;
use App\Services\Dental\DentalRecord;
use App\Services\ToothStatusHistoryService;
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

test('charting a tooth keeps its current state and every change in its history', function () {
    ($this->asDoctor)()->post("/clinic/consultations/{$this->encounter->id}/dental/chart", ['tooth' => 36, 'condition' => 'caries', 'surfaces' => ['O', 'M']])->assertSessionHasNoErrors();
    ($this->asDoctor)()->post("/clinic/consultations/{$this->encounter->id}/dental/chart", ['tooth' => 36, 'condition' => 'filled', 'surfaces' => ['M', 'O'], 'note' => 'Composite'])->assertSessionHasNoErrors();

    $tooth = PatientDentalChart::withoutGlobalScopes()->where('patient_id', $this->patient->id)->sole();
    $history = PatientToothStatusHistory::withoutGlobalScopes()->where('patient_id', $this->patient->id)->orderBy('id')->get();

    expect($tooth->tooth_number)->toBe(36)
        ->and($tooth->status)->toBe('filled')
        ->and($tooth->surfaces)->toBe(['M', 'O'])
        ->and($tooth->encounter_id)->toBe($this->encounter->id)
        ->and($history)->toHaveCount(2)
        ->and($history[1]->previous_status)->toBe('caries')
        ->and($history[1]->clinic_id)->toBe($this->f->clinic->id)
        ->and($history[1]->encounter_id)->toBe($this->encounter->id)
        ->and(SecurityAuditLog::where('event', 'dental.tooth.created')->count())->toBe(2);

    ($this->asDoctor)()->getJson("/clinic/consultations/{$this->encounter->id}/dental/teeth/36")
        ->assertOk()->assertJsonPath('data.0.status', 'filled')->assertJsonPath('data.1.status', 'caries');
});

test('the FDI chart refuses an unknown tooth, a surface on a whole-tooth condition, and a signed consultation', function () {
    ($this->asDoctor)()->post("/clinic/consultations/{$this->encounter->id}/dental/chart", ['tooth' => 19, 'condition' => 'caries'])
        ->assertSessionHasErrors(['dental' => 'Tooth 19 is not an FDI tooth number.']);
    ($this->asDoctor)()->post("/clinic/consultations/{$this->encounter->id}/dental/chart", ['tooth' => 11, 'condition' => 'missing', 'surfaces' => ['M']])
        ->assertSessionHasErrors(['dental' => 'Missing applies to the whole tooth, not to surfaces.']);

    $workspace = app(EncounterWorkspace::class);
    $workspace->autosave($this->encounter, ['chief_complaint' => 'Check-up'], 0);
    $workspace->finalize($this->encounter, $this->me);

    ($this->asDoctor)()->post("/clinic/consultations/{$this->encounter->id}/dental/chart", ['tooth' => 11, 'condition' => 'sound'])->assertForbidden();
    expect(PatientDentalChart::withoutGlobalScopes()->count())->toBe(0);
});

test('a branch without a dental specialty has no dental chart', function () {
    $this->f->clinic->update(['specialties' => ['physiotherapy']]);

    ($this->asDoctor)()->post("/clinic/consultations/{$this->encounter->id}/dental/chart", ['tooth' => 36, 'condition' => 'caries'])->assertForbidden();
    ($this->asDoctor)()->get("/clinic/consultations/{$this->encounter->id}")
        ->assertInertia(fn (Assert $page) => $page->where('dental', null));
});

test('a treatment plan is proposed, accepted, and done item by item into the consultation that bills it', function () {
    ($this->asDoctor)()->post("/clinic/consultations/{$this->encounter->id}/dental/plans", [
        'title' => 'Restore lower left',
        'items' => [
            ['clinical_procedure_id' => $this->rct->id, 'tooth_number' => 36],
            ['clinical_procedure_id' => $this->filling->id, 'tooth_number' => 37, 'surfaces' => ['O']],
        ],
    ])->assertSessionHasNoErrors();

    $plan = TreatmentPlan::withoutGlobalScopes()->where('patient_id', $this->patient->id)->sole();
    expect($plan->status)->toBe(TreatmentPlan::PROPOSED)
        ->and($plan->records()->pluck('status')->map->value->unique()->all())->toBe(['proposed']);

    ($this->asDoctor)()->post("/clinic/consultations/{$this->encounter->id}/dental/plans/{$plan->id}/accept")->assertSessionHasNoErrors();
    [$rct, $filling] = $plan->records()->orderBy('sort_order')->get();

    ($this->asDoctor)()->post("/clinic/consultations/{$this->encounter->id}/dental/plans/{$plan->id}/items/{$rct->id}/complete", ['resulting_condition' => 'root_canal'])
        ->assertSessionHasNoErrors();

    $rct->refresh();
    $procedure = $this->encounter->procedures()->sole();
    expect($rct->status)->toBe(TreatmentStatus::Completed)
        ->and($rct->patient_procedure_id)->toBe($procedure->id)
        ->and($procedure->clinical_procedure_id)->toBe($this->rct->id)
        ->and($procedure->details['tooth_number'])->toBe(36)
        ->and(PatientDentalChart::withoutGlobalScopes()->where('tooth_number', 36)->value('status'))->toBe('root_canal')
        ->and(PatientToothStatusHistory::withoutGlobalScopes()->where('tooth_number', 36)->value('kind'))->toBe(PatientToothStatusHistory::TREATMENT)
        // The first treatment done moves the plan from accepted to active.
        ->and($plan->fresh()->status)->toBe(TreatmentPlan::ACTIVE);

    ($this->asDoctor)()->post("/clinic/consultations/{$this->encounter->id}/dental/plans/{$plan->id}/items/{$filling->id}/cancel")->assertSessionHasNoErrors();
    expect($plan->fresh()->status)->toBe(TreatmentPlan::COMPLETED);
});

test('treatment is done only from an accepted plan, and only with the clinic\'s own services', function () {
    $plan = app(DentalRecord::class)->proposePlan($this->encounter, 'Plan', [['clinical_procedure_id' => $this->rct->id, 'tooth_number' => 46]], null, $this->me);
    $item = $plan->records()->sole();

    ($this->asDoctor)()->post("/clinic/consultations/{$this->encounter->id}/dental/plans/{$plan->id}/items/{$item->id}/complete")
        ->assertSessionHasErrors(['dental' => 'Treatment is done from an accepted plan.']);

    $foreign = ClinicalProcedure::withoutGlobalScopes()->create(['clinic_id' => ReceptionFixture::make('elsewhere')->clinic->id, 'code' => 'X', 'name' => 'Theirs', 'category' => 'General', 'is_active' => true]);
    ($this->asDoctor)()->post("/clinic/consultations/{$this->encounter->id}/dental/plans", ['items' => [['clinical_procedure_id' => $foreign->id]]])
        ->assertSessionHasErrors(['dental' => 'Each treatment must be a service from the clinic\'s list.']);
});

test('a plan item cannot be completed through another patient\'s consultation', function () {
    $plan = app(DentalRecord::class)->proposePlan($this->encounter, 'Plan', [['clinical_procedure_id' => $this->rct->id, 'tooth_number' => 46]], null, $this->me);
    app(DentalRecord::class)->acceptPlan($plan);
    $item = $plan->records()->sole();
    $other = app(EncounterWorkspace::class)->startForPatient($this->f->patient('Other', 'Patient'), $this->f->branch, $this->me);

    ($this->asDoctor)()->post("/clinic/consultations/{$other->id}/dental/plans/{$plan->id}/items/{$item->id}/complete")->assertNotFound();

    expect($item->fresh()->status)->toBe(TreatmentStatus::Planned);
});

test('another doctor can read the chart but not write it', function () {
    app(DentalRecord::class)->recordFinding($this->encounter, 21, 'crown', [], null, $this->me);
    [$colleagueUser, $colleagueSession] = $this->f->memberWithRole(ClinicRole::Doctor);
    $this->f->doctor('Dr. Colleague', $colleagueUser);
    $asColleague = fn () => $this->actingAs($colleagueUser)->withSession($colleagueSession);

    $asColleague()->get("/clinic/consultations/{$this->encounter->id}")
        ->assertInertia(fn (Assert $page) => $page->where('dental.teeth.0.tooth', 21)->where('dental.can_write', false));
    $asColleague()->post("/clinic/consultations/{$this->encounter->id}/dental/chart", ['tooth' => 21, 'condition' => 'sound'])->assertForbidden();
});

test('the legacy tooth status writer still writes clinic-owned history', function () {
    $history = app(ToothStatusHistoryService::class)->logToothStatus($this->patient, 11, 'crown');

    expect($history->clinic_id)->toBe($this->f->clinic->id)
        ->and(TreatmentRecord::query()->count())->toBe(0);
});
