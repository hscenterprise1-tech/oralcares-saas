<?php

use App\Models\Patient;
use App\Models\PatientClinicalEntry;
use App\Models\PatientMedicalHistory;
use App\Models\PatientProfile;
use App\Support\Clinical\DefaultClinicalCatalogue;
use Carbon\CarbonImmutable;
use Tests\Support\ReceptionFixture;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-30 10:00', 'Asia/Kolkata'));
    $this->f = ReceptionFixture::make();
    $this->asOwner = fn () => $this->actingAs($this->f->owner)->withSession($this->f->session);
});

test('patient create page renders with colourful sections and required props', function () {
    ($this->asOwner)()->get('/clinic/patients/create')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('ClinicPortal/Patients/Create')
            ->has('specialties')
            ->has('practitioners')
            ->has('form.fields')
            ->has('form.catalogue.conditions')
            ->has('form.catalogue.allergies')
            ->has('links.index')
        );
});

test('patient creation persists identity, contact, and auto-generates UHID', function () {
    $payload = [
        'surface' => 'full_page',
        'first_name' => 'Aditya',
        'middle_name' => 'Kumar',
        'last_name' => 'Verma',
        'gender' => 'male',
        'date_of_birth' => '1995-05-15',
        'blood_group' => 'O+',
        'phone' => '9876501234',
        'email' => 'aditya.verma@example.com',
        'address' => 'Flat 301, Lakeview Tower',
        'city' => 'Bengaluru',
        'state' => 'Karnataka',
        'pincode' => '560001',
    ];

    $response = ($this->asOwner)()->post('/clinic/patients', $payload);

    $response->assertSessionHasNoErrors();
    $patient = Patient::where('phone', '9876501234')->first();
    expect($patient)->not->toBeNull();
    expect($patient->first_name)->toBe('Aditya');
    expect($patient->middle_name)->toBe('Kumar');
    expect($patient->last_name)->toBe('Verma');
    expect($patient->gender)->toBe('male');
    expect($patient->blood_group)->toBe('O+');
    expect($patient->uhid)->not->toBeNull();

    $profile = PatientProfile::where('patient_id', $patient->id)->first();
    expect($profile)->not->toBeNull();
    expect($profile->address)->toBe('Flat 301, Lakeview Tower');
    expect($profile->city)->toBe('Bengaluru');
    expect($profile->pincode)->toBe('560001');
});

test('patient creation with approximate age when DOB is unknown', function () {
    $payload = [
        'surface' => 'full_page',
        'first_name' => 'Suman',
        'last_name' => 'Devi',
        'gender' => 'female',
        'age' => 45,
        'phone' => '9876505678',
    ];

    ($this->asOwner)()->post('/clinic/patients', $payload)->assertSessionHasNoErrors();

    $patient = Patient::where('phone', '9876505678')->first();
    expect($patient)->not->toBeNull();
    expect($patient->date_of_birth)->toBeNull();
    expect($patient->age)->toBe(45);
});

test('duplicate patient detection flags matching phone and requires override acknowledgment', function () {
    $existing = $this->f->patient('Pooja', 'Hegde', ['phone' => '9888877777']);

    // Check duplicate endpoint
    $check = ($this->asOwner)()->postJson('/clinic/patients/check-duplicate', ['phone' => '9888877777']);
    $check->assertOk()->assertJsonPath('has_duplicates', true);
    expect($check->json('matches.0.id'))->toBe($existing->id);

    // Register without acknowledgment fails
    ($this->asOwner)()->post('/clinic/patients', [
        'surface' => 'full_page',
        'first_name' => 'Pooja',
        'last_name' => 'Hegde',
        'gender' => 'female',
        'phone' => '9888877777',
    ])->assertSessionHas('possible_duplicates');

    // Register with acknowledgment and valid reason succeeds
    ($this->asOwner)()->post('/clinic/patients', [
        'surface' => 'full_page',
        'first_name' => 'Pooja',
        'last_name' => 'Hegde',
        'gender' => 'female',
        'phone' => '9888877777',
        'duplicate_acknowledged_ids' => [$existing->id],
        'duplicate_reason' => 'Family member sharing same mobile line',
    ])->assertSessionHasNoErrors();

    expect(Patient::where('phone', '9888877777')->count())->toBe(2);
});

test('patient creation records medical conditions, allergies, and notes', function () {
    $payload = [
        'surface' => 'full_page',
        'first_name' => 'Vikram',
        'last_name' => 'Rathore',
        'gender' => 'male',
        'phone' => '9812345678',
        'medical_conditions' => [
            ['key' => 'diabetes', 'label' => 'Diabetes'],
            ['key' => 'hypertension', 'label' => 'Hypertension'],
        ],
        'allergies' => [
            ['label' => 'Penicillin'],
        ],
        'medications' => [
            ['label' => 'Metformin 500mg'],
        ],
        'notes' => 'Patient has mild asthma during seasonal changes.',
    ];

    ($this->asOwner)()->post('/clinic/patients', $payload)->assertSessionHasNoErrors();

    $patient = Patient::where('phone', '9812345678')->first();
    expect($patient)->not->toBeNull();

    $conditions = PatientClinicalEntry::where('patient_id', $patient->id)
        ->where('kind', DefaultClinicalCatalogue::KIND_CONDITION)
        ->pluck('label')
        ->all();
    expect($conditions)->toContain('Diabetes');
    expect($conditions)->toContain('Hypertension');

    $allergies = PatientClinicalEntry::where('patient_id', $patient->id)
        ->where('kind', DefaultClinicalCatalogue::KIND_ALLERGY)
        ->pluck('label')
        ->all();
    expect($allergies)->toContain('Penicillin');

    $history = PatientMedicalHistory::where('patient_id', $patient->id)->first();
    expect($history)->not->toBeNull();
    expect($history->notes)->toBe('Patient has mild asthma during seasonal changes.');
});

test('patient edit page renders with protected doctor badge, documents, and existing data', function () {
    $patient = $this->f->patient('Ramesh', 'Gupta', [
        'phone' => '9900011223',
        'gender' => 'male',
        'blood_group' => 'B+',
    ]);

    ($this->asOwner)()->get("/clinic/patients/{$patient->id}/edit")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('ClinicPortal/Patients/Edit')
            ->where('patient.first_name', 'Ramesh')
            ->where('patient.last_name', 'Gupta')
            ->where('patient.phone', '9900011223')
            ->where('patient.gender', 'male')
            ->where('patient.blood_group', 'B+')
            ->has('patient.uhid')
            ->has('patient.registered_on')
            ->has('patient.documents')
            ->has('clinical.can_edit')
        );
});

test('safe partial updates: omitted fields are preserved and not overwritten with null', function () {
    $patient = $this->f->patient('Sunil', 'Gavaskar', [
        'phone' => '9822233344',
        'gender' => 'male',
        'blood_group' => 'AB+',
        'date_of_birth' => '1980-07-10',
    ]);
    $patient->profile()->create([
        'organization_id' => $this->f->clinic->organization_id,
        'clinic_id' => $this->f->clinic->id,
        'address' => 'Old Address 123',
        'city' => 'Pune',
        'pincode' => '411001',
    ]);

    $originalUhid = $patient->uhid;
    $originalCreated = $patient->created_at;

    // Partial update only changing phone and city, while sending existing required fields
    $updatePayload = [
        'first_name' => 'Sunil',
        'last_name' => 'Gavaskar',
        'gender' => 'male',
        'phone' => '9822299999',
        'city' => 'Nagpur',
    ];

    ($this->asOwner)()->put("/clinic/patients/{$patient->id}", $updatePayload)->assertSessionHasNoErrors();

    $patient->refresh();
    expect($patient->phone)->toBe('9822299999');
    expect($patient->blood_group)->toBe('AB+'); // preserved
    expect($patient->date_of_birth?->format('Y-m-d'))->toBe('1980-07-10'); // preserved
    expect($patient->uhid)->toBe($originalUhid); // immutable

    $profile = $patient->profile;
    expect($profile->city)->toBe('Nagpur'); // updated
    expect($profile->address)->toBe('Old Address 123'); // preserved
    expect($profile->pincode)->toBe('411001'); // preserved
});

test('permanent default doctor cannot be changed via demographic patient edit', function () {
    $doctor1 = $this->f->doctor('Dr. Mehta');
    $doctor2 = $this->f->doctor('Dr. Patel');

    $patient = $this->f->patient('Karan', 'Johar', [
        'current_practitioner_id' => $doctor1->id,
        'gender' => 'male',
    ]);

    // Attempting to send doctor_id in update should be ignored
    ($this->asOwner)()->put("/clinic/patients/{$patient->id}", [
        'first_name' => 'Karan',
        'last_name' => 'Johar',
        'gender' => 'male',
        'phone' => '9811122233',
        'current_practitioner_id' => $doctor2->id,
        'doctor_id' => $doctor2->id,
    ])->assertSessionHasNoErrors();

    $patient->refresh();
    expect($patient->current_practitioner_id)->toBe($doctor1->id);
});

test('tenant and branch isolation prevents cross-clinic access to patient create and edit', function () {
    $otherFixture = ReceptionFixture::make('other-clinic');
    $otherPatient = $otherFixture->patient('Cross', 'Clinic');

    // Our owner cannot access other clinic's patient edit
    ($this->asOwner)()->get("/clinic/patients/{$otherPatient->id}/edit")->assertNotFound();
    ($this->asOwner)()->put("/clinic/patients/{$otherPatient->id}", [
        'first_name' => 'Hacked',
        'last_name' => 'Clinic',
        'gender' => 'male',
        'phone' => '9811199999',
    ])->assertForbidden();
});
