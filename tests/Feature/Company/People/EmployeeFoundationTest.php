<?php

namespace Tests\Feature\Company\People;

use App\EmploymentStatus;
use App\Models\CompanyDepartment;
use App\Models\CompanyEmployee;
use App\Models\User;
use App\UserType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected CompanyDepartment $department;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'user_type' => UserType::MasterAdmin,
        ]);

        $this->department = CompanyDepartment::create([
            'name' => 'Engineering',
            'code' => 'ENG',
            'status' => 'active',
            'sort_order' => 1,
        ]);
    }

    public function test_it_can_list_employees_directory_and_compute_kpi_payload_metrics(): void
    {
        $user = User::factory()->companyUser()->create(['name' => 'Rohan Sharma']);

        $employee = CompanyEmployee::create([
            'user_id' => $user->id,
            'employee_code' => 'EMP-1001',
            'designation' => 'Senior Developer',
            'department_id' => $this->department->id,
            'employment_status' => 'active',
            'joining_date' => now()->toDateString(),
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('company.people.employees.index'));

        $response->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Company/People/EmployeesWorkspace')
                ->has('employees')
                ->has('departments')
                ->has('kpi')
            );
    }

    public function test_it_can_create_employee_with_linked_user_and_employee_code(): void
    {
        $response = $this->actingAs($this->admin)
            ->post(route('company.people.employees.store'), [
                'name' => 'Ananya Verma',
                'email' => 'ananya@oralcares.in',
                'employee_code' => 'EMP-2002',
                'designation' => 'Sales Manager',
                'department_id' => $this->department->id,
                'employment_status' => 'active',
                'joining_date' => now()->toDateString(),
            ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('users', ['email' => 'ananya@oralcares.in']);
        $this->assertDatabaseHas('company_employees', ['employee_code' => 'EMP-2002']);
    }

    public function test_it_can_render_single_employee_detail_360_payload(): void
    {
        $user = User::factory()->companyUser()->create(['name' => 'Vikram Malhotra']);

        $employee = CompanyEmployee::create([
            'user_id' => $user->id,
            'employee_code' => 'EMP-3003',
            'designation' => 'Operations Lead',
            'department_id' => $this->department->id,
            'employment_status' => 'active',
            'joining_date' => now()->toDateString(),
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('company.people.employees.show', $employee->id));

        $response->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Company/People/EmployeeDetail')
                ->where('employee.employee_code', 'EMP-3003')
            );
    }

    public function test_it_can_transition_employee_status_and_log_audit_activity(): void
    {
        $user = User::factory()->companyUser()->create();

        $employee = CompanyEmployee::create([
            'user_id' => $user->id,
            'employee_code' => 'EMP-4004',
            'designation' => 'QA Specialist',
            'department_id' => $this->department->id,
            'employment_status' => 'active',
            'joining_date' => now()->toDateString(),
        ]);

        $response = $this->actingAs($this->admin)
            ->post(route('company.people.employees.transition', $employee->id), [
                'employment_status' => 'suspended',
            ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('company_employees', [
            'id' => $employee->id,
            'employment_status' => 'suspended',
        ]);
        /*
         * Employee audit lives in `company_employee_activities`, not the
         * polymorphic `activities` table — that is the relation the model
         * defines and the only one anything has ever written an employee event
         * to.
         */
        $this->assertDatabaseHas('company_employee_activities', [
            'company_employee_id' => $employee->id,
            'event' => 'Status Changed',
        ]);
    }

    /**
     * Every status the interface offers has to be one the endpoint accepts.
     *
     * The three validators disagreed with the enum and with each other: `store`
     * asked for `full_time,part_time,contract,probation,terminated` — employment
     * *type*, none of it a status — so creating an employee could not succeed by
     * any input at all. `transitionStatus` allowed `probation` and `terminated`
     * on top of the real values, and both would have been stored and then thrown
     * on the cast. All three now name `EmploymentStatus`.
     */
    public function test_every_employment_status_in_the_enum_is_accepted(): void
    {
        foreach (EmploymentStatus::cases() as $index => $status) {
            $employee = CompanyEmployee::create([
                'user_id' => User::factory()->create()->id,
                'employee_code' => 'EMP-ENUM-'.$index,
                'designation' => 'Analyst',
                'department_id' => $this->department->id,
                'employment_status' => EmploymentStatus::Active->value,
                'joining_date' => now()->toDateString(),
            ]);

            $this->actingAs($this->admin)
                ->post(route('company.people.employees.transition', $employee->id), [
                    'employment_status' => $status->value,
                ])
                ->assertSessionHasNoErrors();

            $this->assertSame($status, $employee->fresh()->employment_status);
        }
    }

    public function test_it_refuses_a_status_outside_the_enum(): void
    {
        $employee = CompanyEmployee::create([
            'user_id' => User::factory()->create()->id,
            'employee_code' => 'EMP-BAD-1',
            'designation' => 'Analyst',
            'department_id' => $this->department->id,
            'employment_status' => EmploymentStatus::Active->value,
            'joining_date' => now()->toDateString(),
        ]);

        /* `terminated` used to pass validation and then fail on the cast. */
        $this->actingAs($this->admin)
            ->post(route('company.people.employees.transition', $employee->id), [
                'employment_status' => 'terminated',
            ])
            ->assertSessionHasErrors('employment_status');

        $this->assertSame(EmploymentStatus::Active, $employee->fresh()->employment_status);
    }

    public function test_creating_an_employee_accepts_a_real_status(): void
    {
        $this->actingAs($this->admin)
            ->post(route('company.people.employees.store'), [
                'name' => 'Rhea Nair',
                'email' => 'rhea@oralcares.in',
                'employee_code' => 'EMP-3003',
                'designation' => 'Analyst',
                'department_id' => $this->department->id,
                'employment_status' => EmploymentStatus::Invited->value,
                'joining_date' => now()->toDateString(),
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('company_employees', [
            'employee_code' => 'EMP-3003',
            'employment_status' => 'invited',
        ]);
    }
}
