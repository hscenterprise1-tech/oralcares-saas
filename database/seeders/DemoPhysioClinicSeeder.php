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
use App\Services\Physio\PhysioCatalogue;
use App\Services\Reception\PatientFlowConfigService;
use App\Support\Specialty\PracticeAreas;
use App\UserType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * LOCAL DEMO ONLY — never called by DatabaseSeeder and refuses any
 * environment but local/testing.
 *
 * "OralCares Demo Physio": a physiotherapy-only clinic run from the front
 * desk. The owner and the reception sign in; the physiotherapist has no
 * login, so reception opens the visit, records the problem and plan, gives
 * the sessions, sells the package and takes the payment in the therapist's
 * name. A priced catalogue (treatments, session types, packages) is set.
 *
 *   php artisan db:seed --class=DemoPhysioClinicSeeder
 *
 * Accounts: demo@oralcares.in (owner), reception@oralcares.in (reception).
 * Password: DEMO_PHYSIO_PASSWORD, by default the one the owner chose for the
 * demo (2026-11-05). Idempotent; it creates no patients, bills or messages
 * and connects no WhatsApp number.
 */
class DemoPhysioClinicSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('DemoPhysioClinicSeeder runs only in local or testing environments.');
        }

        $password = Hash::make((string) env('DEMO_PHYSIO_PASSWORD', '123456'));

        $clinic = Clinic::query()->withoutGlobalScopes()->where('slug', 'oralcares-demo-physio')->first()
            ?? Clinic::factory()->create(['slug' => 'oralcares-demo-physio', 'name' => 'OralCares Demo Physio', 'specialties' => ['physiotherapy']]);
        $clinic->update(['name' => 'OralCares Demo Physio', 'specialties' => ['physiotherapy']]);
        app(ClinicSpecialties::class)->sync($clinic, ['physiotherapy']);
        $branch = Branch::query()->withoutGlobalScopes()->firstOrCreate(
            ['clinic_id' => $clinic->id, 'name' => 'Main Branch'],
            Branch::factory()->make(['clinic_id' => $clinic->id, 'name' => 'Main Branch', 'is_main' => true, 'status' => BranchStatus::Active])->toArray(),
        );

        $owner = $this->member($clinic, $branch, 'demo@oralcares.in', 'Demo Owner', null, $password);
        $this->member($clinic, $branch, 'reception@oralcares.in', 'Demo Reception', ClinicRole::Receptionist, $password);
        $this->practitioner($clinic, 'Dr. Priya Desai', 'orthopedic_rehabilitation');

        app(PatientFlowConfigService::class)->updateCheckIn($clinic, ['payment' => PatientFlowConfigService::PAY_AFTER_CONSULTATION], $owner);
        app(PatientFieldConfigService::class)->applyTemplate($clinic, 'physio', $owner);
        app(PhysioCatalogue::class)->save($clinic, $this->catalogue(), $owner);
    }

    /** A member of the clinic: the owner (no role) or one holding a role. */
    private function member(Clinic $clinic, Branch $branch, string $email, string $name, ?ClinicRole $role, string $password): User
    {
        $user = User::query()->updateOrCreate(['email' => $email], ['name' => $name, 'password' => $password, 'user_type' => UserType::ClinicUser]);
        ClinicMembership::query()->withoutGlobalScopes()->updateOrCreate(
            ['clinic_id' => $clinic->id, 'user_id' => $user->id],
            ['is_primary_owner' => $role === null, 'has_all_branches_access' => true, 'status' => ClinicMembershipStatus::Active],
        );
        if ($role) {
            setPermissionsTeamId(null);
            Role::firstOrCreate(['name' => $role->value, 'guard_name' => 'web', 'branch_id' => null])
                ->syncPermissions(collect($role->permissions())->map(fn (string $p) => Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']))->all());
            app(ClinicRoleAssignmentService::class)->syncRoles($user, [$role->value], [$branch]);
        }
        // Staff have a staff record: bills and payments are made by a member of staff.
        ClinicEmployee::query()->withoutGlobalScopes()->firstOrCreate(['clinic_id' => $clinic->id, 'user_id' => $user->id], ['name' => $name, 'email' => $email, 'status' => 'active']);

        return $user;
    }

    /** A physiotherapist without a login: the desk works for them. */
    private function practitioner(Clinic $clinic, string $name, string $area): ClinicEmployee
    {
        $employee = ClinicEmployee::query()->withoutGlobalScopes()->firstOrCreate(
            ['clinic_id' => $clinic->id, 'name' => $name],
            ['user_id' => null, 'email' => str($name)->slug().'@demo-physio.test', 'status' => 'active'],
        );
        $keyed = ['organization_id' => $clinic->organization_id, 'practice_area_key' => $area];
        $entry = ClinicalSpecialty::query()->withoutGlobalScopes()->where($keyed)->first()
            ?? ClinicalSpecialty::query()->withoutGlobalScopes()->forceCreate([...$keyed, 'name' => app(PracticeAreas::class)->get($area)['label'], 'is_active' => true]);
        Practitioner::query()->withoutGlobalScopes()->updateOrCreate(
            ['clinic_employee_id' => $employee->id],
            ['clinic_id' => $clinic->id, 'organization_id' => $clinic->organization_id, 'specialty' => $entry->name, 'primary_specialty_id' => $entry->id, 'consultation_fee' => null, 'is_active' => true],
        );

        return $employee;
    }

    /**
     * A small clinic's priced menu: what each treatment costs, the session
     * types per problem (a fixed price or the treatments' sum) and packages.
     *
     * @return array<string, mixed>
     */
    private function catalogue(): array
    {
        $types = fn (int $full, int $exercise) => [
            ['key' => 'full', 'label' => 'Full treatment', 'treatments' => ['hot_pack', 'ift', 'ultrasound', 'exercise'], 'price' => $full],
            ['key' => 'machine', 'label' => 'Machine only', 'treatments' => ['hot_pack', 'ift', 'ultrasound'], 'price' => null],
            ['key' => 'exercise', 'label' => 'Exercise only', 'treatments' => ['exercise'], 'price' => $exercise],
        ];
        $packages = fn (string $problem, int $ten, int $fifteen) => [
            ['id' => "{$problem}_10", 'name' => '10 sessions', 'sessions' => 10, 'price' => $ten, 'valid_days' => 30, 'types' => ['full', 'machine', 'exercise']],
            ['id' => "{$problem}_15", 'name' => '15 sessions', 'sessions' => 15, 'price' => $fifteen, 'valid_days' => 45, 'types' => ['full', 'machine', 'exercise']],
        ];

        return [
            'treatments' => [
                ['key' => 'hot_pack', 'label' => 'Hot pack', 'minutes' => 10, 'price' => 50],
                ['key' => 'ift', 'label' => 'IFT', 'minutes' => 15, 'price' => 150],
                ['key' => 'tens', 'label' => 'TENS', 'minutes' => 15, 'price' => 120],
                ['key' => 'ultrasound', 'label' => 'Ultrasound', 'minutes' => 8, 'price' => 100],
                ['key' => 'traction', 'label' => 'Traction', 'minutes' => 15, 'price' => 150],
                ['key' => 'exercise', 'label' => 'Exercise therapy', 'minutes' => 20, 'price' => 200],
            ],
            'addons' => [
                ['key' => 'cupping', 'label' => 'Cupping', 'minutes' => 15, 'price' => 300],
                ['key' => 'dry_needling', 'label' => 'Dry needling', 'minutes' => 15, 'price' => 400],
                ['key' => 'taping', 'label' => 'Kinesio taping', 'minutes' => 10, 'price' => 250],
            ],
            'problems' => [
                ['key' => 'knee_pain', 'label' => 'Knee pain', 'sided' => true, 'types' => $types(400, 250), 'packages' => $packages('knee', 3500, 5000),
                    'diagnoses' => ['Patellofemoral pain', 'Knee osteoarthritis', 'Ligament sprain', 'Post-operative (TKR)'], 'exercises' => ['straight_leg_raise', 'quad_sets', 'heel_slides', 'wall_squats']],
                ['key' => 'back_pain', 'label' => 'Low back pain', 'sided' => false, 'types' => [...$types(450, 250), ['key' => 'traction', 'label' => 'With traction', 'treatments' => ['hot_pack', 'ift', 'traction', 'exercise'], 'price' => 500]],
                    'packages' => $packages('back', 4000, 5500), 'diagnoses' => ['Lumbar spondylosis', 'Muscle spasm', 'Disc bulge with sciatica'], 'exercises' => ['cat_camel', 'pelvic_tilt', 'bridging']],
                ['key' => 'neck_pain', 'label' => 'Neck pain', 'sided' => false, 'types' => $types(400, 250), 'packages' => $packages('neck', 3500, 5000),
                    'diagnoses' => ['Cervical spondylosis', 'Neck sprain', 'Postural neck pain'], 'exercises' => ['chin_tucks', 'neck_rotation']],
                ['key' => 'shoulder_pain', 'label' => 'Shoulder pain', 'sided' => true, 'types' => $types(400, 250), 'packages' => $packages('shoulder', 3500, 5000),
                    'diagnoses' => ['Frozen shoulder', 'Shoulder impingement', 'Rotator cuff strain'], 'exercises' => ['pendulum', 'wall_climb']],
                ['key' => 'ankle_sprain', 'label' => 'Ankle sprain', 'sided' => true, 'types' => $types(350, 200), 'packages' => $packages('ankle', 3000, 4200),
                    'diagnoses' => ['Lateral ankle sprain', 'Achilles tendinopathy'], 'exercises' => ['ankle_pumps']],
            ],
        ];
    }
}
