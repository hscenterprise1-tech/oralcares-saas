<?php

use App\BillingCycle;
use App\LeadStatus;
use App\Models\CompanyQuotation;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\User;
use App\QuotationStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Step 4 — billing cycles, quotation versioning, and the strict Won gate.
 *
 * The Won gate itself is not new: `DealStageService::requirementMet('won', ...)`
 * already refused the move without an approved quotation settled in full,
 * on both `leads.transition` and `leads.convert`. What these tests hold is
 * the sharper, more specific contract this step adds on top of it — an exact
 * rejection message on the transition path, and the two commercial facts
 * (billing tenure, version supersession) the gate's evidence is built from.
 */
beforeEach(function () {
    $this->org = Organization::factory()->create();
    $this->admin = User::factory()->masterAdmin()->create(['organization_id' => $this->org->id]);
});

// ─────────────────────────────────────────────────────────────────────────────
// Task 1 — billing cycles
// ─────────────────────────────────────────────────────────────────────────────

it('normalises grand_total to an annual figure for every billing cycle', function () {
    $cases = [
        [BillingCycle::Monthly, 4000, 48000.0],
        [BillingCycle::Quarterly, 12000, 48000.0],
        [BillingCycle::HalfYearly, 24000, 48000.0],
        [BillingCycle::Annual, 48000, 48000.0],
    ];

    foreach ($cases as [$cycle, $amount, $expectedArr]) {
        $quotation = new CompanyQuotation(['grand_total' => $amount, 'billing_cycle' => $cycle->value]);

        expect($quotation->normalizedArr())->toBe($expectedArr);
    }
});

it('creates a quotation at the chosen billing tenure and defaults to monthly', function () {
    $lead = Lead::factory()->create(['organization_id' => $this->org->id]);

    $this->actingAs($this->admin)
        ->post(route('company.crm.leads.create-quotation', $lead), [
            'billing_cycle' => 'quarterly',
            'amount' => 12000,
        ])
        ->assertSessionHasNoErrors();

    $quotation = CompanyQuotation::where('lead_id', $lead->id)->firstOrFail();

    // 18% GST on 12000: grand total = 14160.00; annualised (×4 for quarterly) = 56640.00.
    expect($quotation->billing_cycle)->toBe(BillingCycle::Quarterly)
        ->and((float) $quotation->grand_total)->toBe(14160.0)
        ->and($quotation->normalizedArr())->toBe(56640.0);

    $default = Lead::factory()->create(['organization_id' => $this->org->id]);

    $this->actingAs($this->admin)->post(route('company.crm.leads.create-quotation', $default), [
        'amount' => 5000,
    ]);

    expect(CompanyQuotation::where('lead_id', $default->id)->firstOrFail()->billing_cycle)
        ->toBe(BillingCycle::Monthly);
});

// ─────────────────────────────────────────────────────────────────────────────
// Task 2 — versioning
// ─────────────────────────────────────────────────────────────────────────────

it('revising a quotation supersedes the predecessor and creates v2', function () {
    $lead = Lead::factory()->create(['organization_id' => $this->org->id]);

    $this->actingAs($this->admin)->post(route('company.crm.leads.create-quotation', $lead), [
        'billing_cycle' => 'monthly',
        'amount' => 4000,
    ]);

    $v1 = CompanyQuotation::where('lead_id', $lead->id)->firstOrFail();

    expect($v1->version)->toBe(1)
        ->and($v1->status)->toBe(QuotationStatus::Draft);

    $this->actingAs($this->admin)
        ->post(route('company.crm.quotations.revise', $v1), [
            'billing_cycle' => 'annual',
            'items' => [[
                'title' => 'Enterprise Plan — Annual',
                'quantity' => 1,
                'unit_price' => 45000,
                'discount' => 0,
            ]],
        ])
        ->assertSessionHasNoErrors();

    $v1->refresh();
    $v2 = CompanyQuotation::where('lead_id', $lead->id)->where('version', 2)->firstOrFail();

    expect($v1->status)->toBe(QuotationStatus::Superseded)
        ->and($v1->isActive())->toBeFalse()
        ->and($v2->status)->toBe(QuotationStatus::Draft)
        ->and($v2->isActive())->toBeTrue()
        ->and($v2->parent_id)->toBe($v1->id)
        ->and($v2->billing_cycle)->toBe(BillingCycle::Annual)
        // 18% GST on 45000: grand total = 53100.00.
        ->and((float) $v2->grand_total)->toBe(53100.0);

    /*
     * Only one active quotation per deal — not "the newest one happens to be
     * the only one still Draft", but the predecessor genuinely retired.
     */
    expect(CompanyQuotation::where('lead_id', $lead->id)->get()->filter->isActive())->toHaveCount(1);
});

it('does not let a rejected or expired predecessor be relabelled superseded', function () {
    $lead = Lead::factory()->create(['organization_id' => $this->org->id]);
    $rejected = CompanyQuotation::factory()->create([
        'lead_id' => $lead->id,
        'quotation_number' => 'QT-KEEP-1',
        'status' => QuotationStatus::Rejected,
        'grand_total' => 4000,
    ]);

    $this->actingAs($this->admin)->post(route('company.crm.quotations.revise', $rejected), [
        'items' => [['title' => 'Retry', 'quantity' => 1, 'unit_price' => 4000, 'discount' => 0]],
    ]);

    // The specific reason it stopped being active is more informative than a
    // generic "superseded" — revising it must not overwrite that.
    expect($rejected->fresh()->status)->toBe(QuotationStatus::Rejected);
});

// ─────────────────────────────────────────────────────────────────────────────
// Task 3 — strict Won gate
// ─────────────────────────────────────────────────────────────────────────────

/*
 * `ValidationException` renders as HTTP 422 for the real request Inertia's
 * client actually sends (`X-Requested-With: XMLHttpRequest`, which every
 * Inertia visit carries) — a plain test `post()` gets Laravel's non-XHR
 * fallback, a 302 with the same message flashed to the session, which is the
 * convention `DealCommercialLifecycleTest` already asserts this exact gate
 * with. Both are the same `ValidationException`; this exercises it the way
 * this suite does everywhere else and checks the response headers agree
 * with what a real browser request gets.
 */
it('refuses to mark a deal Won with a validation error when no payment is reconciled, as a 422 for a real client', function () {
    $lead = Lead::factory()->create([
        'organization_id' => $this->org->id,
        'status' => LeadStatus::Negotiation,
    ]);

    $this->actingAs($this->admin)
        ->post(route('company.crm.leads.transition', $lead), ['stage' => 'won'])
        ->assertSessionHasErrors(['stage' => 'Cannot mark deal as Won without reconciled 100% advance payment.']);

    expect($lead->fresh()->status)->not->toBe(LeadStatus::Won);

    $this->actingAs($this->admin)
        ->postJson(route('company.crm.leads.transition', $lead), ['stage' => 'won'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['stage' => 'Cannot mark deal as Won without reconciled 100% advance payment.']);
});

it('permits Won once a reconciled advance payment settles the approved quotation, and the deal locks', function () {
    $lead = Lead::factory()->create([
        'organization_id' => $this->org->id,
        'status' => LeadStatus::Negotiation,
    ]);

    $quotation = CompanyQuotation::factory()->create([
        'lead_id' => $lead->id,
        'status' => QuotationStatus::Approved,
        'subtotal' => 50000,
        'discount' => 0,
        'tax' => 0,
        'grand_total' => 50000,
    ]);

    // Record the advance payment — the Record Advance Payment modal's route,
    // always `successful`: a rep opening it is asserting the money arrived.
    $this->actingAs($this->admin)
        ->post(route('company.crm.quotations.payments.store', $quotation), [
            'amount' => 50000,
            'gateway' => 'bank_transfer',
            'payment_method' => 'bank_transfer',
            'transaction_id' => 'UTR900000001',
            'payment_date' => now()->toDateString(),
            'status' => 'successful',
        ])
        ->assertSessionHasNoErrors();

    expect(Payment::where('company_quotation_id', $quotation->id)->where('status', 'successful')->exists())->toBeTrue();
    expect($lead->fresh()->hasApprovedPaidQuotation())->toBeTrue();

    $this->actingAs($this->admin)
        ->post(route('company.crm.leads.transition', $lead), ['stage' => 'won'])
        ->assertSessionHasNoErrors();

    $lead->refresh();
    expect($lead->status)->toBe(LeadStatus::Won);

    /*
     * Locked permanently — the state machine refuses every edge out of Won,
     * for any target, including back to where it just came from.
     */
    $this->actingAs($this->admin)
        ->post(route('company.crm.leads.transition', $lead), ['stage' => 'negotiation'])
        ->assertSessionHasErrors();

    expect($lead->fresh()->status)->toBe(LeadStatus::Won);
});
