<?php

use App\Models\CompanyEmployee;
use App\Models\CompanyQuotation;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\Trial;
use App\Models\User;
use App\QuotationStatus;
use App\Services\CRM\TrialExtensionApprovalService;
use App\Services\LeadWorkspaceService;
use App\Services\MyWorkspaceService;
use App\UserType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * CRM completion pass — My Work's Approvals tab covered only trial extension
 * requests. A manager whose only approval authority was over quotation
 * discounts had no queue at all: the notification built alongside it
 * (QuotationApprovalNotificationTest) was their sole signal, and acting on it
 * meant leaving My Work to find the quotation. This suite pins the queue
 * itself, reusing the exact fixture shape MyWorkExecutionTest already
 * established for the trial-extension side of the same tab.
 */
beforeEach(function () {
    setPermissionsTeamId(0);
    Role::firstOrCreate(['name' => 'CRM Manager', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'Sales Representative', 'guard_name' => 'web']);
    grantCrmManagerPermissionsToRole(Role::findByName('CRM Manager', 'web'));
    grantCrmPermissionsToRole(Role::findByName('Sales Representative', 'web'));

    $this->org = Organization::factory()->create();

    $this->rep = User::factory()->create(['user_type' => UserType::CompanyUser, 'organization_id' => $this->org->id]);
    $this->rep->assignRole('Sales Representative');

    $this->manager = User::factory()->create(['user_type' => UserType::CompanyUser, 'organization_id' => $this->org->id]);
    $this->manager->assignRole('CRM Manager');
});

function pendingDiscountQuotation(User $rep, Organization $org): CompanyQuotation
{
    $lead = Lead::factory()->create(['organization_id' => $org->id, 'assigned_user_id' => $rep->id]);
    $employee = CompanyEmployee::factory()->create();

    $quotation = CompanyQuotation::factory()->create([
        'organization_id' => $org->id,
        'lead_id' => $lead->id,
        'prepared_by_id' => $employee->id,
        'status' => QuotationStatus::Draft,
        'subtotal' => 100000,
        'discount' => 0,
        'tax' => 0,
        'grand_total' => 100000,
    ]);

    app(LeadWorkspaceService::class)->setQuotationDiscount($quotation, 'percentage', 25, $rep);

    return $quotation->fresh();
}

it('surfaces a pending quotation discount in a manager\'s My Work approvals queue', function () {
    $quotation = pendingDiscountQuotation($this->rep, $this->org);

    $payload = app(MyWorkspaceService::class)->payload($this->manager);

    expect($payload['can_approve_extensions'])->toBeTrue()
        ->and($payload['pending_approvals']['count'])->toBe(1)
        ->and($payload['pending_approvals']['items'][0]['type'])->toBe('quotation_discount')
        ->and($payload['pending_approvals']['items'][0]['quotation_id'])->toBe($quotation->id)
        ->and($payload['pending_approvals']['items'][0]['discount'])->toBe(25000.0);
});

it('gives a rep with no approval authority an empty queue for a pending quotation discount', function () {
    pendingDiscountQuotation($this->rep, $this->org);

    $payload = app(MyWorkspaceService::class)->payload($this->rep);

    expect($payload['pending_approvals']['count'])->toBe(0);
});

it('mixes trial extension and quotation discount approvals in one queue', function () {
    pendingDiscountQuotation($this->rep, $this->org);

    $lead = Lead::factory()->create(['organization_id' => $this->org->id, 'assigned_user_id' => $this->rep->id]);
    $trial = Trial::factory()->create(['lead_id' => $lead->id, 'status' => 'active']);
    app(TrialExtensionApprovalService::class)->requestExtension($trial, $this->rep, 5, 'Needs more time.');

    $payload = app(MyWorkspaceService::class)->payload($this->manager);

    $types = collect($payload['pending_approvals']['items'])->pluck('type')->sort()->values()->all();

    expect($payload['pending_approvals']['count'])->toBe(2)
        ->and($types)->toBe(['quotation_discount', 'trial_extension']);
});

it('approves a quotation discount through My Work\'s approval route and clears the queue', function () {
    $quotation = pendingDiscountQuotation($this->rep, $this->org);

    expect(app(MyWorkspaceService::class)->payload($this->manager)['pending_approvals']['count'])->toBe(1);

    $this->actingAs($this->manager)
        ->post(route('company.crm.quotations.approve', $quotation))
        ->assertSessionHasNoErrors();

    expect(app(MyWorkspaceService::class)->payload($this->manager)['pending_approvals']['count'])->toBe(0)
        ->and($quotation->fresh()->approved_at)->not->toBeNull();
});

it('rejects a quotation discount through My Work\'s reject route with a reason and clears the queue', function () {
    $quotation = pendingDiscountQuotation($this->rep, $this->org);

    $this->actingAs($this->manager)
        ->post(route('company.crm.quotations.reject', $quotation), ['reason' => 'Margin too thin for this account.'])
        ->assertSessionHasNoErrors();

    expect(app(MyWorkspaceService::class)->payload($this->manager)['pending_approvals']['count'])->toBe(0)
        ->and($quotation->fresh()->status)->toBe(QuotationStatus::Rejected);
});

it('renders the mixed approvals queue on the My Work page', function () {
    pendingDiscountQuotation($this->rep, $this->org);

    $this->actingAs($this->manager)
        ->get(route('company.crm.my-workspace'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Company/Crm/MyWorkspace')
            ->where('pending_approvals.count', 1)
            ->where('pending_approvals.items.0.type', 'quotation_discount')
        );
});

it('keeps tenant isolation on the quotation-discount approvals queue', function () {
    pendingDiscountQuotation($this->rep, $this->org);

    $otherOrg = Organization::factory()->create();
    $stranger = User::factory()->create(['user_type' => UserType::CompanyUser, 'organization_id' => $otherOrg->id]);
    $stranger->assignRole('CRM Manager');

    expect(app(MyWorkspaceService::class)->payload($stranger)['pending_approvals']['count'])->toBe(0);
});
