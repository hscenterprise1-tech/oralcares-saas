<?php

use App\DepartmentStatus;
use App\EmploymentStatus;
use App\Models\CompanyDepartment;
use App\Models\CompanyEmployee;
use App\Models\CompanyTeam;
use App\Models\User;
use App\TeamStatus;
use Database\Seeders\CompanyOrganizationSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('a department can be created and contains correct defaults', function () {
    $department = CompanyDepartment::create([
        'name' => 'Finance and Accounts',
        'code' => 'finance-accounts',
        'description' => 'Handles company invoices and accounting',
    ]);

    $department->refresh();

    expect($department->id)->not->toBeNull()
        ->and($department->name)->toBe('Finance and Accounts')
        ->and($department->code)->toBe('finance-accounts')
        ->and($department->description)->toBe('Handles company invoices and accounting')
        ->and($department->status)->toBe(DepartmentStatus::Active)
        ->and($department->sort_order)->toBe(0);
});

test('a team belongs to a department and defaults to active', function () {
    $department = CompanyDepartment::factory()->create();

    $team = CompanyTeam::create([
        'department_id' => $department->id,
        'name' => 'West Sales',
        'code' => 'west-sales',
        'description' => 'Sales team covering the western region',
    ]);

    $team->refresh();

    expect($team->id)->not->toBeNull()
        ->and($team->department_id)->toBe($department->id)
        ->and($team->name)->toBe('West Sales')
        ->and($team->code)->toBe('west-sales')
        ->and($team->status)->toBe(TeamStatus::Active)
        ->and($team->department->id)->toBe($department->id);
});

test('an employee can be created and has correct relationships', function () {
    $department = CompanyDepartment::factory()->create();
    $team = CompanyTeam::factory()->create(['department_id' => $department->id]);
    $user = User::factory()->companyUser()->create();

    $employee = CompanyEmployee::create([
        'employee_code' => 'EMP-001',
        'user_id' => $user->id,
        'department_id' => $department->id,
        'team_id' => $team->id,
        'designation' => 'Executive',
        'employment_status' => EmploymentStatus::Active,
        'joining_date' => '2026-07-01',
        'timezone' => 'UTC',
        'language' => 'en',
    ]);

    expect($employee->id)->not->toBeNull()
        ->and($employee->employee_code)->toBe('EMP-001')
        ->and($employee->user_id)->toBe($user->id)
        ->and($employee->department_id)->toBe($department->id)
        ->and($employee->team_id)->toBe($team->id)
        ->and($employee->designation)->toBe('Executive')
        ->and($employee->employment_status)->toBe(EmploymentStatus::Active)
        ->and($employee->joining_date->toDateString())->toBe('2026-07-01')
        ->and($employee->user->id)->toBe($user->id)
        ->and($employee->department->id)->toBe($department->id)
        ->and($employee->team->id)->toBe($team->id);
});

test('unlimited hierarchy depth reporting structure works correctly', function () {
    $department = CompanyDepartment::factory()->create();

    // CEO
    $ceo = CompanyEmployee::factory()->create([
        'employee_code' => 'EMP-CEO',
        'department_id' => $department->id,
        'designation' => 'CEO',
    ]);

    // Head of Ops reporting to CEO
    $opsHead = CompanyEmployee::factory()->create([
        'employee_code' => 'EMP-OPS-HEAD',
        'department_id' => $department->id,
        'manager_id' => $ceo->id,
        'designation' => 'Operations Head',
    ]);

    // Support Manager reporting to Ops Head
    $supportManager = CompanyEmployee::factory()->create([
        'employee_code' => 'EMP-SUP-MGR',
        'department_id' => $department->id,
        'manager_id' => $opsHead->id,
        'designation' => 'Support Manager',
    ]);

    // Support Executive reporting to Support Manager
    $executive = CompanyEmployee::factory()->create([
        'employee_code' => 'EMP-SUP-EXEC',
        'department_id' => $department->id,
        'manager_id' => $supportManager->id,
        'designation' => 'Support Executive',
    ]);

    // Test upward hierarchy traversal
    expect($executive->manager->id)->toBe($supportManager->id)
        ->and($executive->manager->manager->id)->toBe($opsHead->id)
        ->and($executive->manager->manager->manager->id)->toBe($ceo->id)
        ->and($ceo->manager)->toBeNull();

    // Test downward reporting structure
    expect($ceo->directReports)->toHaveCount(1)
        ->and($ceo->directReports->first()->id)->toBe($opsHead->id)
        ->and($opsHead->directReports->first()->id)->toBe($supportManager->id)
        ->and($supportManager->directReports->first()->id)->toBe($executive->id);
});

test('duplicate department code is rejected', function () {
    CompanyDepartment::create([
        'name' => 'HR Department',
        'code' => 'hr',
    ]);

    $this->expectException(QueryException::class);

    CompanyDepartment::create([
        'name' => 'Human Resources',
        'code' => 'hr',
    ]);
});

test('duplicate team code is rejected', function () {
    $department = CompanyDepartment::factory()->create();

    CompanyTeam::create([
        'department_id' => $department->id,
        'name' => 'Team A',
        'code' => 'team-a',
    ]);

    $this->expectException(QueryException::class);

    CompanyTeam::create([
        'department_id' => $department->id,
        'name' => 'Team A Alt',
        'code' => 'team-a',
    ]);
});

test('duplicate employee code is rejected', function () {
    $user1 = User::factory()->companyUser()->create();
    $user2 = User::factory()->companyUser()->create();

    CompanyEmployee::factory()->create([
        'employee_code' => 'EMP-100',
        'user_id' => $user1->id,
    ]);

    $this->expectException(QueryException::class);

    CompanyEmployee::factory()->create([
        'employee_code' => 'EMP-100',
        'user_id' => $user2->id,
    ]);
});

test('one user cannot have duplicate employee records', function () {
    $user = User::factory()->companyUser()->create();

    CompanyEmployee::factory()->create([
        'employee_code' => 'EMP-200',
        'user_id' => $user->id,
    ]);

    $this->expectException(QueryException::class);

    CompanyEmployee::factory()->create([
        'employee_code' => 'EMP-300',
        'user_id' => $user->id,
    ]);
});

test('company seeder correctly seeds the foundation', function () {
    $this->seed(CompanyOrganizationSeeder::class);

    // Initial departments seeded = 7 (Sales, Support, Finance, Operations, Development, HR, Marketing)
    expect(CompanyDepartment::count())->toBe(7)
        ->and(CompanyTeam::count())->toBe(7);

    $salesDept = CompanyDepartment::where('code', 'sales')->first();
    expect($salesDept)->not->toBeNull()
        ->and($salesDept->teams->first()->code)->toBe('sales-operations');
});
