<?php

use App\Models\Clinic;
use App\Models\Patient;
use App\Services\Patient\UhidGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->clinic = Clinic::factory()->create();
    $this->generator = app(UhidGenerator::class);
});

it('generates a correctly formatted UHID', function () {
    $patient = Patient::factory()->create(['clinic_id' => $this->clinic->id, 'uhid' => null]);

    $uhid = $this->generator->generate($this->clinic);

    expect($uhid)->toStartWith('OC-')
        ->and(strlen($uhid))->toBe(11); // OC- (3) + 8 digits
});

it('generates sequential UHIDs within the same clinic', function () {
    $first = $this->generator->generate($this->clinic);
    Patient::factory()->create(['clinic_id' => $this->clinic->id, 'uhid' => $first]);

    $second = $this->generator->generate($this->clinic);
    Patient::factory()->create(['clinic_id' => $this->clinic->id, 'uhid' => $second]);

    expect($first)->toBe('OC-00000001')
        ->and($second)->toBe('OC-00000002');
});

it('generates UHIDs independently per clinic', function () {
    $clinic2 = Clinic::factory()->create();

    $uhidClinic1 = $this->generator->generate($this->clinic);
    Patient::factory()->create(['clinic_id' => $this->clinic->id, 'uhid' => $uhidClinic1]);

    $uhidClinic2 = $this->generator->generate($clinic2);
    Patient::factory()->create(['clinic_id' => $clinic2->id, 'uhid' => $uhidClinic2]);

    // Both start at 00000001 since they are separate clinics
    expect($uhidClinic1)->toBe('OC-00000001')
        ->and($uhidClinic2)->toBe('OC-00000001');
});

it('generates a unique UHID per patient and it never changes', function () {
    $uhid = $this->generator->generate($this->clinic);
    $patient = Patient::factory()->create(['clinic_id' => $this->clinic->id, 'uhid' => $uhid]);

    $patientId = $patient->id;
    $originalUhid = $patient->uhid;

    // Simulate multiple visits — UHID must not change
    $patient->update(['gender' => 'female', 'age' => 30]);

    $reloadedPatient = Patient::withoutGlobalScopes()->find($patientId);

    expect($reloadedPatient->uhid)->toBe($originalUhid);
});
