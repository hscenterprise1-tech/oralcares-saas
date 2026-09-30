<?php

use App\Models\Branch;
use App\Models\Clinic;
use App\Models\Patient;
use App\Models\PatientVisit;
use App\Services\Patient\CaseNumberGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->clinic = Clinic::factory()->create();
    $this->branch = Branch::factory()->create(['clinic_id' => $this->clinic->id]);
    $this->patient = Patient::factory()->create([
        'clinic_id' => $this->clinic->id,
        'uhid' => 'OC-00000001',
    ]);
    $this->visit = PatientVisit::factory()->create([
        'clinic_id' => $this->clinic->id,
        'branch_id' => $this->branch->id,
        'patient_id' => $this->patient->id,
    ]);
    $this->generator = app(CaseNumberGenerator::class);
});

it('generates a case number with the correct format', function () {
    $caseNumber = $this->generator->forPatient($this->clinic, $this->patient, CarbonImmutable::create(2026, 6, 1));

    expect($caseNumber->case_number)->toBe('OPD-2026-000001')
        ->and($caseNumber->year)->toBe(2026)
        ->and($caseNumber->sequence)->toBe(1);
});

it('increments the sequence within the same year', function () {
    $patient2 = Patient::factory()->create([
        'clinic_id' => $this->clinic->id,
        'uhid' => 'OC-00000002',
    ]);
    $visit2 = PatientVisit::factory()->create([
        'clinic_id' => $this->clinic->id,
        'branch_id' => $this->branch->id,
        'patient_id' => $patient2->id,
    ]);

    $first = $this->generator->forPatient($this->clinic, $this->patient, CarbonImmutable::create(2026, 6, 1));
    $second = $this->generator->forPatient($this->clinic, $patient2, CarbonImmutable::create(2026, 6, 1));

    expect($first->case_number)->toBe('OPD-2026-000001')
        ->and($second->case_number)->toBe('OPD-2026-000002');
});

it('resets the sequence at the start of a new year', function () {
    $firstYear = $this->generator->forPatient($this->clinic, $this->patient, CarbonImmutable::create(2026, 6, 1));

    $visit2026 = PatientVisit::factory()->create([
        'clinic_id' => $this->clinic->id,
        'branch_id' => $this->branch->id,
        'patient_id' => $this->patient->id,
    ]);
    $secondYear = $this->generator->forPatient($this->clinic, $this->patient, CarbonImmutable::create(2027, 6, 1));

    expect($firstYear->case_number)->toBe('OPD-2026-000001')
        ->and($secondYear->case_number)->toBe('OPD-2027-000001');
});

it('allows the same patient to have multiple yearly case numbers', function () {
    $cn2026 = $this->generator->forPatient($this->clinic, $this->patient, CarbonImmutable::create(2026, 6, 1));

    $visit2027 = PatientVisit::factory()->create([
        'clinic_id' => $this->clinic->id,
        'branch_id' => $this->branch->id,
        'patient_id' => $this->patient->id,
    ]);
    $cn2027 = $this->generator->forPatient($this->clinic, $this->patient, CarbonImmutable::create(2027, 6, 1));

    expect($cn2026->patient_id)->toBe($this->patient->id)
        ->and($cn2027->patient_id)->toBe($this->patient->id)
        ->and($cn2026->case_number)->not->toBe($cn2027->case_number);
});

it('keeps the UHID unchanged when a new case number is generated', function () {
    $this->generator->forPatient($this->clinic, $this->patient, CarbonImmutable::create(2026, 6, 1));

    $freshPatient = Patient::withoutGlobalScopes()->find($this->patient->id);
    expect($freshPatient->uhid)->toBe('OC-00000001');
});
