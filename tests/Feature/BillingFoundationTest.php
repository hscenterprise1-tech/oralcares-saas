<?php

use App\BillingStatus;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Patient;
use App\Models\PatientBillingPayment;
use App\Models\User;
use App\Services\BillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * Rewritten on 2026-10-02 for the canonical clinic bill (`PatientBilling`,
 * ADR-001 §G). It exercised the legacy `Invoice` writer, which is now
 * read-only; the behaviour it checked — free-text charge lines, line
 * discounts, totals and an issued bill on the patient's timeline — is the
 * same.
 */
it('issues a bill and accurately calculates net amounts', function () {
    $organization = Organization::factory()->create();
    $clinic = Clinic::factory()->create(['organization_id' => $organization->id]);
    $branch = Branch::factory()->create(['organization_id' => $organization->id, 'clinic_id' => $clinic->id]);
    $patient = Patient::factory()->create(['organization_id' => $organization->id, 'clinic_id' => $clinic->id]);
    $this->actingAs(User::factory()->create(['organization_id' => $organization->id]));

    $bill = app(BillingService::class)->createBill($clinic, $patient, $branch, [
        ['item_type' => 'consultation', 'description' => 'Consultation Fee', 'quantity' => 1, 'unit_price' => 150.00],
        ['description' => 'Surgical Extraction', 'quantity' => 2, 'unit_price' => 200.00, 'discount_amount' => 50.00],
    ]);

    // 1 × 150 = 150; 2 × 200 − 50 = 350 → gross 550, discount 50, due 500.
    expect($bill->billing_number)->toStartWith('INV-')
        ->and($bill->status)->toBe(BillingStatus::Pending)
        ->and((float) $bill->total_amount)->toBe(550.0)
        ->and((float) $bill->discount_amount)->toBe(50.0)
        ->and((float) $bill->final_amount)->toBe(500.0)
        ->and((float) $bill->outstanding_balance)->toBe(500.0)
        ->and($bill->issued_at)->not->toBeNull()
        ->and($bill->items)->toHaveCount(2)
        ->and($patient->timelineEvents()->where('event_type', 'bill_issued')->count())->toBe(1);
});

it('keeps legacy invoices read-only', function () {
    expect(fn () => Invoice::withoutGlobalScopes()->create(['invoice_number' => 'INV-LEGACY-1']))
        ->toThrow(LogicException::class, 'Legacy invoices are read-only');
});

it('gives payments the clinic and branch of their bill, and refuses another clinic\'s', function () {
    $organization = Organization::factory()->create();
    $clinic = Clinic::factory()->create(['organization_id' => $organization->id]);
    $other = Clinic::factory()->create(['organization_id' => $organization->id]);
    $branch = Branch::factory()->create(['organization_id' => $organization->id, 'clinic_id' => $clinic->id]);
    $patient = Patient::factory()->create(['organization_id' => $organization->id, 'clinic_id' => $clinic->id]);
    $this->actingAs(User::factory()->create(['organization_id' => $organization->id]));
    $bill = app(BillingService::class)->createBill($clinic, $patient, $branch, [['description' => 'Consultation', 'unit_price' => 100]]);

    $payment = PatientBillingPayment::withoutGlobalScopes()->create(['patient_billing_id' => $bill->id, 'payment_method' => 'cash', 'amount' => 50]);

    expect($payment->clinic_id)->toBe($clinic->id)
        ->and($payment->branch_id)->toBe($branch->id)
        ->and(fn () => PatientBillingPayment::withoutGlobalScopes()->create(['patient_billing_id' => $bill->id, 'clinic_id' => $other->id, 'payment_method' => 'cash', 'amount' => 50]))
        ->toThrow(LogicException::class);
});
