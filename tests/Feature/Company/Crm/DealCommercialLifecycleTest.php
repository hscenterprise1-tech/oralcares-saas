<?php

use App\LeadStatus;
use App\Models\CommercialDocument;
use App\Models\CommercialInvoice;
use App\Models\CompanyPlan;
use App\Models\CompanyQuotation;
use App\Models\Demo;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\User;
use App\QuotationStatus;
use App\Services\CRM\DealCommercialService;
use App\Services\CRM\DealDetailService;
use App\UserType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * Quotation → Approved → Payment → Invoice → Won.
 *
 * There is no agreement step. The one that used to sit between Approved and
 * Payment described paperwork this product never drew: agreements lived in a
 * table that had never held a row outside a single historical record, and
 * money could only attach to one — so a payment against a real quotation
 * reconciled against nothing and Won could never be reached.
 *
 * Money is now recorded against the approved quotation it settles, the invoice
 * is raised from the settlement rather than drawn by hand, and Won asks for
 * exactly what Convert asks for.
 */
beforeEach(function () {
    setPermissionsTeamId(0);
    $role = Role::firstOrCreate(['name' => 'Commercial Manager', 'guard_name' => 'web']);
    grantCrmManagerPermissionsToRole($role);
    grantCrmPermissionsToRole($role, ['crm.quotations.approve', 'payments.record']);

    $this->org = Organization::factory()->create();
    $this->user = User::factory()->create([
        'user_type' => UserType::CompanyUser,
        'organization_id' => $this->org->id,
    ]);
    $this->user->assignRole($role);

    $this->plan = CompanyPlan::factory()->create();

    $this->lead = Lead::factory()->create([
        'organization_id' => $this->org->id,
        'assigned_user_id' => $this->user->id,
        'status' => LeadStatus::Negotiation,
    ]);

    $this->actingAs($this->user);
});

function quotationFor(Lead $lead, string $status = 'draft'): CompanyQuotation
{
    return CompanyQuotation::factory()->create([
        'lead_id' => $lead->id,
        'organization_id' => $lead->organization_id,
        'status' => $status,
        'approved_at' => $status === QuotationStatus::Approved->value ? now() : null,
        'grand_total' => 540000,
        'subtotal' => 540000,
        'tax' => 0,
        'discount' => 0,
    ]);
}

function payQuotation(CompanyQuotation $quotation, float $amount, string $status = 'successful'): void
{
    test()->post(route('company.crm.quotations.payments.store', $quotation), [
        'amount' => $amount,
        'gateway' => 'bank_transfer',
        'transaction_id' => 'NEFT-'.fake()->numerify('#####'),
        'status' => $status,
    ]);
}

/* ─────────────────────────── money needs an approved quotation ─────────── */

it('refuses money against a quotation nobody has approved', function () {
    $quotation = quotationFor($this->lead, QuotationStatus::Sent->value);

    payQuotation($quotation, 540000);

    expect(Payment::count())->toBe(0)
        ->and(CommercialInvoice::count())->toBe(0);
});

it('settles an approved quotation and raises its invoice from the settlement', function () {
    $quotation = quotationFor($this->lead, QuotationStatus::Approved->value);

    payQuotation($quotation, 540000);

    $payment = Payment::sole();

    expect($payment->lead_id)->toBe($this->lead->id)
        /* Keyed to the quotation, which is what makes it reconcilable. */
        ->and($payment->company_quotation_id)->toBe($quotation->id)
        ->and($payment->status)->toBe('successful')
        ->and($this->lead->hasApprovedPaidQuotation())->toBeTrue();

    $invoice = CommercialInvoice::sole();

    expect($invoice->company_quotation_id)->toBe($quotation->id)
        ->and((float) $invoice->final_amount)->toBe(540000.0)
        ->and($invoice->status)->toBe('paid');
});

it('raises no invoice while a quotation is only part paid', function () {
    $quotation = quotationFor($this->lead, QuotationStatus::Approved->value);

    payQuotation($quotation, 200000);

    expect(Payment::count())->toBe(1)
        ->and(CommercialInvoice::count())->toBe(0)
        ->and($this->lead->fresh()->hasApprovedPaidQuotation())->toBeFalse();

    /* The balance settles it, and the invoice follows the money. */
    payQuotation($quotation, 340000);

    expect(CommercialInvoice::count())->toBe(1)
        ->and($this->lead->fresh()->hasApprovedPaidQuotation())->toBeTrue();
});

/* ────────────────────────────────────── the gate on Won ────────────────── */

it('blocks Won until a quotation is approved and settled in full', function () {
    $quotation = quotationFor($this->lead, QuotationStatus::Approved->value);

    /* Approved, nothing paid. */
    $this->post(route('company.crm.leads.transition', $this->lead), ['stage' => 'won'])
        ->assertSessionHasErrors();

    expect($this->lead->fresh()->status->value)->not->toBe('won');

    /* Approved, part paid. */
    payQuotation($quotation, 200000);

    $this->post(route('company.crm.leads.transition', $this->lead), ['stage' => 'won'])
        ->assertSessionHasErrors();

    expect($this->lead->fresh()->status->value)->not->toBe('won');

    /* Settled in full. */
    payQuotation($quotation, 340000);

    $this->post(route('company.crm.leads.transition', $this->lead), ['stage' => 'won'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($this->lead->fresh()->status->value)->toBe('won');
});

it('still wins a deal closed under the old flow, on its historical agreement', function () {
    /*
     * The agreement step is gone, but the deals it closed are real. A lead
     * carrying an accepted agreement and a settled payment must keep winning,
     * or CC3 would have broken the history it was told to preserve.
     */
    $agreement = CommercialDocument::factory()->create([
        'lead_id' => $this->lead->id,
        'type' => 'agreement',
        'status' => 'accepted',
        'grand_total' => 540000,
    ]);

    Payment::factory()->create([
        'lead_id' => $this->lead->id,
        'commercial_document_id' => $agreement->id,
        'company_quotation_id' => null,
        'amount' => 540000,
        'status' => 'successful',
    ]);

    expect($this->lead->hasAcceptedAgreement())->toBeTrue()
        ->and($this->lead->hasApprovedPaidQuotation())->toBeFalse();

    $this->post(route('company.crm.leads.transition', $this->lead), ['stage' => 'won'])
        ->assertSessionHasNoErrors();

    expect($this->lead->fresh()->status->value)->toBe('won');
});

it('leaves a deal already sitting on Won exactly where it is', function () {
    /*
     * The gate is on the transition into Won, not on rows that are already
     * there. Historical deals that predate the rule keep their stage and their
     * customer link; nothing re-checks them.
     */
    $won = Lead::factory()->create([
        'organization_id' => $this->org->id,
        'status' => LeadStatus::Won,
    ]);

    $this->get(route('company.crm.leads.show', $won))->assertOk();

    expect($won->fresh()->status->value)->toBe('won')
        ->and($won->hasAcceptedAgreement())->toBeFalse();
});

/* ─────────────────────────────────────── what the page renders ─────────── */

it('reports the lifecycle as the five corrected steps', function () {
    $lifecycle = app(DealCommercialService::class)->lifecycle($this->lead);

    expect(collect($lifecycle['steps'])->pluck('key')->all())
        ->toBe(['quotation', 'approved', 'payment', 'invoice', 'won'])
        ->and(collect($lifecycle['steps'])->every(fn ($s) => $s['done'] === false))->toBeTrue()
        ->and($lifecycle['can_close'])->toBeFalse()
        ->and($lifecycle['settled_quotation_id'])->toBeNull()
        ->and($lifecycle['invoice_number'])->toBeNull();

    $quotation = quotationFor($this->lead, QuotationStatus::Approved->value);
    $lifecycle = app(DealCommercialService::class)->lifecycle($this->lead->fresh());

    expect(collect($lifecycle['steps'])->firstWhere('key', 'approved')['done'])->toBeTrue()
        ->and(collect($lifecycle['steps'])->firstWhere('key', 'invoice')['done'])->toBeFalse()
        ->and($lifecycle['can_close'])->toBeFalse();

    payQuotation($quotation, 540000);
    $lifecycle = app(DealCommercialService::class)->lifecycle($this->lead->fresh());

    expect(collect($lifecycle['steps'])->firstWhere('key', 'payment')['done'])->toBeTrue()
        ->and(collect($lifecycle['steps'])->firstWhere('key', 'invoice')['done'])->toBeTrue()
        ->and($lifecycle['settled_quotation_id'])->toBe($quotation->id)
        ->and($lifecycle['invoice_number'])->not->toBeNull()
        ->and($lifecycle['can_close'])->toBeTrue();
});

it('sends the deal page invoices rather than agreements', function () {
    $quotation = quotationFor($this->lead, QuotationStatus::Approved->value);
    payQuotation($quotation, 540000);

    $this->get(route('company.crm.leads.show', $this->lead))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Company/Crm/Leads/Show'));

    $payload = app(DealDetailService::class)->payload($this->lead->fresh());

    expect($payload)->toHaveKey('invoices')
        ->and($payload)->not->toHaveKey('agreements')
        ->and($payload['invoices']['has_invoice'])->toBeTrue()
        ->and($payload['invoices']['items'])->toHaveCount(1);

    /* And the conversion checklist asks about the quotation, not paperwork. */
    expect(collect($payload['conversion']['requirements'])->pluck('key'))
        ->toContain('quotation_settled')
        ->not->toContain('agreement');
});

it('does not reopen a closed deal when an activity on it finishes', function () {
    /*
     * Completing a demo drove the stage from its outcome unconditionally, so a
     * post-sale walkthrough on a Won deal pulled it back to Demo Completed
     * while its customer organisation stayed provisioned — the deal read as
     * mid-pipeline and as a live customer at the same time.
     */
    $this->lead->forceFill(['status' => LeadStatus::Won])->save();

    $demo = Demo::factory()->create([
        'lead_id' => $this->lead->id,
        'status' => 'scheduled',
        /* A company user: CRM records cannot be assigned to clinic users. */
        'presenter_id' => $this->user->id,
    ]);

    $this->post(route('company.crm.demos.complete', $demo), [
        'outcome' => 'quotation_requested',
    ])->assertRedirect();

    expect($this->lead->fresh()->status->value)->toBe('won')
        ->and($demo->fresh()->status->value ?? $demo->fresh()->status)->toBe('completed');
});
