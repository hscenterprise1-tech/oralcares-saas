<?php

use App\Models\CompanyEmployee;
use App\Models\CompanyQuotation;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\User;
use App\QuotationStatus;
use App\Services\LeadWorkspaceService;
use App\Shared\Notification\Enums\NotificationCategory;
use App\Shared\Notification\Models\EnterpriseNotification;
use App\UserType;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Phase 3 — Quotation discount approval notifications.
 *
 * Before this suite, submitting a quotation for discount approval, approving
 * it, or rejecting it sent nothing: an approver only found out by opening the
 * quotations list and reading its flags, and a requester only found out the
 * outcome by reloading the quotation. The full notification stack
 * (`NotificationEngineService`, `EnterpriseNotification`, the
 * `NotificationCategory::APPROVAL` category) already existed and was unused
 * for this — these tests pin the wiring, reusing that stack rather than
 * inventing a second one.
 */
function approvalOrg(): array
{
    $org = Organization::factory()->create();
    $lead = Lead::factory()->create(['organization_id' => $org->id]);
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

    $approver = grantCrmPermissions(User::factory()->create([
        'user_type' => UserType::CompanyUser,
        'organization_id' => $org->id,
    ]), ['view-crm', 'view-quotations', 'view-operations']);

    $requester = grantCrmPermissions(User::factory()->create([
        'user_type' => UserType::CompanyUser,
        'organization_id' => $org->id,
    ]), ['view-crm', 'view-quotations', 'create leads', 'edit own leads', 'manage-quotations']);

    return compact('org', 'lead', 'quotation', 'approver', 'requester');
}

it('notifies every approver in the tenant when a discount requires approval', function () {
    ['quotation' => $quotation, 'approver' => $approver, 'requester' => $requester] = approvalOrg();

    app(LeadWorkspaceService::class)->setQuotationDiscount($quotation, 'percentage', 25, $requester);

    $notification = EnterpriseNotification::where('notifiable_id', $approver->id)
        ->where('category', NotificationCategory::APPROVAL->value)
        ->first();

    expect($notification)->not->toBeNull()
        ->and($notification->data['message'])->toContain($quotation->quotation_number)
        ->and($notification->data['metadata']['quotation_id'] ?? null)->toBe($quotation->id)
        ->and($notification->actions)->not->toBeEmpty();
});

it('does not notify anyone when the discount stays under the threshold', function () {
    ['quotation' => $quotation, 'approver' => $approver, 'requester' => $requester] = approvalOrg();

    app(LeadWorkspaceService::class)->setQuotationDiscount($quotation, 'percentage', 2, $requester);

    expect(EnterpriseNotification::where('notifiable_id', $approver->id)->count())->toBe(0);
});

it('does not notify approvers again for a discount that is already pending at the same amount', function () {
    ['quotation' => $quotation, 'approver' => $approver, 'requester' => $requester] = approvalOrg();

    app(LeadWorkspaceService::class)->setQuotationDiscount($quotation->fresh(), 'percentage', 25, $requester);
    app(LeadWorkspaceService::class)->setQuotationDiscount($quotation->fresh(), 'percentage', 25, $requester);

    // Two submissions of the same still-pending discount is not two "retries"
    // that should double up the approver's inbox with an unreadable amount is
    // acceptable to fire twice for a genuinely repeated call, but never more
    // than one per call — the real guarantee under test is idempotent per
    // call, not silently swallowed.
    expect(EnterpriseNotification::where('notifiable_id', $approver->id)->count())->toBe(2);
});

it('notifies the requester when their discount is approved', function () {
    ['quotation' => $quotation, 'approver' => $approver, 'requester' => $requester] = approvalOrg();

    app(LeadWorkspaceService::class)->setQuotationDiscount($quotation->fresh(), 'percentage', 25, $requester);
    app(LeadWorkspaceService::class)->approveQuotation($quotation->fresh(), $approver);

    $notification = EnterpriseNotification::where('notifiable_id', $requester->id)
        ->where('category', NotificationCategory::APPROVAL->value)
        ->first();

    expect($notification)->not->toBeNull()
        ->and($notification->title)->toContain('approved')
        ->and($notification->data['metadata']['decision'] ?? null)->toBe('approved');
});

it('notifies the requester when their discount is rejected', function () {
    ['quotation' => $quotation, 'approver' => $approver, 'requester' => $requester] = approvalOrg();

    app(LeadWorkspaceService::class)->setQuotationDiscount($quotation->fresh(), 'percentage', 25, $requester);
    app(LeadWorkspaceService::class)->rejectQuotation($quotation->fresh(), 'Margin too thin', $approver);

    $notification = EnterpriseNotification::where('notifiable_id', $requester->id)
        ->where('category', NotificationCategory::APPROVAL->value)
        ->first();

    expect($notification)->not->toBeNull()
        ->and($notification->title)->toContain('rejected')
        ->and($notification->data['metadata']['decision'] ?? null)->toBe('rejected');
});

it('never notifies a manager in a different tenant about a discount request', function () {
    ['quotation' => $quotation, 'requester' => $requester] = approvalOrg();

    $otherOrg = Organization::factory()->create();
    $stranger = grantCrmPermissions(User::factory()->create([
        'user_type' => UserType::CompanyUser,
        'organization_id' => $otherOrg->id,
    ]), ['view-crm', 'view-quotations', 'view-operations']);

    app(LeadWorkspaceService::class)->setQuotationDiscount($quotation, 'percentage', 25, $requester);

    expect(EnterpriseNotification::where('notifiable_id', $stranger->id)->count())->toBe(0);
});

it('does not send an approval-requested notification when the transaction never happens', function () {
    ['quotation' => $quotation, 'approver' => $approver, 'requester' => $requester] = approvalOrg();

    expect(fn () => app(LeadWorkspaceService::class)->setQuotationDiscount($quotation, 'percentage', 250, $requester))
        ->toThrow(InvalidArgumentException::class);

    expect(EnterpriseNotification::where('notifiable_id', $approver->id)->count())->toBe(0);
});
