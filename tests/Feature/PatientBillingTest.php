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
use App\Models\Organization;
use App\Models\Patient;
use App\PaymentMethod;
use App\Services\BillingService;
use App\Services\PaymentService;
use Carbon\CarbonImmutable;
use Database\Seeders\SystemRoleTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

/*
 * The canonical clinic bill (ADR-001 §G). Updated 2026-10-02: numbers come
 * from the branch's financial-year series (INV-2627-00001) instead of a
 * per-clinic `REC-` counter, and catalogue services must belong to the
 * billing clinic.
 */

beforeEach(function () {
    $this->seed(SystemRoleTemplateSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-10-02 11:00', 'Asia/Kolkata'));
});

/** @return array{0: Clinic, 1: Branch, 2: Patient} */
function billingClinic(array $billingConfig = []): array
{
    $clinic = Clinic::factory()->create(['organization_id' => Organization::factory()->create()->id, 'billing_config' => $billingConfig ?: null]);
    $branch = Branch::factory()->create(['clinic_id' => $clinic->id, 'organization_id' => $clinic->organization_id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id, 'organization_id' => $clinic->organization_id]);

    return [$clinic, $branch, $patient];
}

function billingService(Clinic $clinic, string $code = 'D0120', string $name = 'Evaluation'): ClinicalProcedure
{
    return ClinicalProcedure::withoutGlobalScopes()->create(['clinic_id' => $clinic->id, 'code' => $code, 'name' => $name, 'category' => 'preventive']);
}

test('successful billing creation calculates final amounts and receipt numbering', function () {
    Event::fake([BillingCreated::class]);
    [$clinic, $branch, $patient] = billingClinic();
    $proc1 = billingService($clinic, 'D0120', 'Periodic oral evaluation');
    $proc2 = billingService($clinic, 'D1110', 'Prophylaxis - adult');

    $bill = app(BillingService::class)->createBill($clinic, $patient, $branch, [
        ['clinical_procedure_id' => $proc1->id, 'quantity' => 1, 'unit_price' => 150.00, 'discount_amount' => 10.00],
        ['clinical_procedure_id' => $proc2->id, 'quantity' => 2, 'unit_price' => 100.00, 'discount_amount' => 20.00],
    ], null, 10.00, 'percentage');

    // Gross 350; line discounts 30; bill discount 10% of 350 = 35 → due 285.
    expect($bill->billing_number)->toBe('INV-2627-00001')
        ->and($bill->financial_year)->toBe('2026-27')
        ->and($bill->status)->toBe(BillingStatus::Pending)
        ->and((float) $bill->total_amount)->toBe(350.00)
        ->and((float) $bill->discount_amount)->toBe(65.00)
        ->and((float) $bill->final_amount)->toBe(285.00)
        ->and((float) $bill->outstanding_balance)->toBe(285.00)
        ->and((float) $bill->items->sum('final_amount'))->toBe(285.00)
        ->and($bill->items->pluck('description')->all())->toBe(['Periodic oral evaluation', 'Prophylaxis - adult']);

    Event::assertDispatched(BillingCreated::class);
});

test('payments transition billing status and outstanding balances', function () {
    Event::fake([PaymentRecorded::class, BillCompleted::class]);
    [$clinic, $branch, $patient] = billingClinic();
    $bill = app(BillingService::class)->createBill($clinic, $patient, $branch, [
        ['clinical_procedure_id' => billingService($clinic)->id, 'quantity' => 1, 'unit_price' => 100.00],
    ]);
    $payments = app(PaymentService::class);

    $first = $payments->recordPayment($bill, PaymentMethod::Upi, 40.00, 'TXN123');
    expect($bill->fresh()->status)->toBe(BillingStatus::PartiallyPaid)
        ->and((float) $bill->fresh()->outstanding_balance)->toBe(60.00)
        ->and($first->receipt_number)->toBe('RCT-2627-00001');
    Event::assertDispatched(PaymentRecorded::class);

    $second = $payments->recordPayment($bill, PaymentMethod::Cash, 60.00);
    expect($bill->fresh()->status)->toBe(BillingStatus::Paid)
        ->and((float) $bill->fresh()->outstanding_balance)->toBe(0.00)
        ->and((float) $bill->fresh()->paid_amount)->toBe(100.00)
        ->and($second->receipt_number)->toBe('RCT-2627-00002');
    Event::assertDispatched(BillCompleted::class);
});

test('bill cancellation and refund operations', function () {
    Event::fake([RefundInitiated::class]);
    [$clinic, $branch, $patient] = billingClinic();
    $doctor = ClinicEmployee::withoutGlobalScopes()->create(['clinic_id' => $clinic->id, 'name' => 'Dr. House', 'email' => 'house@example.com']);
    $proc = billingService($clinic);
    $billing = app(BillingService::class);
    $payments = app(PaymentService::class);

    $bill1 = $billing->createBill($clinic, $patient, $branch, [['clinical_procedure_id' => $proc->id, 'unit_price' => 100.00]]);
    $billing->cancelBill($bill1, $doctor, 'Entered twice');
    expect($bill1->fresh()->status)->toBe(BillingStatus::Cancelled)
        ->and((float) $bill1->fresh()->outstanding_balance)->toBe(0.0)
        ->and($bill1->fresh()->cancellation_reason)->toBe('Entered twice');

    $bill2 = $billing->createBill($clinic, $patient, $branch, [['clinical_procedure_id' => $proc->id, 'unit_price' => 200.00]]);
    $payment = $payments->recordPayment($bill2, PaymentMethod::Card, 200.00);
    $refund = $payments->recordRefund($bill2, 200.00, 'Customer request', $doctor, $payment);

    expect($bill2->fresh()->status)->toBe(BillingStatus::Refunded)
        ->and($bill2->fresh()->refunds)->toHaveCount(1)
        ->and($refund->refund_number)->toBe('RFD-2627-00001')
        ->and($refund->payment_method)->toBe(PaymentMethod::Card);
    Event::assertDispatched(RefundInitiated::class);
});

test('each branch numbers its own bills, and the series restarts with the financial year', function () {
    [$clinic, $main, $patient] = billingClinic();
    $annexe = Branch::factory()->create(['clinic_id' => $clinic->id, 'organization_id' => $clinic->organization_id]);
    $line = [['description' => 'Consultation', 'unit_price' => 300]];
    $billing = app(BillingService::class);

    expect($billing->createBill($clinic, $patient, $main, $line)->billing_number)->toBe('INV-2627-00001')
        ->and($billing->createBill($clinic, $patient, $main, $line)->billing_number)->toBe('INV-2627-00002')
        ->and($billing->createBill($clinic, $patient, $annexe, $line)->billing_number)->toBe('INV-2627-00001');

    $this->travelTo(CarbonImmutable::parse('2027-04-01 10:00', 'Asia/Kolkata'));

    expect($billing->createBill($clinic, $patient, $main, $line)->billing_number)->toBe('INV-2728-00001');
});

test('a payment can never exceed what is due, and a cancelled bill takes none', function () {
    [$clinic, $branch, $patient] = billingClinic();
    $billing = app(BillingService::class);
    $bill = $billing->createBill($clinic, $patient, $branch, [['description' => 'Consultation', 'unit_price' => 500]]);

    expect(fn () => app(PaymentService::class)->recordPayment($bill, PaymentMethod::Cash, 500.01))
        ->toThrow(DomainException::class, 'more than the amount due');

    $cancelled = $billing->cancelBill($billing->createBill($clinic, $patient, $branch, [['description' => 'Consultation', 'unit_price' => 500]]));
    expect(fn () => app(PaymentService::class)->recordPayment($cancelled, PaymentMethod::Cash, 100))
        ->toThrow(DomainException::class, 'cancelled');
});

test('a bill with money against it cannot be cancelled until refunded', function () {
    [$clinic, $branch, $patient] = billingClinic();
    $bill = app(BillingService::class)->createBill($clinic, $patient, $branch, [['description' => 'Consultation', 'unit_price' => 500]]);
    app(PaymentService::class)->recordPayment($bill, PaymentMethod::Cash, 200);

    expect(fn () => app(BillingService::class)->cancelBill($bill))->toThrow(DomainException::class, 'Refund them');
});

test('a refund never exceeds what was collected', function () {
    [$clinic, $branch, $patient] = billingClinic();
    $bill = app(BillingService::class)->createBill($clinic, $patient, $branch, [['description' => 'Consultation', 'unit_price' => 500]]);
    app(PaymentService::class)->recordPayment($bill, PaymentMethod::Cash, 200);

    expect(fn () => app(PaymentService::class)->recordRefund($bill, 200.01))->toThrow(DomainException::class);
});

test('a payment method the clinic does not accept is refused', function () {
    [$clinic, $branch, $patient] = billingClinic(['payment_methods' => ['cash']]);
    $bill = app(BillingService::class)->createBill($clinic, $patient, $branch, [['description' => 'Consultation', 'unit_price' => 500]]);

    expect(fn () => app(PaymentService::class)->recordPayment($bill, PaymentMethod::Card, 100))->toThrow(DomainException::class, 'not a payment method');
});

test('a bill cannot name another clinic\'s patient, branch or catalogue service', function () {
    [$clinic, $branch, $patient] = billingClinic();
    [$other, $otherBranch, $otherPatient] = billingClinic();
    $billing = app(BillingService::class);
    $line = [['description' => 'Consultation', 'unit_price' => 100]];

    expect(fn () => $billing->createBill($clinic, $otherPatient, $branch, $line))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $billing->createBill($clinic, $patient, $otherBranch, $line))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $billing->createBill($clinic, $patient, $branch, [['clinical_procedure_id' => billingService($other)->id, 'unit_price' => 100]]))
        ->toThrow(InvalidArgumentException::class, 'catalogue');
});

test('tax follows the clinic billing configuration', function (string $mode, float $final, float $tax) {
    [$clinic, $branch, $patient] = billingClinic(['tax_mode' => $mode, 'tax_percent' => 18]);

    $bill = app(BillingService::class)->createBill($clinic, $patient, $branch, [['description' => 'Aligners', 'unit_price' => 1000]]);

    expect((float) $bill->final_amount)->toBe($final)
        ->and((float) $bill->tax_amount)->toBe($tax);
})->with([
    'no tax' => ['none', 1000.0, 0.0],
    'added on top' => ['exclusive', 1180.0, 180.0],
    'included in the price' => ['inclusive', 1000.0, 152.54],
]);
