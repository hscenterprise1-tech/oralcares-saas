<?php

use App\ClinicRole;
use App\LabCaseStatus;
use App\Models\ClinicalProcedure;
use App\Models\ClinicalProcedurePrice;
use App\Models\EndodonticRecord;
use App\Models\ImplantRecord;
use App\Models\LabOrder;
use App\Models\OrthodonticCase;
use App\Models\PeriodontalExam;
use App\Models\SecurityAuditLog;
use App\Models\TreatmentPlan;
use App\Services\Clinical\EncounterWorkspace;
use App\Services\Dental\DentalRecord;
use Carbon\CarbonImmutable;
use Tests\Support\ReceptionFixture;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-03 10:00', 'Asia/Kolkata'));
    $this->f = ReceptionFixture::make();
    $this->f->clinic->update(['specialties' => ['dental']]);
    [$this->doctorUser, $this->doctorSession] = $this->f->memberWithRole(ClinicRole::Doctor);
    $this->me = $this->f->doctor('Dr. Me', $this->doctorUser);
    $this->patient = $this->f->patient('Ravi', 'Kumar');
    $this->actingAs($this->doctorUser);
    $this->encounter = app(EncounterWorkspace::class)->startForPatient($this->patient, $this->f->branch, $this->me);
    $this->asDoctor = fn () => $this->actingAs($this->doctorUser)->withSession($this->doctorSession);
    $this->c = fn (string $path) => "/clinic/consultations/{$this->encounter->id}/dental/{$path}";
});

test('a root canal record follows its stages, keeps its canals, and locks once completed', function () {
    ($this->asDoctor)()->post(($this->c)('endodontic'), ['tooth_number' => 46, 'pulp_status' => 'irreversible_pulpitis_symptomatic', 'stage' => 'access'])->assertSessionHasNoErrors();
    $record = EndodonticRecord::sole();

    ($this->asDoctor)()->post(($this->c)("endodontic/{$record->id}"), ['stage' => 'cleaning_shaping', 'canals' => [['name' => 'MB', 'working_length' => 20.5, 'master_file' => '30/.04'], ['name' => 'D', 'working_length' => 21]]])->assertSessionHasNoErrors();
    ($this->asDoctor)()->post(($this->c)("endodontic/{$record->id}"), ['canals' => [['name' => 'MB', 'working_length' => 55]]])->assertSessionHasErrors('dental');
    ($this->asDoctor)()->post(($this->c)("endodontic/{$record->id}"), ['stage' => 'restoration', 'status' => 'completed', 'obturation_technique' => 'Warm vertical'])->assertSessionHasNoErrors();

    expect($record->fresh())->status->toBe('completed')->stage->toBe('restoration')->canals->toHaveCount(2)->completed_at->not->toBeNull()
        ->and(SecurityAuditLog::where('event', 'like', 'dental.endodontic.%')->count())->toBe(3); // created, then two accepted updates
    ($this->asDoctor)()->post(($this->c)("endodontic/{$record->id}"), ['pulp_status' => 'necrotic'])->assertSessionHasErrors('dental');
    ($this->asDoctor)()->post(($this->c)("endodontic/{$record->id}"), ['review_on' => '2026-11-03'])->assertSessionHasNoErrors();
});

test('an implant needs its placement date once placed; one active orthodontic case at a time, with visits', function () {
    ($this->asDoctor)()->post(($this->c)('implants'), ['tooth_number' => 36, 'status' => 'placed'])->assertSessionHasErrors('dental');
    ($this->asDoctor)()->post(($this->c)('implants'), ['tooth_number' => 36, 'status' => 'placed', 'placed_on' => '2026-10-03', 'system' => 'Nobel Active', 'diameter_mm' => 4.3, 'length_mm' => 10])->assertSessionHasNoErrors();
    expect(ImplantRecord::sole())->system->toBe('Nobel Active')->diameter_mm->toBe(4.3);

    ($this->asDoctor)()->post(($this->c)('orthodontic'), ['appliance' => 'fixed_ceramic', 'arches' => 'both'])->assertSessionHasNoErrors();
    ($this->asDoctor)()->post(($this->c)('orthodontic'), ['appliance' => 'aligners'])->assertSessionHasErrors('dental');
    $case = OrthodonticCase::sole();
    ($this->asDoctor)()->post(($this->c)("orthodontic/{$case->id}/visit"), ['phase' => 'levelling', 'upper_wire' => '0.014 NiTi', 'next_visit_weeks' => 6])->assertSessionHasNoErrors();
    expect($case->fresh()->phase)->toBe('levelling')->and($case->visits()->count())->toBe(1);
});

test('lab work runs ordered → sent → received → fitted → completed; the desk sends and receives, only clinicians fit', function () {
    ($this->asDoctor)()->post(($this->c)('lab'), ['teeth' => [26], 'restoration' => 'Zirconia crown', 'shade' => 'A2', 'laboratory_name' => 'SmileLab'])->assertSessionHasNoErrors();
    $order = LabOrder::sole();
    expect($order->status)->toBe(LabCaseStatus::Draft)->and($order->shade)->toBe('A2');

    [$receptionUser, $receptionSession] = $this->f->memberWithRole(ClinicRole::Receptionist);
    $desk = fn () => $this->actingAs($receptionUser)->withSession($receptionSession);
    $step = fn (string $s) => "/clinic/patients/{$this->patient->id}/dental/lab/{$order->id}/{$s}";

    $desk()->post($step('send'))->assertSessionHasNoErrors();
    $desk()->post($step('receive'))->assertSessionHasNoErrors();
    $desk()->post($step('fit'))->assertForbidden();
    ($this->asDoctor)()->post($step('complete'))->assertSessionHasErrors('dental');
    ($this->asDoctor)()->post($step('fit'))->assertSessionHasNoErrors();
    ($this->asDoctor)()->post($step('complete'))->assertSessionHasNoErrors();

    expect($order->fresh())->status->toBe(LabCaseStatus::Completed)->sent_at->not->toBeNull()->received_at->not->toBeNull()->fitted_at->not->toBeNull();
    $desk()->getJson("/clinic/patients/{$this->patient->id}/360/dental")->assertJsonPath('view', 'operational')->assertJsonPath('lab.0.status_label', 'Completed');
});

test('a lab step on another patient\'s case is not found', function () {
    ($this->asDoctor)()->post(($this->c)('lab'), ['teeth' => [26], 'restoration' => 'Crown'])->assertSessionHasNoErrors();
    $other = $this->f->patient('Other', 'Person');
    ($this->asDoctor)()->post("/clinic/patients/{$other->id}/dental/lab/".LabOrder::sole()->id.'/send')->assertNotFound();
});

test('the periodontal exam computes whole-mouth figures from the sites recorded', function () {
    ($this->asDoctor)()->post(($this->c)('periodontal'), ['teeth' => [
        ['tooth' => 16, 'probing_depths' => [3, 2, 5, 4, 2, 3], 'bleeding' => [false, false, true, true, false, false], 'mobility' => 1],
        ['tooth' => 26, 'probing_depths' => [2, 2, 2, 2, 2, 7], 'bleeding' => [false, false, false, false, false, true]],
        ['tooth' => 36, 'missing' => true],
    ], 'stage' => 'III', 'grade' => 'B'])->assertSessionHasNoErrors();

    expect(PeriodontalExam::sole())->teeth_examined->toBe(2)->sites_4mm_plus->toBe(3)->sites_6mm_plus->toBe(1)->bop_percent->toBe(25.0)->stage->toBe('III');
    ($this->asDoctor)()->post(($this->c)('periodontal'), ['teeth' => [['tooth' => 16, 'probing_depths' => [16]]]])->assertSessionHasErrors();
});

test('a plan is estimated from the clinic price list, runs proposed → accepted → active, and can pause, resume and cancel', function () {
    $priced = ClinicalProcedure::create(['clinic_id' => $this->f->clinic->id, 'code' => 'CRN', 'name' => 'Crown', 'category' => 'Prosth', 'is_active' => true]);
    $unpriced = ClinicalProcedure::create(['clinic_id' => $this->f->clinic->id, 'code' => 'FIL', 'name' => 'Filling', 'category' => 'Rest', 'is_active' => true]);
    ClinicalProcedurePrice::create(['clinic_id' => $this->f->clinic->id, 'clinical_procedure_id' => $priced->id, 'price' => 8000]);
    $plan = app(DentalRecord::class)->proposePlan($this->encounter, 'Restore', [['clinical_procedure_id' => $priced->id, 'tooth_number' => 26], ['clinical_procedure_id' => $unpriced->id, 'tooth_number' => 36, 'surfaces' => ['O']]], null, $this->me);
    expect($plan->records()->orderBy('sort_order')->pluck('price')->map(fn ($p) => $p === null ? null : (float) $p)->all())->toBe([8000.0, null]);

    $t = fn (string $name, array $body = []) => ($this->asDoctor)()->post("/clinic/patients/{$this->patient->id}/dental/plans/{$plan->id}/{$name}", $body);
    $t('pause', ['reason' => 'x'])->assertSessionHasErrors();
    $t('accept')->assertSessionHasNoErrors();
    $t('pause', ['reason' => 'Patient travelling'])->assertSessionHasNoErrors();
    expect($plan->fresh()->status)->toBe(TreatmentPlan::PAUSED);
    ($this->asDoctor)()->post("/clinic/consultations/{$this->encounter->id}/dental/plans/{$plan->id}/items/{$plan->records()->first()->id}/complete")->assertSessionHasErrors('dental');
    $t('resume')->assertSessionHasNoErrors();
    expect($plan->fresh()->status)->toBe(TreatmentPlan::ACCEPTED);
    $t('cancel', ['reason' => 'Changed clinic'])->assertSessionHasNoErrors();
    expect($plan->fresh())->status->toBe(TreatmentPlan::CANCELLED)->status_reason->toBe('Changed clinic');

    [$receptionUser, $receptionSession] = $this->f->memberWithRole(ClinicRole::Receptionist);
    $this->actingAs($receptionUser)->withSession($receptionSession)->post("/clinic/patients/{$this->patient->id}/dental/plans/{$plan->id}/resume")->assertForbidden();
});

test('ongoing treatment lists only teeth with live treatment, and each context gets its own teeth from the server', function () {
    $service = ClinicalProcedure::create(['clinic_id' => $this->f->clinic->id, 'code' => 'CRN', 'name' => 'Crown', 'category' => 'Prosth', 'is_active' => true]);
    app(DentalRecord::class)->recordFinding($this->encounter, 11, 'filled', ['M'], null, $this->me);
    $plan = app(DentalRecord::class)->proposePlan($this->encounter, 'Crown 26', [['clinical_procedure_id' => $service->id, 'tooth_number' => 26]], null, $this->me);
    ($this->asDoctor)()->post(($this->c)('endodontic'), ['tooth_number' => 46, 'stage' => 'access'])->assertSessionHasNoErrors();
    ($this->asDoctor)()->post(($this->c)('implants'), ['tooth_number' => 36, 'status' => 'planned'])->assertSessionHasNoErrors();

    $json = ($this->asDoctor)()->getJson("/clinic/patients/{$this->patient->id}/360/dental")->assertOk()->json();
    expect(collect($json['ongoing'])->pluck('tooth')->sort()->values()->all())->toBe([26, 36, 46])
        ->and($json['states']['11'])->toBe('restored')->and($json['states']['46'])->toBe('in_progress')->and($json['states']['26'])->toBe('planned')
        ->and($json['contexts']['endodontic'])->toBe([46])->and($json['contexts']['implant'])->toBe([36])
        ->and($json['contexts']['plans'][(string) $plan->id])->toBe([26]);
});

test('specialty records are written only in an open consultation of a dental branch', function () {
    $workspace = app(EncounterWorkspace::class);
    $workspace->autosave($this->encounter, ['chief_complaint' => 'Pain'], 0);
    $workspace->finalize($this->encounter, $this->me);
    // The consultation policy refuses a signed consultation before any write.
    ($this->asDoctor)()->post(($this->c)('endodontic'), ['tooth_number' => 46])->assertForbidden();
    expect(EndodonticRecord::count())->toBe(0);

    $this->f->clinic->update(['specialties' => ['physiotherapy']]);
    $open = $workspace->startForPatient($this->patient, $this->f->branch, $this->me);
    ($this->asDoctor)()->post("/clinic/consultations/{$open->id}/dental/implants", ['tooth_number' => 36])->assertForbidden();
});
