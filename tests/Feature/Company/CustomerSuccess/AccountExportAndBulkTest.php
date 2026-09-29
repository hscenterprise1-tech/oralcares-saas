<?php

declare(strict_types=1);

namespace Tests\Feature\Company\CustomerSuccess;

use App\CustomerChurnRisk;
use App\Models\Organization;
use App\Models\User;
use App\UserType;
use Database\Seeders\CrmRolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * Taking the worklist off the screen, and acting on more than one row.
 *
 * Customer Success had neither, while CRM has had an export since Phase 3 and
 * Marketing a bulk tag since the contacts rebuild. At a hundred and twenty
 * thousand accounts, "open each one and change it" is not an operation.
 */
beforeEach(function () {
    setPermissionsTeamId(0);
    $this->seed(CrmRolePermissionSeeder::class);

    $this->vendorOrg = Organization::factory()->provider()->create(['name' => 'OralCares HQ']);

    $this->masterAdmin = User::factory()->create([
        'organization_id' => $this->vendorOrg->id,
        'user_type' => UserType::MasterAdmin,
    ]);

    /* CRM Manager carries `manage-organizations`; Sales Representative does not. */
    $this->csManager = User::factory()->create([
        'organization_id' => $this->vendorOrg->id,
        'user_type' => UserType::CompanyUser,
    ]);
    setPermissionsTeamId(0);
    $this->csManager->assignRole(Role::findByName('CRM Manager', 'web'));

    $this->csRep = User::factory()->create([
        'organization_id' => $this->vendorOrg->id,
        'user_type' => UserType::CompanyUser,
    ]);
    setPermissionsTeamId(0);
    $this->csRep->assignRole(Role::findByName('Sales Representative', 'web'));

    $this->alpha = Organization::factory()->create([
        'name' => 'Alpha Dental',
        'city' => 'Pune',
        'churn_risk' => CustomerChurnRisk::Low,
        'health_score' => 90,
    ]);

    $this->beta = Organization::factory()->create([
        'name' => 'Beta Dental',
        'city' => 'Nashik',
        'churn_risk' => CustomerChurnRisk::Low,
        'health_score' => 40,
    ]);

    projectSnapshot($this->alpha->id, [
        'health_score' => 90,
        'churn_risk' => CustomerChurnRisk::Low->value,
        'stage' => 'live',
        'mrr' => 12500,
        'days_to_expiry' => 20,
        'has_subscription' => true,
        'purchased_seats' => 10,
        'used_seats' => 4,
        'seat_utilization_pct' => 40,
        'computed_at' => now(),
    ]);

    projectSnapshot($this->beta->id, [
        'health_score' => 40,
        'churn_risk' => CustomerChurnRisk::Low->value,
        'stage' => 'live',
        /* Deliberately unrecorded — the export must not invent a zero. */
        'mrr' => null,
        'days_to_expiry' => 300,
        'has_subscription' => true,
        'computed_at' => now(),
    ]);
});

/* ───────────────────────────────── export ─────────────────────────────────── */

function csvFrom($response): array
{
    ob_start();
    $response->sendContent();

    return array_values(array_filter(explode("\n", str_replace("\r\n", "\n", (string) ob_get_clean()))));
}

it('streams the account book as a csv', function () {
    $response = $this->actingAs($this->masterAdmin)
        ->get(route('company.customer-success.accounts.export'));

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('text/csv');
    expect($response->headers->get('content-disposition'))->toContain('customer_success_accounts_');

    $rows = csvFrom($response);

    expect($rows[0])->toContain('Account ID')
        ->and($rows[0])->toContain('Churn Risk')
        ->and($rows[0])->toContain('Days To Expiry');

    expect(implode("\n", $rows))->toContain('Alpha Dental')
        ->and(implode("\n", $rows))->toContain('Beta Dental');
});

it('exports exactly the rows the reader has filtered to', function () {
    /*
     * The export shares the list's builder rather than carrying its own copy of
     * the rules — an export that stops matching the screen it was taken from is
     * worse than no export.
     */
    $rows = csvFrom(
        $this->actingAs($this->masterAdmin)
            ->get(route('company.customer-success.accounts.export', ['search' => 'Alpha']))
    );

    $body = implode("\n", $rows);

    expect($body)->toContain('Alpha Dental')
        ->and($body)->not->toContain('Beta Dental');
});

it('leaves an unrecorded mrr blank rather than exporting a zero', function () {
    $rows = csvFrom(
        $this->actingAs($this->masterAdmin)
            ->get(route('company.customer-success.accounts.export', ['search' => 'Beta']))
    );

    $beta = collect($rows)->first(fn ($row) => str_contains($row, 'Beta Dental'));
    $columns = str_getcsv((string) $beta);

    /* MRR and ARR sit at 13 and 14; both must be empty, not "0.00". */
    expect($columns[13])->toBe('')
        ->and($columns[14])->toBe('');
});

it('never exports an account outside the reader scope', function () {
    /*
     * A rep sees only what is theirs. The export runs through the same
     * `CrmScope` builder the list does, so it cannot become a way around it.
     */
    $rows = csvFrom(
        $this->actingAs($this->csRep)->get(route('company.customer-success.accounts.export'))
    );

    $body = implode("\n", $rows);

    expect($body)->not->toContain('Alpha Dental')
        ->and($body)->not->toContain('Beta Dental');
});

/* ────────────────────────────── bulk update ───────────────────────────────── */

it('reassigns a selection of accounts in one action', function () {
    $newOwner = User::factory()->create([
        'organization_id' => $this->vendorOrg->id,
        'user_type' => UserType::CompanyUser,
    ]);

    $this->actingAs($this->masterAdmin)
        ->post(route('company.customer-success.accounts.bulk'), [
            'ids' => [$this->alpha->id, $this->beta->id],
            'assigned_user_id' => $newOwner->id,
        ])
        ->assertSessionHasNoErrors();

    expect($this->alpha->fresh()->assigned_user_id)->toBe($newOwner->id)
        ->and($this->beta->fresh()->assigned_user_id)->toBe($newOwner->id);
});

it('records every bulk change on the account timeline', function () {
    $this->actingAs($this->masterAdmin)
        ->post(route('company.customer-success.accounts.bulk'), [
            'ids' => [$this->alpha->id],
            'churn_risk' => CustomerChurnRisk::Medium->value,
        ])
        ->assertSessionHasNoErrors();

    expect($this->alpha->fresh()->churn_risk)->toBe(CustomerChurnRisk::Medium);

    $this->assertDatabaseHas('activities', [
        'subject_type' => 'organization',
        'subject_id' => $this->alpha->id,
        'type' => 'record_updated',
    ]);
});

it('rebuilds the projection for every account it touched', function () {
    /*
     * The snapshot is what the list filters and sorts on. A bulk change that
     * did not rebuild it would move the account without moving it in any of the
     * worklists that are supposed to find it.
     */
    $this->actingAs($this->masterAdmin)
        ->post(route('company.customer-success.accounts.bulk'), [
            'ids' => [$this->alpha->id],
            'churn_risk' => CustomerChurnRisk::High->value,
        ])
        ->assertSessionHasNoErrors();

    expect($this->alpha->fresh()->snapshot->churn_risk->value)->toBe(CustomerChurnRisk::High->value);
});

it('refuses the bulk write to a reader who may only read', function () {
    $this->actingAs($this->csRep)
        ->post(route('company.customer-success.accounts.bulk'), [
            'ids' => [$this->alpha->id],
            'churn_risk' => CustomerChurnRisk::High->value,
        ])
        ->assertForbidden();

    expect($this->alpha->fresh()->churn_risk)->toBe(CustomerChurnRisk::Low);
});

it('ignores a posted id the actor may not reach', function () {
    /*
     * The ids are re-resolved through `CrmScope` rather than trusted. A manager
     * scoped to their own tenant who posts somebody else's account id gets
     * nothing changed, not a silent cross-tenant write.
     */
    $foreignVendor = Organization::factory()->provider()->create(['name' => 'Other HQ']);
    $foreignManager = User::factory()->create([
        'organization_id' => $foreignVendor->id,
        'user_type' => UserType::CompanyUser,
    ]);
    setPermissionsTeamId(0);
    $foreignManager->assignRole(Role::findByName('CRM Manager', 'web'));

    $this->actingAs($foreignManager)
        ->post(route('company.customer-success.accounts.bulk'), [
            'ids' => [$this->alpha->id],
            'churn_risk' => CustomerChurnRisk::High->value,
        ])
        ->assertSessionHasErrors('bulk');

    expect($this->alpha->fresh()->churn_risk)->toBe(CustomerChurnRisk::Low);
});

it('refuses a bulk call that names no change', function () {
    $this->actingAs($this->masterAdmin)
        ->post(route('company.customer-success.accounts.bulk'), ['ids' => [$this->alpha->id]])
        ->assertSessionHasErrors('bulk');
});

it('bounds how many accounts one call may change', function () {
    $this->actingAs($this->masterAdmin)
        ->post(route('company.customer-success.accounts.bulk'), [
            'ids' => range(1, 501),
            'churn_risk' => CustomerChurnRisk::High->value,
        ])
        ->assertSessionHasErrors('ids');
});
