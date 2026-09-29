<?php

use App\Models\CompanyEmployee;
use App\Models\CompanyQuotation;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\User;
use App\QuotationStatus;
use App\Services\LeadWorkspaceService;
use App\Services\QuotationApprovalService;
use App\UserType;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Phase 2B — the tiered discount/approval workflow.
 *
 * Two ceilings, not one: an admin-tier user (master admin, or anyone holding
 * `crm.quotations.approve` — same permission set the pre-existing approve/
 * reject gate already used) may discount up to `admin_direct_discount_percent`
 * (20% by default) with no approval step at all. An employee keeps following
 * the original, unchanged `crm.quotation_approval_discount_percent` config
 * threshold (default 5%). Which rule applies is decided once, by who actually
 * set the discount (`discount_requested_by_id`), not by whoever later calls
 * send().
 */
function discountQuotation(array $attributes = []): CompanyQuotation
{
    /*
     * The lead and the quotation share one tenant — an `organization_id`
     * override is for the quotation *and* the lead behind it, since
     * `QuotationPolicy`/`LeadPolicy` both check the record's own column, not
     * each other's. Defaults to a fresh organisation exactly as before when
     * no caller cares which tenant it is.
     */
    $orgId = $attributes['organization_id'] ?? Organization::factory()->create()->id;
    $lead = Lead::factory()->create(['organization_id' => $orgId]);
    $employee = CompanyEmployee::factory()->create();

    return CompanyQuotation::factory()->create(array_merge([
        'organization_id' => $orgId,
        'lead_id' => $lead->id,
        'prepared_by_id' => $employee->id,
        'status' => QuotationStatus::Draft,
        'subtotal' => 100000,
        'discount' => 0,
        'tax' => 0,
        'grand_total' => 100000,
    ], $attributes));
}

function adminTierUser(?int $organizationId = null): User
{
    return grantCrmPermissions(User::factory()->create([
        'user_type' => UserType::CompanyUser,
        'organization_id' => $organizationId,
    ]), [
        'view-crm', 'view-quotations', 'view-leads', 'manage-quotations', 'view-operations',
    ]);
}

function employeeUser(?int $organizationId = null): User
{
    return grantCrmPermissions(User::factory()->create([
        'user_type' => UserType::CompanyUser,
        'organization_id' => $organizationId,
    ]), [
        'view-crm', 'view-quotations', 'view-leads', 'create leads', 'edit own leads', 'manage-quotations',
    ]);
}

/* ──────────────────────────────────────────── admin tier ───────────────── */

it('lets an admin apply a discount within 20% with no approval required', function () {
    $admin = adminTierUser();
    $quotation = discountQuotation();

    $result = app(LeadWorkspaceService::class)->setQuotationDiscount($quotation, 'percentage', 15, $admin);

    expect($result['requires_approval'])->toBeFalse()
        ->and($quotation->fresh()->discount_requested_by_id)->toBe($admin->id)
        ->and(app(QuotationApprovalService::class)->canBeSent($quotation->fresh()))->toBeTrue();
});

it('lets an admin apply a discount at exactly 20% with no approval required', function () {
    $admin = adminTierUser();
    $quotation = discountQuotation();

    $result = app(LeadWorkspaceService::class)->setQuotationDiscount($quotation, 'percentage', 20, $admin);

    expect($result['requires_approval'])->toBeFalse()
        ->and(app(QuotationApprovalService::class)->canBeSent($quotation->fresh()))->toBeTrue();
});

it('requires approval when an admin discounts above 20%', function () {
    $admin = adminTierUser();
    $quotation = discountQuotation();

    $result = app(LeadWorkspaceService::class)->setQuotationDiscount($quotation, 'percentage', 20.01, $admin);

    expect($result['requires_approval'])->toBeTrue()
        ->and(app(QuotationApprovalService::class)->canBeSent($quotation->fresh()))->toBeFalse();
});

it('grants the admin ceiling to a master admin regardless of permissions', function () {
    $master = User::factory()->masterAdmin()->create();
    $quotation = discountQuotation();

    $result = app(LeadWorkspaceService::class)->setQuotationDiscount($quotation, 'percentage', 20, $master);

    expect($result['requires_approval'])->toBeFalse();
});

/* ────────────────────────────────────────── employee tier ──────────────── */

it('lets an employee apply a discount within the configured threshold', function () {
    config(['crm.quotation_approval_discount_percent' => 5]);
    $employee = employeeUser();
    $quotation = discountQuotation();

    $result = app(LeadWorkspaceService::class)->setQuotationDiscount($quotation, 'percentage', 4, $employee);

    expect($result['requires_approval'])->toBeFalse();
});

it('requires approval when an employee discounts exactly at the threshold', function () {
    config(['crm.quotation_approval_discount_percent' => 5]);
    $employee = employeeUser();
    $quotation = discountQuotation();

    $result = app(LeadWorkspaceService::class)->setQuotationDiscount($quotation, 'percentage', 5, $employee);

    // Inclusive, unchanged from the original global-threshold behaviour: a
    // discount *at* the threshold is already the exception it names.
    expect($result['requires_approval'])->toBeTrue();
});

it('requires approval when an employee discounts above the threshold', function () {
    config(['crm.quotation_approval_discount_percent' => 5]);
    $employee = employeeUser();
    $quotation = discountQuotation(['organization_id' => $employee->resolveOrganizationId()]);

    $result = app(LeadWorkspaceService::class)->setQuotationDiscount($quotation, 'percentage', 12, $employee);

    expect($result['requires_approval'])->toBeTrue();

    $this->actingAs($employee)
        ->post(route('company.crm.quotations.send', $quotation->fresh()))
        ->assertSessionHasErrors('quotation');
});

it('does not let an employee apply the admin ceiling', function () {
    config(['crm.quotation_approval_discount_percent' => 5]);
    $employee = employeeUser();
    $quotation = discountQuotation();

    // 15% is within the admin's 20% ceiling but well above the employee's 5%.
    $result = app(LeadWorkspaceService::class)->setQuotationDiscount($quotation, 'percentage', 15, $employee);

    expect($result['requires_approval'])->toBeTrue();
});

/* ──────────────────────────────────────── percentage vs fixed ──────────── */

it('computes the discount amount correctly for a percentage discount', function () {
    $admin = adminTierUser();
    $quotation = discountQuotation(['subtotal' => 100000]);

    app(LeadWorkspaceService::class)->setQuotationDiscount($quotation, 'percentage', 10, $admin);

    // Taxable 90000; 18% GST = 16200; grand total = 106200.
    expect((float) $quotation->fresh()->discount)->toBe(10000.0)
        ->and((float) $quotation->fresh()->taxable_amount)->toBe(90000.0)
        ->and((float) $quotation->fresh()->grand_total)->toBe(106200.0);
});

it('computes the discount amount correctly for a fixed-amount discount', function () {
    $admin = adminTierUser();
    $quotation = discountQuotation(['subtotal' => 100000]);

    app(LeadWorkspaceService::class)->setQuotationDiscount($quotation, 'fixed', 10000, $admin);

    expect((float) $quotation->fresh()->discount)->toBe(10000.0)
        ->and($quotation->fresh()->discount_type)->toBe('fixed')
        ->and((float) $quotation->fresh()->discount_value)->toBe(10000.0);
});

it('converts a fixed-amount discount to its effective percentage for the approval decision', function () {
    config(['crm.quotation_approval_discount_percent' => 5]);
    $employee = employeeUser();
    // ₹12,000 of a ₹100,000 subtotal is 12% — well above the 5% employee threshold.
    $quotation = discountQuotation(['subtotal' => 100000]);

    $result = app(LeadWorkspaceService::class)->setQuotationDiscount($quotation, 'fixed', 12000, $employee);

    expect($result['requires_approval'])->toBeTrue();

    // The inverse: ₹3,000 of ₹100,000 is 3%, under the threshold.
    $quotation2 = discountQuotation(['subtotal' => 100000]);
    $result2 = app(LeadWorkspaceService::class)->setQuotationDiscount($quotation2, 'fixed', 3000, $employee);

    expect($result2['requires_approval'])->toBeFalse();
});

it('does not let a fixed-amount discount bypass the admin ceiling at the exact boundary', function () {
    $admin = adminTierUser();
    // Exactly 20% of 100000 is 20000 — must NOT require approval (inclusive ceiling).
    $quotation = discountQuotation(['subtotal' => 100000]);
    $result = app(LeadWorkspaceService::class)->setQuotationDiscount($quotation, 'fixed', 20000, $admin);
    expect($result['requires_approval'])->toBeFalse();

    // One paisa over must require approval.
    $quotation2 = discountQuotation(['subtotal' => 100000]);
    $result2 = app(LeadWorkspaceService::class)->setQuotationDiscount($quotation2, 'fixed', 20000.01, $admin);
    expect($result2['requires_approval'])->toBeTrue();
});

/* ──────────────────────────────────────────────── edge cases ───────────── */

it('treats a zero discount as never requiring approval', function () {
    $employee = employeeUser();
    $quotation = discountQuotation();

    $result = app(LeadWorkspaceService::class)->setQuotationDiscount($quotation, 'percentage', 0, $employee);

    expect($result['requires_approval'])->toBeFalse()
        ->and((float) $quotation->fresh()->discount)->toBe(0.0);
});

it('refuses a fixed discount greater than the subtotal', function () {
    $admin = adminTierUser();
    $quotation = discountQuotation(['subtotal' => 100000]);

    expect(fn () => app(LeadWorkspaceService::class)->setQuotationDiscount($quotation, 'fixed', 150000, $admin))
        ->toThrow(InvalidArgumentException::class, 'cannot exceed the quotation subtotal');
});

it('refuses a percentage discount greater than 100', function () {
    $admin = adminTierUser();
    $quotation = discountQuotation();

    expect(fn () => app(LeadWorkspaceService::class)->setQuotationDiscount($quotation, 'percentage', 150, $admin))
        ->toThrow(InvalidArgumentException::class, 'cannot exceed 100%');
});

it('clamps a negative discount to zero rather than increasing the total', function () {
    $admin = adminTierUser();
    $quotation = discountQuotation(['subtotal' => 100000]);

    app(LeadWorkspaceService::class)->setQuotationDiscount($quotation, 'fixed', -5000, $admin);

    expect((float) $quotation->fresh()->discount)->toBe(0.0);
});

it('rejects an invalid discount type', function () {
    $admin = adminTierUser();
    $quotation = discountQuotation();

    expect(fn () => app(LeadWorkspaceService::class)->setQuotationDiscount($quotation, 'not-a-type', 10, $admin))
        ->toThrow(InvalidArgumentException::class);
});

/* ──────────────────────────────────────── GST recalculation ────────────── */

it('recalculates GST from the discounted taxable amount, not the pre-discount subtotal', function () {
    $admin = adminTierUser();
    $quotation = discountQuotation(['subtotal' => 200000]);

    app(LeadWorkspaceService::class)->setQuotationDiscount($quotation, 'percentage', 25, $admin);

    // Taxable 200000 * 0.75 = 150000; 18% GST = 27000; grand total = 177000.
    expect((float) $quotation->fresh()->taxable_amount)->toBe(150000.0)
        ->and((float) $quotation->fresh()->tax)->toBe(27000.0)
        ->and((float) $quotation->fresh()->grand_total)->toBe(177000.0);
});

/* ───────────────────────────────────── authorization bypass attempts ───── */

it('does not let an employee bypass approval by calling the backend endpoint directly', function () {
    config(['crm.quotation_approval_discount_percent' => 5]);
    $employee = employeeUser();
    $quotation = discountQuotation(['organization_id' => $employee->resolveOrganizationId()]);

    $this->actingAs($employee)
        ->put(route('company.crm.quotations.discount', $quotation), [
            'discount_type' => 'percentage',
            'discount_value' => 25,
        ])
        ->assertSessionHasNoErrors();

    // Setting the discount is allowed (pricing is authoring work) — but
    // sending it is not, regardless of what the UI would or wouldn't show.
    $this->actingAs($employee)
        ->post(route('company.crm.quotations.send', $quotation->fresh()))
        ->assertSessionHasErrors('quotation');

    expect($quotation->fresh()->status)->toBe(QuotationStatus::Draft);
});

it('does not let an employee approve their own over-threshold discount', function () {
    config(['crm.quotation_approval_discount_percent' => 5]);
    $employee = employeeUser();
    $quotation = discountQuotation(['status' => QuotationStatus::Sent, 'organization_id' => $employee->resolveOrganizationId()]);

    app(LeadWorkspaceService::class)->setQuotationDiscount($quotation, 'percentage', 25, $employee);

    $this->actingAs($employee)
        ->post(route('company.crm.quotations.approve', $quotation->fresh()))
        ->assertForbidden();
});

it('still requires approval for an over-threshold employee discount even after an admin later views it', function () {
    /*
     * The rule is evaluated once, by who set the discount — not re-derived
     * from whoever happens to call send() later. An admin merely *viewing*
     * the quotation must not silently relax a decision an employee made.
     */
    config(['crm.quotation_approval_discount_percent' => 5]);
    $employee = employeeUser();
    $admin = adminTierUser($employee->resolveOrganizationId());
    $quotation = discountQuotation(['organization_id' => $employee->resolveOrganizationId()]);

    app(LeadWorkspaceService::class)->setQuotationDiscount($quotation, 'percentage', 15, $employee);

    expect(app(QuotationApprovalService::class)->canBeSent($quotation->fresh()))->toBeFalse();

    $this->actingAs($admin)
        ->post(route('company.crm.quotations.send', $quotation->fresh()))
        ->assertSessionHasErrors('quotation');
});

/* ──────────────────────────────────────────────── tenant isolation ─────── */

it('enforces tenant isolation on the discount endpoint', function () {
    $otherOrg = Organization::factory()->create();
    $employee = grantCrmPermissions(User::factory()->create([
        'user_type' => UserType::CompanyUser,
        'organization_id' => $otherOrg->id,
    ]), ['view-crm', 'view-quotations', 'view-leads', 'create leads', 'edit own leads', 'manage-quotations']);

    $foreignQuotation = discountQuotation();
    expect($foreignQuotation->organization_id)->not->toBe($otherOrg->id);

    $this->actingAs($employee)
        ->put(route('company.crm.quotations.discount', $foreignQuotation), [
            'discount_type' => 'percentage',
            'discount_value' => 10,
        ])
        ->assertForbidden();
});

it('keeps one tenant discount request from leaking approval requirements into another', function () {
    config(['crm.quotation_approval_discount_percent' => 5]);
    $employeeA = employeeUser();
    $employeeB = employeeUser();

    $quotationA = discountQuotation(['subtotal' => 100000]);
    $quotationB = discountQuotation(['subtotal' => 100000]);

    app(LeadWorkspaceService::class)->setQuotationDiscount($quotationA, 'percentage', 20, $employeeA);
    app(LeadWorkspaceService::class)->setQuotationDiscount($quotationB, 'percentage', 2, $employeeB);

    expect(app(QuotationApprovalService::class)->canBeSent($quotationA->fresh()))->toBeFalse()
        ->and(app(QuotationApprovalService::class)->canBeSent($quotationB->fresh()))->toBeTrue();
});
