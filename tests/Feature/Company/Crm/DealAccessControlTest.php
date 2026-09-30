<?php

use App\Models\CompanyQuotation;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\User;
use App\QuotationStatus;
use App\UserType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * Who may open a deal, and who may change it.
 *
 * Two rules that the old policy did not hold, both of which were reachable in
 * production rather than theoretical:
 *
 *   - A deal belongs to one tenant. `sharesTenantWith()` treated a null on
 *     either side as a match, and null was the normal state for every deal
 *     created before the tenant stamp, so any CRM user could open any
 *     organisation's pipeline.
 *   - Reading and writing are different rights. `update()` accepted the read
 *     permissions as fallbacks, so a viewer could rewrite every field.
 */
beforeEach(function () {
    setPermissionsTeamId(0);

    $this->editorRole = Role::firstOrCreate(['name' => 'Deal Editor', 'guard_name' => 'web']);
    grantCrmManagerPermissionsToRole($this->editorRole);

    /* Read everything, write nothing — the Operations Manager shape. */
    $this->viewerRole = Role::firstOrCreate(['name' => 'Deal Viewer', 'guard_name' => 'web']);
    grantCrmPermissionsToRole($this->viewerRole, ['view-crm', 'view-leads', 'view-operations', 'view-analytics']);

    $this->orgA = Organization::factory()->create();
    $this->orgB = Organization::factory()->create();

    $this->editor = User::factory()->create([
        'user_type' => UserType::CompanyUser,
        'organization_id' => $this->orgA->id,
    ]);
    $this->editor->assignRole($this->editorRole);

    $this->viewer = User::factory()->create([
        'user_type' => UserType::CompanyUser,
        'organization_id' => $this->orgA->id,
    ]);
    $this->viewer->assignRole($this->viewerRole);

    $this->outsider = User::factory()->create([
        'user_type' => UserType::CompanyUser,
        'organization_id' => $this->orgB->id,
    ]);
    $this->outsider->assignRole($this->editorRole);

    $this->deal = Lead::factory()->create([
        'organization_id' => $this->orgA->id,
        'assigned_user_id' => $this->editor->id,
    ]);
});

it('lets a tenant member with write permission open and change a deal', function () {
    $this->actingAs($this->editor)
        ->get(route('company.crm.leads.show', $this->deal))
        ->assertOk();

    $this->actingAs($this->editor)
        ->put(route('company.crm.leads.update', $this->deal), ['company_name' => 'Renamed By Editor'])
        ->assertRedirect();

    expect($this->deal->fresh()->company_name)->toBe('Renamed By Editor');
});

it('refuses another tenant the deal entirely', function () {
    $this->actingAs($this->outsider)
        ->get(route('company.crm.leads.show', $this->deal))
        ->assertForbidden();

    $this->actingAs($this->outsider)
        ->put(route('company.crm.leads.update', $this->deal), ['company_name' => 'Taken'])
        ->assertForbidden();

    expect($this->deal->fresh()->company_name)->not->toBe('Taken');
});

it('refuses a tenantless deal to everyone but a master admin', function () {
    /*
     * The exact shape of the hole: before the fix, a null tenant on either
     * side returned true, so this deal was open to every authenticated CRM
     * user in the database.
     */
    $orphan = Lead::factory()->create(['organization_id' => $this->orgA->id]);
    Lead::withoutEvents(fn () => $orphan->forceFill(['organization_id' => null])->saveQuietly());

    $this->actingAs($this->editor)
        ->get(route('company.crm.leads.show', $orphan))
        ->assertForbidden();

    $this->actingAs($this->outsider)
        ->get(route('company.crm.leads.show', $orphan))
        ->assertForbidden();

    $admin = User::factory()->create(['user_type' => UserType::MasterAdmin]);

    $this->actingAs($admin)
        ->get(route('company.crm.leads.show', $orphan))
        ->assertOk();
});

it('lets a viewer read a deal and refuses every way of changing it', function () {
    $this->actingAs($this->viewer)
        ->get(route('company.crm.leads.show', $this->deal))
        ->assertOk();

    $this->actingAs($this->viewer)
        ->put(route('company.crm.leads.update', $this->deal), ['company_name' => 'Viewer Edit'])
        ->assertForbidden();

    $this->actingAs($this->viewer)
        ->post(route('company.crm.leads.transition', $this->deal), ['status' => 'contacted'])
        ->assertForbidden();

    $this->actingAs($this->viewer)
        ->post(route('company.crm.leads.tasks.store', $this->deal), ['title' => 'Sneak a task in'])
        ->assertForbidden();

    expect($this->deal->fresh()->company_name)->not->toBe('Viewer Edit');
});

it('stamps the tenant on a deal created without one', function () {
    /* Nothing should be able to produce another tenantless row. */
    $this->actingAs($this->editor);

    $deal = Lead::create([
        'company_name' => 'No Tenant Given',
        'contact_name' => 'Someone',
        'phone' => '+91 90000 00000',
    ]);

    expect($deal->organization_id)->toBe($this->orgA->id);
});

it('tells the page what a viewer may do, so no Edit button is ever shown', function () {
    /*
     * The page used to have no idea: `canEdit` defaulted to true inside the
     * section component, so a read-only user saw six Edit buttons and found
     * out the truth as a 403. The server decides now, and the page renders
     * from that decision.
     */
    $this->actingAs($this->viewer)
        ->get(route('company.crm.leads.show', $this->deal))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('can.update', false)
            ->where('can.convert', false)
            ->where('can.approve_quotation', false)
            ->where('can.delete', false)
        );

    $this->actingAs($this->editor)
        ->get(route('company.crm.leads.show', $this->deal))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('can.update', true));
});

it('opens the profile editor read-only for a viewer', function () {
    $this->actingAs($this->viewer)
        ->get(route('company.crm.leads.profile', $this->deal))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Company/Crm/Leads/Profile')
            ->where('can.update', false)
            ->has('profile.organisation')
        );

    /* And refuses the save the read-only form does not offer. */
    $this->actingAs($this->viewer)
        ->put(route('company.crm.leads.update', $this->deal), ['company_name' => 'Nope'])
        ->assertForbidden();
});

it('refuses every commercial action to a viewer and to another tenant', function () {
    /*
     * Money is recorded against the quotation it settles, never against the
     * deal — the deal-level endpoint keyed payments to an agreement and is
     * gone. The authority is the same either way: neither a viewer nor another
     * tenant may record it.
     */
    $quotation = CompanyQuotation::factory()->create([
        'lead_id' => $this->deal->id,
        'organization_id' => $this->orgA->id,
        'status' => QuotationStatus::Approved,
        'approved_at' => now(),
        'subtotal' => 1000,
        'tax' => 0,
        'discount' => 0,
        'grand_total' => 1000,
    ]);

    foreach ([$this->viewer, $this->outsider] as $user) {
        $this->actingAs($user)
            ->post(route('company.crm.quotations.payments.store', $quotation), [
                'amount' => 1000,
                'gateway' => 'bank_transfer',
                'status' => 'successful',
            ])
            ->assertForbidden();
    }

    expect($this->deal->payments()->count())->toBe(0);
});

it('no longer routes the retired agreement and deal-payment paths', function () {
    /*
     * CC3 removed them. A route that still resolves is a path somebody can
     * still reach, whatever the interface stops offering.
     */
    foreach ([
        'company.crm.leads.agreement.generate',
        'company.crm.leads.agreement.accept',
        'company.crm.leads.payments.store',
        'company.crm.documents.index',
        'company.crm.documents.create',
        'company.crm.documents.store',
        'company.crm.documents.show',
    ] as $name) {
        expect(Route::has($name))->toBeFalse("route {$name} still exists");
    }
});
