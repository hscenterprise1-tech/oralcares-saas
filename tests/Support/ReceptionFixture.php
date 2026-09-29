<?php

namespace Tests\Support;

use App\AppointmentStatus;
use App\BranchStatus;
use App\ClinicMembershipStatus;
use App\ClinicRole;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicEmployee;
use App\Models\ClinicMembership;
use App\Models\Patient;
use App\Models\Practitioner;
use App\Models\User;
use App\Services\ClinicContext;
use App\Services\ClinicRoleAssignmentService;
use App\UserType;
use Carbon\CarbonInterface;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * One clinic with a main branch, an owner, and two doctors — the shared
 * starting point for the reception/queue tests.
 */
class ReceptionFixture
{
    public Clinic $clinic;

    public Branch $branch;

    public User $owner;

    /** @var array<string, int> */
    public array $session;

    public ClinicEmployee $doctorA;

    public ClinicEmployee $doctorB;

    public static function make(string $slug = 'reception-clinic'): self
    {
        $fixture = new self;
        $fixture->clinic = Clinic::factory()->create(['slug' => $slug]);
        $fixture->branch = Branch::factory()->create([
            'clinic_id' => $fixture->clinic->id, 'name' => 'Main Branch', 'is_main' => true, 'status' => BranchStatus::Active,
        ]);

        $fixture->owner = User::factory()->create(['user_type' => UserType::ClinicUser]);
        $membership = ClinicMembership::factory()->create([
            'clinic_id' => $fixture->clinic->id, 'user_id' => $fixture->owner->id, 'is_primary_owner' => true,
            'has_all_branches_access' => true, 'status' => ClinicMembershipStatus::Active,
        ]);
        $fixture->session = [
            ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID => $membership->id,
            ClinicContext::SESSION_BRANCH_ID => $fixture->branch->id,
        ];

        $fixture->doctorA = $fixture->doctor('Dr. Asha Mehta');
        $fixture->doctorB = $fixture->doctor('Dr. Bala Iyer');

        return $fixture;
    }

    public function doctor(string $name, ?User $user = null): ClinicEmployee
    {
        $employee = ClinicEmployee::create([
            'clinic_id' => $this->clinic->id, 'name' => $name, 'status' => 'active', 'user_id' => $user?->id,
            'email' => str($name)->slug().'@example.com',
        ]);
        Practitioner::create([
            'clinic_id' => $this->clinic->id, 'organization_id' => $this->clinic->organization_id,
            'clinic_employee_id' => $employee->id, 'specialty' => 'Dentist', 'is_active' => true,
        ]);

        return $employee;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function patient(string $first = 'Rahul', string $last = 'Sharma', array $attributes = []): Patient
    {
        return Patient::create(array_merge([
            'clinic_id' => $this->clinic->id, 'first_name' => $first, 'last_name' => $last,
            'phone' => fake()->unique()->numerify('98########'), 'gender' => 'male',
        ], $attributes));
    }

    public function appointment(Patient $patient, ClinicEmployee $doctor, CarbonInterface $at, AppointmentStatus $status = AppointmentStatus::Scheduled): Appointment
    {
        return Appointment::create([
            'clinic_id' => $this->clinic->id, 'branch_id' => $this->branch->id, 'patient_id' => $patient->id,
            'practitioner_id' => $doctor->id, 'scheduled_at' => $at, 'duration_minutes' => 15, 'status' => $status,
        ]);
    }

    /**
     * A signed-in member holding exactly one clinic role at the main branch.
     *
     * @return array{0: User, 1: array<string, int>}
     */
    public function memberWithRole(ClinicRole $role): array
    {
        setPermissionsTeamId(null);
        Role::firstOrCreate(['name' => $role->value, 'guard_name' => 'web', 'branch_id' => null])
            ->syncPermissions(collect($role->permissions())
                ->map(fn (string $perm) => Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']))
                ->all());

        $user = User::factory()->create(['user_type' => UserType::ClinicUser]);
        $membership = ClinicMembership::factory()->create([
            'clinic_id' => $this->clinic->id, 'user_id' => $user->id, 'is_primary_owner' => false,
            'has_all_branches_access' => true, 'status' => ClinicMembershipStatus::Active,
        ]);
        app(ClinicRoleAssignmentService::class)->syncRoles($user, [$role->value], [$this->branch]);

        return [$user, [
            ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID => $membership->id,
            ClinicContext::SESSION_BRANCH_ID => $this->branch->id,
        ]];
    }
}
