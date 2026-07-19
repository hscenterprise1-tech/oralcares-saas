<?php

use App\BillingStatus;
use App\Events\BillCompleted;
use App\Events\BillingCreated;
use App\Events\PaymentRecorded;
use App\Events\RefundInitiated;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicalProcedure;
use App\Models\ClinicEmployee;
use App\Models\Patient;
use App\PaymentMethod;
use App\Services\BillingService;
use App\Services\PaymentService;
use Database\Seeders\SystemRoleTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(SystemRoleTemplateSeeder::class);
});

test('successful billing creation calculates final amounts and receipt numbering', function () {
    Event::fake([BillingCreated::class]);

    $clinic = Clinic::factory()->create();
    $branch = Branch::factory()->create(['clinic_id' => $clinic->id]);
    $patient = Patient::create(['clinic_id' => $clinic->id, 'patient_number' => 'PT-1', 'name' => 'John Doe']);

    $proc1 = ClinicalProcedure::create([
        'code' => 'D0120',
        'name' => 'Periodic oral evaluation',
        'category' => 'preventive',
        'default_duration_minutes' => 30,
        'clinical_complexity' => 'low',
    ]);

    $proc2 = ClinicalProcedure::create([
        'code' => 'D1110',
        'name' => 'Prophylaxis - adult',
        'category' => 'preventive',
        'default_duration_minutes' => 45,
        'clinical_complexity' => 'low',
    ]);

    $billingService = app(BillingService::class);

    $bill = $billingService->createBill(
        $clinic,
        $patient,
        $branch,
        [
            [
                'clinical_procedure_id' => $proc1->id,
                'quantity' => 1,
                'unit_price' => 150.00,
                'discount_amount' => 10.00,
            ],
            [
                'clinical_procedure_id' => $proc2->id,
                'quantity' => 2,
                'unit_price' => 100.00,
                'discount_amount' => 20.00,
            ],
        ],
        null,
        10.00,
        'percentage'
    );

    // Subtotal calculations: (150 * 1) + (100 * 2) = 350.00
    // Item-level discounts: 10 + 20 = 30.00
    // Bill-level discount (10% of 350): 35.00
    // Total discount: 30 + 35 = 65.00
    // Final Amount: 350 - 65 = 285.00
    expect($bill->billing_number)->toBe("REC-{$clinic->id}-00001")
        ->and($bill->status)->toBe(BillingStatus::Pending)
        ->and((float) $bill->total_amount)->toBe(350.00)
        ->and((float) $bill->discount_amount)->toBe(65.00)
        ->and((float) $bill->final_amount)->toBe(285.00)
        ->and((float) $bill->outstanding_balance)->toBe(285.00);

    Event::assertDispatched(BillingCreated::class);
});

test('payments transition billing status and outstanding balances', function () {
    Event::fake([PaymentRecorded::class, BillCompleted::class]);

    $clinic = Clinic::factory()->create();
    $branch = Branch::factory()->create(['clinic_id' => $clinic->id]);
    $patient = Patient::create(['clinic_id' => $clinic->id, 'patient_number' => 'PT-1', 'name' => 'John Doe']);

    $proc = ClinicalProcedure::create([
        'code' => 'D0120',
        'name' => 'Evaluation',
        'category' => 'preventive',
    ]);

    $billingService = app(BillingService::class);
    $paymentService = app(PaymentService::class);

    $bill = $billingService->createBill($clinic, $patient, $branch, [
        [
            'clinical_procedure_id' => $proc->id,
            'quantity' => 1,
            'unit_price' => 100.00,
            'discount_amount' => 0.00,
        ],
    ]);

    // 1. Partial Payment
    $paymentService->recordPayment($bill, PaymentMethod::UPI, 40.00, 'TXN123');
    expect($bill->fresh()->status)->toBe(BillingStatus::PartiallyPaid)
        ->and((float) $bill->fresh()->outstanding_balance)->toBe(60.00);
    Event::assertDispatched(PaymentRecorded::class);

    // 2. Settlement Payment
    $paymentService->recordPayment($bill, PaymentMethod::Cash, 60.00);
    expect($bill->fresh()->status)->toBe(BillingStatus::Paid)
        ->and((float) $bill->fresh()->outstanding_balance)->toBe(0.00);
    Event::assertDispatched(BillCompleted::class);
});

test('bill cancellation and refund operations', function () {
    Event::fake([RefundInitiated::class]);

    $clinic = Clinic::factory()->create();
    $branch = Branch::factory()->create(['clinic_id' => $clinic->id]);
    $patient = Patient::create(['clinic_id' => $clinic->id, 'patient_number' => 'PT-1', 'name' => 'John Doe']);
    $doctor = ClinicEmployee::create(['clinic_id' => $clinic->id, 'name' => 'Dr. House', 'email' => 'house@example.com']);

    $proc = ClinicalProcedure::create([
        'code' => 'D0120',
        'name' => 'Evaluation',
        'category' => 'preventive',
    ]);

    $billingService = app(BillingService::class);
    $paymentService = app(PaymentService::class);

    // Test Cancel
    $bill1 = $billingService->createBill($clinic, $patient, $branch, [
        [
            'clinical_procedure_id' => $proc->id,
            'quantity' => 1,
            'unit_price' => 100.00,
        ],
    ]);
    $billingService->cancelBill($bill1, $doctor);
    expect($bill1->fresh()->status)->toBe(BillingStatus::Cancelled);

    // Test Refund
    $bill2 = $billingService->createBill($clinic, $patient, $branch, [
        [
            'clinical_procedure_id' => $proc->id,
            'quantity' => 1,
            'unit_price' => 200.00,
        ],
    ]);

    $paymentService->recordPayment($bill2, PaymentMethod::Card, 200.00);
    $paymentService->recordRefund($bill2, 200.00, 'Customer request', $doctor);

    expect($bill2->fresh()->status)->toBe(BillingStatus::Refunded)
        ->and($bill2->fresh()->refunds)->toHaveCount(1);
    Event::assertDispatched(RefundInitiated::class);
});
