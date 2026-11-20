<?php

namespace Database\Seeders;

use App\BranchStatus;
use App\ClinicMembershipStatus;
use App\ClinicRole;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicalProcedure;
use App\Models\ClinicalProcedurePrice;
use App\Models\ClinicEmployee;
use App\Models\ClinicMembership;
use App\Models\Patient;
use App\Models\Practitioner;
use App\Models\User;
use App\Services\Clinical\EncounterWorkspace;
use App\Services\ClinicRoleAssignmentService;
use App\Services\Dental\DentalRecord;
use App\Services\Dental\DentalSpecialtyRecords;
use App\Services\LaboratoryCaseService;
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
 * A dental clinic with one account per role (owner, dentist, reception,
 * billing, clinic admin) and a patient with real dental history written
 * through the application's services, for verifying Patient 360's
 * role-aware views by hand:
 *
 *   php artisan db:seed --class=Patient360BrowserTestSeeder
 *
 * Accounts: p360-{owner|dentist|reception|billing|admin}@browser-test.test
 * Password: the value of PATIENT360_TEST_PASSWORD, default
 * `p360-local-browser-test` (local only).
 */
class Patient360BrowserTestSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Patient360BrowserTestSeeder runs only in local or testing environments.');
        }

        $password = Hash::make((string) env('PATIENT360_TEST_PASSWORD', 'p360-local-browser-test'));
        $clinic = Clinic::query()->withoutGlobalScopes()->where('slug', 'p360-browser-dental')->first()
            ?? Clinic::factory()->create(['slug' => 'p360-browser-dental', 'name' => 'Smile Studio (browser test)', 'specialties' => ['dental']]);
        $clinic->update(['specialties' => ['dental']]);
        $branch = Branch::query()->withoutGlobalScopes()->firstOrCreate(
            ['clinic_id' => $clinic->id, 'name' => 'Main Branch'],
            Branch::factory()->make(['clinic_id' => $clinic->id, 'name' => 'Main Branch', 'is_main' => true, 'status' => BranchStatus::Active])->toArray(),
        );
        // The organisation scope shows a member only their own organisation's rows, so the branch and the members must carry the clinic's.
        $branch->forceFill(['organization_id' => $clinic->organization_id])->save();

        $member = function (string $who, ?ClinicRole $role, bool $owner = false) use ($clinic, $branch, $password): User {
            $user = User::query()->updateOrCreate(['email' => "p360-{$who}@browser-test.test"], ['name' => 'P360 '.ucfirst($who), 'password' => $password, 'user_type' => UserType::ClinicUser, 'organization_id' => $clinic->organization_id]);
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

            return $user;
        };

        $owner = $member('owner', null, true);
        $dentistUser = $member('dentist', ClinicRole::Doctor);
        $member('reception', ClinicRole::Receptionist);
        $member('billing', ClinicRole::BillingStaff);
        $member('admin', ClinicRole::ClinicAdmin);

        $dentist = ClinicEmployee::query()->withoutGlobalScopes()->firstOrCreate(
            ['clinic_id' => $clinic->id, 'user_id' => $dentistUser->id],
            ['name' => 'Dr. Priya Nair', 'email' => 'p360-dentist@browser-test.test', 'status' => 'active'],
        );
        Practitioner::query()->withoutGlobalScopes()->firstOrCreate(
            ['clinic_employee_id' => $dentist->id],
            ['clinic_id' => $clinic->id, 'organization_id' => $clinic->organization_id, 'specialty' => 'Dentist', 'is_active' => true],
        );
        ClinicEmployee::query()->withoutGlobalScopes()->firstOrCreate(['clinic_id' => $clinic->id, 'user_id' => $owner->id], ['name' => 'P360 Owner', 'email' => 'p360-owner@browser-test.test', 'status' => 'active']);

        if (Patient::query()->withoutGlobalScopes()->where('clinic_id', $clinic->id)->exists()) {
            return;
        }

        // A patient with history written the way the app writes it.
        auth()->login($dentistUser);
        $patient = Patient::create(['clinic_id' => $clinic->id, 'first_name' => 'Ananya', 'last_name' => 'Rao', 'phone' => '9876501234', 'gender' => 'female', 'age' => 34]);
        $crown = ClinicalProcedure::create(['clinic_id' => $clinic->id, 'code' => 'CRN', 'name' => 'Zirconia crown', 'category' => 'Prosthodontics', 'is_active' => true]);
        $filling = ClinicalProcedure::create(['clinic_id' => $clinic->id, 'code' => 'FIL', 'name' => 'Composite filling', 'category' => 'Restorative', 'is_active' => true]);
        ClinicalProcedurePrice::create(['clinic_id' => $clinic->id, 'clinical_procedure_id' => $crown->id, 'price' => 9500]);
        ClinicalProcedurePrice::create(['clinic_id' => $clinic->id, 'clinical_procedure_id' => $filling->id, 'price' => 1800]);

        $encounter = app(EncounterWorkspace::class)->startForPatient($patient, $branch, $dentist);
        $dental = app(DentalRecord::class);
        $dental->recordFinding($encounter, 36, 'caries', ['M', 'O'], 'Deep lesion', $dentist);
        $dental->recordFinding($encounter, 16, 'periapical', [], 'Periapical radiolucency on IOPA', $dentist);
        $dental->recordFinding($encounter, 11, 'filled', ['M'], null, $dentist);
        $dental->recordFinding($encounter, 46, 'missing', [], null, $dentist);
        $plan = $dental->proposePlan($encounter, 'Restore and crown', [
            ['clinical_procedure_id' => $filling->id, 'tooth_number' => 36, 'surfaces' => ['M', 'O']],
            ['clinical_procedure_id' => $crown->id, 'tooth_number' => 26],
        ], null, $dentist);
        $dental->acceptPlan($plan);
        app(DentalSpecialtyRecords::class)->saveEndodontic($encounter, null, ['tooth_number' => 16, 'stage' => 'cleaning_shaping', 'pulp_status' => 'necrotic', 'periapical_status' => 'asymptomatic_apical_periodontitis', 'canals' => [['name' => 'MB', 'working_length' => 20.5], ['name' => 'DB', 'working_length' => 20], ['name' => 'P', 'working_length' => 21.5]]], $dentist);
        app(LaboratoryCaseService::class)->order($encounter, $dentist, ['teeth' => [26], 'restoration' => 'Zirconia crown', 'shade' => 'A2', 'laboratory_name' => 'SmileLab', 'delivery_due_date' => now()->addWeek()->toDateString()]);
        auth()->logout();
    }
}
