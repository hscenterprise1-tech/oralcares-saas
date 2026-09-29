<?php

use App\ClinicMembershipStatus;
use App\ClinicRole;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicEmployee;
use App\Models\ClinicEmployeeInvitation;
use App\Models\ClinicMembership;
use App\Models\OnboardingProgress;
use App\Models\Organization;
use App\Models\User;
use Spatie\Permission\Models\Role;

function setupEmployeeTest()
{
    $org = Organization::factory()->create();
    $clinic = Clinic::factory()->create(['organization_id' => $org->id]);
    $user = User::factory()->create(['organization_id' => $org->id]);

    ClinicMembership::factory()->create([
        'clinic_id' => $clinic->id,
        'user_id' => $user->id,
        'status' => ClinicMembershipStatus::Active,
        'has_all_branches_access' => true,
        // Admin pages require staff.manage / branch.manage; the owner always holds them.
        'is_primary_owner' => true,
    ]);

    OnboardingProgress::create([
        'organization_id' => $org->id,
        'current_step' => 'completed',
        'status' => 'completed',
    ]);

    return [$user, $clinic];
}

it('can list employees', function () {
    [$user] = setupEmployeeTest();
    $this->actingAs($user)->get(route('admin.employees.index'))->assertStatus(200);
});

it('can show create employee form', function () {
    [$user] = setupEmployeeTest();
    $this->actingAs($user)->get(route('admin.employees.create'))->assertStatus(200);
});

it('can store a new employee', function () {
    [$user, $clinic] = setupEmployeeTest();

    $response = $this->actingAs($user)->post(route('admin.employees.store'), [
        'name' => 'John Doe',
        'email' => 'john.doe@example.com',
        'phone' => '1234567890',
        'status' => 'active',
        'branches' => [],
        'roles' => [],
    ]);

    $response->assertRedirect(route('admin.employees.index'));
    expect(ClinicEmployee::where('email', 'john.doe@example.com')->exists())->toBeTrue();
});

it('can delete an employee', function () {
    [$user, $clinic] = setupEmployeeTest();

    $employee = ClinicEmployee::create([
        'clinic_id' => $clinic->id,
        'name' => 'Jane Doe',
        'email' => 'jane.doe@example.com',
        'status' => 'active',
    ]);

    $response = $this->actingAs($user)->delete(route('admin.employees.destroy', $employee->id));

    $response->assertRedirect(route('admin.employees.index'));
    expect(ClinicEmployee::where('id', $employee->id)->exists())->toBeFalse();
});

it('can update an employee\'s branches and roles', function () {
    [$user, $clinic] = setupEmployeeTest();

    $branch = Branch::factory()->create(['clinic_id' => $clinic->id, 'organization_id' => $clinic->organization_id]);

    setPermissionsTeamId(null);
    $role = Role::create(['name' => ClinicRole::Receptionist->value, 'guard_name' => 'web', 'branch_id' => null]);

    $employee = ClinicEmployee::create([
        'clinic_id' => $clinic->id,
        'name' => 'Update Me',
        'email' => 'update.me@example.com',
        'status' => 'active',
    ]);

    $response = $this->actingAs($user)->put(route('admin.employees.update', $employee->id), [
        'name' => 'Updated Name',
        'email' => 'update.me@example.com',
        'phone' => '9998887776',
        'status' => 'active',
        'branches' => [$branch->id],
        'roles' => [$role->id],
    ]);

    $response->assertRedirect(route('admin.employees.index'));

    $employee->refresh();
    expect($employee->name)->toBe('Updated Name')
        ->and($employee->phone)->toBe('9998887776')
        ->and($employee->branches->pluck('id')->all())->toBe([$branch->id])
        ->and($employee->roles->pluck('id')->all())->toBe([$role->id]);
});

it('employee index and edit expose the employee\'s active pending invitation', function () {
    [$user, $clinic] = setupEmployeeTest();

    $employee = ClinicEmployee::create([
        'clinic_id' => $clinic->id,
        'name' => 'Invited Person',
        'email' => 'invited.person@example.com',
        'status' => 'active',
    ]);

    ClinicEmployeeInvitation::create([
        'clinic_id' => $clinic->id,
        'clinic_employee_id' => $employee->id,
        'email' => 'invited.person@example.com',
        'token' => hash('sha256', 'sometoken'),
        'expires_at' => now()->addDays(3),
        'created_by' => $user->id,
    ]);

    $this->actingAs($user)->get(route('admin.employees.index'))->assertInertia(fn ($page) => $page
        ->component('Clinic/Admin/Employees/Index')
        ->where('employees.0.invitations.0.email', 'invited.person@example.com')
    );

    $this->actingAs($user)->get(route('admin.employees.edit', $employee->id))->assertInertia(fn ($page) => $page
        ->component('Clinic/Admin/Employees/Edit')
        ->where('employee.invitations.0.email', 'invited.person@example.com')
    );
});
