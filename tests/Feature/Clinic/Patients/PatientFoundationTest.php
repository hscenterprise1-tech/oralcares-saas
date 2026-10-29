<?php

use App\BranchStatus;
use App\ClinicMembershipStatus;
use App\ClinicRole;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\ClinicMembership;
use App\Models\ClinicSpecialty;
use App\Models\ConsentRecord;
use App\Models\HealthcareSpecialtyMaster;
use App\Models\Patient;
use App\Models\PatientBranchActivity;
use App\Models\PatientSpecialty;
use App\Models\PatientVisit;
use App\Models\SecurityAuditLog;
use App\Models\TreatmentPlan;
use App\Models\User;
use App\Services\Clinical\EncounterWorkspace;
use App\Services\ClinicContext;
use App\Services\ClinicRoleAssignmentService;
use App\Services\Patient\ClinicSpecialties;
use App\Services\Patient\PatientSpecialties;
use App\Support\Specialty\SpecialtyRegistry;
use App\UserType;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Tests\Support\ReceptionFixture;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-03 10:00', 'Asia/Kolkata'));
    foreach ([['general_dentistry', 'dental'], ['physiotherapy', 'rehabilitation'], ['general_medicine', 'general']] as [$key, $category]) {
        HealthcareSpecialtyMaster::create(['key' => $key, 'name' => str($key)->headline(), 'category' => $category, 'category_label' => str($category)->headline(), 'is_active' => true]);
    }
    $this->f = ReceptionFixture::make();
    $this->f->clinic->update(['specialties' => ['dental']]);
    $this->asOwner = fn () => $this->actingAs($this->f->owner)->withSession($this->f->session);
    $this->register = fn (array $fields = []) => ($this->asOwner)()->post('/clinic/patients', [
        'first_name' => 'Kavya', 'middle_name' => 'Lakshmi', 'last_name' => 'Menon', 'phone' => '9811100001', 'gender' => 'female', ...$fields,
    ]);

    // A second branch and a member limited to it.
    $this->otherBranch = Branch::factory()->create(['clinic_id' => $this->f->clinic->id, 'name' => 'East', 'status' => BranchStatus::Active]);
    $this->f->memberWithRole(ClinicRole::Receptionist);
    $this->eastUser = User::factory()->create(['user_type' => UserType::ClinicUser]);
    $membership = ClinicMembership::factory()->create([
        'clinic_id' => $this->f->clinic->id, 'user_id' => $this->eastUser->id, 'is_primary_owner' => false,
        'has_all_branches_access' => false, 'status' => ClinicMembershipStatus::Active,
    ]);
    $membership->assignBranch($this->otherBranch);
    app(ClinicRoleAssignmentService::class)->syncRoles($this->eastUser, [ClinicRole::Receptionist->value], [$this->otherBranch]);
    $this->asEast = fn () => $this->actingAs($this->eastUser)->withSession([
        ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID => $membership->id, ClinicContext::SESSION_BRANCH_ID => $this->otherBranch->id,
    ]);
});

test('registration keeps a maintained full name, derives age, and records branch, specialty and consent', function () {
    ($this->register)(['date_of_birth' => '1990-10-04', 'preferred_channel' => 'whatsapp', 'communication_consent' => true])->assertSessionHasNoErrors();

    $patient = Patient::sole();
    expect($patient->getRawOriginal('name'))->toBe('Kavya Lakshmi Menon')
        ->and($patient->full_name)->toBe('Kavya Lakshmi Menon')
        ->and($patient->age)->toBe(35)
        ->and($patient->profile->preferred_channel)->toBe('whatsapp')
        ->and(PatientBranchActivity::sole())->branch_id->toBe($this->f->branch->id)->first_source->toBe('registration')
        ->and(PatientSpecialty::sole())->source->toBe('registration')->status->toBe(PatientSpecialty::ACTIVE)
        ->and(ConsentRecord::sole())->consent_type->toBe('communication')->captured_by->toBe($this->f->owner->id)->capture_method->toBe('verbal');

    $patient->update(['middle_name' => null, 'last_name' => 'Nair']);
    expect($patient->fresh()->getRawOriginal('name'))->toBe('Kavya Nair');
});

test('the date of birth is the source of age; a stored age is only kept for patients without one', function () {
    ($this->register)(['date_of_birth' => '2000-01-15', 'age' => 99])->assertSessionHasNoErrors();
    $withDob = Patient::sole();
    expect($withDob->age)->toBe(26)->and($withDob->ageSource())->toBe('date_of_birth')->and($withDob->getRawOriginal('age'))->toBeNull();

    $recorded = $this->f->patient('Old', 'Record', ['age' => 40]);
    expect($recorded->age)->toBe(40)->and($recorded->ageSource())->toBe('recorded');

    // Once a date of birth exists it wins, and the legacy value is neither read nor rewritten.
    $recorded->update(['date_of_birth' => '1990-06-01']);
    $recorded->update(['age' => 12]);
    expect($recorded->fresh()->age)->toBe(36)->and($recorded->fresh()->getRawOriginal('age'))->toBe(40);
});

test('registration takes only the clinic\'s active specialties, and a patient can hold several', function () {
    app(ClinicSpecialties::class)->sync($this->f->clinic, ['dental', 'physiotherapy']);

    ($this->register)(['specialties' => ['dental', 'ophthalmology']])->assertSessionHasErrors('specialties.1');
    ($this->register)(['specialties' => ['dental', 'physiotherapy']])->assertSessionHasNoErrors();

    expect(PatientSpecialty::query()->with('clinicSpecialty')->get()->pluck('clinicSpecialty.specialty_key')->sort()->values()->all())
        ->toBe(['dental', 'physiotherapy']);
});

test('a possible existing patient blocks registration on the server until every match is acknowledged', function () {
    $existing = $this->f->patient('Kavya', 'Menon', ['phone' => '9811100001']);
    $sameEmail = $this->f->patient('K', 'Menon', ['email' => 'Kavya@Example.com']);

    ($this->register)(['email' => 'kavya@example.com'])->assertSessionHasErrors('duplicate')->assertSessionHas('possible_duplicates');
    ($this->asOwner)()->postJson('/clinic/patients', ['first_name' => 'Kavya', 'last_name' => 'Menon', 'phone' => '98111 00001', 'gender' => 'female'])
        ->assertStatus(409)->assertJsonPath('possible_duplicates.0.id', $existing->id)->assertJsonPath('possible_duplicates.0.matched_on', ['phone']);
    expect(Patient::count())->toBe(2);

    // Acknowledging only one of the two matches is not enough.
    ($this->register)(['email' => 'kavya@example.com', 'duplicate_acknowledged_ids' => [$existing->id]])->assertSessionHasErrors('duplicate');
    expect(Patient::count())->toBe(2);

    ($this->register)(['email' => 'kavya@example.com', 'duplicate_acknowledged_ids' => [$existing->id, $sameEmail->id], 'duplicate_reason' => 'Twin sister sharing the family phone'])
        ->assertSessionHasNoErrors()->assertRedirect();

    $audit = SecurityAuditLog::where('event', 'patient.duplicate_override')->sole();
    expect(Patient::count())->toBe(3)
        ->and($audit->context['possible_duplicate_ids'])->toEqualCanonicalizing([$existing->id, $sameEmail->id])
        ->and($audit->context['reason'])->toBe('Twin sister sharing the family phone');
});

test('the live duplicate check shows the same privacy-safe match as the gate, never the full phone or email', function () {
    $existing = $this->f->patient('Kavya', 'Menon', ['phone' => '9811100001', 'email' => 'kavya@example.com']);

    $json = ($this->asOwner)()->postJson('/clinic/patients/check-duplicate', ['phone' => '9811100001', 'email' => 'KAVYA@example.com'])
        ->assertOk()->assertJsonPath('has_duplicates', true)
        ->assertJsonPath('matches.0.id', $existing->id)->assertJsonPath('matches.0.phone_hint', '••••0001')
        ->assertJsonPath('matches.0.matched_on', ['phone', 'email'])->json();

    expect(json_encode($json))->not->toContain('9811100001')->not->toContain('kavya@example.com')
        ->and(array_keys($json['matches'][0]))->not->toContain('phone', 'email');
});

test('name and date of birth together find a duplicate; either alone does not', function () {
    $this->f->patient('Rohan', 'Das', ['phone' => '9000000001', 'date_of_birth' => '1985-02-01']);

    ($this->register)(['first_name' => 'rohan', 'middle_name' => null, 'last_name' => 'DAS', 'phone' => '9000000002', 'date_of_birth' => '1985-02-01'])->assertSessionHasErrors('duplicate');
    ($this->register)(['first_name' => 'Rohan', 'middle_name' => null, 'last_name' => 'Das', 'phone' => '9000000003', 'date_of_birth' => '1986-02-01'])->assertSessionHasNoErrors();
});

test('updating allergies keeps the patient\'s manual safety alerts', function () {
    ($this->register)(['medical_alerts' => ['Pacemaker'], 'allergies' => ['Latex']])->assertSessionHasNoErrors();
    $patient = Patient::sole();

    ($this->asOwner)()->put("/clinic/patients/{$patient->id}", ['first_name' => 'Kavya', 'last_name' => 'Menon', 'phone' => '9811100001', 'gender' => 'female', 'allergies' => ['Penicillin'], 'medical_alerts' => ['Pacemaker']])
        ->assertSessionHasNoErrors();

    expect($patient->alerts()->orderBy('type')->pluck('description', 'type')->all())->toBe(['allergy' => 'Allergy: Penicillin', 'manual' => 'Pacemaker']);
});

test('the clinic\'s specialties live in clinic_specialties, and deactivating keeps the row', function () {
    $registry = app(SpecialtyRegistry::class);
    expect(ClinicSpecialty::sole())->specialty_key->toBe('dental')->master->key->toBe('general_dentistry');

    $this->f->clinic->update(['specialties' => ['general_hospital', 'physio']]);
    $clinic = $this->f->clinic->fresh();

    expect(ClinicSpecialty::query()->orderBy('specialty_key')->pluck('status', 'specialty_key')->all())
        ->toBe(['dental' => 'inactive', 'general_medicine' => 'active', 'physiotherapy' => 'active'])
        ->and($registry->forClinic($clinic))->toBe(['general_medicine', 'physiotherapy'])
        // A direct legacy write is mirrored, not rewritten.
        ->and($clinic->specialties)->toBe(['general_hospital', 'physio']);
});

test('an enrolment comes from an explicit act; activity only moves last_activity_at', function () {
    $patient = $this->f->patient();
    app(ClinicSpecialties::class)->sync($this->f->clinic, ['dental', 'physiotherapy']);

    $this->actingAs($this->f->owner);
    $encounter = app(EncounterWorkspace::class)->startForPatient($patient, $this->f->branch, $this->f->doctorA, null, 'physiotherapy');
    expect(PatientSpecialty::sole())->source->toBe('encounter');

    // Ordinary activity in a specialty the patient is not enrolled in creates nothing.
    $this->travel(1)->day();
    app(PatientSpecialties::class)->touch($patient->clinic_id, $patient->id, 'dental', now());
    app(PatientSpecialties::class)->touch($patient->clinic_id, $patient->id, 'physiotherapy', now());

    expect(PatientSpecialty::count())->toBe(1)
        ->and(PatientSpecialty::sole()->last_activity_at->isSameDay(now()))->toBeTrue()
        ->and(fn () => app(PatientSpecialties::class)->enroll($patient, 'ophthalmology', 'manual'))->toThrow(DomainException::class);
});

test('a branch-limited member sees only patients with activity at their branches', function () {
    $here = $this->f->patient('Main', 'Only');
    PatientVisit::create(['clinic_id' => $this->f->clinic->id, 'branch_id' => $this->f->branch->id, 'patient_id' => $here->id, 'visit_date' => today(), 'registration_type' => 'new', 'queue_status' => 'not_queued', 'visit_status' => 'registered']);
    $east = $this->f->patient('East', 'Seen');
    PatientVisit::create(['clinic_id' => $this->f->clinic->id, 'branch_id' => $this->otherBranch->id, 'patient_id' => $east->id, 'visit_date' => today(), 'registration_type' => 'new', 'queue_status' => 'not_queued', 'visit_status' => 'registered']);

    ($this->asEast)()->get('/clinic/patients?view=all')->assertOk()
        ->assertInertia(fn ($page) => $page->has('patients.data', 1)->where('patients.data.0.id', $east->id));
    ($this->asEast)()->get("/clinic/patients/{$here->id}")->assertForbidden();
    ($this->asEast)()->put("/clinic/patients/{$here->id}", ['first_name' => 'Changed', 'last_name' => 'Name', 'phone' => '9000011111', 'gender' => 'male'])->assertForbidden();
    ($this->asEast)()->get("/clinic/patients/{$east->id}")->assertOk();

    // Unlimited members still see every clinic patient.
    ($this->asOwner)()->get('/clinic/patients')->assertInertia(fn ($page) => $page->has('patients.data', 2));

    // An appointment booked at East opens the other patient for them too.
    $this->f->appointment($here, $this->f->doctorA, now()->addDay())->update(['branch_id' => $this->otherBranch->id]);
    Appointment::create(['clinic_id' => $this->f->clinic->id, 'branch_id' => $this->otherBranch->id, 'patient_id' => $here->id, 'practitioner_id' => $this->f->doctorA->id, 'scheduled_at' => now()->addDays(2), 'duration_minutes' => 15, 'status' => 'scheduled']);
    ($this->asEast)()->get("/clinic/patients/{$here->id}")->assertOk();
});

test('a branch-limited clinician cannot write clinical data for a patient seen only at another branch', function () {
    $patient = $this->f->patient('Main', 'Only');
    PatientVisit::create(['clinic_id' => $this->f->clinic->id, 'branch_id' => $this->f->branch->id, 'patient_id' => $patient->id, 'visit_date' => today(), 'registration_type' => 'new', 'queue_status' => 'not_queued', 'visit_status' => 'registered']);
    $plan = TreatmentPlan::query()->withoutGlobalScopes()->create(['clinic_id' => $this->f->clinic->id, 'patient_id' => $patient->id, 'title' => 'Restore 36', 'status' => TreatmentPlan::PROPOSED]);
    $this->f->memberWithRole(ClinicRole::Doctor);
    $doctorAt = function (Branch $branch): array {
        $user = User::factory()->create(['user_type' => UserType::ClinicUser]);
        $membership = ClinicMembership::factory()->create([
            'clinic_id' => $this->f->clinic->id, 'user_id' => $user->id, 'is_primary_owner' => false,
            'has_all_branches_access' => false, 'status' => ClinicMembershipStatus::Active,
        ]);
        $membership->assignBranch($branch);
        app(ClinicRoleAssignmentService::class)->syncRoles($user, [ClinicRole::Doctor->value], [$branch]);
        $this->f->doctor("Dr {$branch->name}", $user);

        return [$user, [ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID => $membership->id, ClinicContext::SESSION_BRANCH_ID => $branch->id]];
    };
    $accept = "/clinic/patients/{$patient->id}/dental/plans/{$plan->id}/accept";

    [$east, $eastSession] = $doctorAt($this->otherBranch);
    $this->actingAs($east)->withSession($eastSession)->post($accept)->assertForbidden();
    expect($plan->fresh()->status)->toBe(TreatmentPlan::PROPOSED);

    [$main, $mainSession] = $doctorAt($this->f->branch);
    $this->actingAs($main)->withSession($mainSession)->post($accept)->assertRedirect();
});

test('another clinic cannot open, list or enrol this clinic\'s patients', function () {
    $patient = $this->f->patient();
    $other = ReceptionFixture::make('other-clinic');
    $other->clinic->update(['specialties' => ['dental']]);

    $this->actingAs($other->owner)->withSession($other->session)->get("/clinic/patients/{$patient->id}")->assertForbidden();
    $this->actingAs($other->owner)->withSession($other->session)->get('/clinic/patients')
        ->assertInertia(fn ($page) => $page->has('patients.data', 0));

    app(ClinicContext::class)->runForClinic($other->clinic, null, function () use ($patient) {
        expect(PatientSpecialty::query()->where('patient_id', $patient->id)->count())->toBe(0)
            ->and(PatientBranchActivity::query()->where('patient_id', $patient->id)->count())->toBe(0);
    });

    // The database refuses a branch-activity row tying this patient to the other clinic's branch.
    expect(fn () => PatientBranchActivity::query()->withoutGlobalScopes()->create([
        'clinic_id' => $patient->clinic_id, 'patient_id' => $patient->id, 'branch_id' => $other->branch->id,
        'first_seen_at' => now(), 'last_seen_at' => now(), 'first_source' => 'visit',
    ]))->toThrow(QueryException::class);
});

test('an unauthenticated or non-clinic user cannot reach patients', function () {
    $patient = $this->f->patient();

    $this->get("/clinic/patients/{$patient->id}")->assertRedirect();
    $this->postJson('/clinic/patients', ['first_name' => 'X', 'phone' => '1'])->assertUnauthorized();
});
