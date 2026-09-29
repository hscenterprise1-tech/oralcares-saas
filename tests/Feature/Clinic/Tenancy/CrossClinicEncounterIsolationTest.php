<?php

use App\BranchStatus;
use App\ClinicMembershipStatus;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicalDiagnosis;
use App\Models\ClinicalEncounter;
use App\Models\ClinicalNote;
use App\Models\ClinicMembership;
use App\Models\Organization;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\User;
use App\Services\ClinicContext;
use App\Services\EncounterManagementService;
use App\UserType;
use App\VisitStatus;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;

beforeEach(function () {
    app()->instance('enforce_tenant_scope_in_tests', true);
});

test('ClinicalEncounter is strictly isolated between clinics and Clinic A cannot read Clinic B encounters', function () {
    $org = Organization::factory()->create();

    $clinicA = Clinic::factory()->create(['organization_id' => $org->id, 'name' => 'Clinic Alpha']);
    $clinicB = Clinic::factory()->create(['organization_id' => $org->id, 'name' => 'Clinic Beta']);

    $branchA = Branch::factory()->create(['clinic_id' => $clinicA->id, 'organization_id' => $org->id]);
    $branchB = Branch::factory()->create(['clinic_id' => $clinicB->id, 'organization_id' => $org->id]);

    $patientA = Patient::withoutGlobalScopes()->create([
        'organization_id' => $org->id,
        'clinic_id' => $clinicA->id,
        'first_name' => 'Alice',
        'last_name' => 'Alpha',
        'phone' => '9000000001',
    ]);

    $patientB = Patient::withoutGlobalScopes()->create([
        'organization_id' => $org->id,
        'clinic_id' => $clinicB->id,
        'first_name' => 'Bob',
        'last_name' => 'Beta',
        'phone' => '9000000002',
    ]);

    $encounterA = ClinicalEncounter::withoutGlobalScopes()->create([
        'organization_id' => $org->id,
        'clinic_id' => $clinicA->id,
        'branch_id' => $branchA->id,
        'patient_id' => $patientA->id,
        'encounter_number' => 'ENC-A-001',
        'status' => 'in_progress',
        'started_at' => now(),
    ]);

    $encounterB = ClinicalEncounter::withoutGlobalScopes()->create([
        'organization_id' => $org->id,
        'clinic_id' => $clinicB->id,
        'branch_id' => $branchB->id,
        'patient_id' => $patientB->id,
        'encounter_number' => 'ENC-B-002',
        'status' => 'in_progress',
        'started_at' => now(),
    ]);

    $userA = User::factory()->create(['user_type' => UserType::ClinicUser]);
    $membershipA = ClinicMembership::factory()->create([
        'clinic_id' => $clinicA->id,
        'user_id' => $userA->id,
        'status' => ClinicMembershipStatus::Active,
        'has_all_branches_access' => true,
    ]);

    // Act as user of Clinic A
    $this->actingAs($userA);
    app(ClinicContext::class)->activateMembership($membershipA);

    // 1. Reading all encounters
    $visibleEncounters = ClinicalEncounter::all();
    expect($visibleEncounters->pluck('id'))->toContain($encounterA->id)
        ->and($visibleEncounters->pluck('id'))->not->toContain($encounterB->id);

    // 2. Direct lookup of Clinic B encounter
    expect(ClinicalEncounter::find($encounterB->id))->toBeNull();
    expect(fn () => ClinicalEncounter::findOrFail($encounterB->id))
        ->toThrow(ModelNotFoundException::class);
});

test('Clinic A cannot update or delete Clinic B encounters', function () {
    $org = Organization::factory()->create();

    $clinicA = Clinic::factory()->create(['organization_id' => $org->id]);
    $clinicB = Clinic::factory()->create(['organization_id' => $org->id]);

    $branchA = Branch::factory()->create(['clinic_id' => $clinicA->id, 'organization_id' => $org->id]);
    $branchB = Branch::factory()->create(['clinic_id' => $clinicB->id, 'organization_id' => $org->id]);

    $patientB = Patient::withoutGlobalScopes()->create([
        'organization_id' => $org->id,
        'clinic_id' => $clinicB->id,
        'first_name' => 'Bob',
        'last_name' => 'Beta',
        'phone' => '9000000003',
    ]);

    $encounterB = ClinicalEncounter::withoutGlobalScopes()->create([
        'organization_id' => $org->id,
        'clinic_id' => $clinicB->id,
        'branch_id' => $branchB->id,
        'patient_id' => $patientB->id,
        'encounter_number' => 'ENC-B-SECURE',
        'status' => 'in_progress',
        'started_at' => now(),
    ]);

    $userA = User::factory()->create(['user_type' => UserType::ClinicUser]);
    $membershipA = ClinicMembership::factory()->create([
        'clinic_id' => $clinicA->id,
        'user_id' => $userA->id,
        'status' => ClinicMembershipStatus::Active,
        'has_all_branches_access' => true,
    ]);

    $this->actingAs($userA);
    app(ClinicContext::class)->activateMembership($membershipA);

    // 1. Attempt update on Clinic B encounter through query builder
    $updatedCount = ClinicalEncounter::where('id', $encounterB->id)->update([
        'status' => 'completed',
    ]);
    expect($updatedCount)->toBe(0);

    // Verify Clinic B encounter remains unchanged in the database
    $freshEncounterB = ClinicalEncounter::withoutGlobalScopes()->find($encounterB->id);
    expect($freshEncounterB->status)->toBe(VisitStatus::InProgress);

    // 2. Attempt delete on Clinic B encounter through query builder
    $deletedCount = ClinicalEncounter::where('id', $encounterB->id)->delete();
    expect($deletedCount)->toBe(0);

    // Verify Clinic B encounter still exists
    expect(ClinicalEncounter::withoutGlobalScopes()->find($encounterB->id))->not->toBeNull();
});

test('child clinical records (notes, diagnoses, prescriptions) are isolated and protected against cross-clinic operations', function () {
    $org = Organization::factory()->create();

    $clinicA = Clinic::factory()->create(['organization_id' => $org->id]);
    $clinicB = Clinic::factory()->create(['organization_id' => $org->id]);

    $branchB = Branch::factory()->create(['clinic_id' => $clinicB->id, 'organization_id' => $org->id]);

    $patientB = Patient::withoutGlobalScopes()->create([
        'organization_id' => $org->id,
        'clinic_id' => $clinicB->id,
        'first_name' => 'Charlie',
        'last_name' => 'Brown',
        'phone' => '9000000004',
    ]);

    $encounterB = ClinicalEncounter::withoutGlobalScopes()->create([
        'organization_id' => $org->id,
        'clinic_id' => $clinicB->id,
        'branch_id' => $branchB->id,
        'patient_id' => $patientB->id,
        'encounter_number' => 'ENC-B-CHILDREN',
        'status' => 'in_progress',
        'started_at' => now(),
    ]);

    // Create child records under Clinic B
    $noteB = ClinicalNote::withoutGlobalScopes()->create([
        'organization_id' => $org->id,
        'clinic_id' => $clinicB->id,
        'patient_id' => $patientB->id,
        'encounter_id' => $encounterB->id,
        'note_type' => 'progress',
        'content' => 'Confidential patient clinical notes for Clinic B',
    ]);

    $diagnosisB = ClinicalDiagnosis::withoutGlobalScopes()->create([
        'organization_id' => $org->id,
        'clinic_id' => $clinicB->id,
        'encounter_id' => $encounterB->id,
        'patient_id' => $patientB->id,
        'description' => 'Periodontitis',
        'status' => 'confirmed',
    ]);

    $prescriptionB = Prescription::withoutGlobalScopes()->create([
        'organization_id' => $org->id,
        'clinic_id' => $clinicB->id,
        'encounter_id' => $encounterB->id,
        'patient_id' => $patientB->id,
        'prescription_number' => 'RX-B-001',
        'status' => 'active',
    ]);

    // User belonging to Clinic A
    $userA = User::factory()->create(['user_type' => UserType::ClinicUser]);
    $membershipA = ClinicMembership::factory()->create([
        'clinic_id' => $clinicA->id,
        'user_id' => $userA->id,
        'status' => ClinicMembershipStatus::Active,
        'has_all_branches_access' => true,
    ]);

    $this->actingAs($userA);
    app(ClinicContext::class)->activateMembership($membershipA);

    // 1. Reading child records under Clinic A context
    expect(ClinicalNote::find($noteB->id))->toBeNull();
    expect(ClinicalDiagnosis::find($diagnosisB->id))->toBeNull();
    expect(Prescription::find($prescriptionB->id))->toBeNull();

    // 2. Attempting to update Clinic B child records under Clinic A context
    expect(ClinicalNote::where('id', $noteB->id)->update(['content' => 'Tampered notes']))->toBe(0);
    expect(ClinicalDiagnosis::where('id', $diagnosisB->id)->update(['status' => 'resolved']))->toBe(0);
    expect(Prescription::where('id', $prescriptionB->id)->update(['status' => 'cancelled']))->toBe(0);

    // Verify records remained unchanged
    expect(ClinicalNote::withoutGlobalScopes()->find($noteB->id)->content)->toBe('Confidential patient clinical notes for Clinic B');
    expect(ClinicalDiagnosis::withoutGlobalScopes()->find($diagnosisB->id)->status)->toBe('confirmed');
    expect(Prescription::withoutGlobalScopes()->find($prescriptionB->id)->status)->toBe('active');

    // 3. Attempting to delete Clinic B child records under Clinic A context
    expect(ClinicalNote::where('id', $noteB->id)->delete())->toBe(0);
    expect(ClinicalDiagnosis::where('id', $diagnosisB->id)->delete())->toBe(0);
    expect(Prescription::where('id', $prescriptionB->id)->delete())->toBe(0);

    // Verify records were not deleted
    expect(ClinicalNote::withoutGlobalScopes()->find($noteB->id))->not->toBeNull();
    expect(ClinicalDiagnosis::withoutGlobalScopes()->find($diagnosisB->id))->not->toBeNull();
    expect(Prescription::withoutGlobalScopes()->find($prescriptionB->id))->not->toBeNull();
});

test('starting an encounter rejects cross-clinic patient and branch misalignment', function () {
    $org = Organization::factory()->create();

    $clinicA = Clinic::factory()->create(['organization_id' => $org->id]);
    $clinicB = Clinic::factory()->create(['organization_id' => $org->id]);

    $branchA = Branch::factory()->create(['clinic_id' => $clinicA->id, 'organization_id' => $org->id]);
    $branchB = Branch::factory()->create(['clinic_id' => $clinicB->id, 'organization_id' => $org->id]);

    $patientA = Patient::withoutGlobalScopes()->create([
        'organization_id' => $org->id,
        'clinic_id' => $clinicA->id,
        'first_name' => 'Alice',
        'last_name' => 'Alpha',
        'phone' => '9000000005',
    ]);

    $service = new EncounterManagementService;

    // Attempt to start an encounter for Clinic A's patient using Clinic B's branch
    expect(fn () => $service->startEncounter($patientA, $branchB))
        ->toThrow(InvalidArgumentException::class, 'Branch does not belong to the patient clinic.');
});

test('branch authorization is strictly enforced when user lacks access to the branch', function () {
    $org = Organization::factory()->create();
    $clinic = Clinic::factory()->create(['organization_id' => $org->id]);

    $branchAllowed = Branch::factory()->create(['clinic_id' => $clinic->id, 'organization_id' => $org->id, 'status' => BranchStatus::Active]);
    $branchRestricted = Branch::factory()->create(['clinic_id' => $clinic->id, 'organization_id' => $org->id, 'status' => BranchStatus::Active]);

    $patient = Patient::withoutGlobalScopes()->create([
        'organization_id' => $org->id,
        'clinic_id' => $clinic->id,
        'first_name' => 'Dave',
        'last_name' => 'Dent',
        'phone' => '9000000006',
    ]);

    $user = User::factory()->create(['user_type' => UserType::ClinicUser]);
    $membership = ClinicMembership::factory()->create([
        'clinic_id' => $clinic->id,
        'user_id' => $user->id,
        'status' => ClinicMembershipStatus::Active,
        'has_all_branches_access' => false,
    ]);

    // Grant access only to $branchAllowed
    $membership->assignBranch($branchAllowed);

    $this->actingAs($user);
    $clinicContext = app(ClinicContext::class);
    $clinicContext->activateMembership($membership);
    $clinicContext->activateBranch($branchAllowed);

    $service = new EncounterManagementService;

    // Starting encounter at permitted branch succeeds
    $encounter = $service->startEncounter($patient, $branchAllowed);
    expect($encounter)->not->toBeNull()
        ->and($encounter->branch_id)->toBe($branchAllowed->id)
        ->and($encounter->clinic_id)->toBe($clinic->id);

    // Starting encounter at restricted branch throws AuthorizationException
    expect(fn () => $service->startEncounter($patient, $branchRestricted))
        ->toThrow(AuthorizationException::class, 'User does not have access to the specified branch.');
});

test('cross-organization isolation is strictly enforced for encounters and clinical records', function () {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();

    $clinicA = Clinic::factory()->create(['organization_id' => $orgA->id]);
    $clinicB = Clinic::factory()->create(['organization_id' => $orgB->id]);

    $branchA = Branch::factory()->create(['clinic_id' => $clinicA->id, 'organization_id' => $orgA->id]);
    $branchB = Branch::factory()->create(['clinic_id' => $clinicB->id, 'organization_id' => $orgB->id]);

    $patientB = Patient::withoutGlobalScopes()->create([
        'organization_id' => $orgB->id,
        'clinic_id' => $clinicB->id,
        'first_name' => 'Eve',
        'last_name' => 'External',
        'phone' => '9000000007',
    ]);

    $encounterB = ClinicalEncounter::withoutGlobalScopes()->create([
        'organization_id' => $orgB->id,
        'clinic_id' => $clinicB->id,
        'branch_id' => $branchB->id,
        'patient_id' => $patientB->id,
        'encounter_number' => 'ENC-EXT-001',
        'status' => 'in_progress',
        'started_at' => now(),
    ]);

    $userA = User::factory()->create(['user_type' => UserType::ClinicUser]);
    $membershipA = ClinicMembership::factory()->create([
        'clinic_id' => $clinicA->id,
        'user_id' => $userA->id,
        'status' => ClinicMembershipStatus::Active,
        'has_all_branches_access' => true,
    ]);

    $this->actingAs($userA);
    app(ClinicContext::class)->activateMembership($membershipA);

    // Cannot find encounter from another organization
    expect(ClinicalEncounter::find($encounterB->id))->toBeNull();

    // Query attempting to explicitly filter by clinicB id under clinicA context returns 0 rows
    $encountersExplicitQuery = ClinicalEncounter::where('clinic_id', $clinicB->id)->get();
    expect($encountersExplicitQuery)->toHaveCount(0);
});

test('explicit CRUD (Read, Create, Update, Delete) isolation for Encounter, Note, Diagnosis, and Prescription across Clinic A and Clinic B', function () {
    $org = Organization::factory()->create();

    $clinicA = Clinic::factory()->create(['organization_id' => $org->id, 'name' => 'Clinic A']);
    $clinicB = Clinic::factory()->create(['organization_id' => $org->id, 'name' => 'Clinic B']);

    $branchA = Branch::factory()->create(['clinic_id' => $clinicA->id, 'organization_id' => $org->id]);
    $branchB = Branch::factory()->create(['clinic_id' => $clinicB->id, 'organization_id' => $org->id]);

    $patientA = Patient::withoutGlobalScopes()->create([
        'organization_id' => $org->id,
        'clinic_id' => $clinicA->id,
        'first_name' => 'Patient',
        'last_name' => 'Alpha',
        'phone' => '9111111111',
    ]);

    $patientB = Patient::withoutGlobalScopes()->create([
        'organization_id' => $org->id,
        'clinic_id' => $clinicB->id,
        'first_name' => 'Patient',
        'last_name' => 'Beta',
        'phone' => '9222222222',
    ]);

    // Pre-create Clinic B records (raw without scopes to simulate existing tenant data)
    $encounterB = ClinicalEncounter::withoutGlobalScopes()->create([
        'organization_id' => $org->id,
        'clinic_id' => $clinicB->id,
        'branch_id' => $branchB->id,
        'patient_id' => $patientB->id,
        'encounter_number' => 'ENC-B-CRUD',
        'status' => 'in_progress',
        'started_at' => now(),
    ]);

    $noteB = ClinicalNote::withoutGlobalScopes()->create([
        'organization_id' => $org->id,
        'clinic_id' => $clinicB->id,
        'patient_id' => $patientB->id,
        'encounter_id' => $encounterB->id,
        'note_type' => 'SOAP',
        'content' => 'Original Clinic B Clinical Note',
    ]);

    $diagnosisB = ClinicalDiagnosis::withoutGlobalScopes()->create([
        'organization_id' => $org->id,
        'clinic_id' => $clinicB->id,
        'encounter_id' => $encounterB->id,
        'patient_id' => $patientB->id,
        'description' => 'Original Clinic B Diagnosis',
        'status' => 'confirmed',
    ]);

    $prescriptionB = Prescription::withoutGlobalScopes()->create([
        'organization_id' => $org->id,
        'clinic_id' => $clinicB->id,
        'encounter_id' => $encounterB->id,
        'patient_id' => $patientB->id,
        'prescription_number' => 'RX-B-CRUD',
        'status' => 'active',
    ]);

    // Setup active context for Clinic A
    $userA = User::factory()->create(['user_type' => UserType::ClinicUser]);
    $membershipA = ClinicMembership::factory()->create([
        'clinic_id' => $clinicA->id,
        'user_id' => $userA->id,
        'status' => ClinicMembershipStatus::Active,
        'has_all_branches_access' => true,
    ]);

    $this->actingAs($userA);
    app(ClinicContext::class)->activateMembership($membershipA);

    // ==========================================
    // 1. READ ISOLATION (Clinic A cannot read Clinic B records)
    // ==========================================
    expect(ClinicalEncounter::find($encounterB->id))->toBeNull()
        ->and(ClinicalNote::find($noteB->id))->toBeNull()
        ->and(ClinicalDiagnosis::find($diagnosisB->id))->toBeNull()
        ->and(Prescription::find($prescriptionB->id))->toBeNull();

    // ==========================================
    // 2. UPDATE ISOLATION (Clinic A cannot update Clinic B records)
    // ==========================================
    expect(ClinicalEncounter::where('id', $encounterB->id)->update(['status' => 'cancelled']))->toBe(0)
        ->and(ClinicalNote::where('id', $noteB->id)->update(['content' => 'Hacked Note']))->toBe(0)
        ->and(ClinicalDiagnosis::where('id', $diagnosisB->id)->update(['description' => 'Hacked Diagnosis']))->toBe(0)
        ->and(Prescription::where('id', $prescriptionB->id)->update(['status' => 'cancelled']))->toBe(0);

    // Assert Clinic B data is completely unaltered in database
    expect(ClinicalEncounter::withoutGlobalScopes()->find($encounterB->id)->status)->toBe(VisitStatus::InProgress)
        ->and(ClinicalNote::withoutGlobalScopes()->find($noteB->id)->content)->toBe('Original Clinic B Clinical Note')
        ->and(ClinicalDiagnosis::withoutGlobalScopes()->find($diagnosisB->id)->description)->toBe('Original Clinic B Diagnosis')
        ->and(Prescription::withoutGlobalScopes()->find($prescriptionB->id)->status)->toBe('active');

    // ==========================================
    // 3. DELETE ISOLATION (Clinic A cannot delete Clinic B records)
    // ==========================================
    expect(ClinicalEncounter::where('id', $encounterB->id)->delete())->toBe(0)
        ->and(ClinicalNote::where('id', $noteB->id)->delete())->toBe(0)
        ->and(ClinicalDiagnosis::where('id', $diagnosisB->id)->delete())->toBe(0)
        ->and(Prescription::where('id', $prescriptionB->id)->delete())->toBe(0);

    // Assert Clinic B records still exist in database
    expect(ClinicalEncounter::withoutGlobalScopes()->find($encounterB->id))->not->toBeNull()
        ->and(ClinicalNote::withoutGlobalScopes()->find($noteB->id))->not->toBeNull()
        ->and(ClinicalDiagnosis::withoutGlobalScopes()->find($diagnosisB->id))->not->toBeNull()
        ->and(Prescription::withoutGlobalScopes()->find($prescriptionB->id))->not->toBeNull();

    // ==========================================
    // 4. CREATE ISOLATION (Clinic A creates records; they are strictly scoped to Clinic A and invisible to Clinic B)
    // ==========================================
    $encounterA = ClinicalEncounter::create([
        'branch_id' => $branchA->id,
        'patient_id' => $patientA->id,
        'encounter_number' => 'ENC-A-CREATED',
        'status' => 'in_progress',
        'started_at' => now(),
    ]);

    $noteA = ClinicalNote::create([
        'patient_id' => $patientA->id,
        'encounter_id' => $encounterA->id,
        'note_type' => 'SOAP',
        'content' => 'Clinic A Clinical Note Content',
    ]);

    $diagnosisA = ClinicalDiagnosis::create([
        'encounter_id' => $encounterA->id,
        'patient_id' => $patientA->id,
        'description' => 'Clinic A Diagnosis',
        'status' => 'confirmed',
    ]);

    $prescriptionA = Prescription::create([
        'encounter_id' => $encounterA->id,
        'patient_id' => $patientA->id,
        'prescription_number' => 'RX-A-CREATED',
        'status' => 'active',
    ]);

    // Clinic A can read its own newly created records
    expect($encounterA->clinic_id)->toBe($clinicA->id)
        ->and($noteA->clinic_id)->toBe($clinicA->id)
        ->and($diagnosisA->clinic_id)->toBe($clinicA->id)
        ->and($prescriptionA->clinic_id)->toBe($clinicA->id);

    // Switch context to Clinic B
    $userB = User::factory()->create(['user_type' => UserType::ClinicUser]);
    $membershipB = ClinicMembership::factory()->create([
        'clinic_id' => $clinicB->id,
        'user_id' => $userB->id,
        'status' => ClinicMembershipStatus::Active,
        'has_all_branches_access' => true,
    ]);

    $this->actingAs($userB);
    app(ClinicContext::class)->activateMembership($membershipB);

    // Clinic B cannot read, update, or delete Clinic A's newly created records
    expect(ClinicalEncounter::find($encounterA->id))->toBeNull()
        ->and(ClinicalNote::find($noteA->id))->toBeNull()
        ->and(ClinicalDiagnosis::find($diagnosisA->id))->toBeNull()
        ->and(Prescription::find($prescriptionA->id))->toBeNull();

    expect(ClinicalEncounter::where('id', $encounterA->id)->update(['status' => 'cancelled']))->toBe(0)
        ->and(ClinicalNote::where('id', $noteA->id)->update(['content' => 'Tampered By B']))->toBe(0)
        ->and(ClinicalDiagnosis::where('id', $diagnosisA->id)->update(['description' => 'Tampered By B']))->toBe(0)
        ->and(Prescription::where('id', $prescriptionA->id)->update(['status' => 'cancelled']))->toBe(0);

    expect(ClinicalEncounter::where('id', $encounterA->id)->delete())->toBe(0)
        ->and(ClinicalNote::where('id', $noteA->id)->delete())->toBe(0)
        ->and(ClinicalDiagnosis::where('id', $diagnosisA->id)->delete())->toBe(0)
        ->and(Prescription::where('id', $prescriptionA->id)->delete())->toBe(0);
});
