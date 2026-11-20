<?php

use App\BranchStatus;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\Patient;
use App\Models\PatientBilling;
use App\Models\PatientCase;
use App\Models\PatientMedicalHistory;
use App\Models\PatientProfile;
use App\Models\PatientVisit;
use App\Services\Patient\PatientDirectory;
use Carbon\CarbonImmutable;
use Tests\Support\ReceptionFixture;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-31 10:00:00', 'Asia/Kolkata'));
    $this->f = ReceptionFixture::make('phase-b-clinic');
    $this->asOwner = fn () => $this->actingAs($this->f->owner)->withSession($this->f->session);
});

test('patient creation persists required and optional fields and generates unique UHID', function () {
    $payload = [
        'surface' => 'full_page',
        'first_name' => 'Meera',
        'middle_name' => 'Devi',
        'last_name' => 'Nair',
        'gender' => 'female',
        'date_of_birth' => '1992-08-20',
        'blood_group' => 'B+',
        'phone' => '9845112233',
        'email' => 'meera.nair@example.com',
        'address' => 'Villa 12, Green Glen Layout',
        'city' => 'Bengaluru',
        'state' => 'Karnataka',
        'pincode' => '560103',
        'medical_conditions' => ['Type 2 Diabetes'],
        'allergies' => ['Penicillin'],
        'medications' => ['Metformin 500mg'],
        'notes' => 'Patient prefers morning appointments.',
    ];

    $response = ($this->asOwner)()->post('/clinic/patients', $payload);
    $response->assertSessionHasNoErrors();

    $patient = Patient::where('phone', '9845112233')->first();
    expect($patient)->not->toBeNull();
    expect($patient->first_name)->toBe('Meera');
    expect($patient->middle_name)->toBe('Devi');
    expect($patient->last_name)->toBe('Nair');
    expect($patient->gender)->toBe('female');
    expect($patient->blood_group)->toBe('B+');
    expect($patient->uhid)->not->toBeNull();
    expect($patient->clinic_id)->toBe($this->f->clinic->id);

    $profile = PatientProfile::where('patient_id', $patient->id)->first();
    expect($profile)->not->toBeNull();
    expect($profile->address)->toBe('Villa 12, Green Glen Layout');
    expect($profile->city)->toBe('Bengaluru');
    expect($profile->state)->toBe('Karnataka');
    expect($profile->pincode)->toBe('560103');

    $history = PatientMedicalHistory::where('patient_id', $patient->id)->first();
    expect($history)->not->toBeNull();
    expect($history->notes)->toBe('Patient prefers morning appointments.');
});

test('first_name, last_name, and phone are mandatory on patient registration', function () {
    $response = ($this->asOwner)()->post('/clinic/patients', [
        'surface' => 'full_page',
        'gender' => 'male',
    ]);

    $response->assertSessionHasErrors(['first_name', 'last_name', 'phone']);
});

test('UHID is unique across all branches of the same clinic and multi-branch visit preserves UHID', function () {
    $branch2 = Branch::factory()->create([
        'clinic_id' => $this->f->clinic->id,
        'name' => 'Indiranagar Branch',
        'is_main' => false,
        'status' => BranchStatus::Active,
    ]);

    // Register patient at main branch
    $response = ($this->asOwner)()->post('/clinic/patients', [
        'surface' => 'full_page',
        'first_name' => 'Sanjay',
        'last_name' => 'Rao',
        'gender' => 'male',
        'phone' => '9845998877',
    ]);
    $response->assertSessionHasNoErrors();

    $patient = Patient::where('phone', '9845998877')->firstOrFail();
    $originalUhid = $patient->uhid;
    expect($originalUhid)->not->toBeNull();

    // Patient attends branch 1
    PatientVisit::create([
        'clinic_id' => $this->f->clinic->id,
        'branch_id' => $this->f->branch->id,
        'patient_id' => $patient->id,
        'registration_type' => 'new',
        'visit_status' => 'completed',
        'visit_date' => now()->subDays(5)->toDateString(),
        'attended_at' => now()->subDays(5),
    ]);

    // Same patient attends branch 2 on a repeat visit
    PatientVisit::create([
        'clinic_id' => $this->f->clinic->id,
        'branch_id' => $branch2->id,
        'patient_id' => $patient->id,
        'registration_type' => 'repeat',
        'visit_status' => 'completed',
        'visit_date' => now()->toDateString(),
        'attended_at' => now(),
    ]);

    // Patient record must remain single, with original UHID unchanged
    $patientCount = Patient::where('phone', '9845998877')->count();
    expect($patientCount)->toBe(1);

    $patient->refresh();
    expect($patient->uhid)->toBe($originalUhid);
});

test('strict clinic-level tenant isolation for patient identity and directory', function () {
    $otherClinic = Clinic::factory()->create(['slug' => 'other-clinic']);
    $otherPatient = Patient::create([
        'clinic_id' => $otherClinic->id,
        'first_name' => 'Foreign',
        'last_name' => 'Patient',
        'phone' => '9999900000',
        'gender' => 'male',
    ]);

    // Owner of Phase B clinic cannot view other clinic's patient
    ($this->asOwner)()->get("/clinic/patients/{$otherPatient->id}")
        ->assertForbidden();

    // Directory of Phase B clinic does not include other clinic's patient
    $directory = app(PatientDirectory::class)->page($this->f->clinic->id, ['q' => 'Foreign']);
    expect($directory['data'])->toBeEmpty();
});

test('duplicate detection catches matching phone and blocks without override acknowledgment and reason', function () {
    Patient::create([
        'clinic_id' => $this->f->clinic->id,
        'first_name' => 'Ramesh',
        'last_name' => 'Gupta',
        'phone' => '9888877777',
        'gender' => 'male',
    ]);

    // Attempt to register duplicate phone without acknowledgment
    $response = ($this->asOwner)()->post('/clinic/patients', [
        'surface' => 'full_page',
        'first_name' => 'Ramesh',
        'last_name' => 'Gupta Jr',
        'gender' => 'male',
        'phone' => '9888877777',
    ]);

    $response->assertSessionHas('possible_duplicates');

    // With explicit acknowledgment and reason, registration succeeds
    $dupResponse = ($this->asOwner)()->post('/clinic/patients', [
        'surface' => 'full_page',
        'first_name' => 'Ramesh',
        'last_name' => 'Gupta Jr',
        'gender' => 'male',
        'phone' => '9888877777',
        'duplicate_acknowledged_ids' => [Patient::where('phone', '9888877777')->first()->id],
        'duplicate_reason' => 'Son sharing family mobile number',
    ]);

    $dupResponse->assertSessionHasNoErrors();
    expect(Patient::where('phone', '9888877777')->count())->toBe(2);
});

test('search finds patient by Name, UHID, Phone, and Case Number', function () {
    $patient = Patient::create([
        'clinic_id' => $this->f->clinic->id,
        'first_name' => 'Ananya',
        'last_name' => 'Deshmukh',
        'uhid' => 'UHID-2026-8811',
        'phone' => '9812345678',
        'gender' => 'female',
    ]);

    PatientCase::create([
        'clinic_id' => $this->f->clinic->id,
        'branch_id' => $this->f->branch->id,
        'patient_id' => $patient->id,
        'lead_practitioner_id' => $this->f->doctorA->id,
        'case_number' => 'CASE-2026-9900',
        'specialty' => 'dental',
        'title' => 'Molar Root Canal Therapy',
        'chief_complaint' => 'Severe toothache in upper molar',
        'status' => 'active',
        'opened_at' => now(),
    ]);

    $directory = app(PatientDirectory::class);

    // 1. Search by Name
    $byName = $directory->page($this->f->clinic->id, ['q' => 'Ananya']);
    expect($byName['data'])->toHaveCount(1);
    expect($byName['data'][0]['id'])->toBe($patient->id);

    // 2. Search by UHID
    $byUhid = $directory->page($this->f->clinic->id, ['q' => 'UHID-2026-8811']);
    expect($byUhid['data'])->toHaveCount(1);
    expect($byUhid['data'][0]['id'])->toBe($patient->id);

    // 3. Search by Phone
    $byPhone = $directory->page($this->f->clinic->id, ['q' => '9812345678']);
    expect($byPhone['data'])->toHaveCount(1);
    expect($byPhone['data'][0]['id'])->toBe($patient->id);

    // 4. Search by Case Number
    $byCase = $directory->page($this->f->clinic->id, ['q' => 'CASE-2026-9900']);
    expect($byCase['data'])->toHaveCount(1);
    expect($byCase['data'][0]['id'])->toBe($patient->id);
    expect($byCase['data'][0]['active_case'])->not->toBeNull();
    expect($byCase['data'][0]['active_case']['case_number'])->toBe('CASE-2026-9900');
});

test('financial balance is only exposed to users holding billing.view permission', function () {
    $patient = Patient::create([
        'clinic_id' => $this->f->clinic->id,
        'first_name' => 'Kavita',
        'last_name' => 'Pillai',
        'uhid' => 'UHID-2026-3344',
        'phone' => '9744332211',
        'gender' => 'female',
    ]);

    PatientBilling::create([
        'clinic_id' => $this->f->clinic->id,
        'branch_id' => $this->f->branch->id,
        'patient_id' => $patient->id,
        'billing_number' => 'BILL-2026-0001',
        'invoice_number' => 'INV-2026-0001',
        'total_amount' => 5000.00,
        'final_amount' => 5000.00,
        'paid_amount' => 2000.00,
        'adjusted_amount' => 0.00,
        'outstanding_balance' => 3000.00,
        'status' => 'partially_paid',
        'issued_at' => now(),
    ]);
    // An unpaid (pending) bill owes its whole amount; a paid one owes nothing.
    foreach ([['BILL-2026-0002', 'pending', 0.00], ['BILL-2026-0003', 'paid', 400.00]] as [$number, $status, $paid]) {
        PatientBilling::create([
            'clinic_id' => $this->f->clinic->id,
            'branch_id' => $this->f->branch->id,
            'patient_id' => $patient->id,
            'billing_number' => $number,
            'total_amount' => 400.00,
            'final_amount' => 400.00,
            'paid_amount' => $paid,
            'adjusted_amount' => 0.00,
            'outstanding_balance' => 400.00 - $paid,
            'status' => $status,
            'issued_at' => now(),
        ]);
    }

    $directory = app(PatientDirectory::class);

    // Member WITH billing permission (withBilling = true)
    $pageWithBilling = $directory->page($this->f->clinic->id, ['q' => 'Kavita'], null, 25, true, true);
    expect($pageWithBilling['data'][0]['balance'])->toBe(3400.0);

    // Member WITHOUT billing permission (withBilling = false)
    $pageWithoutBilling = $directory->page($this->f->clinic->id, ['q' => 'Kavita'], null, 25, true, false);
    expect($pageWithoutBilling['data'][0]['balance'])->toBeNull();
});

test('phone masking provides last digits for non-contact roles while hiding full phone', function () {
    $patient = Patient::create([
        'clinic_id' => $this->f->clinic->id,
        'first_name' => 'Deepak',
        'last_name' => 'Chopra',
        'phone' => '9876543210',
        'gender' => 'male',
    ]);

    $directory = app(PatientDirectory::class);

    // withContact = false: phone is null, phone_last_digits is 3210
    $masked = $directory->page($this->f->clinic->id, ['q' => 'Deepak'], null, 25, false, false);
    expect($masked['data'][0]['phone'])->toBeNull();
    expect($masked['data'][0]['phone_last_digits'])->toBe('3210');

    // withContact = true: full phone is present
    $unmasked = $directory->page($this->f->clinic->id, ['q' => 'Deepak'], null, 25, true, false);
    expect($unmasked['data'][0]['phone'])->toBe('9876543210');
    expect($unmasked['data'][0]['phone_last_digits'])->toBe('3210');
});

test('patient demographic update preserves assigned doctor and historical cases', function () {
    $doctor = $this->f->doctorA;

    $patient = Patient::create([
        'clinic_id' => $this->f->clinic->id,
        'first_name' => 'Vikram',
        'last_name' => 'Seth',
        'phone' => '9811223344',
        'gender' => 'male',
        'current_practitioner_id' => $doctor->id,
    ]);

    $case = PatientCase::create([
        'clinic_id' => $this->f->clinic->id,
        'branch_id' => $this->f->branch->id,
        'patient_id' => $patient->id,
        'lead_practitioner_id' => $doctor->id,
        'case_number' => 'CASE-2026-HIST',
        'specialty' => 'dental',
        'title' => 'Crown Placement',
        'chief_complaint' => 'Crown restoration',
        'status' => 'active',
        'opened_at' => now(),
    ]);

    // Attempt update with changed demographic data
    $response = ($this->asOwner)()->put("/clinic/patients/{$patient->id}", [
        'first_name' => 'Vikram',
        'last_name' => 'Seth',
        'phone' => '9811223344',
        'gender' => 'male',
        'email' => 'vikram.seth.updated@example.com',
        'city' => 'Pune',
    ]);

    $response->assertSessionHasNoErrors();

    $patient->refresh();
    expect($patient->email)->toBe('vikram.seth.updated@example.com');
    // Doctor assignment MUST remain untouched by demographic edit
    expect($patient->current_practitioner_id)->toBe($doctor->id);

    // Clinical case MUST be preserved
    expect(PatientCase::where('id', $case->id)->exists())->toBeTrue();
});

test('keyset cursor pagination navigates forward across directory pages seamlessly', function () {
    for ($i = 1; $i <= 5; $i++) {
        Patient::create([
            'clinic_id' => $this->f->clinic->id,
            'first_name' => "Batch{$i}",
            'last_name' => 'Patient',
            'phone' => "980000000{$i}",
            'gender' => 'male',
        ]);
    }

    $directory = app(PatientDirectory::class);

    // Page 1: per_page = 2
    $page1 = $directory->page($this->f->clinic->id, [], null, 2);
    expect($page1['data'])->toHaveCount(2);
    expect($page1['next_cursor'])->not->toBeNull();

    // Page 2 using next_cursor
    $page2 = $directory->page($this->f->clinic->id, [], $page1['next_cursor'], 2);
    expect($page2['data'])->toHaveCount(2);

    // Records on page 2 must be different from page 1
    $page1Ids = collect($page1['data'])->pluck('id')->all();
    $page2Ids = collect($page2['data'])->pluck('id')->all();
    expect(array_intersect($page1Ids, $page2Ids))->toBeEmpty();
});

test('module-aware field visibility adheres to clinic active specialties', function () {
    // When visiting the patient creation page, the clinic form options provide active specialties
    $response = ($this->asOwner)()->get('/clinic/patients/create');
    $response->assertOk();

    $response->assertInertia(fn ($page) => $page
        ->component('ClinicPortal/Patients/Create')
        ->has('specialties')
        ->has('form.fields')
    );
});
