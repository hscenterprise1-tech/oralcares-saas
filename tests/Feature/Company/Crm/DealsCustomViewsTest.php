<?php

use App\LeadStatus;
use App\Models\CrmSavedView;
use App\Models\Organization;
use App\Models\User;
use App\UserType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * A person's own named cuts of the Deals list.
 *
 * Two rules under test: a saved view can only carry filters the list actually
 * understands, and it belongs to exactly one person.
 */
beforeEach(function () {
    setPermissionsTeamId(0);
    Role::firstOrCreate(['name' => 'CRM Manager', 'guard_name' => 'web']);
    grantCrmManagerPermissionsToRole(Role::findByName('CRM Manager', 'web'));

    $this->org = Organization::factory()->create();
    $this->user = User::factory()->create([
        'user_type' => UserType::CompanyUser,
        'organization_id' => $this->org->id,
    ]);
    $this->user->assignRole('CRM Manager');
});

it('saves the cut currently on screen under a name', function () {
    $this->actingAs($this->user)
        ->post(route('company.crm.deals.views.store'), [
            'name' => 'Mumbai enterprise',
            'filters' => ['city' => 'Mumbai', 'value_min' => '500000', 'stage' => LeadStatus::Negotiation->value],
        ])
        ->assertRedirect();

    $saved = CrmSavedView::where('user_id', $this->user->id)->sole();

    expect($saved->name)->toBe('Mumbai enterprise')
        // Key order follows what was sent, which is what makes it predictable.
        ->and($saved->filters)->toBe([
            'city' => 'Mumbai',
            'value_min' => '500000',
            'stage' => LeadStatus::Negotiation->value,
        ]);
});

it('drops anything the deals query would not understand', function () {
    $this->actingAs($this->user)
        ->post(route('company.crm.deals.views.store'), [
            'name' => 'Odd one',
            'filters' => ['city' => 'Pune', 'page' => '4', 'evil' => 'DROP TABLE', 'stage' => 'all', 'search' => ''],
        ]);

    expect(CrmSavedView::where('user_id', $this->user->id)->sole()->filters)
        ->toBe(['city' => 'Pune']);
});

it('refuses to save a view with nothing in it', function () {
    $this->actingAs($this->user)
        ->post(route('company.crm.deals.views.store'), ['name' => 'Empty', 'filters' => []])
        ->assertSessionHasErrors('filters');

    expect(CrmSavedView::count())->toBe(0);
});

it('replaces a view saved again under the same name', function () {
    foreach (['Pune', 'Nashik'] as $city) {
        $this->actingAs($this->user)->post(route('company.crm.deals.views.store'), [
            'name' => 'My patch',
            'filters' => ['city' => $city],
        ]);
    }

    $saved = CrmSavedView::where('user_id', $this->user->id)->sole();

    expect($saved->filters)->toBe(['city' => 'Nashik']);
});

it('will not let one person delete another person saved view', function () {
    $stranger = User::factory()->create([
        'user_type' => UserType::CompanyUser,
        'organization_id' => $this->org->id,
    ]);
    $stranger->assignRole('CRM Manager');

    $theirs = CrmSavedView::create([
        'user_id' => $stranger->id,
        'organization_id' => $this->org->id,
        'name' => 'Theirs',
        'filters' => ['city' => 'Delhi'],
    ]);

    $this->actingAs($this->user)
        ->delete(route('company.crm.deals.views.destroy', $theirs))
        ->assertForbidden();

    expect(CrmSavedView::whereKey($theirs->id)->exists())->toBeTrue();
});

it('sends a person only their own saved views', function () {
    CrmSavedView::create(['user_id' => $this->user->id, 'organization_id' => $this->org->id, 'name' => 'Mine', 'filters' => ['city' => 'Pune']]);

    $stranger = User::factory()->create(['user_type' => UserType::CompanyUser, 'organization_id' => $this->org->id]);
    CrmSavedView::create(['user_id' => $stranger->id, 'organization_id' => $this->org->id, 'name' => 'Theirs', 'filters' => ['city' => 'Delhi']]);

    $this->actingAs($this->user)
        ->get(route('company.crm.deals.index'))
        ->assertInertia(fn ($page) => $page
            ->has('customViews', 1)
            ->where('customViews.0.name', 'Mine')
        );
});
