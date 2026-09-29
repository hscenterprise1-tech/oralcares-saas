<?php

use App\Models\Branch;
use App\Models\CaseNumber;
use App\Models\Clinic;
use App\Models\Patient;
use App\Models\PatientVisit;
use App\Services\Patient\PatientSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->clinic = Clinic::factory()->create();
    $this->branch = Branch::factory()->create(['clinic_id' => $this->clinic->id]);
    $this->service = app(PatientSearchService::class);
});

it('finds a patient by mobile number', function () {
    $patient = Patient::factory()->create([
        'clinic_id' => $this->clinic->id,
        'uhid' => 'OC-00000001',
        'phone' => '9876543210',
    ]);

    $results = $this->service->search($this->clinic, '9876543210', 'mobile');

    expect($results)->toHaveCount(1)
        ->and($results->first()->id)->toBe($patient->id);
});

it('finds a patient by partial name', function () {
    Patient::factory()->create([
        'clinic_id' => $this->clinic->id,
        'uhid' => 'OC-00000001',
        'first_name' => 'Rahul',
        'last_name' => 'Sharma',
        'name' => 'Rahul Sharma',
    ]);

    $results = $this->service->search($this->clinic, 'Rahul', 'name');

    expect($results)->toHaveCount(1)
        ->and($results->first()->first_name)->toBe('Rahul');
});

it('finds a patient by exact UHID', function () {
    $patient = Patient::factory()->create([
        'clinic_id' => $this->clinic->id,
        'uhid' => 'OC-00000005',
    ]);

    $results = $this->service->search($this->clinic, 'OC-00000005', 'uhid');

    expect($results)->toHaveCount(1)
        ->and($results->first()->uhid)->toBe('OC-00000005');
});

it('finds a patient by their previous year case number', function () {
    $patient = Patient::factory()->create([
        'clinic_id' => $this->clinic->id,
        'uhid' => 'OC-00000001',
    ]);
    $visit = PatientVisit::factory()->create([
        'clinic_id' => $this->clinic->id,
        'branch_id' => $this->branch->id,
        'patient_id' => $patient->id,
    ]);
    CaseNumber::create([
        'clinic_id' => $this->clinic->id,
        'patient_id' => $patient->id,
        'patient_visit_id' => $visit->id,
        'year' => 2025,
        'sequence' => 1,
        'case_number' => '2025-000001',
    ]);

    $results = $this->service->search($this->clinic, '2025-000001', 'case_number');

    expect($results)->toHaveCount(1)
        ->and($results->first()->id)->toBe($patient->id);
});

it('does not return patients from other clinics', function () {
    $otherClinic = Clinic::factory()->create();
    Patient::factory()->create([
        'clinic_id' => $otherClinic->id,
        'uhid' => 'OC-00000001',
        'phone' => '9999999999',
    ]);

    $results = $this->service->search($this->clinic, '9999999999', 'mobile');

    expect($results)->toHaveCount(0);
});
