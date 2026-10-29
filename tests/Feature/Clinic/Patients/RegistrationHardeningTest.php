<?php

use App\BranchStatus;
use App\ClinicMembershipStatus;
use App\ClinicRole;
use App\Models\Branch;
use App\Models\ClinicMembership;
use App\Models\HealthcareSpecialtyMaster;
use App\Models\Patient;
use App\Models\PatientBranchActivity;
use App\Models\PatientClinicalEntry;
use App\Models\PatientVisit;
use App\Models\SecurityAuditLog;
use App\Models\User;
use App\Services\ClinicContext;
use App\Services\ClinicRoleAssignmentService;
use App\Services\PatientFieldConfigService;
use App\UserType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\ReceptionFixture;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-04 10:00', 'Asia/Kolkata'));
    HealthcareSpecialtyMaster::create(['key' => 'general_dentistry', 'name' => 'General Dentistry', 'category' => 'dental', 'category_label' => 'Dental', 'is_active' => true]);
    $this->f = ReceptionFixture::make();
    $this->f->clinic->update(['specialties' => ['dental']]);
    [$this->receptionist, $this->receptionSession] = $this->f->memberWithRole(ClinicRole::Receptionist);
    $this->asReception = fn () => $this->actingAs($this->receptionist)->withSession($this->receptionSession);
    $this->fields = fn (array $fields = []) => [
        'first_name' => 'Kavya', 'last_name' => 'Menon', 'phone' => '9811100001', 'gender' => 'female', ...$fields,
    ];

    // A receptionist limited to a second branch, East.
    $this->east = Branch::factory()->create(['clinic_id' => $this->f->clinic->id, 'name' => 'East', 'status' => BranchStatus::Active]);
    $eastUser = User::factory()->create(['user_type' => UserType::ClinicUser]);
    $membership = ClinicMembership::factory()->create(['clinic_id' => $this->f->clinic->id, 'user_id' => $eastUser->id, 'is_primary_owner' => false, 'has_all_branches_access' => false, 'status' => ClinicMembershipStatus::Active]);
    $membership->assignBranch($this->east);
    app(ClinicRoleAssignmentService::class)->syncRoles($eastUser, [ClinicRole::Receptionist->value], [$this->east]);
    $this->asEast = fn () => $this->actingAs($eastUser)->withSession([ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID => $membership->id, ClinicContext::SESSION_BRANCH_ID => $this->east->id]);

    // A patient of the main branch only.
    $this->mainPatient = fn () => tap($this->f->patient('Rajesh', 'Patel', ['phone' => '9876501284', 'gender' => 'male', 'date_of_birth' => '1974-05-01']), function (Patient $patient) {
        PatientBranchActivity::create(['clinic_id' => $this->f->clinic->id, 'patient_id' => $patient->id, 'branch_id' => $this->f->branch->id, 'first_seen_at' => now()->subDays(3), 'last_seen_at' => now()->subDays(3), 'first_source' => 'visit']);
    });
});

test('a full-page form submitted twice with the same request key registers one patient', function () {
    $fields = ($this->fields)(['idempotency_key' => 'page-key-0001']);

    $first = ($this->asReception)()->post('/clinic/patients', $fields)->assertSessionHas('success');
    $first->assertRedirect(route('clinic.patients.show', Patient::sole()));
    ($this->asReception)()->post('/clinic/patients', $fields)->assertRedirect(route('clinic.patients.show', Patient::sole()))
        ->assertSessionHas('success', 'This patient is already registered; the form was not saved twice.');

    expect(Patient::count())->toBe(1)
        ->and(DB::table('intake_submissions')->where('idempotency_key', 'page-key-0001')->value('status'))->toBe(302);
});

test('a full-page submit stopped by a possible duplicate frees its key for the acknowledged retry', function () {
    $existing = $this->f->patient('Kavya', 'Menon', ['phone' => '9811100001']);

    ($this->asReception)()->post('/clinic/patients', ($this->fields)(['idempotency_key' => 'page-key-0002']))
        ->assertSessionHasErrors('duplicate');
    expect(DB::table('intake_submissions')->count())->toBe(0);

    ($this->asReception)()->post('/clinic/patients', ($this->fields)([
        'idempotency_key' => 'page-key-0002', 'duplicate_acknowledged_ids' => [$existing->id], 'duplicate_reason' => 'Sister on the family phone',
    ]))->assertSessionHasNoErrors()->assertRedirect(route('clinic.patients.show', Patient::query()->latest('id')->first()));

    expect(Patient::count())->toBe(2)
        ->and(SecurityAuditLog::where('event', 'patient.duplicate_override')->sole()->context['reason'])->toBe('Sister on the family phone');
});

test('a request key used by someone else registers nothing', function () {
    ($this->asReception)()->post('/clinic/patients', ($this->fields)(['idempotency_key' => 'page-key-0003']))->assertRedirect();

    $this->actingAs($this->f->owner)->withSession($this->f->session)
        ->post('/clinic/patients', ($this->fields)(['idempotency_key' => 'page-key-0003', 'first_name' => 'Other', 'phone' => '9811100999']))
        ->assertSessionHasErrors('idempotency_key');

    expect(Patient::count())->toBe(1);
});

test('phone numbers may be Indian or international, but not letters or impossible lengths', function (string $phone, bool $valid) {
    $response = ($this->asReception)()->post('/clinic/patients', ($this->fields)(['phone' => $phone]));

    $valid ? $response->assertSessionHasNoErrors() : $response->assertSessionHasErrors('phone');
})->with([
    'Indian mobile' => ['9876543210', true],
    'Indian with country code' => ['+91 98765 43210', true],
    'UK landline' => ['+44 20 7946 0958', true],
    'US grouped' => ['+1 (415) 555-0100', true],
    'letters' => ['98765abcde', false],
    'too short' => ['12345', false],
    'too long' => ['+1234567890123456', false],
    'plus in the middle' => ['98765+43210', false],
]);

test('the New Case outcome links only to built pages: the patient\'s record, not the consultation yet', function () {
    $response = ($this->asReception)()->postJson('/clinic/patients', ['surface' => 'new_case', 'specialties' => ['dental'], ...($this->fields)()])
        ->assertCreated();
    $profile = route('clinic.patients.show', Patient::sole());

    $response->assertJsonPath('patient.url', $profile)
        ->assertJsonPath('links.patient', $profile)
        ->assertJsonPath('links.consultation', null);
});

test('a branch-limited member sees another branch\'s duplicate only masked, and cannot register over it without a reason', function () {
    $patient = ($this->mainPatient)();
    $criteria = ['first_name' => 'Rajesh', 'last_name' => 'Patel', 'phone' => '9876501284', 'gender' => 'male'];

    $match = ($this->asEast)()->postJson('/clinic/patients/check-duplicate', $criteria)->assertOk()->json('matches.0');
    expect($match)->toMatchArray([
        'id' => $patient->id, 'restricted' => true, 'name' => 'R. P.', 'uhid' => null, 'age' => null, 'gender' => null,
        'date_of_birth' => null, 'phone_hint' => null, 'specialties' => [], 'last_seen' => null, 'matched_on' => ['phone'],
    ])->and(json_encode($match))->not->toContain('Rajesh', 'Patel', '1284', 'Main');

    // The server gate holds the same line on the same masked data.
    ($this->asEast)()->postJson('/clinic/patients', ['surface' => 'new_case', ...$criteria])->assertStatus(409)
        ->assertJsonPath('possible_duplicates.0.restricted', true)->assertJsonPath('possible_duplicates.0.uhid', null);
    ($this->asEast)()->postJson('/clinic/patients', ['surface' => 'new_case', ...$criteria, 'duplicate_acknowledged_ids' => [$patient->id]])
        ->assertStatus(422)->assertJsonValidationErrors('duplicate_reason');
    expect(Patient::count())->toBe(1);

    // A reasoned, audited override still registers a separate person.
    ($this->asEast)()->postJson('/clinic/patients', ['surface' => 'new_case', ...$criteria, 'duplicate_acknowledged_ids' => [$patient->id], 'duplicate_reason' => 'Different person, same name and family phone'])
        ->assertCreated();
    expect(Patient::count())->toBe(2)
        ->and(SecurityAuditLog::where('event', 'patient.duplicate_override')->sole()->context['reason'])->toBe('Different person, same name and family phone');
});

test('an unlimited member sees the same duplicate in full', function () {
    $patient = ($this->mainPatient)();

    ($this->asReception)()->postJson('/clinic/patients/check-duplicate', ['first_name' => 'Rajesh', 'phone' => '9876501284'])->assertOk()
        ->assertJsonPath('matches.0.restricted', false)
        ->assertJsonPath('matches.0.uhid', $patient->uhid)
        ->assertJsonPath('matches.0.name', 'Rajesh Patel')
        ->assertJsonPath('matches.0.last_seen.branch', $this->f->branch->name);
});

test('a masked duplicate is no way into another branch\'s patient', function () {
    $patient = ($this->mainPatient)();

    // Neither the search, nor check-in, nor the profile.
    ($this->asEast)()->getJson('/clinic/search/patients?q=Rajesh')->assertOk()->assertJsonCount(0);
    ($this->asEast)()->getJson('/clinic/reception/check-in/search?q=9876501284')->assertOk()->assertJsonCount(0, 'patients');
    ($this->asEast)()->postJson('/clinic/reception/check-in', ['patient_id' => $patient->id, 'practitioner_id' => $this->f->doctorA->id])->assertForbidden();
    ($this->asEast)()->get("/clinic/patients/{$patient->id}")->assertForbidden();

    expect(PatientVisit::count())->toBe(0);
});

test('a patient registered at East is East\'s to find and check in', function () {
    ($this->asEast)()->postJson('/clinic/patients', ['surface' => 'new_case', 'specialties' => ['dental'], ...($this->fields)()])->assertCreated();

    ($this->asEast)()->getJson('/clinic/reception/check-in/search?q=Kavya')->assertOk()->assertJsonPath('patients.0.name', 'Kavya Menon');
});

test('duplicate detection and identity search never reach another clinic', function () {
    $other = ReceptionFixture::make('other-clinic');
    $other->patient('Kavya', 'Menon', ['phone' => '9811100001']);

    ($this->asReception)()->postJson('/clinic/patients/check-duplicate', ['first_name' => 'Kavya', 'phone' => '9811100001'])->assertOk()->assertJsonCount(0, 'matches');
    ($this->asReception)()->getJson('/clinic/reception/check-in/search?q=Kavya')->assertOk()->assertJsonCount(0, 'patients');
    ($this->asReception)()->post('/clinic/patients', ($this->fields)())->assertSessionHasNoErrors();

    expect(Patient::withoutGlobalScopes()->where('clinic_id', $this->f->clinic->id)->count())->toBe(1);
});

test('the full registration page is built and gets the same field configuration as New Case', function () {
    ($this->asReception)()->get('/clinic/patients/create')->assertOk()
        ->assertInertia(fn ($page) => $page->component('ClinicPortal/Patients/Create', true)
            ->where('links.index', route('clinic.patients.index'))
            ->has('form.fields.last_name.state')
            ->has('form.catalogue.conditions')
            ->has('form.catalogue.allergies')
            ->has('form.quick_care.allergy_status')
            ->has('form.can.record_clinical')
            ->where('specialties', [['key' => 'dental', 'label' => 'Dental']])
        );
});

test('current medications, when the clinic requires them, are offered, enforced and recorded on both forms', function () {
    app(PatientFieldConfigService::class)->update($this->f->clinic, ['medications' => 'required'], $this->f->owner);

    // Both forms are sent the medication list and the setting (a required field shows on both).
    ($this->asReception)()->getJson('/clinic/reception/intake')->assertJsonPath('fields.medications.state', 'required')->assertJsonStructure(['catalogue' => ['medications']]);
    ($this->asReception)()->get('/clinic/patients/create')->assertInertia(fn ($page) => $page->where('form.fields.medications.state', 'required')->has('form.catalogue.medications'));

    ($this->asReception)()->postJson('/clinic/patients', ['surface' => 'new_case', ...($this->fields)()])->assertStatus(422)->assertJsonValidationErrors('medications');
    ($this->asReception)()->post('/clinic/patients', ($this->fields)(['phone' => '9811100002']))->assertSessionHasErrors('medications');

    ($this->asReception)()->postJson('/clinic/patients', ['surface' => 'new_case', ...($this->fields)(['medications' => [['label' => 'Metformin 500 mg']]])])->assertCreated();
    expect(PatientClinicalEntry::query()->withoutGlobalScopes()->where('kind', 'medication')->sole()->label)->toBe('Metformin 500 mg');
});

test('registering needs the create permission, whatever the request key', function () {
    [$billing, $session] = $this->f->memberWithRole(ClinicRole::BillingStaff);

    $this->actingAs($billing)->withSession($session)->post('/clinic/patients', ($this->fields)(['idempotency_key' => 'page-key-0004']))->assertForbidden();

    expect(Patient::count())->toBe(0)->and(DB::table('intake_submissions')->count())->toBe(0);
});
