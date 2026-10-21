<?php

use App\BranchStatus;
use App\ClinicMembershipStatus;
use App\ClinicRole;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicMembership;
use App\Models\Organization;
use App\Models\Patient;
use App\Models\User;
use App\Services\ClinicContext;
use App\Services\ClinicRoleAssignmentService;
use App\Shared\Search\Models\SearchDocument;
use App\UserType;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/*
 * `/api/search` reads one cross-entity index that holds HQ records and every
 * clinic's patients. A clinic user must find only their active clinic's own
 * records; HQ must never find a clinic's patients.
 */

function searchIsolationClinic(Organization $organization, string $slug): array
{
    $clinic = Clinic::factory()->create(['organization_id' => $organization->id, 'slug' => $slug]);
    $branch = Branch::factory()->create([
        'clinic_id' => $clinic->id, 'organization_id' => $organization->id, 'is_main' => true, 'status' => BranchStatus::Active,
    ]);

    return [$clinic, $branch];
}

function searchIsolationPatient(Clinic $clinic, string $name): Patient
{
    [$first, $last] = explode(' ', $name);

    return Patient::withoutGlobalScopes()->create([
        'clinic_id' => $clinic->id,
        'organization_id' => $clinic->organization_id,
        'uhid' => 'UH-'.Str::upper(Str::random(8)),
        'first_name' => $first,
        'last_name' => $last,
        'name' => $name,
        'phone' => '9'.random_int(100000000, 999999999),
        'gender' => 'female',
    ]);
}

/**
 * A clinic member holding a clinic role at the given branch; returns the
 * session that makes this membership the active one.
 *
 * @return array<string, int>
 */
function searchIsolationMember(User $user, Clinic $clinic, Branch $branch, ?ClinicRole $role): array
{
    $membership = ClinicMembership::factory()->create([
        'clinic_id' => $clinic->id, 'user_id' => $user->id, 'is_primary_owner' => false,
        'has_all_branches_access' => true, 'status' => ClinicMembershipStatus::Active,
    ]);

    if ($role) {
        setPermissionsTeamId(null);
        Role::firstOrCreate(['name' => $role->value, 'guard_name' => 'web', 'branch_id' => null])
            ->syncPermissions(collect($role->permissions())
                ->map(fn (string $permission) => Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']))
                ->all());
        app(ClinicRoleAssignmentService::class)->syncRoles($user, [$role->value], [$branch]);
    }

    return [
        ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID => $membership->id,
        ClinicContext::SESSION_BRANCH_ID => $branch->id,
    ];
}

/** @return list<string> */
function searchTitles(TestResponse $response): array
{
    return collect($response->assertOk()->json('data'))->flatten(1)->pluck('title')->sort()->values()->all();
}

it('finds patients of the active clinic for a member who may search patients', function () {
    $organization = Organization::factory()->create();
    [$clinic, $branch] = searchIsolationClinic($organization, 'search-own');
    searchIsolationPatient($clinic, 'Asha Verma');
    $user = User::factory()->create(['user_type' => UserType::ClinicUser, 'organization_id' => $organization->id]);
    $session = searchIsolationMember($user, $clinic, $branch, ClinicRole::Receptionist);

    $response = $this->actingAs($user)->withSession($session)->getJson('/api/search?q=Asha');

    expect(searchTitles($response))->toBe(['Asha Verma']);
});

it('never shows a sister clinic of the same organisation', function () {
    $organization = Organization::factory()->create();
    [$mine, $branch] = searchIsolationClinic($organization, 'search-mine');
    [$sister] = searchIsolationClinic($organization, 'search-sister');
    searchIsolationPatient($mine, 'Asha Verma');
    searchIsolationPatient($sister, 'Asha Kapoor');
    $user = User::factory()->create(['user_type' => UserType::ClinicUser, 'organization_id' => $organization->id]);
    $session = searchIsolationMember($user, $mine, $branch, ClinicRole::Receptionist);

    $response = $this->actingAs($user)->withSession($session)->getJson('/api/search?q=Asha');

    expect(searchTitles($response))->toBe(['Asha Verma']);
});

it('searches the clinic a multi-organisation member is working in, not the organisation on the user row', function () {
    $first = Organization::factory()->create();
    $second = Organization::factory()->create();
    [$clinicA, $branchA] = searchIsolationClinic($first, 'search-first');
    [$clinicB, $branchB] = searchIsolationClinic($second, 'search-second');
    searchIsolationPatient($clinicA, 'Asha Verma');
    searchIsolationPatient($clinicB, 'Asha Kapoor');
    $user = User::factory()->create(['user_type' => UserType::ClinicUser, 'organization_id' => $first->id]);
    searchIsolationMember($user, $clinicA, $branchA, ClinicRole::Receptionist);
    $inSecond = searchIsolationMember($user, $clinicB, $branchB, ClinicRole::Receptionist);

    $response = $this->actingAs($user)->withSession($inSecond)->getJson('/api/search?q=Asha');

    expect(searchTitles($response))->toBe(['Asha Kapoor']);
});

it('finds nothing for a member without the patient search permission', function () {
    $organization = Organization::factory()->create();
    [$clinic, $branch] = searchIsolationClinic($organization, 'search-noperm');
    searchIsolationPatient($clinic, 'Asha Verma');
    $user = User::factory()->create(['user_type' => UserType::ClinicUser, 'organization_id' => $organization->id]);
    $session = searchIsolationMember($user, $clinic, $branch, null);

    $response = $this->actingAs($user)->withSession($session)->getJson('/api/search?q=Asha');

    expect(searchTitles($response))->toBe([]);
});

it('finds nothing for a clinic user with no active clinic', function () {
    $organization = Organization::factory()->create();
    [$clinic] = searchIsolationClinic($organization, 'search-nocontext');
    searchIsolationPatient($clinic, 'Asha Verma');
    $user = User::factory()->create(['user_type' => UserType::ClinicUser, 'organization_id' => $organization->id]);

    $response = $this->actingAs($user)->getJson('/api/search?q=Asha');

    expect(searchTitles($response))->toBe([]);
});

it('never shows head office a clinic patient', function (UserType $type) {
    $organization = Organization::factory()->create();
    [$clinic] = searchIsolationClinic($organization, 'search-hq-'.$type->value);
    searchIsolationPatient($clinic, 'Asha Verma');
    SearchDocument::create([
        'module' => 'crm', 'model_type' => 'App\Models\Lead', 'model_id' => 990001, 'clinic_id' => null,
        'title' => 'Asha Dental Lead', 'searchable_text' => 'Asha Dental Lead', 'url' => '/company/crm/deals',
    ]);
    $user = User::factory()->create(['user_type' => $type, 'organization_id' => null]);

    $response = $this->actingAs($user)->getJson('/api/search?q=Asha');

    expect(searchTitles($response))->toBe(['Asha Dental Lead']);
})->with([
    'company user' => UserType::CompanyUser,
    'master admin' => UserType::MasterAdmin,
]);
