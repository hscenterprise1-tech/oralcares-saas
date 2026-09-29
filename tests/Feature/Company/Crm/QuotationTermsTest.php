<?php

use App\Models\CompanyEmployee;
use App\Models\CompanyQuotation;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\User;
use App\Services\LeadWorkspaceService;
use App\UserType;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Phase 2B — structured commercial terms.
 *
 * `terms` already existed as a single free-text column; this splits the
 * concepts a real commercial document needs — payment terms, implementation/
 * setup terms, support/AMC terms, cancellation/refund terms — into their own
 * columns, each defaulted from `config('commercial.default_terms')` rather
 * than a string hardcoded into the PDF template, and each overridable per
 * quotation.
 */
it('defaults every structured terms field from config when none are supplied', function () {
    $org = Organization::factory()->create();
    $rep = grantCrmPermissions(User::factory()->create(['user_type' => UserType::CompanyUser, 'organization_id' => $org->id]));
    $lead = Lead::factory()->create(['organization_id' => $org->id]);

    $quotation = app(LeadWorkspaceService::class)->createQuotation($lead, [], [
        ['title' => 'Core Suite', 'quantity' => 1, 'unit_price' => 10000, 'discount' => 0],
    ], $rep);

    expect($quotation->terms)->toBe(config('commercial.default_terms.general'))
        ->and($quotation->payment_terms)->toBe(config('commercial.default_terms.payment'))
        ->and($quotation->implementation_terms)->toBe(config('commercial.default_terms.implementation'))
        ->and($quotation->support_terms)->toBe(config('commercial.default_terms.support'))
        ->and($quotation->cancellation_terms)->toBe(config('commercial.default_terms.cancellation'));
});

it('persists custom terms supplied at creation instead of the defaults', function () {
    $org = Organization::factory()->create();
    $rep = grantCrmPermissions(User::factory()->create(['user_type' => UserType::CompanyUser, 'organization_id' => $org->id]));
    $lead = Lead::factory()->create(['organization_id' => $org->id]);

    $quotation = app(LeadWorkspaceService::class)->createQuotation($lead, [
        'payment_terms' => '50% advance, 50% on go-live.',
        'support_terms' => 'Priority support included for 12 months.',
    ], [
        ['title' => 'Core Suite', 'quantity' => 1, 'unit_price' => 10000, 'discount' => 0],
    ], $rep);

    expect($quotation->payment_terms)->toBe('50% advance, 50% on go-live.')
        ->and($quotation->support_terms)->toBe('Priority support included for 12 months.')
        // Untouched fields still fall back to config.
        ->and($quotation->implementation_terms)->toBe(config('commercial.default_terms.implementation'));
});

it('accepts structured terms through the create-quotation endpoint', function () {
    $org = Organization::factory()->create();
    $rep = grantCrmPermissions(User::factory()->create(['user_type' => UserType::CompanyUser, 'organization_id' => $org->id]));
    $lead = Lead::factory()->create(['organization_id' => $org->id]);

    $this->actingAs($rep)
        ->post(route('company.crm.leads.create-quotation', $lead), [
            'amount' => 10000,
            'payment_terms' => 'Net 15 days.',
            'implementation_terms' => 'Go-live within 2 weeks of payment.',
            'cancellation_terms' => 'No refunds after implementation begins.',
        ])
        ->assertSessionHasNoErrors();

    $quotation = CompanyQuotation::where('lead_id', $lead->id)->firstOrFail();

    expect($quotation->payment_terms)->toBe('Net 15 days.')
        ->and($quotation->implementation_terms)->toBe('Go-live within 2 weeks of payment.')
        ->and($quotation->cancellation_terms)->toBe('No refunds after implementation begins.');
});

it('renders the structured terms on the printable quotation', function () {
    $org = Organization::factory()->create();
    $lead = Lead::factory()->create(['organization_id' => $org->id]);
    $employee = CompanyEmployee::factory()->create();
    $rep = grantCrmPermissions(User::factory()->create(['user_type' => UserType::CompanyUser, 'organization_id' => $org->id]));

    $quotation = CompanyQuotation::create([
        'organization_id' => $org->id,
        'lead_id' => $lead->id,
        'prepared_by_id' => $employee->id,
        'quotation_number' => 'QT-TERMS-1',
        'version' => 1,
        'status' => 'draft',
        'subtotal' => 10000,
        'grand_total' => 10000,
        'payment_terms' => 'Payable via bank transfer within 7 days.',
        'implementation_terms' => 'Setup begins within 3 business days.',
        'support_terms' => 'Standard support included for the subscription term.',
        'cancellation_terms' => 'Advance payments are non-refundable once implementation begins.',
    ]);

    $response = $this->actingAs($rep)
        ->get(route('company.crm.quotations.printable', $quotation));

    $response->assertSuccessful();
    $html = $response->getContent();

    expect($html)->toContain('Payable via bank transfer within 7 days.')
        ->and($html)->toContain('Setup begins within 3 business days.')
        ->and($html)->toContain('Standard support included for the subscription term.')
        ->and($html)->toContain('Advance payments are non-refundable once implementation begins.');
});

it('renders the structured terms in the quotation PDF', function () {
    $org = Organization::factory()->create();
    $lead = Lead::factory()->create(['organization_id' => $org->id]);
    $employee = CompanyEmployee::factory()->create();
    $rep = grantCrmPermissions(User::factory()->create(['user_type' => UserType::CompanyUser, 'organization_id' => $org->id]));

    $quotation = CompanyQuotation::create([
        'organization_id' => $org->id,
        'lead_id' => $lead->id,
        'prepared_by_id' => $employee->id,
        'quotation_number' => 'QT-TERMS-2',
        'version' => 1,
        'status' => 'draft',
        'subtotal' => 10000,
        'grand_total' => 10000,
        'payment_terms' => 'UniquePaymentTermMarker12345',
    ]);

    $response = $this->actingAs($rep)
        ->get(route('company.crm.quotations.pdf', $quotation));

    $response->assertSuccessful();

    preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $response->getContent(), $matches);
    $text = '';
    foreach ($matches[1] as $stream) {
        $inflated = @gzuncompress($stream);
        if ($inflated === false) {
            $inflated = @gzinflate($stream);
        }
        if ($inflated !== false) {
            $text .= $inflated;
        }
    }

    expect($text)->toContain('UniquePaymentTermMarker12345');
});
