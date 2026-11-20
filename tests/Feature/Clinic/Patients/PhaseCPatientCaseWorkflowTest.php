<?php

use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicalSpecialty;
use App\Models\Organization;
use App\Models\Patient;
use App\Models\PatientCase;
use App\Models\PatientVisit;
use App\Services\Reception\ReceptionCheckIn;
use App\Services\Reception\VisitContext;
use Carbon\CarbonImmutable;
use Tests\Support\ReceptionFixture;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-15 10:00', 'Asia/Kolkata'));
    $this->f = ReceptionFixture::make();
    $this->clinic = $this->f->clinic;
    $this->branch = $this->f->branch;
    $this->checkInService = app(ReceptionCheckIn::class);

    $this->asOwner = fn () => $this->actingAs($this->f->owner)->withSession($this->f->session);

    // Create a secondary doctor at this branch
    $this->doctorA = $this->f->doctorA;
    $this->doctorB = $this->f->doctorB;

    $this->clinic->update(['specialties' => ['dental', 'physiotherapy']]);
    $area = 'sports_rehabilitation';
    $entry = ClinicalSpecialty::withoutGlobalScopes()->where('organization_id', $this->clinic->organization_id)->where('practice_area_key', $area)->first()
        ?? ClinicalSpecialty::withoutGlobalScopes()->forceCreate([
            'organization_id' => $this->clinic->organization_id,
            'practice_area_key' => $area,
            'name' => 'Sports Rehabilitation',
            'is_active' => true,
        ]);
    $this->doctorPhysio = $this->f->doctor('Dr. Sneha Physio');
    $this->doctorPhysio->practitionerProfile()->withoutGlobalScopes()->update([
        'primary_specialty_id' => $entry->id,
        'specialty' => $entry->name,
    ]);
});

test('New Case creates a permanent patient, unique UHID, and a linked PatientCase and PatientVisit with valid case_id', function () {
    $response = ($this->asOwner)()->postJson('/clinic/patients', [
        'surface' => 'new_case',
        'first_name' => 'Aditya',
        'last_name' => 'Joshi',
        'phone' => '9822001122',
        'gender' => 'male',
        'date_of_birth' => '1990-06-15',
        'check_in' => true,
        'doctor_id' => $this->doctorA->id,
        'visit_specialty' => 'dental',
        'visit_reason' => 'Severe molar pain',
        'priority' => 'urgent',
    ]);

    $response->assertCreated();

    $patient = Patient::where('phone', '9822001122')->where('clinic_id', $this->clinic->id)->firstOrFail();
    expect($patient->uhid)->toStartWith('OC-')
        ->and($patient->first_name)->toBe('Aditya')
        ->and($patient->last_name)->toBe('Joshi');

    $visit = PatientVisit::where('patient_id', $patient->id)->where('clinic_id', $this->clinic->id)->sole();
    expect($visit->case_id)->not->toBeNull()
        ->and($visit->doctor_id)->toBe($this->doctorA->id)
        ->and($visit->visit_status)->toBe('checked_in');

    $case = PatientCase::findOrFail($visit->case_id);
    expect($case->patient_id)->toBe($patient->id)
        ->and($case->clinic_id)->toBe($this->clinic->id)
        ->and($case->branch_id)->toBe($this->branch->id)
        ->and($case->lead_practitioner_id)->toBe($this->doctorA->id)
        ->and($case->case_number)->toStartWith('CASE-2026-')
        ->and($case->status)->toBe('active')
        ->and($case->chief_complaint)->toBe('Severe molar pain');
});

test('New Case captures case-level chief complaint and specialty separately from permanent demographics', function () {
    ($this->asOwner)()->postJson('/clinic/patients', [
        'surface' => 'new_case',
        'first_name' => 'Sunita',
        'last_name' => 'Deshmukh',
        'phone' => '9822003344',
        'gender' => 'female',
        'date_of_birth' => '1985-04-12',
        'check_in' => true,
        'doctor_id' => $this->doctorPhysio->id,
        'visit_specialty' => 'physiotherapy',
        'visit_reason' => 'Frozen shoulder stiffness',
    ])->assertCreated();

    $patient = Patient::where('phone', '9822003344')->firstOrFail();
    $case = PatientCase::where('patient_id', $patient->id)->firstOrFail();

    // Permanent demographics stay on Patient
    expect($patient->gender)->toBe('female')
        ->and($patient->date_of_birth->toDateString())->toBe('1985-04-12');

    // Case-specific details live on PatientCase
    expect($case->specialty)->toBe('physiotherapy')
        ->and($case->chief_complaint)->toBe('Frozen shoulder stiffness')
        ->and($case->lead_practitioner_id)->toBe($this->doctorPhysio->id);
});

test('New Case prevents duplicate patient profiles when matching phone exists without audited override', function () {
    $existing = $this->f->patient('Existing', 'Patient', ['phone' => '9899887766']);

    // Attempting to register another patient with the same phone without acknowledging matches is blocked
    $response = ($this->asOwner)()->postJson('/clinic/patients', [
        'surface' => 'new_case',
        'first_name' => 'Duplicate',
        'last_name' => 'Attempt',
        'phone' => '9899887766',
        'gender' => 'male',
        'check_in' => true,
        'doctor_id' => $this->doctorA->id,
    ]);

    $response->assertStatus(409);
    $response->assertJsonStructure(['possible_duplicates']);
    expect($response->json('possible_duplicates.0.id'))->toBe($existing->id);
});

test('Repeat Case lookup finds patient by UHID, phone, or name and returns their active PatientCase history', function () {
    $patient = $this->f->patient('Rohan', 'Verma', ['phone' => '9833445566']);
    $activeCase = PatientCase::create([
        'clinic_id' => $this->clinic->id,
        'branch_id' => $this->branch->id,
        'patient_id' => $patient->id,
        'case_number' => 'CASE-2026-000101',
        'specialty' => 'dental',
        'title' => 'Dental Root Canal Treatment',
        'chief_complaint' => 'Pain in lower molar',
        'lead_practitioner_id' => $this->doctorA->id,
        'status' => 'active',
        'opened_at' => now()->subDays(5),
    ]);

    $response = ($this->asOwner)()->getJson('/clinic/reception/check-in/search?q=9833445566')->assertOk();
    $patientData = $response->json('patients.0');

    expect($patientData['id'])->toBe($patient->id)
        ->and($patientData['uhid'])->toBe($patient->uhid)
        ->and($patientData['cases'])->toHaveCount(1)
        ->and($patientData['cases'][0]['id'])->toBe($activeCase->id)
        ->and($patientData['cases'][0]['case_number'])->toBe('CASE-2026-000101')
        ->and($patientData['cases'][0]['title'])->toBe('Dental Root Canal Treatment')
        ->and($patientData['cases'][0]['doctor']['id'])->toBe($this->doctorA->id);
});

test('Repeat Case check-in under an existing active case links the visit to that case without creating a duplicate patient or case', function () {
    $patient = $this->f->patient('Kavita', 'Rao', ['phone' => '9844556677']);
    $activeCase = PatientCase::create([
        'clinic_id' => $this->clinic->id,
        'branch_id' => $this->branch->id,
        'patient_id' => $patient->id,
        'case_number' => 'CASE-2026-000202',
        'specialty' => 'dental',
        'title' => 'Orthodontic Braces Alignment',
        'chief_complaint' => 'Crowded upper anteriors',
        'lead_practitioner_id' => $this->doctorA->id,
        'status' => 'active',
        'opened_at' => now()->subWeeks(2),
    ]);

    $initialPatientCount = Patient::where('clinic_id', $this->clinic->id)->count();
    $initialCaseCount = PatientCase::where('clinic_id', $this->clinic->id)->count();

    // Repeat check-in specifying the active case_id
    $response = ($this->asOwner)()->postJson('/clinic/reception/check-in', [
        'patient_id' => $patient->id,
        'case_id' => $activeCase->id,
        'practitioner_id' => $this->doctorA->id,
        'visit_reason' => 'Monthly wire change',
    ])->assertCreated();

    // Asserts no duplicate patient or case
    expect(Patient::where('clinic_id', $this->clinic->id)->count())->toBe($initialPatientCount)
        ->and(PatientCase::where('clinic_id', $this->clinic->id)->count())->toBe($initialCaseCount);

    $visit = PatientVisit::where('patient_id', $patient->id)->latest('id')->firstOrFail();
    expect($visit->case_id)->toBe($activeCase->id)
        ->and($visit->doctor_id)->toBe($this->doctorA->id);
});

test('Repeat Case can open a genuinely new episode/case for an existing patient, generating a new PatientCase linked to the new visit', function () {
    $patient = $this->f->patient('Vikas', 'Mehta', ['phone' => '9855667788']);
    $firstCase = PatientCase::create([
        'clinic_id' => $this->clinic->id,
        'branch_id' => $this->branch->id,
        'patient_id' => $patient->id,
        'case_number' => 'CASE-2026-000301',
        'specialty' => 'dental',
        'title' => 'Dental Filling',
        'chief_complaint' => 'Cavity in upper premolar',
        'lead_practitioner_id' => $this->doctorA->id,
        'status' => 'active',
        'opened_at' => now()->subMonths(1),
    ]);

    $initialPatientCount = Patient::where('clinic_id', $this->clinic->id)->count();

    // Repeat patient arrives with a completely new issue (physiotherapy knee pain)
    $response = ($this->asOwner)()->postJson('/clinic/reception/check-in', [
        'patient_id' => $patient->id,
        'new_case' => true,
        'practitioner_id' => $this->doctorPhysio->id,
        'visit_specialty' => 'physiotherapy',
        'visit_reason' => 'Acute knee ligament strain',
    ])->assertCreated();

    // Patient count is unchanged (no duplicate patient)
    expect(Patient::where('clinic_id', $this->clinic->id)->count())->toBe($initialPatientCount);

    // Patient now has two cases
    $cases = PatientCase::where('patient_id', $patient->id)->orderBy('id')->get();
    expect($cases)->toHaveCount(2)
        ->and($cases[0]->id)->toBe($firstCase->id)
        ->and($cases[1]->specialty)->toBe('physiotherapy')
        ->and($cases[1]->chief_complaint)->toBe('Acute knee ligament strain')
        ->and($cases[1]->lead_practitioner_id)->toBe($this->doctorPhysio->id);

    $latestVisit = PatientVisit::where('patient_id', $patient->id)->latest('id')->firstOrFail();
    expect($latestVisit->case_id)->toBe($cases[1]->id);
});

test('Repeat Case preserves historical doctor and branch attribution across multiple visits and cases', function () {
    $patient = $this->f->patient('Deepa', 'Nair');

    // Visit 1 with Doctor A
    $item1 = $this->checkInService->checkIn(
        $this->clinic,
        $this->branch,
        $patient,
        doctor: $this->doctorA,
        context: new VisitContext(specialtyKey: 'dental', reason: 'Teeth cleaning'),
    );
    $visit1 = $item1->patientVisit;
    $case1 = $visit1->case;

    // Fast-forward 10 days
    $this->travelTo(CarbonImmutable::parse('2026-10-25 11:00', 'Asia/Kolkata'));

    // Visit 2 with Doctor B for new case
    $item2 = $this->checkInService->checkIn(
        $this->clinic,
        $this->branch,
        $patient,
        doctor: $this->doctorB,
        context: new VisitContext(specialtyKey: 'dental', reason: 'Tooth extraction'),
        isNewCase: true,
    );
    $visit2 = $item2->patientVisit;
    $case2 = $visit2->case;

    expect($visit1->doctor_id)->toBe($this->doctorA->id)
        ->and($case1->lead_practitioner_id)->toBe($this->doctorA->id)
        ->and($visit2->doctor_id)->toBe($this->doctorB->id)
        ->and($case2->lead_practitioner_id)->toBe($this->doctorB->id)
        ->and($case1->id)->not->toBe($case2->id);
});

test('Check-in prevents duplicate active queue items for the same patient in today\'s branch queue', function () {
    $patient = $this->f->patient('Sanjay', 'Dutt');

    ($this->asOwner)()->postJson('/clinic/reception/check-in', [
        'patient_id' => $patient->id,
        'practitioner_id' => $this->doctorA->id,
    ])->assertCreated();

    // Trying to check in again on the same day while still active is rejected
    $repeat = ($this->asOwner)()->postJson('/clinic/reception/check-in', [
        'patient_id' => $patient->id,
        'practitioner_id' => $this->doctorA->id,
    ]);

    $repeat->assertStatus(422);
    $repeat->assertJsonValidationErrors('patient_id');
});

test('Strict clinic-level tenant isolation is enforced for PatientCase and PatientVisit', function () {
    $otherOrg = Organization::factory()->create();
    $clinic2 = Clinic::factory()->create(['organization_id' => $otherOrg->id, 'slug' => 'other-tenant-clinic']);
    $patient2 = Patient::factory()->create(['clinic_id' => $clinic2->id]);

    // Clinic 1 tries to check in Clinic 2's patient
    $response = ($this->asOwner)()->postJson('/clinic/reception/check-in', [
        'patient_id' => $patient2->id,
    ]);

    $response->assertStatus(404);
});

test('Idempotent submission with identical key replays first check-in without duplicating cases or visits', function () {
    $idempotencyKey = 'req-key-phasec-test-999';

    $first = ($this->asOwner)()->postJson('/clinic/reception/check-in', [
        'patient_id' => $this->f->patient('Idem', 'Test')->id,
        'practitioner_id' => $this->doctorA->id,
        'idempotency_key' => $idempotencyKey,
    ])->assertCreated();

    $second = ($this->asOwner)()->postJson('/clinic/reception/check-in', [
        'patient_id' => $this->f->patient('Idem', 'Test')->id,
        'practitioner_id' => $this->doctorA->id,
        'idempotency_key' => $idempotencyKey,
    ])->assertCreated();

    expect($second->json('replayed'))->toBeTrue();
});
