<?php

use App\Models\CompanyEmployee;
use App\Models\CompanyQuotation;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\User;
use App\QuotationStatus;
use App\Services\CRM\QuotationStateMachine;
use App\Services\LeadWorkspaceService;
use App\UserType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * Phase 2 — quotation state-machine guards.
 *
 * Before this, `approveQuotation()`, `rejectQuotation()` and `sendQuotation()`
 * wrote their target status unconditionally, with no check on where the
 * quotation currently stood — an approved, rejected, expired, superseded or
 * converted quotation could be re-approved, re-rejected or (re)sent with
 * nothing to stop it. `QuotationStateMachine::blockReason()` is the one place
 * that question is now answered, and every action asks it the same way.
 */
function stateMachineQuotation(QuotationStatus $status, array $attributes = []): CompanyQuotation
{
    $org = Organization::factory()->create();
    $lead = Lead::factory()->create(['organization_id' => $org->id]);
    $employee = CompanyEmployee::factory()->create();

    return CompanyQuotation::factory()->create(array_merge([
        'organization_id' => $org->id,
        'lead_id' => $lead->id,
        'prepared_by_id' => $employee->id,
        'status' => $status,
        'subtotal' => 10000,
        'discount' => 0,
        'tax' => 0,
        'grand_total' => 10000,
    ], $attributes));
}

/* ───────────────────────────────────────────────── send ────────────────── */

it('permits send only from draft, or from approved with no sent_at yet', function () {
    $machine = app(QuotationStateMachine::class);

    expect($machine->blockReason(stateMachineQuotation(QuotationStatus::Draft), 'send'))->toBeNull()
        ->and($machine->blockReason(stateMachineQuotation(QuotationStatus::Approved, ['sent_at' => null]), 'send'))->toBeNull();
});

it('blocks send from every status a discount pre-approval or a real send has already moved past', function (QuotationStatus $status) {
    $quotation = stateMachineQuotation($status, ['sent_at' => $status === QuotationStatus::Approved ? now() : null]);

    expect(app(QuotationStateMachine::class)->blockReason($quotation, 'send'))->not->toBeNull();
})->with([
    QuotationStatus::Sent,
    QuotationStatus::Negotiation,
    QuotationStatus::Approved,
    QuotationStatus::Rejected,
    QuotationStatus::Expired,
    QuotationStatus::Superseded,
    QuotationStatus::Converted,
]);

it('refuses to send through the service with a clear reason', function () {
    $quotation = stateMachineQuotation(QuotationStatus::Rejected);

    expect(fn () => app(LeadWorkspaceService::class)->sendQuotation($quotation))
        ->toThrow(InvalidArgumentException::class, 'rejected');
});

/* ─────────────────────────────────────────────── approve ───────────────── */

it('permits approve from draft (discount pre-approval), sent and negotiation', function (QuotationStatus $status) {
    expect(app(QuotationStateMachine::class)->blockReason(stateMachineQuotation($status), 'approve'))->toBeNull();
})->with([QuotationStatus::Draft, QuotationStatus::Sent, QuotationStatus::Negotiation]);

it('blocks approve on an already-decided, retired or won quotation', function (QuotationStatus $status) {
    expect(app(QuotationStateMachine::class)->blockReason(stateMachineQuotation($status), 'approve'))->not->toBeNull();
})->with([
    QuotationStatus::Approved,
    QuotationStatus::Rejected,
    QuotationStatus::Expired,
    QuotationStatus::Superseded,
    QuotationStatus::Converted,
]);

it('refuses to approve an already-rejected quotation through the service', function () {
    $quotation = stateMachineQuotation(QuotationStatus::Rejected);

    expect(fn () => app(LeadWorkspaceService::class)->approveQuotation($quotation))
        ->toThrow(InvalidArgumentException::class, 'rejected');

    expect($quotation->fresh()->status)->toBe(QuotationStatus::Rejected);
});

/* ──────────────────────────────────────────────── reject ───────────────── */

it('permits reject from draft, sent and negotiation', function (QuotationStatus $status) {
    expect(app(QuotationStateMachine::class)->blockReason(stateMachineQuotation($status), 'reject'))->toBeNull();
})->with([QuotationStatus::Draft, QuotationStatus::Sent, QuotationStatus::Negotiation]);

it('blocks reject on an already-converted, approved or terminal quotation', function (QuotationStatus $status) {
    expect(app(QuotationStateMachine::class)->blockReason(stateMachineQuotation($status), 'reject'))->not->toBeNull();
})->with([
    QuotationStatus::Approved,
    QuotationStatus::Rejected,
    QuotationStatus::Expired,
    QuotationStatus::Superseded,
    QuotationStatus::Converted,
]);

it('refuses to reject an already-approved quotation through the service', function () {
    $quotation = stateMachineQuotation(QuotationStatus::Approved);

    expect(fn () => app(LeadWorkspaceService::class)->rejectQuotation($quotation, 'Changed my mind'))
        ->toThrow(InvalidArgumentException::class, 'already approved');

    expect($quotation->fresh()->status)->toBe(QuotationStatus::Approved);
});

/* ──────────────────────────────────────────────── revise ───────────────── */

it('permits revising every status except one already superseded or converted', function (QuotationStatus $status) {
    expect(app(QuotationStateMachine::class)->blockReason(stateMachineQuotation($status), 'revise'))->toBeNull();
})->with([
    QuotationStatus::Draft,
    QuotationStatus::Sent,
    QuotationStatus::Negotiation,
    QuotationStatus::Approved,
    QuotationStatus::Rejected,
    QuotationStatus::Expired,
]);

it('blocks converting an invalid (already superseded or converted) quotation', function (QuotationStatus $status) {
    expect(app(QuotationStateMachine::class)->blockReason(stateMachineQuotation($status), 'revise'))->not->toBeNull();
})->with([QuotationStatus::Superseded, QuotationStatus::Converted]);

it('refuses to revise an already-superseded quotation through the service', function () {
    $quotation = stateMachineQuotation(QuotationStatus::Superseded);

    expect(fn () => app(LeadWorkspaceService::class)->reviseQuotation($quotation, [
        ['title' => 'Retry', 'quantity' => 1, 'unit_price' => 1000, 'discount' => 0],
    ]))->toThrow(InvalidArgumentException::class, 'superseded');
});

/* ────────────────────────────────────────── terminal-state protection ──── */

it('blocks every guarded action once a quotation has converted and won the deal', function (string $action) {
    $quotation = stateMachineQuotation(QuotationStatus::Converted);

    expect(app(QuotationStateMachine::class)->blockReason($quotation, $action))
        ->toContain('converted and won the deal');
})->with(['send', 'approve', 'reject', 'revise']);

/* ──────────────────────────────────────────── HTTP / authorization ─────── */

beforeEach(function () {
    setPermissionsTeamId(0);
    Role::firstOrCreate(['name' => 'CRM Manager', 'guard_name' => 'web']);
    grantCrmManagerPermissionsToRole(Role::findByName('CRM Manager', 'web'));

    $this->org = Organization::factory()->create();
    $this->manager = User::factory()->create(['user_type' => UserType::CompanyUser, 'organization_id' => $this->org->id]);
    $this->manager->assignRole('CRM Manager');
});

it('returns a field error rather than a 500 when approving an invalid quotation over HTTP', function () {
    $lead = Lead::factory()->create(['organization_id' => $this->org->id]);
    $employee = CompanyEmployee::factory()->create();

    $quotation = CompanyQuotation::factory()->create([
        'organization_id' => $this->org->id,
        'lead_id' => $lead->id,
        'prepared_by_id' => $employee->id,
        'status' => QuotationStatus::Rejected,
    ]);

    $this->actingAs($this->manager)
        ->post(route('company.crm.quotations.approve', $quotation))
        ->assertSessionHasErrors('quotation');

    expect($quotation->fresh()->status)->toBe(QuotationStatus::Rejected);
});

it('enforces tenant isolation before the state machine ever runs', function () {
    $otherOrg = Organization::factory()->create();
    $otherLead = Lead::factory()->create(['organization_id' => $otherOrg->id]);
    $otherEmployee = CompanyEmployee::factory()->create();

    $foreignQuotation = CompanyQuotation::factory()->create([
        'organization_id' => $otherOrg->id,
        'lead_id' => $otherLead->id,
        'prepared_by_id' => $otherEmployee->id,
        'status' => QuotationStatus::Sent,
    ]);

    $this->actingAs($this->manager)
        ->post(route('company.crm.quotations.approve', $foreignQuotation))
        ->assertForbidden();

    expect($foreignQuotation->fresh()->status)->toBe(QuotationStatus::Sent);
});
