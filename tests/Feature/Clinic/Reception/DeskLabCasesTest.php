<?php

use App\ClinicRole;
use App\LabCaseStatus;
use App\Models\LabOrder;
use App\Services\Clinical\EncounterWorkspace;
use App\Services\Reception\FrontDesk;
use Carbon\CarbonImmutable;
use Tests\Support\ReceptionFixture;

/*
 * The desk sees the dental lab work it has to move: made but not sent, out with
 * the lab (late ones flagged), and arrived and waiting for the patient to be called in.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-03 10:00', 'Asia/Kolkata'));
    $this->f = ReceptionFixture::make();
    $this->f->clinic->update(['specialties' => ['dental']]);
    [$this->doctorUser, $this->doctorSession] = $this->f->memberWithRole(ClinicRole::Doctor);
    $this->me = $this->f->doctor('Dr. Me', $this->doctorUser);
    $this->patient = $this->f->patient('Ravi', 'Kumar');
    $this->actingAs($this->doctorUser);
    $encounter = app(EncounterWorkspace::class)->startForPatient($this->patient, $this->f->branch, $this->me);
    $this->actingAs($this->doctorUser)->withSession($this->doctorSession)
        ->post("/clinic/consultations/{$encounter->id}/dental/lab", ['teeth' => [26], 'restoration' => 'Zirconia crown', 'laboratory_name' => 'SmileLab'])->assertSessionHasNoErrors();
    $this->order = LabOrder::withoutGlobalScopes()->sole();
    [$this->deskUser, $this->deskSession] = $this->f->memberWithRole(ClinicRole::Receptionist);
    $this->asDesk = fn () => $this->actingAs($this->deskUser)->withSession($this->deskSession);
    $this->lab = function () {
        $lab = null;
        ($this->asDesk)()->get(route('clinic.reception'))->assertInertia(function ($page) use (&$lab) {
            $lab = $page->toArray()['props']['lab'] ?? null;
        });

        return $lab;
    };
});

test('a made lab case is offered to be sent, then shows as out with the lab, then as arrived to call the patient', function () {
    $lab = ($this->lab)();
    expect($lab['rows'][0]['state'])->toBe('to_send')->and($lab['rows'][0]['steps'])->toHaveKey('send')->and($lab['rows'][0]['what'])->toContain('Zirconia crown')->toContain('26');

    ($this->asDesk)()->post($lab['rows'][0]['steps']['send'])->assertSessionHasNoErrors();
    $lab = ($this->lab)();
    expect($lab['rows'][0]['state'])->toBe('with_lab')->and($lab['count']['with_lab'])->toBe(1)->and($lab['rows'][0]['steps'])->toHaveKey('receive');

    ($this->asDesk)()->post($lab['rows'][0]['steps']['receive'])->assertSessionHasNoErrors();
    $lab = ($this->lab)();
    expect($lab['rows'][0]['state'])->toBe('arrived')->and($lab['count']['arrived'])->toBe(1)->and($lab['rows'][0]['steps'])->toBe([]);
});

test('a case past its due date is flagged late, and arrived cases come before late ones', function () {
    ($this->asDesk)()->post(route('clinic.patients.dental.lab.step', [$this->patient->id, $this->order->id, 'send']))->assertSessionHasNoErrors();
    $this->order->forceFill(['delivery_due_date' => '2026-10-01'])->save();
    $late = ($this->lab)();
    expect($late['rows'][0]['late'])->toBeTrue()->and($late['count']['late'])->toBe(1);

    $second = LabOrder::withoutGlobalScopes()->create([
        'clinic_id' => $this->order->clinic_id, 'branch_id' => $this->order->branch_id, 'patient_id' => $this->patient->id, 'lab_order_number' => 'LAB-X2',
        'status' => LabCaseStatus::Delivered, 'restoration' => 'Bridge', 'requested_teeth' => [14, 15, 16],
    ]);
    $rows = ($this->lab)()['rows'];

    expect($rows[0]['id'])->toBe($second->id)->and($rows[0]['state'])->toBe('arrived')->and($rows[1]['late'])->toBeTrue();
});

test('with no open lab case, or for a member who does not work the queue, nothing is sent', function () {
    $this->order->forceFill(['status' => LabCaseStatus::Completed])->save();
    expect(($this->lab)())->toBeNull();

    $this->order->forceFill(['status' => LabCaseStatus::Draft])->save();
    [$billing] = $this->f->memberWithRole(ClinicRole::BillingStaff);
    expect(app(FrontDesk::class)->labCases($this->f->clinic, $this->f->branch, $billing))->toBeNull();
});
