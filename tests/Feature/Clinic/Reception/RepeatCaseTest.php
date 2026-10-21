<?php

use App\BranchStatus;
use App\ClinicMembershipStatus;
use App\ClinicRole;
use App\Models\Branch;
use App\Models\ClinicMembership;
use App\Models\HealthcareSpecialtyMaster;
use App\Models\Patient;
use App\Models\PatientBranchActivity;
use App\Models\PatientProfile;
use App\Models\PatientVisit;
use App\Models\User;
use App\Services\Clinical\ClinicalCatalogue;
use App\Services\ClinicContext;
use App\Services\ClinicRoleAssignmentService;
use App\Services\Patient\ClinicSpecialties;
use App\Services\Patient\PatientClinicalEntries;
use App\Services\PatientFieldConfigService;
use App\Services\Reception\ReceptionCheckIn;
use App\UserType;
use Carbon\CarbonImmutable;
use Tests\Support\ReceptionFixture;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-04 10:00', 'Asia/Kolkata'));
    HealthcareSpecialtyMaster::create(['key' => 'general_dentistry', 'name' => 'General Dentistry', 'category' => 'dental', 'category_label' => 'Dental', 'is_active' => true]);
    $this->f = ReceptionFixture::make();
    $this->f->clinic->update(['specialties' => ['dental']]);
    [$this->receptionist, $this->receptionSession] = $this->f->memberWithRole(ClinicRole::Receptionist);
    $this->asReception = fn () => $this->actingAs($this->receptionist)->withSession($this->receptionSession);

    // A returning patient with a recorded allergy, a critical and a moderate condition.
    $this->patient = $this->f->patient('Rajesh', 'Patel', ['phone' => '9876501284', 'gender' => 'male', 'date_of_birth' => '1974-05-01']);
    PatientBranchActivity::create(['clinic_id' => $this->f->clinic->id, 'patient_id' => $this->patient->id, 'branch_id' => $this->f->branch->id, 'first_seen_at' => now()->subDays(13), 'last_seen_at' => now()->subDays(13), 'first_source' => 'visit']);
    $entries = app(PatientClinicalEntries::class);
    $clinicId = $this->f->clinic->id;
    $entries->add($this->patient, 'allergy', $entries->prepare($clinicId, 'allergy', [['label' => 'Penicillin']], 'allergies'), 'registration', null);
    $entries->add($this->patient, 'condition', $entries->prepare($clinicId, 'condition', [['key' => 'heart_disease'], ['key' => 'hypertension']], 'conditions'), 'registration', null);
});

test('search finds a returning patient by mobile, number or name, with identity and safety flags only', function () {
    foreach (['98765012', $this->patient->uhid, 'Rajesh Pat'] as $query) {
        ($this->asReception)()->getJson('/clinic/reception/check-in/search?q='.urlencode($query))->assertOk()
            ->assertJsonPath('patients.0.id', $this->patient->id);
    }

    $row = ($this->asReception)()->getJson('/clinic/reception/check-in/search?q=Rajesh')->json('patients.0');
    expect($row)->toMatchArray(['name' => 'Rajesh Patel', 'gender' => 'male', 'status' => 'active', 'last_seen' => ['at' => now()->subDays(13)->toDateString(), 'branch' => $this->f->branch->name]])
        ->and($row['age'])->toBe(52)
        // Safety flags: the allergy and the critical condition, never the moderate one.
        ->and(collect($row['safety_flags'])->pluck('label')->all())->toBe(['Heart disease', 'Penicillin'])
        ->and(json_encode($row))->not->toContain('Hypertension')
        ->and(array_keys($row))->not->toContain('conditions', 'medications', 'allergies');
});

test('Repeat Case shows the address on file, read-only, and checking in never changes it', function () {
    PatientProfile::query()->withoutGlobalScopes()->updateOrCreate(['patient_id' => $this->patient->id], [
        'clinic_id' => $this->f->clinic->id, 'organization_id' => $this->f->clinic->organization_id,
        'address' => '12 MG Road', 'locality' => 'Kothrud', 'city' => 'Pune', 'state' => 'Maharashtra', 'pincode' => '411038', 'country' => 'India',
    ]);

    ($this->asReception)()->getJson('/clinic/reception/check-in/search?q=Rajesh')->assertOk()
        ->assertJsonPath('patients.0.address', '12 MG Road, Kothrud, Pune, Maharashtra, 411038, India');

    // An address the clinic hides is not shown at the desk.
    app(PatientFieldConfigService::class)->update($this->f->clinic, ['address' => 'hidden']);
    ($this->asReception)()->getJson('/clinic/reception/check-in/search?q=Rajesh')->assertOk()->assertJsonPath('patients.0.address', null);

    // Checking in, even with address keys in the request, leaves the address as it was.
    ($this->asReception)()->postJson('/clinic/reception/check-in', ['patient_id' => $this->patient->id, 'address' => 'Overwritten', 'city' => 'Elsewhere'])->assertSuccessful();
    expect(PatientProfile::query()->withoutGlobalScopes()->where('patient_id', $this->patient->id)->first())
        ->address->toBe('12 MG Road')->city->toBe('Pune')->postal_code->toBe('411038')->country->toBe('India');
});

test('a clinic whose saved form setting predates the popup layout still gets the address on New Case; an explicit choice is kept', function () {
    // The format saved before per-surface settings existed: a bare state per field.
    $this->f->clinic->update(['patient_field_config' => ['address' => 'optional', 'email' => 'optional', 'blood_group' => 'hidden']]);
    $fields = app(PatientFieldConfigService::class)->effective($this->f->clinic->fresh(), $this->receptionist, null, PatientFieldConfigService::SURFACE_NEW_CASE);
    expect($fields['address']['state'])->toBe('optional')
        ->and($fields['address_city']['state'])->toBe('optional')
        ->and($fields['address_country']['state'])->toBe('optional')
        ->and($fields['email']['state'])->toBe('optional')
        ->and($fields['blood_group']['state'])->toBe('hidden');

    // A clinic that took the address off the popup on purpose keeps that.
    app(PatientFieldConfigService::class)->update($this->f->clinic->fresh(), ['address' => ['state' => 'optional', 'new_case' => false]]);
    $fields = app(PatientFieldConfigService::class)->effective($this->f->clinic->fresh(), $this->receptionist, null, PatientFieldConfigService::SURFACE_NEW_CASE);
    expect($fields['address']['state'])->toBe('hidden')->and($fields['address_city']['state'])->toBe('hidden');
});

test('search is bounded and needs three characters', function () {
    foreach (range(1, 12) as $n) {
        $this->f->patient("Rajeshwar{$n}", 'Rao');
    }

    ($this->asReception)()->getJson('/clinic/reception/check-in/search?q=Ra')->assertOk()->assertJsonCount(0, 'patients');
    ($this->asReception)()->getJson('/clinic/reception/check-in/search?q=Raj')->assertOk()->assertJsonCount(8, 'patients');
});

// P1.2 changed this policy: identity search used to be clinic-wide (the
// person was found, without safety flags). Search now covers only the
// member's branches, and check-in refuses another branch's patient.
test('a member limited to another branch neither finds the person nor checks them in', function () {
    $east = Branch::factory()->create(['clinic_id' => $this->f->clinic->id, 'name' => 'East', 'status' => BranchStatus::Active]);
    $user = User::factory()->create(['user_type' => UserType::ClinicUser]);
    $membership = ClinicMembership::factory()->create(['clinic_id' => $this->f->clinic->id, 'user_id' => $user->id, 'is_primary_owner' => false, 'has_all_branches_access' => false, 'status' => ClinicMembershipStatus::Active]);
    $membership->assignBranch($east);
    app(ClinicRoleAssignmentService::class)->syncRoles($user, [ClinicRole::Receptionist->value], [$east]);
    $asEast = fn () => $this->actingAs($user)->withSession([ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID => $membership->id, ClinicContext::SESSION_BRANCH_ID => $east->id]);

    foreach (['Rajesh', '9876501284', $this->patient->uhid] as $query) {
        $asEast()->getJson('/clinic/reception/check-in/search?q='.urlencode($query))->assertOk()->assertJsonCount(0, 'patients');
    }

    $asEast()->postJson('/clinic/reception/check-in', ['patient_id' => $this->patient->id, 'practitioner_id' => $this->f->doctorB->id])->assertForbidden();
    expect(PatientVisit::count())->toBe(0);

    // Once they have activity at East (seen there), East's desk finds them.
    PatientBranchActivity::create(['clinic_id' => $this->f->clinic->id, 'patient_id' => $this->patient->id, 'branch_id' => $east->id, 'first_seen_at' => now(), 'last_seen_at' => now(), 'first_source' => 'visit']);
    $asEast()->getJson('/clinic/reception/check-in/search?q=Rajesh')->assertOk()->assertJsonPath('patients.0.id', $this->patient->id);
});

test('Repeat Case checks in with today\'s specialty, type and reason, without touching the patient record', function () {
    $before = $this->patient->fresh()->only(['first_name', 'last_name', 'phone', 'date_of_birth', 'updated_at']);

    ($this->asReception)()->postJson('/clinic/reception/check-in', [
        'patient_id' => $this->patient->id,
        'practitioner_id' => $this->f->doctorB->id,
        'visit_specialty' => 'dental',
        'visit_type' => 'follow_up',
        'visit_reason' => 'Crown review',
        'priority' => 'senior_citizen',
    ])->assertCreated()->assertJsonPath('check_in.doctor', 'Dr. Bala Iyer');

    $visit = PatientVisit::sole();
    expect($visit->visit_type_key)->toBe('follow_up')
        ->and($visit->visit_reason)->toBe('Crown review')
        ->and($visit->clinic_specialty_id)->toBe(app(ClinicSpecialties::class)->active($this->f->clinic, 'dental')->id)
        ->and($this->patient->fresh()->only(['first_name', 'last_name', 'phone', 'date_of_birth', 'updated_at']))->toEqual($before);
});

test('check-in refuses a visit type the clinic archived, and a merged record', function () {
    app(ClinicalCatalogue::class)->configure($this->f->clinic, $this->f->owner, 'visit_type', 'review', ['archived' => true]);

    ($this->asReception)()->postJson('/clinic/reception/check-in', ['patient_id' => $this->patient->id, 'visit_type' => 'review'])
        ->assertStatus(422)->assertJsonValidationErrors('visit_type');

    $merged = $this->f->patient('Gone', 'Record', ['status' => 'merged']);
    ($this->asReception)()->postJson('/clinic/reception/check-in', ['patient_id' => $merged->id])->assertStatus(422);
    expect(PatientVisit::count())->toBe(0);
});

test('another clinic\'s patient cannot be checked in', function () {
    $other = ReceptionFixture::make('other-clinic');
    $theirs = $other->patient('Their', 'Patient');

    ($this->asReception)()->postJson('/clinic/reception/check-in', ['patient_id' => $theirs->id])->assertNotFound();
});

test('recent lists patients seen here lately and not already queued, and stays short', function () {
    $queued = $this->f->patient('Already', 'Queued');
    PatientBranchActivity::create(['clinic_id' => $this->f->clinic->id, 'patient_id' => $queued->id, 'branch_id' => $this->f->branch->id, 'first_seen_at' => now()->subDay(), 'last_seen_at' => now()->subDay(), 'first_source' => 'visit']);
    app(ReceptionCheckIn::class)->checkIn($this->f->clinic, $this->f->branch, $queued);
    $old = $this->f->patient('Long', 'Ago');
    PatientBranchActivity::create(['clinic_id' => $this->f->clinic->id, 'patient_id' => $old->id, 'branch_id' => $this->f->branch->id, 'first_seen_at' => now()->subDays(40), 'last_seen_at' => now()->subDays(40), 'first_source' => 'visit']);

    $ids = collect(($this->asReception)()->getJson('/clinic/reception/check-in/recent')->assertOk()->json('patients'))->pluck('id')->all();

    expect($ids)->toBe([$this->patient->id]);
});

test('the intake payload carries configuration, never patient data, and needs the queue permission', function () {
    $payload = ($this->asReception)()->getJson('/clinic/reception/intake')->assertOk()->json();

    expect(collect($payload['catalogue']['conditions'])->pluck('key'))->toContain('diabetes', 'heart_disease')
        ->and(collect($payload['catalogue']['visit_types'])->pluck('key')->all())->toBe(['consultation', 'follow_up', 'procedure', 'review'])
        ->and($payload['fields']['last_name']['state'])->toBe('required')
        ->and($payload['can'])->toBe(['register' => true, 'record_clinical' => true])
        ->and(json_encode($payload))->not->toContain('Rajesh');

    [$billing, $session] = $this->f->memberWithRole(ClinicRole::BillingStaff);
    $this->actingAs($billing)->withSession($session)->getJson('/clinic/reception/intake')->assertForbidden();
    $this->actingAs($billing)->withSession($session)->getJson('/clinic/reception/check-in/search?q=Rajesh')->assertForbidden();
});
