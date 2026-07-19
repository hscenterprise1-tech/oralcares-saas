<?php

use App\EmploymentStatus;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicDepartment;
use App\Models\ClinicMembership;
use App\Models\ClinicRoom;
use App\Models\User;
use App\Services\ClinicAuthorizationService;
use App\Services\ClinicInvitationService;
use App\Services\ClinicOrganizationService;
use App\Services\ClinicRoleService;
use App\UserType;
use Database\Seeders\SystemRoleTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(SystemRoleTemplateSeeder::class);
});

test('employee profiles can be created and assigned branches, departments, and rooms', function () {
    $clinic = Clinic::factory()->create();
    $branch1 = Branch::factory()->create(['clinic_id' => $clinic->id]);
    $branch2 = Branch::factory()->create(['clinic_id' => $clinic->id]);

    $dept1 = ClinicDepartment::create(['clinic_id' => $clinic->id, 'name' => 'General Surgery', 'key' => 'surgery']);
    $dept2 = ClinicDepartment::create(['clinic_id' => $clinic->id, 'name' => 'Orthodontics', 'key' => 'ortho']);

    $room1 = ClinicRoom::create(['branch_id' => $branch1->id, 'name' => 'Operatory 1', 'key' => 'op1', 'type' => 'operatory']);
    $room2 = ClinicRoom::create(['branch_id' => $branch1->id, 'name' => 'Consultation 1', 'key' => 'c1', 'type' => 'consultation']);

    $orgService = app(ClinicOrganizationService::class);

    $employee = $orgService->createEmployee($clinic, [
        'name' => 'Dr. Jane Smith',
        'email' => 'jane.smith@example.com',
        'phone' => '1234567890',
    ]);

    expect($employee->name)->toBe('Dr. Jane Smith')
        ->and($employee->status)->toBe(EmploymentStatus::PendingInvitation);

    // Assign branches
    $orgService->assignBranches($employee, [$branch1->id, $branch2->id]);
    expect($employee->branches)->toHaveCount(2)
        ->and($employee->branches->pluck('id'))->toContain($branch1->id, $branch2->id);

    // Assign departments
    $orgService->assignDepartments($employee, [$dept1->id, $dept2->id]);
    expect($employee->departments)->toHaveCount(2)
        ->and($employee->departments->pluck('id'))->toContain($dept1->id, $dept2->id);

    // Assign rooms
    $orgService->assignRooms($employee, [$room1->id, $room2->id]);
    expect($employee->rooms)->toHaveCount(2)
        ->and($employee->rooms->pluck('id'))->toContain($room1->id, $room2->id);
});

test('hierarchical reporting relationships can be established', function () {
    $clinic = Clinic::factory()->create();
    $orgService = app(ClinicOrganizationService::class);

    $manager = $orgService->createEmployee($clinic, [
        'name' => 'Dr. Chief Manager',
        'email' => 'chief@example.com',
    ]);

    $staff = $orgService->createEmployee($clinic, [
        'name' => 'Assistant Junior',
        'email' => 'junior@example.com',
    ]);

    $orgService->setReportTo($staff, $manager);

    expect($staff->fresh()->reportsTo->id)->toBe($manager->id)
        ->and($manager->fresh()->subordinates)->toHaveCount(1)
        ->and($manager->fresh()->subordinates->first()->id)->toBe($staff->id);
});

test('clinic role creation and permission resolution', function () {
    $clinic = Clinic::factory()->create();
    $branch = Branch::factory()->create(['clinic_id' => $clinic->id]);

    $orgService = app(ClinicOrganizationService::class);
    $employee = $orgService->createEmployee($clinic, [
        'name' => 'Nurse Joy',
        'email' => 'joy@example.com',
    ]);

    $orgService->assignBranches($employee, [$branch->id]);

    $roleService = app(ClinicRoleService::class);
    $role = $roleService->createRole($clinic, 'Senior Nurse', 'senior_nurse', ['patients.view', 'queue.manage']);

    $roleService->assignRoleToEmployee($employee, $role);

    $authService = app(ClinicAuthorizationService::class);

    // Check with branch
    expect($authService->hasPermission($employee, 'patients.view', $branch))->toBeTrue();
    expect($authService->hasPermission($employee, 'queue.manage', $branch))->toBeTrue();
    expect($authService->hasPermission($employee, 'patients.delete', $branch))->toBeFalse();

    // Check with unregistered branch should fail
    $otherBranch = Branch::factory()->create();
    expect($authService->hasPermission($employee, 'patients.view', $otherBranch))->toBeFalse();
});

test('invitation lifecycle generates tokens, accepts and creates users', function () {
    $clinic = Clinic::factory()->create();
    $invitedBy = User::factory()->create(['user_type' => UserType::MasterAdmin]);

    $orgService = app(ClinicOrganizationService::class);
    $employee = $orgService->createEmployee($clinic, [
        'name' => 'Assistant Joy',
        'email' => 'joy@example.com',
    ]);

    $invitationService = app(ClinicInvitationService::class);
    $invitation = $invitationService->inviteEmployee($employee, $invitedBy);

    expect($invitation->token)->not->toBeNull()
        ->and($employee->fresh()->status)->toBe(EmploymentStatus::Invited);

    // Accept invitation
    $updatedEmployee = $invitationService->acceptInvitation($invitation->token, 'secret_pass_123');

    expect($updatedEmployee->status)->toBe(EmploymentStatus::Active)
        ->and($updatedEmployee->user_id)->not->toBeNull();

    // Verify user record
    $user = User::find($updatedEmployee->user_id);
    expect($user->email)->toBe('joy@example.com')
        ->and(Hash::check('secret_pass_123', $user->password))->toBeTrue();

    // Verify ClinicMembership is also generated
    $membership = ClinicMembership::where('clinic_id', $clinic->id)->where('user_id', $user->id)->first();
    expect($membership)->not->toBeNull();
});
