<?php

use App\Models\CompanyEmployee;
use App\Models\CompanyQuotation;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\User;
use App\QuotationStatus;
use App\Services\QuotationApprovalService;
use App\UserType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * Slice C — discount approval enforcement.
 *
 * `crm.quotation_approval_discount_percent` has existed since Slice A but
 * nothing read it, so a rep could discount without limit. The gate blocks the
 * *send*, not the draft, and approval is a management authority separate from
 * the ability to edit.
 */
beforeEach(function () {
    setPermissionsTeamId(0);
    config(['crm.quotation_approval_discount_percent' => 20]);

    Role::firstOrCreate(['name' => 'CRM Manager', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'Sales Representative', 'guard_name' => 'web']);
    grantCrmManagerPermissionsToRole(Role::findByName('CRM Manager', 'web'));
    grantCrmPermissionsToRole(Role::findByName('Sales Representative', 'web'));

    $this->org = Organization::factory()->create();

    $this->manager = User::factory()->create(['user_type' => UserType::CompanyUser, 'organization_id' => $this->org->id]);
    $this->manager->assignRole('CRM Manager');

    $this->rep = User::factory()->create(['user_type' => UserType::CompanyUser, 'organization_id' => $this->org->id]);
    $this->rep->assignRole('Sales Representative');

    $this->lead = Lead::factory()->create([
        'organization_id' => $this->org->id,
        'assigned_user_id' => $this->rep->id,
    ]);

    $this->employee = CompanyEmployee::create([
        'organization_id' => $this->org->id,
        'user_id' => $this->rep->id,
        'employee_code' => 'EMP-APP1',
        'employment_status' => 'active',
    ]);
});

function quotationDiscounted(float $subtotal, float $discount, array $attributes = []): CompanyQuotation
{
    return CompanyQuotation::create(array_merge([
        'organization_id' => test()->org->id,
        'lead_id' => test()->lead->id,
        'prepared_by_id' => test()->employee->id,
        'quotation_number' => 'QT-'.fake()->unique()->numberBetween(10000, 99999),
        'version' => 1,
        'status' => QuotationStatus::Draft,
        'subtotal' => $subtotal,
        'discount' => $discount,
        'tax' => 0,
        'grand_total' => $subtotal - $discount,
    ], $attributes));
}

it('computes the discount percentage against the subtotal', function () {
    $quotation = quotationDiscounted(100000, 15000);

    expect(app(QuotationApprovalService::class)->discountPercent($quotation))->toBe(15.0);
});

it('reports zero percent rather than dividing by a zero subtotal', function () {
    $quotation = quotationDiscounted(0, 0);

    expect(app(QuotationApprovalService::class)->discountPercent($quotation))->toBe(0.0);
});

it('allows a send below the threshold', function () {
    $quotation = quotationDiscounted(100000, 10000);

    $this->actingAs($this->rep)
        ->post(route('company.crm.quotations.send', $quotation))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($quotation->fresh()->status)->toBe(QuotationStatus::Sent);
});

it('blocks a send exactly at the threshold', function () {
    // The threshold names the exception, so a discount *at* it is exceptional.
    $quotation = quotationDiscounted(100000, 20000);

    $this->actingAs($this->rep)
        ->post(route('company.crm.quotations.send', $quotation))
        ->assertSessionHasErrors('quotation');

    expect($quotation->fresh()->status)->toBe(QuotationStatus::Draft);
});

it('blocks a send above the threshold', function () {
    $quotation = quotationDiscounted(100000, 35000);

    $this->actingAs($this->rep)
        ->post(route('company.crm.quotations.send', $quotation))
        ->assertSessionHasErrors('quotation');

    expect($quotation->fresh()->status)->toBe(QuotationStatus::Draft);
    expect($quotation->fresh()->sent_at)->toBeNull();
});

it('denies approval to a sales representative', function () {
    $quotation = quotationDiscounted(100000, 35000);

    $this->actingAs($this->rep)
        ->post(route('company.crm.quotations.approve', $quotation))
        ->assertForbidden();

    expect($quotation->fresh()->approved_at)->toBeNull();
});

it('allows a manager to approve, unblocking the send', function () {
    $quotation = quotationDiscounted(100000, 35000);

    $this->actingAs($this->manager)
        ->post(route('company.crm.quotations.approve', $quotation), ['notes' => 'Strategic account.'])
        ->assertRedirect();

    expect($quotation->fresh()->approved_at)->not->toBeNull();

    $this->actingAs($this->rep)
        ->post(route('company.crm.quotations.send', $quotation->fresh()))
        ->assertSessionHasNoErrors();

    expect($quotation->fresh()->status)->toBe(QuotationStatus::Sent);
});

it('keeps a rejected quotation blocked', function () {
    $quotation = quotationDiscounted(100000, 35000);

    $this->actingAs($this->manager)
        ->post(route('company.crm.quotations.reject', $quotation), ['reason' => 'Margin too thin.'])
        ->assertRedirect();

    expect($quotation->fresh()->status)->toBe(QuotationStatus::Rejected);

    $this->actingAs($this->rep)
        ->post(route('company.crm.quotations.send', $quotation->fresh()))
        ->assertSessionHasErrors('quotation');
});

it('denies rejection to a sales representative', function () {
    $quotation = quotationDiscounted(100000, 35000);

    $this->actingAs($this->rep)
        ->post(route('company.crm.quotations.reject', $quotation), ['reason' => 'nope'])
        ->assertForbidden();
});

it('requires fresh approval for a revision that crosses the threshold', function () {
    $original = quotationDiscounted(100000, 5000);

    $this->actingAs($this->manager)
        ->post(route('company.crm.quotations.approve', $original))
        ->assertRedirect();

    $this->actingAs($this->rep)
        ->post(route('company.crm.quotations.revise', $original->fresh()), [
            'items' => [[
                'title' => 'Discounted Suite',
                'quantity' => 1,
                'unit_price' => 100000,
                'discount' => 40000,
            ]],
        ])
        ->assertRedirect();

    $revision = CompanyQuotation::where('quotation_number', $original->quotation_number)
        ->where('version', 2)
        ->firstOrFail();

    // The earlier approval belonged to v1 and must not carry forward.
    expect($revision->approved_at)->toBeNull()
        ->and(app(QuotationApprovalService::class)->requiresApproval($revision))->toBeTrue();

    $this->actingAs($this->rep)
        ->post(route('company.crm.quotations.send', $revision))
        ->assertSessionHasErrors('quotation');
});

it('disables the gate entirely when no threshold is configured', function () {
    config(['crm.quotation_approval_discount_percent' => null]);

    $quotation = quotationDiscounted(100000, 90000);
    $approvals = app(QuotationApprovalService::class);

    expect($approvals->threshold())->toBeNull()
        ->and($approvals->requiresApproval($quotation))->toBeFalse();

    $this->actingAs($this->rep)
        ->post(route('company.crm.quotations.send', $quotation))
        ->assertSessionHasNoErrors();
});

it('exposes approval state on the quotation detail page', function () {
    $quotation = quotationDiscounted(100000, 35000);

    $this->actingAs($this->rep)
        ->get(route('company.crm.quotations.show', $quotation))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->where('approval.requires_approval', true)
            ->where('approval.can_be_sent', false)
            ->where('approval.can_approve', false)
            ->where('approval.discount_percent', fn ($v) => (float) $v === 35.0)
            ->where('approval.threshold_percent', fn ($v) => (float) $v === 20.0)
        );

    $this->actingAs($this->manager)
        ->get(route('company.crm.quotations.show', $quotation))
        ->assertInertia(fn ($page) => $page->where('approval.can_approve', true));
});
