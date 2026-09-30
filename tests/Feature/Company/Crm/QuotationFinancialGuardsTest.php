<?php

use App\Models\CompanyEmployee;
use App\Models\CompanyQuotation;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\User;
use App\QuotationStatus;
use App\Services\LeadWorkspaceService;
use App\UserType;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Final QA bug hunt — `saveItemsAndCalculateTotals()` (every quotation create
 * and every item edit) never checked the discount it carried against the
 * subtotal it was about to recompute, and never revoked an existing approval
 * when editing items changed the price that approval had signed off on.
 * `setQuotationDiscount()` already enforced both rules for its own endpoint;
 * this pins the same rules on the item-edit path.
 */
beforeEach(function () {
    $this->org = Organization::factory()->create();

    $this->rep = grantCrmPermissions(User::factory()->create([
        'user_type' => UserType::CompanyUser,
        'organization_id' => $this->org->id,
    ]), ['view-crm', 'view-quotations', 'view-leads', 'manage-quotations']);

    $this->lead = Lead::factory()->create(['organization_id' => $this->org->id]);

    $this->quotation = CompanyQuotation::factory()->create([
        'organization_id' => $this->org->id,
        'lead_id' => $this->lead->id,
        'prepared_by_id' => CompanyEmployee::factory()->create()->id,
        'status' => QuotationStatus::Draft,
    ]);

    // Seeded through the real calculation path rather than hand-typed
    // figures, so the stored `grand_total` is exactly what re-saving the
    // same items would recompute — a hand-typed total that happened to skip
    // GST would make every re-save look like a "change" that never occurred.
    app(LeadWorkspaceService::class)->saveItemsAndCalculateTotals($this->quotation, [
        ['title' => 'Original package', 'quantity' => 1, 'unit_price' => 100000],
    ]);
    $this->quotation->forceFill(['discount' => 30000, 'discount_is_manual' => true])->save();
    app(LeadWorkspaceService::class)->saveItemsAndCalculateTotals($this->quotation->fresh(), [
        ['title' => 'Original package', 'quantity' => 1, 'unit_price' => 100000],
    ]);
    $this->quotation = $this->quotation->fresh();
});

it('refuses an item edit that would leave a manual discount exceeding the new subtotal', function () {
    $this->actingAs($this->rep)
        ->put(route('company.crm.quotations.update', $this->quotation), [
            'items' => [[
                'title' => 'Shrunk package',
                'quantity' => 1,
                'unit_price' => 10000,
            ]],
        ])
        ->assertSessionHasErrors('items');

    // Nothing was written — the old, consistent subtotal/discount pair survives.
    expect((float) $this->quotation->fresh()->subtotal)->toBe(100000.0)
        ->and((float) $this->quotation->fresh()->discount)->toBe(30000.0);
});

it('still allows an item edit that keeps the discount within the new subtotal', function () {
    $this->actingAs($this->rep)
        ->put(route('company.crm.quotations.update', $this->quotation), [
            'items' => [[
                'title' => 'Comfortably larger package',
                'quantity' => 1,
                'unit_price' => 200000,
            ]],
        ])
        ->assertSessionHasNoErrors();

    expect((float) $this->quotation->fresh()->subtotal)->toBe(200000.0)
        ->and((float) $this->quotation->fresh()->discount)->toBe(30000.0);
});

it('revokes an existing approval when an item edit changes the grand total', function () {
    $this->quotation->forceFill([
        'status' => QuotationStatus::Approved,
        'approved_at' => now(),
    ])->save();

    $this->actingAs($this->rep)
        ->put(route('company.crm.quotations.update', $this->quotation), [
            'items' => [[
                'title' => 'Repriced package',
                'quantity' => 1,
                'unit_price' => 150000,
            ]],
        ])
        ->assertSessionHasNoErrors();

    $fresh = $this->quotation->fresh();

    expect($fresh->approved_at)->toBeNull()
        ->and($fresh->status)->toBe(QuotationStatus::Draft);
});

it('does not revoke an approval when an item edit leaves the grand total unchanged', function () {
    // Same subtotal (100000), same discount (30000) — the total this approval
    // actually signed off on is untouched, so re-saving the same items must
    // not disturb it.
    $this->quotation->forceFill([
        'status' => QuotationStatus::Approved,
        'approved_at' => now(),
    ])->save();

    $this->actingAs($this->rep)
        ->put(route('company.crm.quotations.update', $this->quotation), [
            'items' => [[
                'title' => 'Same-priced replacement line',
                'quantity' => 1,
                'unit_price' => 100000,
            ]],
        ])
        ->assertSessionHasNoErrors();

    $fresh = $this->quotation->fresh();

    expect($fresh->approved_at)->not->toBeNull()
        ->and($fresh->status)->toBe(QuotationStatus::Approved);
});

it('refuses a discount exceeding the subtotal at the service level for a brand-new quotation too', function () {
    $newQuotation = CompanyQuotation::factory()->create([
        'organization_id' => $this->org->id,
        'lead_id' => $this->lead->id,
        'prepared_by_id' => CompanyEmployee::factory()->create()->id,
        'status' => QuotationStatus::Draft,
    ]);

    expect(fn () => app(LeadWorkspaceService::class)->saveItemsAndCalculateTotals($newQuotation, [
        ['title' => 'Line with an oversized per-line discount', 'quantity' => 1, 'unit_price' => 1000, 'discount' => 5000],
    ]))->toThrow(InvalidArgumentException::class);
});
