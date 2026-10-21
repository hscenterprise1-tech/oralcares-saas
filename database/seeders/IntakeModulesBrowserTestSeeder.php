<?php

namespace Database\Seeders;

use App\BranchStatus;
use App\ClinicMembershipStatus;
use App\ClinicRole;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicalSpecialty;
use App\Models\ClinicEmployee;
use App\Models\ClinicMembership;
use App\Models\Practitioner;
use App\Models\User;
use App\Services\ClinicRoleAssignmentService;
use App\Services\Patient\ClinicSpecialties;
use App\Services\PatientFieldConfigService;
use App\Services\Reception\PatientFlowConfigService;
use App\Support\Specialty\PracticeAreas;
use App\UserType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * LOCAL BROWSER TESTING ONLY — never called by DatabaseSeeder and refuses
 * any environment but local/testing.
 *
 * Three clinics for checking New Case / Repeat Case, check-in and the
 * payment step by module:
 *
 * - "QA Intake Physio": Physiotherapy only. Owner, reception and a
 *   physiotherapist with a login, plus a second physiotherapist without
 *   one (a treating profile needs no login). No consultation fee; the
 *   clinic bills after the visit, so checking in charges nothing.
 * - "QA Intake Dental": Dental only, collecting at check-in. A dentist with
 *   a ₹300 consultation fee (check-in charges it; payment is the separate
 *   step after) and one with none (nothing charged, no payment step).
 * - "QA Intake Combined": Dental and Physiotherapy, collecting at check-in.
 *   A dentist with a ₹300 fee and a physiotherapist with a ₹500 fee — a
 *   Physiotherapy check-in still charges nothing.
 *
 *   php artisan db:seed --class=IntakeModulesBrowserTestSeeder
 *
 * Accounts: intake-{physio|dental|combined}-{owner|reception|…}@browser-test.test.
 * Password: INTAKE_QA_TEST_PASSWORD, default `intake-local-browser-test`.
 *
 * Idempotent: run it again and nothing is duplicated. It creates no
 * patients, bills or messages, and connects no WhatsApp number: nothing it
 * does reaches anyone outside this machine.
 */
class IntakeModulesBrowserTestSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('IntakeModulesBrowserTestSeeder runs only in local or testing environments.');
        }

        $password = Hash::make((string) env('INTAKE_QA_TEST_PASSWORD', 'intake-local-browser-test'));

        // Physiotherapy only.
        [$clinic, $branch, $member] = $this->clinic('qa-intake-physio', 'QA Intake Physio (browser test)', ['physiotherapy'], 'intake-physio', $password);
        $owner = $member('owner', null, true);
        $member('reception', ClinicRole::Receptionist);
        $this->practitioner($clinic, 'Dr. Neha Shah (QA)', 'sports_rehabilitation', $member('physio', ClinicRole::Doctor), null);
        $this->practitioner($clinic, 'Dr. Arjun Menon (QA, no login)', 'orthopedic_rehabilitation', null, null);
        app(PatientFlowConfigService::class)->updateCheckIn($clinic, ['payment' => PatientFlowConfigService::PAY_AFTER_CONSULTATION], $owner);
        app(PatientFieldConfigService::class)->applyTemplate($clinic, 'physio', $owner);

        // Dental only.
        [$clinic, $branch, $member] = $this->clinic('qa-intake-dental', 'QA Intake Dental (browser test)', ['dental'], 'intake-dental', $password);
        $owner = $member('owner', null, true);
        $member('reception', ClinicRole::Receptionist);
        $this->practitioner($clinic, 'Dr. Meera Rao (QA)', 'general_dentistry', $member('dentist', ClinicRole::Doctor), 300);
        $this->practitioner($clinic, 'Dr. Vikram Das (QA, no fee)', 'general_dentistry', null, null);
        app(PatientFlowConfigService::class)->updateCheckIn($clinic, ['payment' => PatientFlowConfigService::PAY_AT_CHECK_IN], $owner);
        app(PatientFieldConfigService::class)->applyTemplate($clinic, 'dental', $owner);

        // Dental and Physiotherapy.
        [$clinic, $branch, $member] = $this->clinic('qa-intake-combined', 'QA Intake Combined (browser test)', ['dental', 'physiotherapy'], 'intake-combined', $password);
        $owner = $member('owner', null, true);
        $member('reception', ClinicRole::Receptionist);
        $this->practitioner($clinic, 'Dr. Raj Patel (QA)', 'general_dentistry', $member('dentist', ClinicRole::Doctor), 300);
        $this->practitioner($clinic, 'Dr. Kavya Iyer (QA)', 'sports_rehabilitation', $member('physio', ClinicRole::Doctor), 500);
        app(PatientFlowConfigService::class)->updateCheckIn($clinic, ['payment' => PatientFlowConfigService::PAY_AT_CHECK_IN], $owner);
        app(PatientFieldConfigService::class)->applyTemplate($clinic, 'combined', $owner);
    }

    /**
     * A clinic with one active branch and the given specialties, and a
     * function that adds a member to it.
     *
     * @param  list<string>  $specialties
     * @return array{0: Clinic, 1: Branch, 2: callable(string, ?ClinicRole, bool=): User}
     */
    private function clinic(string $slug, string $name, array $specialties, string $emailPrefix, string $password): array
    {
        $clinic = Clinic::query()->withoutGlobalScopes()->where('slug', $slug)->first()
            ?? Clinic::factory()->create(['slug' => $slug, 'name' => $name, 'specialties' => $specialties]);
        $clinic->update(['specialties' => $specialties]);
        app(ClinicSpecialties::class)->sync($clinic, $specialties);
        $branch = Branch::query()->withoutGlobalScopes()->firstOrCreate(
            ['clinic_id' => $clinic->id, 'name' => 'Main Branch'],
            Branch::factory()->make(['clinic_id' => $clinic->id, 'name' => 'Main Branch', 'is_main' => true, 'status' => BranchStatus::Active])->toArray(),
        );

        $member = function (string $who, ?ClinicRole $role, bool $owner = false) use ($clinic, $branch, $emailPrefix, $password): User {
            $user = User::query()->updateOrCreate(['email' => "{$emailPrefix}-{$who}@browser-test.test"], ['name' => 'QA '.ucfirst($who), 'password' => $password, 'user_type' => UserType::ClinicUser]);
            ClinicMembership::query()->withoutGlobalScopes()->updateOrCreate(
                ['clinic_id' => $clinic->id, 'user_id' => $user->id],
                ['is_primary_owner' => $owner, 'has_all_branches_access' => true, 'status' => ClinicMembershipStatus::Active],
            );
            if ($role) {
                setPermissionsTeamId(null);
                Role::firstOrCreate(['name' => $role->value, 'guard_name' => 'web', 'branch_id' => null])
                    ->syncPermissions(collect($role->permissions())->map(fn (string $p) => Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']))->all());
                app(ClinicRoleAssignmentService::class)->syncRoles($user, [$role->value], [$branch]);
            }
            if ($owner) {
                ClinicEmployee::query()->withoutGlobalScopes()->firstOrCreate(['clinic_id' => $clinic->id, 'user_id' => $user->id], ['name' => 'QA Owner', 'email' => $user->email, 'status' => 'active']);
            }

            return $user;
        };

        return [$clinic, $branch, $member];
    }

    /** A practitioner in a keyed practice area, with or without a login and a consultation fee. */
    private function practitioner(Clinic $clinic, string $name, string $area, ?User $user, ?float $fee): ClinicEmployee
    {
        $employee = ClinicEmployee::query()->withoutGlobalScopes()->firstOrCreate(
            ['clinic_id' => $clinic->id, 'name' => $name],
            ['user_id' => $user?->id, 'email' => $user?->email ?? str($name)->slug().'@browser-test.test', 'status' => 'active'],
        );
        $keyed = ['organization_id' => $clinic->organization_id, 'practice_area_key' => $area];
        $entry = ClinicalSpecialty::query()->withoutGlobalScopes()->where($keyed)->first()
            ?? ClinicalSpecialty::query()->withoutGlobalScopes()->forceCreate([...$keyed, 'name' => app(PracticeAreas::class)->get($area)['label'], 'is_active' => true]);
        Practitioner::query()->withoutGlobalScopes()->updateOrCreate(
            ['clinic_employee_id' => $employee->id],
            ['clinic_id' => $clinic->id, 'organization_id' => $clinic->organization_id, 'specialty' => $entry->name, 'primary_specialty_id' => $entry->id, 'consultation_fee' => $fee, 'is_active' => true],
        );

        return $employee;
    }
}
