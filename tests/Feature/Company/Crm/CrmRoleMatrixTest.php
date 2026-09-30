<?php

use App\Models\CompanyQuotation;
use App\Models\Contact;
use App\Models\Demo;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\Task;
use App\Models\Trial;
use App\Models\User;
use App\UserType;
use Database\Seeders\CrmRolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * Slice C — the full role x entity x action matrix.
 *
 * CrmRoleMappingTest pins which personas can reach the CRM at all. This pins
 * what each of them may *do* to every main entity, so a policy loosened in
 * passing fails here rather than in production.
 */
beforeEach(function () {
    setPermissionsTeamId(0);
    $this->seed(CrmRolePermissionSeeder::class);

    $this->org = Organization::factory()->create();

    $this->lead = Lead::factory()->create(['organization_id' => $this->org->id]);
    $this->demo = Demo::factory()->create(['lead_id' => $this->lead->id]);
    $this->trial = Trial::factory()->create(['lead_id' => $this->lead->id]);
    $this->quotation = CompanyQuotation::factory()->create([
        'lead_id' => $this->lead->id,
        'organization_id' => $this->org->id,
    ]);
    $this->task = Task::factory()->create();
    $this->contact = Contact::factory()->create(['lead_id' => $this->lead->id]);
});

function personaFor(string $roleName, ?int $orgId = null): User
{
    setPermissionsTeamId(0);

    $user = User::factory()->create([
        'user_type' => UserType::CompanyUser,
        'organization_id' => $orgId,
    ]);
    $user->assignRole(Role::findByName($roleName, 'web'));
    $user->forgetCachedPermissions();

    return $user->fresh();
}

// ------------------------------------------------------------------- viewing

it('grants read on every main CRM entity to CRM personas', function (string $roleName) {
    $user = personaFor($roleName, $this->org->id);

    expect($user->can('viewAny', Lead::class))->toBeTrue()
        ->and($user->can('view', $this->lead))->toBeTrue()
        ->and($user->can('viewAny', Demo::class))->toBeTrue()
        ->and($user->can('viewAny', Trial::class))->toBeTrue()
        ->and($user->can('viewAny', CompanyQuotation::class))->toBeTrue()
        ->and($user->can('view', $this->quotation))->toBeTrue()
        ->and($user->can('viewAny', Task::class))->toBeTrue()
        ->and($user->can('viewAny', Contact::class))->toBeTrue();
})->with(['CRM Manager', 'Sales Manager', 'Sales Representative', 'Sales Executive']);

it('denies read on every main CRM entity to non-CRM personas', function (string $roleName) {
    $user = personaFor($roleName, $this->org->id);

    expect($user->can('viewAny', Lead::class))->toBeFalse()
        ->and($user->can('view', $this->lead))->toBeFalse()
        ->and($user->can('viewAny', Demo::class))->toBeFalse()
        ->and($user->can('viewAny', Trial::class))->toBeFalse()
        ->and($user->can('viewAny', CompanyQuotation::class))->toBeFalse()
        ->and($user->can('view', $this->quotation))->toBeFalse()
        ->and($user->can('viewAny', Contact::class))->toBeFalse();
})->with(['Finance Manager', 'Finance', 'Support Manager', 'Support Executive', 'HR Manager', 'Operations Manager']);

// ------------------------------------------------------------------ mutating

it('lets CRM personas create and update leads', function (string $roleName) {
    $user = personaFor($roleName, $this->org->id);

    expect($user->can('create', Lead::class))->toBeTrue()
        ->and($user->can('update', $this->lead))->toBeTrue();
})->with(['CRM Manager', 'Sales Manager', 'Sales Representative', 'Sales Executive']);

it('denies lead creation and updates to non-CRM personas', function (string $roleName) {
    $user = personaFor($roleName, $this->org->id);

    expect($user->can('create', Lead::class))->toBeFalse()
        ->and($user->can('update', $this->lead))->toBeFalse();
})->with(['Finance Manager', 'Support Manager', 'HR Manager', 'Operations Manager']);

// ------------------------------------------------------------------ deleting

it('reserves lead deletion for master admins', function (string $roleName) {
    // A lead carries commercial history; deleting one removes it from every
    // downstream report, so no company role holds this.
    expect(personaFor($roleName, $this->org->id)->can('delete', $this->lead))->toBeFalse();
})->with(['CRM Manager', 'Sales Manager', 'Sales Representative', 'Finance Manager']);

it('reserves quotation deletion for management', function () {
    expect(personaFor('Sales Representative', $this->org->id)->can('delete', $this->quotation))->toBeFalse()
        ->and(personaFor('CRM Manager', $this->org->id)->can('delete', $this->quotation))->toBeTrue();
});

// ------------------------------------------------------------------ approving

it('reserves quotation approval for management', function () {
    expect(personaFor('Sales Representative', $this->org->id)->can('crm.quotations.approve'))->toBeFalse()
        ->and(personaFor('Sales Executive', $this->org->id)->can('crm.quotations.approve'))->toBeFalse()
        ->and(personaFor('CRM Manager', $this->org->id)->can('crm.quotations.approve'))->toBeTrue()
        ->and(personaFor('Sales Manager', $this->org->id)->can('crm.quotations.approve'))->toBeTrue();
});

// -------------------------------------------------------------- managing

it('reserves team administration for management', function () {
    expect(personaFor('Sales Representative', $this->org->id)->can('crm.teams.manage'))->toBeFalse()
        ->and(personaFor('CRM Manager', $this->org->id)->can('crm.teams.manage'))->toBeTrue();
});

it('reserves plan catalog administration for management', function () {
    expect(personaFor('Sales Representative', $this->org->id)->can('crm.packages.view'))->toBeFalse()
        ->and(personaFor('CRM Manager', $this->org->id)->can('crm.packages.view'))->toBeTrue();
});

it('reserves team-wide reporting for management', function () {
    expect(personaFor('Sales Representative', $this->org->id)->can('crm.overview.team'))->toBeFalse()
        ->and(personaFor('CRM Manager', $this->org->id)->can('crm.overview.team'))->toBeTrue();
});

// -------------------------------------------------------------- exporting

it('allows CRM personas to export and denies everyone else', function () {
    $this->actingAs(personaFor('Sales Representative', $this->org->id))
        ->get(route('company.crm.leads.export'))
        ->assertSuccessful();

    $this->actingAs(personaFor('Finance Manager', $this->org->id))
        ->get(route('company.crm.leads.export'))
        ->assertForbidden();
});

// -------------------------------------------------------------- master admin

it('gives a master admin every action without holding any role', function () {
    $admin = User::factory()->create(['user_type' => UserType::MasterAdmin]);

    expect($admin->roles)->toBeEmpty()
        ->and($admin->can('viewAny', Lead::class))->toBeTrue()
        ->and($admin->can('delete', $this->lead))->toBeTrue()
        ->and($admin->can('delete', $this->quotation))->toBeTrue()
        ->and($admin->can('crm.teams.manage'))->toBeTrue()
        ->and($admin->can('crm.quotations.approve'))->toBeTrue()
        ->and($admin->can('crm.overview.team'))->toBeTrue();
});

// -------------------------------------------------------------- clinic tenant

it('locks clinic tenant users out of every CRM entity regardless of permission', function () {
    setPermissionsTeamId(0);
    $clinicUser = User::factory()->create(['user_type' => UserType::ClinicUser]);
    $clinicUser->assignRole(Role::findByName('CRM Manager', 'web'));
    $clinicUser = $clinicUser->fresh();

    // Even holding the manager role, the portal boundary denies them.
    expect($clinicUser->can('viewAny', Lead::class))->toBeFalse()
        ->and($clinicUser->can('view', $this->lead))->toBeFalse()
        ->and($clinicUser->can('viewAny', CompanyQuotation::class))->toBeFalse()
        ->and($clinicUser->can('view', $this->quotation))->toBeFalse()
        ->and($clinicUser->can('crm.teams.manage'))->toBeFalse();
});

// -------------------------------------------------------------- cross-tenant

it('denies cross-organization record access', function () {
    $otherOrg = Organization::factory()->create();
    $outsider = personaFor('CRM Manager', $otherOrg->id);

    $ownedLead = Lead::factory()->create(['organization_id' => $this->org->id]);
    $ownedQuotation = CompanyQuotation::factory()->create([
        'lead_id' => $ownedLead->id,
        'organization_id' => $this->org->id,
    ]);

    expect($outsider->can('view', $ownedLead))->toBeFalse()
        ->and($outsider->can('update', $ownedLead))->toBeFalse()
        ->and($outsider->can('view', $ownedQuotation))->toBeFalse()
        ->and($outsider->can('update', $ownedQuotation))->toBeFalse();
});
