<?php

use App\BranchStatus;
use App\ClinicStatus;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\Organization;
use App\Models\User;
use App\Services\CustomerSuccess\AccountStructureService;
use App\UserType;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Account → clinics → branches, managed from the account.
 *
 * The structure was previously editable only from a Blade console that never
 * asked which account a clinic belonged to. Three of the four clinics in the
 * database carry a null `organization_id`, no branches and no memberships for
 * exactly that reason; the one the provisioning engine created carries its
 * account correctly. These pin the rule so a fourth orphan cannot be made.
 */
beforeEach(function () {
    $this->organization = Organization::factory()->create();

    $this->admin = User::factory()->create([
        'user_type' => UserType::MasterAdmin,
        'organization_id' => $this->organization->id,
    ]);
});

it('creates a clinic against the account it was created from', function () {
    $this->actingAs($this->admin)
        ->post(route('company.customer-success.accounts.clinics.store', $this->organization), [
            'name' => 'Northside Dental',
        ])
        ->assertSessionHasNoErrors();

    $clinic = Clinic::where('name', 'Northside Dental')->sole();

    expect($clinic->organization_id)->toBe($this->organization->id)
        ->and($clinic->slug)->toBe('northside-dental')
        ->and($clinic->status)->toBe(ClinicStatus::Active);
});

it('creates the first branch alongside the clinic when one is named', function () {
    $this->actingAs($this->admin)
        ->post(route('company.customer-success.accounts.clinics.store', $this->organization), [
            'name' => 'Southside Dental',
            'primary_branch_name' => 'Southside Main',
        ])
        ->assertSessionHasNoErrors();

    $clinic = Clinic::where('name', 'Southside Dental')->sole();
    $branch = $clinic->branches()->sole();

    expect($branch->name)->toBe('Southside Main')
        ->and($branch->is_main)->toBeTrue()
        /* Carried for scoping; the clinic is the owner. */
        ->and($branch->organization_id)->toBe($this->organization->id)
        ->and($branch->clinic_id)->toBe($clinic->id);
});

it('gives a clinic slug a suffix rather than refusing a duplicate name', function () {
    /*
     * The portal routes by this slug, so it is globally unique — the rule
     * CustomerProvisioningEngine already follows.
     */
    Clinic::factory()->create(['slug' => 'harbour-dental', 'organization_id' => $this->organization->id]);

    $this->actingAs($this->admin)
        ->post(route('company.customer-success.accounts.clinics.store', $this->organization), [
            'name' => 'Harbour Dental',
        ])
        ->assertSessionHasNoErrors();

    $created = Clinic::where('name', 'Harbour Dental')->sole();

    expect($created->slug)->not->toBe('harbour-dental')
        ->and($created->slug)->toStartWith('harbour-dental-');
});

it('makes the first branch of a clinic the main one even when nobody says so', function () {
    $clinic = Clinic::factory()->create(['organization_id' => $this->organization->id]);

    $this->actingAs($this->admin)
        ->post(route('company.customer-success.accounts.clinics.branches.store', [$this->organization, $clinic]), [
            'name' => 'First Location',
        ])
        ->assertSessionHasNoErrors();

    expect($clinic->branches()->sole()->is_main)->toBeTrue();
});

it('keeps exactly one main branch per clinic', function () {
    $clinic = Clinic::factory()->create(['organization_id' => $this->organization->id]);
    $service = app(AccountStructureService::class);

    $first = $service->createBranch($clinic, ['name' => 'One']);
    $second = $service->createBranch($clinic, ['name' => 'Two', 'is_main' => true]);

    expect($first->fresh()->is_main)->toBeFalse()
        ->and($second->fresh()->is_main)->toBeTrue()
        ->and($clinic->branches()->where('is_main', true)->count())->toBe(1);
});

it('retires a clinic by status rather than deleting its history', function () {
    $clinic = Clinic::factory()->create([
        'organization_id' => $this->organization->id,
        'status' => ClinicStatus::Active,
    ]);

    $this->actingAs($this->admin)
        ->put(route('company.customer-success.accounts.clinics.update', [$this->organization, $clinic]), [
            'name' => $clinic->name,
            'status' => ClinicStatus::Inactive->value,
        ])
        ->assertSessionHasNoErrors();

    expect($clinic->fresh()->status)->toBe(ClinicStatus::Inactive)
        ->and(Clinic::whereKey($clinic->id)->exists())->toBeTrue();
});

it('does not change a clinic slug when it is renamed', function () {
    /* Live tenants are routed by the slug; renaming must not break their links. */
    $clinic = Clinic::factory()->create([
        'organization_id' => $this->organization->id,
        'slug' => 'original-slug',
    ]);

    $this->actingAs($this->admin)
        ->put(route('company.customer-success.accounts.clinics.update', [$this->organization, $clinic]), [
            'name' => 'A Completely New Name',
            'status' => ClinicStatus::Active->value,
        ])
        ->assertSessionHasNoErrors();

    expect($clinic->fresh()->name)->toBe('A Completely New Name')
        ->and($clinic->fresh()->slug)->toBe('original-slug');
});

/* ─────────────────────────────── ownership ─────────────────────────────── */

it('refuses a clinic that belongs to another account', function () {
    $otherOrg = Organization::factory()->create();
    $foreign = Clinic::factory()->create(['organization_id' => $otherOrg->id, 'name' => 'Not Yours']);

    $this->actingAs($this->admin)
        ->put(route('company.customer-success.accounts.clinics.update', [$this->organization, $foreign]), [
            'name' => 'Renamed',
            'status' => ClinicStatus::Active->value,
        ])
        ->assertNotFound();

    expect($foreign->fresh()->name)->toBe('Not Yours');
});

it('refuses a clinic that belongs to no account at all', function () {
    /*
     * The three legacy clinics carry a null organisation. They keep their rows,
     * but they cannot be edited through an account that does not own them.
     */
    $orphan = Clinic::factory()->create(['organization_id' => null, 'name' => 'Legacy Orphan']);

    $this->actingAs($this->admin)
        ->put(route('company.customer-success.accounts.clinics.update', [$this->organization, $orphan]), [
            'name' => 'Adopted',
            'status' => ClinicStatus::Active->value,
        ])
        ->assertNotFound();

    expect($orphan->fresh()->name)->toBe('Legacy Orphan')
        ->and($orphan->fresh()->organization_id)->toBeNull();
});

it('refuses a branch that belongs to another clinic', function () {
    $mine = Clinic::factory()->create(['organization_id' => $this->organization->id]);
    $theirs = Clinic::factory()->create(['organization_id' => $this->organization->id]);

    $branch = Branch::create([
        'clinic_id' => $theirs->id,
        'organization_id' => $this->organization->id,
        'name' => 'Theirs',
        'slug' => 'theirs',
        'is_main' => true,
        'status' => BranchStatus::Active,
    ]);

    $this->actingAs($this->admin)
        ->put(route('company.customer-success.accounts.clinics.branches.update', [$this->organization, $mine, $branch]), [
            'name' => 'Stolen',
            'status' => BranchStatus::Active->value,
        ])
        ->assertNotFound();

    expect($branch->fresh()->name)->toBe('Theirs');
});

it('leaves the legacy clinics with no account exactly as they are', function () {
    /*
     * Nothing in this work repairs data. The orphans keep their rows and their
     * null organisation; what changed is that nothing can create another.
     */
    $orphan = Clinic::factory()->create(['organization_id' => null]);

    expect($orphan->fresh()->organization_id)->toBeNull();

    $this->actingAs($this->admin)
        ->post(route('company.customer-success.accounts.clinics.store', $this->organization), [
            'name' => 'Properly Owned',
        ]);

    expect(Clinic::where('name', 'Properly Owned')->sole()->organization_id)
        ->toBe($this->organization->id);
});

/* ─────────────────────── primary owner, migrated from the console ──────── */

it('assigns the primary owner when one is chosen', function () {
    /*
     * The retired console assigned an owner as part of creating a clinic, and
     * that membership is what the clinic portal reads to let anybody in. A
     * clinic with no owner is provisioned but unusable.
     */
    $owner = User::factory()->create([
        'user_type' => UserType::ClinicUser,
        'organization_id' => $this->organization->id,
    ]);

    $this->actingAs($this->admin)
        ->post(route('company.customer-success.accounts.clinics.store', $this->organization), [
            'name' => 'Owned Clinic',
            'owner_id' => $owner->id,
        ])
        ->assertSessionHasNoErrors();

    $clinic = Clinic::where('name', 'Owned Clinic')->sole();
    $membership = $clinic->primaryOwnerMembership;

    expect($membership)->not->toBeNull()
        ->and($membership->user_id)->toBe($owner->id)
        ->and($membership->is_primary_owner)->toBeTrue();
});

it('refuses an owner who is not a clinic user', function () {
    /*
     * AssignPrimaryOwnerService throws for anyone else, and a 500 is the wrong
     * way to tell somebody they picked the wrong person.
     */
    $companyUser = User::factory()->create([
        'user_type' => UserType::CompanyUser,
        'organization_id' => $this->organization->id,
    ]);

    $this->actingAs($this->admin)
        ->post(route('company.customer-success.accounts.clinics.store', $this->organization), [
            'name' => 'Badly Owned',
            'owner_id' => $companyUser->id,
        ])
        ->assertSessionHasErrors('owner_id');

    expect(Clinic::where('name', 'Badly Owned')->exists())->toBeFalse();
});

it('refuses an owner who belongs to a different organization', function () {
    /*
     * Assigning an owner from another account would grant cross-tenant
     * access to this customer's clinics and patient data. The exists rule
     * strictly bounds owner_id to the owning organization.
     */
    $otherOrg = Organization::factory()->create();
    $foreignUser = User::factory()->create([
        'user_type' => UserType::ClinicUser,
        'organization_id' => $otherOrg->id,
    ]);

    $this->actingAs($this->admin)
        ->post(route('company.customer-success.accounts.clinics.store', $this->organization), [
            'name' => 'Cross Tenant Owned',
            'owner_id' => $foreignUser->id,
        ])
        ->assertSessionHasErrors('owner_id');

    expect(Clinic::where('name', 'Cross Tenant Owned')->exists())->toBeFalse();
});

it('creates a clinic without an owner when none is chosen', function () {
    $this->actingAs($this->admin)
        ->post(route('company.customer-success.accounts.clinics.store', $this->organization), [
            'name' => 'Unowned For Now',
        ])
        ->assertSessionHasNoErrors();

    expect(Clinic::where('name', 'Unowned For Now')->sole()->primaryOwnerMembership)->toBeNull();
});

it('sends the account page the clinic users who may own a clinic', function () {
    $otherOrg = Organization::factory()->create();

    User::factory()->create([
        'user_type' => UserType::ClinicUser,
        'organization_id' => $this->organization->id,
        'name' => 'Eligible Owner',
    ]);
    User::factory()->create([
        'user_type' => UserType::CompanyUser,
        'organization_id' => $this->organization->id,
        'name' => 'Not Eligible',
    ]);
    User::factory()->create([
        'user_type' => UserType::ClinicUser,
        'organization_id' => $otherOrg->id,
        'name' => 'Foreign Tenant Owner',
    ]);

    $this->actingAs($this->admin)
        ->get(route('company.customer-success.accounts.show', $this->organization))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->loadDeferredProps(['setup'], fn ($page) => $page
                ->has('eligibleOwners')
                ->where('eligibleOwners', fn ($owners) => collect($owners)->pluck('name')->contains('Eligible Owner')
                    && ! collect($owners)->pluck('name')->contains('Not Eligible')
                    && ! collect($owners)->pluck('name')->contains('Foreign Tenant Owner'))
            )
        );
});
