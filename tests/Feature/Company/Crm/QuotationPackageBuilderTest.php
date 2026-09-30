<?php

use App\Models\CompanyPackageAddon;
use App\Models\CompanyPlan;
use App\Models\CompanyQuotation;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\User;
use App\Services\LeadWorkspaceService;
use App\UserType;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Phase 2B — the package-based quotation builder.
 *
 * `LeadWorkspaceService::buildPackageLineItems()` expands a selected
 * `CompanyPlan`, its additional seats, and any `CompanyPackageAddon` rows
 * into concrete `CompanyQuotationItem` rows, each snapshotting its own
 * title/price at that exact moment. Nothing downstream ever re-reads the
 * catalogue to price an existing quotation — a later price change must never
 * rewrite history.
 */
function packageQuotationLead(): array
{
    $org = Organization::factory()->create();
    $rep = grantCrmPermissions(User::factory()->create([
        'user_type' => UserType::CompanyUser,
        'organization_id' => $org->id,
    ]));
    $lead = Lead::factory()->create([
        'organization_id' => $org->id,
        'assigned_user_id' => $rep->id,
    ]);

    return [$rep, $lead];
}

it('creates an active package with the expected catalogue fields', function () {
    $plan = CompanyPlan::factory()->create([
        'name' => 'Enterprise',
        'price' => 49999,
        'max_users' => 10,
        'additional_user_price' => 999,
        'is_active' => true,
    ]);

    expect($plan->name)->toBe('Enterprise')
        ->and((float) $plan->price)->toBe(49999.0)
        ->and($plan->max_users)->toBe(10)
        ->and((float) $plan->additional_user_price)->toBe(999.0)
        ->and($plan->is_active)->toBeTrue();
});

it('selects a package and generates its base line item', function () {
    [$rep, $lead] = packageQuotationLead();
    $plan = CompanyPlan::factory()->create(['name' => 'Growth', 'price' => 15000, 'is_active' => true]);

    $quotation = app(LeadWorkspaceService::class)->createQuotation($lead, [
        'company_plan_id' => $plan->id,
    ], [], $rep);

    expect($quotation->company_plan_id)->toBe($plan->id)
        ->and($quotation->items)->toHaveCount(1);

    $item = $quotation->items->first();
    expect($item->item_type)->toBe('package_base')
        ->and($item->title)->toBe('Growth')
        ->and((float) $item->unit_price)->toBe(15000.0);
});

it('adds an additional-users line item priced at the plan own rate', function () {
    [$rep, $lead] = packageQuotationLead();
    $plan = CompanyPlan::factory()->create([
        'name' => 'Growth', 'price' => 15000, 'max_users' => 5, 'additional_user_price' => 500, 'is_active' => true,
    ]);

    $quotation = app(LeadWorkspaceService::class)->createQuotation($lead, [
        'company_plan_id' => $plan->id,
        'additional_users' => 3,
    ], [], $rep);

    expect($quotation->items)->toHaveCount(2);

    $usersItem = $quotation->items->firstWhere('item_type', 'additional_users');
    expect($usersItem)->not->toBeNull()
        ->and($usersItem->quantity)->toBe(3)
        ->and((float) $usersItem->unit_price)->toBe(500.0)
        ->and((float) $usersItem->total)->toBe(1500.0);

    // Subtotal = 15000 (base) + 1500 (3 * 500) = 16500.
    expect((float) $quotation->subtotal)->toBe(16500.0);
});

it('skips additional users when the plan sells no extra seats', function () {
    [$rep, $lead] = packageQuotationLead();
    $plan = CompanyPlan::factory()->create(['price' => 15000, 'additional_user_price' => null, 'is_active' => true]);

    $quotation = app(LeadWorkspaceService::class)->createQuotation($lead, [
        'company_plan_id' => $plan->id,
        'additional_users' => 5,
    ], [], $rep);

    expect($quotation->items)->toHaveCount(1);
});

it('attaches selected add-ons as their own priced line items', function () {
    [$rep, $lead] = packageQuotationLead();
    $plan = CompanyPlan::factory()->create(['name' => 'Growth', 'price' => 15000, 'is_active' => true]);
    $cloudBackup = CompanyPackageAddon::factory()->create(['name' => 'Cloud Backup', 'price' => 2000, 'is_active' => true]);
    $amc = CompanyPackageAddon::factory()->create(['name' => 'Support / AMC', 'price' => 5000, 'is_active' => true]);

    $quotation = app(LeadWorkspaceService::class)->createQuotation($lead, [
        'company_plan_id' => $plan->id,
        'addon_ids' => [$cloudBackup->id, $amc->id],
    ], [], $rep);

    expect($quotation->items)->toHaveCount(3);

    $addonItems = $quotation->items->where('item_type', 'addon');
    expect($addonItems)->toHaveCount(2)
        ->and($addonItems->pluck('title')->sort()->values()->all())->toBe(['Cloud Backup', 'Support / AMC'])
        ->and($addonItems->firstWhere('title', 'Cloud Backup')->company_package_addon_id)->toBe($cloudBackup->id);

    // Subtotal = 15000 + 2000 + 5000 = 22000.
    expect((float) $quotation->subtotal)->toBe(22000.0);
});

it('ignores an inactive add-on even if its id is requested', function () {
    [$rep, $lead] = packageQuotationLead();
    $plan = CompanyPlan::factory()->create(['price' => 15000, 'is_active' => true]);
    $inactive = CompanyPackageAddon::factory()->create(['is_active' => false]);

    $quotation = app(LeadWorkspaceService::class)->createQuotation($lead, [
        'company_plan_id' => $plan->id,
        'addon_ids' => [$inactive->id],
    ], [], $rep);

    expect($quotation->items)->toHaveCount(1);
});

it('combines package, additional users, add-ons and a custom item in one calculation', function () {
    [$rep, $lead] = packageQuotationLead();
    $plan = CompanyPlan::factory()->create([
        'name' => 'Growth', 'price' => 15000, 'additional_user_price' => 500, 'is_active' => true,
    ]);
    $addon = CompanyPackageAddon::factory()->create(['name' => 'Module A', 'price' => 3000, 'is_active' => true]);

    $quotation = app(LeadWorkspaceService::class)->createQuotation($lead, [
        'company_plan_id' => $plan->id,
        'additional_users' => 2,
        'addon_ids' => [$addon->id],
    ], [
        ['title' => 'On-site training (one day)', 'quantity' => 1, 'unit_price' => 8000, 'discount' => 0],
    ], $rep);

    expect($quotation->items)->toHaveCount(4);

    // 15000 (base) + 1000 (2 users * 500) + 3000 (addon) + 8000 (custom) = 27000.
    expect((float) $quotation->subtotal)->toBe(27000.0);

    $custom = $quotation->items->firstWhere('item_type', 'custom');
    expect($custom)->not->toBeNull()
        ->and($custom->title)->toBe('On-site training (one day)');
});

it('snapshots the package price onto the quotation and never rewrites it when the catalogue changes', function () {
    [$rep, $lead] = packageQuotationLead();
    $plan = CompanyPlan::factory()->create(['name' => 'Growth', 'price' => 15000, 'is_active' => true]);

    $quotation = app(LeadWorkspaceService::class)->createQuotation($lead, [
        'company_plan_id' => $plan->id,
    ], [], $rep);

    $originalUnitPrice = (float) $quotation->items->first()->unit_price;
    $originalSubtotal = (float) $quotation->subtotal;

    $plan->update(['price' => 99999]);

    $quotation->refresh();
    expect((float) $quotation->items->first()->unit_price)->toBe($originalUnitPrice)
        ->and((float) $quotation->subtotal)->toBe($originalSubtotal)
        ->and($originalUnitPrice)->toBe(15000.0);
});

it('creates a quotation over HTTP using the package builder with additional users and add-ons', function () {
    [$rep, $lead] = packageQuotationLead();
    $plan = CompanyPlan::factory()->create(['name' => 'Growth', 'price' => 15000, 'additional_user_price' => 500, 'is_active' => true]);
    $addon = CompanyPackageAddon::factory()->create(['name' => 'Cloud Backup', 'price' => 2000, 'is_active' => true]);

    $this->actingAs($rep)
        ->post(route('company.crm.leads.create-quotation', $lead), [
            'company_plan_id' => $plan->id,
            'additional_users' => 1,
            'addon_ids' => [$addon->id],
        ])
        ->assertSessionHasNoErrors();

    $quotation = CompanyQuotation::where('lead_id', $lead->id)->with('items')->firstOrFail();

    expect($quotation->items)->toHaveCount(3)
        // 15000 + 500 (1 user) + 2000 (addon) = 17500.
        ->and((float) $quotation->subtotal)->toBe(17500.0);
});

it('rejects an unknown add-on id at the validation layer', function () {
    [$rep, $lead] = packageQuotationLead();
    $plan = CompanyPlan::factory()->create(['is_active' => true]);

    $this->actingAs($rep)
        ->post(route('company.crm.leads.create-quotation', $lead), [
            'company_plan_id' => $plan->id,
            'addon_ids' => [999999],
        ])
        ->assertSessionHasErrors('addon_ids.0');
});
