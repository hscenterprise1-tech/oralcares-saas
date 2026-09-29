<?php

use App\Models\Branch;
use App\Models\ClinicalEncounter;
use App\Models\Organization;
use App\Models\Patient;
use App\Models\User;
use App\Services\BillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('generates an invoice and accurately calculates net amounts', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->create(['organization_id' => $organization->id]);
    $patient = Patient::factory()->create(['organization_id' => $organization->id]);

    $encounter = ClinicalEncounter::create([
        'organization_id' => $organization->id,
        'patient_id' => $patient->id,
        'branch_id' => Branch::factory()->create(['organization_id' => $organization->id, 'clinic_id' => $patient->clinic_id])->id,
        'encounter_number' => 'ENC-BILL-01',
        'status' => 'in_progress',
        'started_at' => now(),
    ]);

    $this->actingAs($user);

    $service = new BillingService;

    $invoice = $service->generateDraftInvoice($patient, $encounter);

    expect($invoice->invoice_number)->toStartWith('INV-')
        ->and($invoice->status)->toBe('draft')
        ->and($invoice->organization_id)->toBe($organization->id);

    $service->addChargeItem($invoice, [
        'description' => 'Consultation Fee',
        'quantity' => 1,
        'unit_price' => 150.00,
    ]);

    $service->addChargeItem($invoice, [
        'description' => 'Surgical Extraction',
        'quantity' => 2,
        'unit_price' => 200.00,
        'discount_amount' => 50.00,
    ]);

    $invoice->refresh();

    // 1 * 150 = 150
    // (2 * 200) - 50 = 350
    // Subtotal: 550, Discount: 50, Total: 500

    expect((float) $invoice->subtotal_amount)->toBe(550.0)
        ->and((float) $invoice->discount_amount)->toBe(50.0)
        ->and((float) $invoice->total_amount)->toBe(500.0)
        ->and((float) $invoice->balance_due)->toBe(500.0);

    $issued = $service->issueInvoice($invoice);

    expect($issued->status)->toBe('issued')
        ->and($issued->issued_at)->not->toBeNull();

    expect($patient->timelineEvents()->where('event_type', 'invoice_issued')->count())->toBe(1);
});
