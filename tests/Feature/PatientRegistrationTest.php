<?php

use App\Models\Clinic;
use App\Models\Patient;
use App\Services\PatientProfileService;
use App\Services\PatientRegistrationService;
use Database\Seeders\SystemRoleTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(SystemRoleTemplateSeeder::class);
});

test('successful patient registration seeds profile and syncs medical alerts', function () {
    $clinic = Clinic::factory()->create();
    $regService = app(PatientRegistrationService::class);

    $patient = $regService->registerPatient(
        $clinic,
        [
            'name' => 'John Doe',
            'email' => 'john.doe@example.com',
            'phone' => '9876543210',
            'date_of_birth' => '1990-05-15',
            'gender' => 'male',
        ],
        [
            'preferred_name' => 'Johnny',
            'secondary_phone' => '1234567890',
            'address' => '123 Main St',
            'city' => 'New York',
        ],
        [
            'diabetes' => true,
            'cardiac_condition' => true,
            'allergies' => ['Penicillin', 'Sulfa Drugs'],
        ]
    );

    expect($patient->patient_number)->toBe("PT-{$clinic->id}-00001")
        ->and($patient->status)->toBe('active');

    // Verify Profile
    expect($patient->profile)->not->toBeNull()
        ->and($patient->profile->preferred_name)->toBe('Johnny')
        ->and($patient->profile->city)->toBe('New York');

    // Verify Age calculation
    $age = app(PatientProfileService::class)->getAge($patient);
    expect($age)->toBe(36); // 2026 - 1990 = 36

    // Verify Medical Alert synchronization
    expect($patient->alerts)->toHaveCount(4)
        ->and($patient->alerts->pluck('description'))->toContain(
            'Diabetic Alert',
            'Cardiac Condition Alert',
            'Allergy: Penicillin',
            'Allergy: Sulfa Drugs'
        );
});

test('duplicate detection catches identical entries and allows override', function () {
    $clinic = Clinic::factory()->create();
    $regService = app(PatientRegistrationService::class);

    // Register Patient 1
    $regService->registerPatient($clinic, [
        'name' => 'Jane Doe',
        'email' => 'jane.doe@example.com',
        'phone' => '9876543210',
    ]);

    // Register Patient 2 with same phone: should fail by default
    $failed = false;
    try {
        $regService->registerPatient($clinic, [
            'name' => 'Jane Smith',
            'phone' => '9876543210',
        ]);
    } catch (InvalidArgumentException $e) {
        $failed = true;
    }
    expect($failed)->toBeTrue();

    // Forced registration bypasses duplicate detection
    $forcedPatient = $regService->registerPatient($clinic, [
        'name' => 'Jane Smith',
        'phone' => '9876543210',
    ], [], [], true);

    expect($forcedPatient->patient_number)->toBe("PT-{$clinic->id}-00002");
});

test('patient records are archived and never physically deleted', function () {
    $clinic = Clinic::factory()->create();
    $regService = app(PatientRegistrationService::class);

    $patient = $regService->registerPatient($clinic, [
        'name' => 'Archive Test',
        'phone' => '5551234',
    ]);

    expect($patient->status)->toBe('active');

    // Archive
    $regService->archivePatient($patient);

    expect($patient->fresh()->status)->toBe('archived');

    // Database still contains the record
    $this->assertDatabaseHas('patients', [
        'id' => $patient->id,
        'name' => 'Archive Test',
    ]);
});
