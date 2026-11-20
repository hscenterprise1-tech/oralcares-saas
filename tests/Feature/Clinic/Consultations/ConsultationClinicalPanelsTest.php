<?php

use App\ClinicRole;
use App\Models\ClinicalProcedure;
use App\Services\Clinical\EncounterWorkspace;
use App\Services\Patient\PatientBranchActivityRecorder;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\ReceptionFixture;

/**
 * The consultation screen's dental panel writes through the links the server
 * gives it: a finding, a plan item done with its resulting condition, gum
 * charting and a lab case — each persisted, and each visible again in the
 * consultation and in Patient 360's Dental section.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-26 10:00', 'Asia/Kolkata'));
    $this->f = ReceptionFixture::make();
    $this->f->clinic->update(['specialties' => ['dental']]);
    [$this->doctorUser, $this->doctorSession] = $this->f->memberWithRole(ClinicRole::Doctor);
    $this->me = $this->f->doctor('Dr. Me', $this->doctorUser);
    $this->patient = $this->f->patient('Rahul', 'Sharma');
    app(PatientBranchActivityRecorder::class)->record($this->f->clinic->id, $this->patient->id, $this->f->branch->id, now(), 'registration');
    $this->actingAs($this->doctorUser);
    $this->encounter = app(EncounterWorkspace::class)->startForPatient($this->patient, $this->f->branch, $this->me);
    $this->asDoctor = fn () => $this->actingAs($this->doctorUser)->withSession($this->doctorSession);
    $this->filling = ClinicalProcedure::create(['clinic_id' => $this->f->clinic->id, 'code' => 'FIL', 'name' => 'Composite filling', 'category' => 'Restorative', 'is_active' => true]);
    $this->dental = function (): array {
        $dental = [];
        ($this->asDoctor)()->get("/clinic/consultations/{$this->encounter->id}?record=1")->assertOk()->assertInertia(function (Assert $page) use (&$dental) {
            $dental = $page->toArray()['props']['dental'];
        });

        return $dental;
    };
});

test('the dental panel records a finding, a plan done today, gum charting and a lab case, and they persist', function () {
    $dental = ($this->dental)();
    expect($dental['links'])->toHaveKeys(['chart', 'plans', 'periodontal', 'lab', 'periodontal_vocabulary'])
        ->and($dental['links']['periodontal_vocabulary']['stages'])->toContain('III');

    ($this->asDoctor)()->post($dental['links']['chart'], ['tooth' => 36, 'condition' => 'caries', 'surfaces' => ['O'], 'note' => 'Deep lesion'])->assertSessionHasNoErrors();
    ($this->asDoctor)()->post($dental['links']['plans'], ['title' => 'Restore 36', 'items' => [['clinical_procedure_id' => $this->filling->id, 'tooth_number' => 36, 'surfaces' => ['O']]]])->assertSessionHasNoErrors();
    $plan = ($this->dental)()['plans'][0];
    ($this->asDoctor)()->post($plan['accept_url'])->assertSessionHasNoErrors();
    $item = ($this->dental)()['plans'][0]['items'][0];
    ($this->asDoctor)()->post($item['complete_url'], ['resulting_condition' => 'filled', 'note' => 'Composite A2'])->assertSessionHasNoErrors();
    ($this->asDoctor)()->post($dental['links']['periodontal'], ['teeth' => [['tooth' => 36, 'probing_depths' => [3, 4, 5, 3, 3, 4], 'bleeding' => [false, true, true, false, false, false], 'mobility' => 1]], 'stage' => 'III', 'grade' => 'B'])->assertSessionHasNoErrors();
    ($this->asDoctor)()->post($dental['links']['lab'], ['teeth' => [36], 'restoration' => 'Zirconia crown', 'laboratory_name' => 'SmileLab', 'delivery_due_date' => '2026-11-02'])->assertSessionHasNoErrors();

    $again = ($this->dental)();
    // The tooth is now filled; the plan, all done, leaves the consultation's open plans.
    expect(collect($again['teeth'])->firstWhere('tooth', 36))->toMatchArray(['status' => 'filled'])
        ->and($again['plans'])->toBe([]);

    $section = ($this->asDoctor)()->getJson(route('clinic.patients.360.section', [$this->patient->id, 'dental']))->assertOk()->json();
    expect($section['periodontal']['latest'])->not->toBeNull()
        ->and(collect($section['lab'])->pluck('restoration')->all())->toContain('Zirconia crown')
        ->and(collect($section['procedures'])->pluck('name')->all())->toContain('Composite filling')
        ->and(collect($section['plans'])->firstWhere('title', 'Restore 36')['items'][0]['status'])->toBe('completed');
});

test('a dental panel write to another patient\'s consultation, or by reception, is refused', function () {
    $dental = ($this->dental)();
    [$reception, $session] = $this->f->memberWithRole(ClinicRole::Receptionist);
    $this->actingAs($reception)->withSession($session)->post($dental['links']['chart'], ['tooth' => 36, 'condition' => 'caries'])->assertForbidden();

    $other = ReceptionFixture::make('other-clinic');
    $this->actingAs($other->owner)->withSession($other->session)->post($dental['links']['lab'], ['teeth' => [36], 'restoration' => 'Crown'])->assertForbidden();
});
