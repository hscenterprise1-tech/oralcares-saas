<?php

namespace Database\Seeders;

use App\BranchStatus;
use App\ClinicMembershipStatus;
use App\ClinicRole;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicEmployee;
use App\Models\ClinicMembership;
use App\Models\Patient;
use App\Models\PatientBilling;
use App\Models\Practitioner;
use App\Models\User;
use App\PaymentMethod;
use App\Services\BillingService;
use App\Services\Business\ClinicExpenses;
use App\Services\ClinicRoleAssignmentService;
use App\Services\PaymentService;
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
 * Records for checking the two kinds of clinic owner by hand, written
 * through the application's own services:
 *
 *  - In the Patient 360 browser clinic (run `Patient360BrowserTestSeeder`
 *    first): a few bills, payments, a refund and expenses, so the owner who
 *    does not treat patients (p360-owner) sees a working Command Center.
 *  - A second dental clinic whose owner is also a dentist
 *    (odm-owner@browser-test.test), with another dentist
 *    (odm-dentist@browser-test.test), patients assigned to each, and a few
 *    bills, so Doctor Mode and Owner Mode can be walked through.
 *
 *   php artisan db:seed --class=OwnerModesBrowserTestSeeder
 *
 * Password for the odm-* accounts: PATIENT360_TEST_PASSWORD, default
 * `p360-local-browser-test` (local only). Running it again adds nothing.
 */
class OwnerModesBrowserTestSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('OwnerModesBrowserTestSeeder runs only in local or testing environments.');
        }

        $this->ownerWhoDoesNotTreat();
        $this->ownerWhoIsADentist();
    }

    /** Money for the Patient 360 browser clinic's owner, who does not treat patients. */
    private function ownerWhoDoesNotTreat(): void
    {
        $clinic = Clinic::query()->withoutGlobalScopes()->where('slug', 'p360-browser-dental')->first();
        $owner = User::query()->where('email', 'p360-owner@browser-test.test')->first();
        if (! $clinic || ! $owner) {
            throw new RuntimeException('Run Patient360BrowserTestSeeder first.');
        }
        if (PatientBilling::query()->withoutGlobalScopes()->where('clinic_id', $clinic->id)->exists()) {
            return;
        }

        $branch = Branch::query()->withoutGlobalScopes()->where('clinic_id', $clinic->id)->where('name', 'Main Branch')->firstOrFail();
        $patients = collect([['Ananya', 'Rao'], ['Vikram', 'Shetty'], ['Meera', 'Iyer']])
            ->map(fn (array $name) => Patient::query()->withoutGlobalScopes()->where('clinic_id', $clinic->id)->where('first_name', $name[0])->first()
                ?? Patient::create(['clinic_id' => $clinic->id, 'first_name' => $name[0], 'last_name' => $name[1], 'phone' => '98765'.random_int(10000, 99999), 'gender' => 'female']));

        auth()->login($owner);
        $this->billsFor($clinic, $branch, $patients->all(), [[4500, 4500], [9500, 3000], [1800, 0]], refundFirst: 500);
        $expenses = app(ClinicExpenses::class);
        $rent = $expenses->categories($clinic)->firstWhere('name', 'Rent');
        $expenses->record($clinic, ['title' => 'October rent', 'amount' => 35000, 'spent_on' => now()->startOfMonth()->toDateString(), 'category_id' => $rent->id, 'branch_id' => $branch->id], $owner);
        $expenses->record($clinic, ['title' => 'Dental chair service', 'amount' => 6200, 'spent_on' => now()->subMonths(2)->toDateString(), 'category_id' => $rent->id, 'branch_id' => $branch->id], $owner);
        auth()->logout();
    }

    /** A clinic whose owner is also its dentist, with a second dentist and patients assigned to each. */
    private function ownerWhoIsADentist(): void
    {
        $password = Hash::make((string) env('PATIENT360_TEST_PASSWORD', 'p360-local-browser-test'));
        $clinic = Clinic::query()->withoutGlobalScopes()->where('slug', 'odm-browser-dental')->first()
            ?? Clinic::factory()->create(['slug' => 'odm-browser-dental', 'name' => 'Dr. Kapoor Dental (browser test)', 'specialties' => ['dental']]);
        $branch = Branch::query()->withoutGlobalScopes()->firstOrCreate(
            ['clinic_id' => $clinic->id, 'name' => 'Main Branch'],
            Branch::factory()->make(['clinic_id' => $clinic->id, 'organization_id' => $clinic->organization_id, 'name' => 'Main Branch', 'is_main' => true, 'status' => BranchStatus::Active])->toArray(),
        );

        $member = function (string $who, string $name, bool $owner, ?ClinicRole $role) use ($clinic, $branch, $password): array {
            $user = User::query()->updateOrCreate(['email' => "odm-{$who}@browser-test.test"], ['name' => $name, 'password' => $password, 'user_type' => UserType::ClinicUser]);
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
            $employee = ClinicEmployee::query()->withoutGlobalScopes()->firstOrCreate(
                ['clinic_id' => $clinic->id, 'user_id' => $user->id],
                ['name' => $name, 'email' => $user->email, 'status' => 'active'],
            );

            return [$user, $employee];
        };
        $dentist = fn (ClinicEmployee $employee) => Practitioner::query()->withoutGlobalScopes()->firstOrCreate(
            ['clinic_employee_id' => $employee->id],
            ['clinic_id' => $clinic->id, 'organization_id' => $clinic->organization_id, 'specialty' => 'Dentist', 'is_active' => true],
        );

        // The owner treats patients: an active practitioner profile, no staff role needed.
        [$owner, $ownerEmployee] = $member('owner', 'Dr. Rohan Kapoor', true, null);
        $dentist($ownerEmployee);
        [, $otherEmployee] = $member('dentist', 'Dr. Sana Qureshi', false, ClinicRole::Doctor);
        $dentist($otherEmployee);
        $member('reception', 'Kavya (Reception)', false, ClinicRole::Receptionist);

        if (Patient::query()->withoutGlobalScopes()->where('clinic_id', $clinic->id)->exists()) {
            return;
        }

        $patient = fn (string $first, string $last, ClinicEmployee $doctor) => Patient::create([
            'clinic_id' => $clinic->id, 'first_name' => $first, 'last_name' => $last, 'phone' => '97654'.random_int(10000, 99999),
            'gender' => 'male', 'current_practitioner_id' => $doctor->id,
        ]);
        $mine = [$patient('Arjun', 'Mehta', $ownerEmployee), $patient('Nisha', 'Verma', $ownerEmployee)];
        $theirs = [$patient('Farhan', 'Ali', $otherEmployee), $patient('Lakshmi', 'Pillai', $otherEmployee)];

        auth()->login($owner);
        $this->billsFor($clinic, $branch, [...$mine, ...$theirs], [[2500, 2500], [6000, 2000], [3200, 3200], [12000, 0]], refundFirst: 0);
        auth()->logout();
    }

    /**
     * One bill per patient, paid as given, written through the billing services.
     *
     * @param  list<Patient>  $patients
     * @param  list<array{0: int, 1: int}>  $amounts  [billed, paid]
     */
    private function billsFor(Clinic $clinic, Branch $branch, array $patients, array $amounts, int $refundFirst): void
    {
        foreach ($patients as $index => $patient) {
            [$billed, $paid] = $amounts[$index];
            $bill = app(BillingService::class)->createBill($clinic, $patient, $branch, [['description' => 'Consultation and treatment', 'quantity' => 1, 'unit_price' => $billed]]);
            if ($paid > 0) {
                app(PaymentService::class)->recordPayment($bill->fresh(), $index % 2 === 0 ? PaymentMethod::Cash : PaymentMethod::Upi, $paid);
            }
            if ($index === 0 && $refundFirst > 0) {
                app(PaymentService::class)->recordRefund($bill->fresh(), $refundFirst, 'Treatment adjusted after review');
            }
        }
    }
}
