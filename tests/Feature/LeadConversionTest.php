<?php

use App\Models\CommercialDocument;
use App\Models\CompanyQuotation;
use App\Models\Lead;
use App\Models\Payment;
use App\Models\User;
use App\QuotationStatus;
use App\UserType;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * The gate on turning a deal into a customer.
 *
 * It used to ask for an accepted agreement and a settled payment. The
 * agreement step does not exist in the actual commercial flow — Quotation →
 * Approval → Payment → Invoice → Won — so the gate asked for paperwork nobody
 * drew, and money recorded against a real quotation could not satisfy it.
 *
 * It now asks for an approved quotation settled in full, which is the same
 * thing `DealStageService` requires for Won. Deals closed under the old flow
 * still qualify on their agreement, because those customers are real.
 */
it('prevents lead conversion with nothing settled against it', function () {
    $user = User::factory()->create(['user_type' => UserType::MasterAdmin]);
    $lead = Lead::factory()->create();

    $this->actingAs($user)->post(route('company.crm.leads.convert', $lead))
        ->assertSessionHas('error', 'Lead cannot be converted without an approved quotation settled in full.');

    $this->assertDatabaseMissing('organizations', [
        'name' => $lead->company_name ?? $lead->contact_name,
    ]);
});

it('prevents lead conversion while the approved quotation is only part paid', function () {
    $user = User::factory()->create(['user_type' => UserType::MasterAdmin]);
    $lead = Lead::factory()->create();

    $quotation = CompanyQuotation::factory()->create([
        'lead_id' => $lead->id,
        'organization_id' => $lead->organization_id,
        'status' => QuotationStatus::Approved,
        'approved_at' => now(),
        'subtotal' => 10000, 'tax' => 0, 'discount' => 0, 'grand_total' => 10000,
    ]);

    Payment::factory()->create([
        'lead_id' => $lead->id,
        'company_quotation_id' => $quotation->id,
        'amount' => 4000,
        'status' => 'successful',
    ]);

    $this->actingAs($user)->post(route('company.crm.leads.convert', $lead))
        ->assertSessionHas('error', 'Lead cannot be converted without an approved quotation settled in full.');

    $this->assertDatabaseMissing('organizations', [
        'name' => $lead->company_name ?? $lead->contact_name,
    ]);
});

it('allows lead conversion once an approved quotation is settled in full', function () {
    $user = User::factory()->create(['user_type' => UserType::MasterAdmin]);
    $lead = Lead::factory()->create(['company_name' => 'Acme Corp']);

    $quotation = CompanyQuotation::factory()->create([
        'lead_id' => $lead->id,
        'organization_id' => $lead->organization_id,
        'status' => QuotationStatus::Approved,
        'approved_at' => now(),
        'subtotal' => 10000, 'tax' => 0, 'discount' => 0, 'grand_total' => 10000,
    ]);

    Payment::factory()->create([
        'lead_id' => $lead->id,
        'company_quotation_id' => $quotation->id,
        'amount' => 10000,
        'status' => 'successful',
    ]);

    $this->actingAs($user)->post(route('company.crm.leads.convert', $lead))
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $this->assertDatabaseHas('organizations', ['name' => 'Acme Corp']);
    $this->assertDatabaseHas('leads', ['id' => $lead->id, 'status' => 'won']);
});

it('still converts a deal closed under the old agreement flow', function () {
    /*
     * The agreement paths are gone, but the deals they closed are real and
     * their customers are live. A lead carrying an accepted agreement and a
     * settled payment must still convert.
     */
    $user = User::factory()->create(['user_type' => UserType::MasterAdmin]);
    $lead = Lead::factory()->create(['company_name' => 'Legacy Dental']);

    CommercialDocument::factory()->create([
        'lead_id' => $lead->id,
        'type' => 'agreement',
        'status' => 'accepted',
    ]);

    $invoice = CommercialDocument::factory()->create([
        'lead_id' => $lead->id,
        'type' => 'invoice',
        'status' => 'paid',
    ]);

    Payment::factory()->create([
        'lead_id' => $lead->id,
        'commercial_document_id' => $invoice->id,
        'company_quotation_id' => null,
        'status' => 'successful',
    ]);

    $this->actingAs($user)->post(route('company.crm.leads.convert', $lead))
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $this->assertDatabaseHas('organizations', ['name' => 'Legacy Dental']);
    $this->assertDatabaseHas('leads', ['id' => $lead->id, 'status' => 'won']);
});
