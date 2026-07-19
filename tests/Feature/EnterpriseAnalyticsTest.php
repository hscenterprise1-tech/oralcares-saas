<?php

use App\BillingStatus;
use App\InventoryTransactionType;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicalConsultation;
use App\Models\ClinicalDiagnosis;
use App\Models\ClinicalEncounter;
use App\Models\ClinicEmployee;
use App\Models\ClinicQueue;
use App\Models\ClinicQueueItem;
use App\Models\InventoryBatch;
use App\Models\InventoryItem;
use App\Models\LabOrder;
use App\Models\Patient;
use App\Models\PatientBilling;
use App\Models\PatientBillingPayment;
use App\Models\PatientBillingRefund;
use App\Models\PatientProfile;
use App\Models\TreatmentRecord;
use App\Models\XrayRecord;
use App\PaymentMethod;
use App\QueueItemStatus;
use App\Services\BranchAnalyticsService;
use App\Services\ClinicalAnalyticsService;
use App\Services\DoctorAnalyticsService;
use App\Services\InventoryAnalyticsService;
use App\Services\InventoryTransactionService;
use App\Services\KpiEngineService;
use App\Services\PatientAnalyticsService;
use App\Services\QueueAnalyticsService;
use App\Services\RevenueAnalyticsService;
use App\VisitPriority;
use Database\Seeders\SystemRoleTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(SystemRoleTemplateSeeder::class);
});

test('KPI engine can cache, fetch and update snapshot statistics', function () {
    $clinic = Clinic::factory()->create();
    $branch = Branch::factory()->create(['clinic_id' => $clinic->id]);

    $kpiService = app(KpiEngineService::class);

    // 1. Cache
    $kpiService->storeKpi($clinic, $branch, 'conversion_rate', ['value' => 84.5], '2026-07-20');

    // 2. Fetch
    $cached = $kpiService->getKpi($clinic, $branch, 'conversion_rate', '2026-07-20');
    expect($cached['value'])->toBe(84.5);

    // 3. Update
    $kpiService->storeKpi($clinic, $branch, 'conversion_rate', ['value' => 88.0], '2026-07-20');
    $updated = $kpiService->getKpi($clinic, $branch, 'conversion_rate', '2026-07-20');
    expect($updated['value'])->toEqual(88.0);
});

test('revenue analytics aggregates invoicing, collections, and outstanding receivables', function () {
    $clinic = Clinic::factory()->create();
    $branch = Branch::factory()->create(['clinic_id' => $clinic->id]);
    $doctor = ClinicEmployee::create(['clinic_id' => $clinic->id, 'name' => 'Dr. House', 'email' => 'house@example.com']);
    $patient = Patient::create(['clinic_id' => $clinic->id, 'patient_number' => 'PT-1', 'name' => 'John Doe']);

    $billing = PatientBilling::create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'branch_id' => $branch->id,
        'doctor_id' => $doctor->id,
        'billing_number' => 'INV-001',
        'status' => BillingStatus::Pending,
        'total_amount' => 1000.00,
        'final_amount' => 1000.00,
        'outstanding_balance' => 600.00,
    ]);

    PatientBillingPayment::create([
        'patient_billing_id' => $billing->id,
        'amount' => 400.00,
        'payment_method' => PaymentMethod::Cash,
    ]);

    PatientBillingRefund::create([
        'patient_billing_id' => $billing->id,
        'amount' => 50.00,
    ]);

    $revenueService = app(RevenueAnalyticsService::class);
    $metrics = $revenueService->getRevenueMetrics($clinic, null, now()->toDateString(), now()->toDateString());

    expect($metrics['total_invoiced'])->toBe(1000.00)
        ->and($metrics['total_collected'])->toBe(400.00)
        ->and($metrics['outstanding_receivables'])->toBe(600.00)
        ->and($metrics['total_refunded'])->toBe(50.00)
        ->and($metrics['payment_method_distribution']['cash'])->toBe(400.00);
});

test('patient demographics and daily footfall metrics', function () {
    $clinic = Clinic::factory()->create();
    $branch = Branch::factory()->create(['clinic_id' => $clinic->id]);
    $doctor = ClinicEmployee::create(['clinic_id' => $clinic->id, 'name' => 'Dr. House', 'email' => 'house@example.com']);

    $patient = Patient::create([
        'clinic_id' => $clinic->id,
        'patient_number' => 'PT-1',
        'name' => 'John Doe',
        'gender' => 'Male',
        'date_of_birth' => '1990-01-01',
    ]);
    PatientProfile::create([
        'patient_id' => $patient->id,
        'preferred_name' => 'JD',
    ]);

    ClinicalEncounter::create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'branch_id' => $branch->id,
        'doctor_id' => $doctor->id,
        'encounter_number' => 'ENC-1',
        'status' => 'checked_in',
    ]);

    $patientService = app(PatientAnalyticsService::class);
    $metrics = $patientService->getPatientMetrics($clinic, null, now()->toDateString(), now()->toDateString());

    expect($metrics['new_patients_count'])->toBe(1)
        ->and($metrics['average_daily_footfall'])->toBe(1.00)
        ->and($metrics['gender_distribution']['male'])->toBe(1);
});

test('doctor performance analytics compiles consultation counts and completions', function () {
    $clinic = Clinic::factory()->create();
    $branch = Branch::factory()->create(['clinic_id' => $clinic->id]);
    $doctor = ClinicEmployee::create(['clinic_id' => $clinic->id, 'name' => 'Dr. House', 'email' => 'house@example.com']);
    $patient = Patient::create(['clinic_id' => $clinic->id, 'patient_number' => 'PT-1', 'name' => 'John Doe']);

    $encounter = ClinicalEncounter::create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'branch_id' => $branch->id,
        'doctor_id' => $doctor->id,
        'encounter_number' => 'ENC-1',
        'status' => 'checked_in',
    ]);

    ClinicalConsultation::create([
        'encounter_id' => $encounter->id,
        'doctor_id' => $doctor->id,
        'status' => 'finished',
        'started_at' => now()->subMinutes(15),
        'finished_at' => now(),
    ]);

    TreatmentRecord::create([
        'patient_id' => $patient->id,
        'encounter_id' => $encounter->id,
        'code' => 'D1110',
        'description' => 'Prophylaxis',
        'status' => 'completed',
        'price' => 150.00,
    ]);

    PatientBilling::create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'branch_id' => $branch->id,
        'doctor_id' => $doctor->id,
        'billing_number' => 'INV-001',
        'status' => BillingStatus::Paid,
        'total_amount' => 150.00,
        'final_amount' => 150.00,
        'outstanding_balance' => 0.00,
    ]);

    $doctorService = app(DoctorAnalyticsService::class);
    $metrics = $doctorService->getDoctorMetrics($clinic, null, now()->toDateString(), now()->toDateString());

    expect($metrics[$doctor->id]['consultations_count'])->toBe(1)
        ->and($metrics[$doctor->id]['completed_treatments_count'])->toBe(1)
        ->and($metrics[$doctor->id]['revenue_generated'])->toBe(150.00);
});

test('clinical frequencies for diagnoses, procedures, and imaging', function () {
    $clinic = Clinic::factory()->create();
    $branch = Branch::factory()->create(['clinic_id' => $clinic->id]);
    $doctor = ClinicEmployee::create(['clinic_id' => $clinic->id, 'name' => 'Dr. House', 'email' => 'house@example.com']);
    $patient = Patient::create(['clinic_id' => $clinic->id, 'patient_number' => 'PT-1', 'name' => 'John Doe']);

    $encounter = ClinicalEncounter::create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'branch_id' => $branch->id,
        'doctor_id' => $doctor->id,
        'encounter_number' => 'ENC-1',
        'status' => 'checked_in',
    ]);

    TreatmentRecord::create([
        'patient_id' => $patient->id,
        'encounter_id' => $encounter->id,
        'code' => 'D1110',
        'description' => 'Prophylaxis',
        'price' => 100.00,
    ]);

    ClinicalDiagnosis::create([
        'patient_id' => $patient->id,
        'encounter_id' => $encounter->id,
        'code' => 'K02.9',
        'description' => 'Dental caries',
        'notes' => 'Dental caries',
    ]);

    XrayRecord::create([
        'patient_id' => $patient->id,
        'encounter_id' => $encounter->id,
        'xray_number' => 'XR-1',
        'status' => 'requested',
    ]);

    LabOrder::create([
        'patient_id' => $patient->id,
        'encounter_id' => $encounter->id,
        'lab_order_number' => 'LB-1',
        'status' => 'requested',
    ]);

    $clinicalService = app(ClinicalAnalyticsService::class);
    $metrics = $clinicalService->getClinicalMetrics($clinic, null, now()->toDateString(), now()->toDateString());

    expect($metrics['procedure_frequencies']['D1110'])->toBe(1)
        ->and($metrics['diagnosis_frequencies']['K02.9'])->toBe(1)
        ->and($metrics['xray_orders_count'])->toBe(1)
        ->and($metrics['lab_orders_count'])->toBe(1);
});

test('inventory analytics tracks item usage rates', function () {
    $clinic = Clinic::factory()->create();
    $branch = Branch::factory()->create(['clinic_id' => $clinic->id]);
    $doctor = ClinicEmployee::create(['clinic_id' => $clinic->id, 'name' => 'Dr. House', 'email' => 'house@example.com']);

    $item = InventoryItem::create([
        'clinic_id' => $clinic->id,
        'sku' => 'COMP-001',
        'name' => 'Composite Resin A2',
        'category' => 'dental_materials',
        'unit' => 'piece',
    ]);

    $batch = InventoryBatch::create([
        'clinic_id' => $clinic->id,
        'branch_id' => $branch->id,
        'inventory_item_id' => $item->id,
        'batch_number' => 'BATCH-ABC',
        'current_quantity' => 20.00,
        'expiry_date' => now()->addMonths(2)->toDateString(),
    ]);

    app(InventoryTransactionService::class)->recordTransaction(
        $branch,
        $item,
        $batch,
        InventoryTransactionType::Consumption,
        4.00,
        'Usage',
        null,
        $doctor
    );

    $inventoryService = app(InventoryAnalyticsService::class);
    $metrics = $inventoryService->getInventoryMetrics($clinic, null);

    expect($metrics['expiring_batches'])->toHaveCount(1)
        ->and($metrics['fast_moving_items']['COMP-001'])->toBe(4.00);
});

test('queue performance analytics aggregates waiting times and skip rates', function () {
    $clinic = Clinic::factory()->create();
    $branch = Branch::factory()->create(['clinic_id' => $clinic->id]);
    $doctor = ClinicEmployee::create(['clinic_id' => $clinic->id, 'name' => 'Dr. House', 'email' => 'house@example.com']);
    $patient = Patient::create(['clinic_id' => $clinic->id, 'patient_number' => 'PT-1', 'name' => 'John Doe']);

    $encounter = ClinicalEncounter::create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'branch_id' => $branch->id,
        'doctor_id' => $doctor->id,
        'encounter_number' => 'ENC-1',
        'status' => 'checked_in',
    ]);

    $queue = ClinicQueue::create([
        'clinic_id' => $clinic->id,
        'branch_id' => $branch->id,
        'name' => 'General Queue',
    ]);

    $queueItem = ClinicQueueItem::create([
        'clinic_queue_id' => $queue->id,
        'encounter_id' => $encounter->id,
        'position' => 1,
        'priority' => VisitPriority::Normal,
        'called_at' => now()->addMinutes(10), // 10 minutes wait
        'status' => QueueItemStatus::Skipped,
    ]);

    // Force created_at backward to simulate waiting delay
    $queueItem->created_at = now();
    $queueItem->save();

    $queueService = app(QueueAnalyticsService::class);
    $metrics = $queueService->getQueueMetrics($clinic, null, now()->toDateString(), now()->toDateString());

    expect($metrics['skipped_patients_count'])->toBe(1)
        ->and($metrics['skip_rate_percentage'])->toBe(100.00);
});

test('branch comparative breakdowns', function () {
    $clinic = Clinic::factory()->create();
    $b1 = Branch::factory()->create(['clinic_id' => $clinic->id, 'name' => 'Branch Alpha']);
    $b2 = Branch::factory()->create(['clinic_id' => $clinic->id, 'name' => 'Branch Beta']);

    $patient = Patient::create(['clinic_id' => $clinic->id, 'patient_number' => 'PT-1', 'name' => 'John Doe']);

    PatientBilling::create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'branch_id' => $b1->id,
        'billing_number' => 'INV-001',
        'status' => BillingStatus::Paid,
        'total_amount' => 500.00,
        'final_amount' => 500.00,
        'outstanding_balance' => 0.00,
    ]);

    PatientBilling::create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'branch_id' => $b2->id,
        'billing_number' => 'INV-002',
        'status' => BillingStatus::Paid,
        'total_amount' => 300.00,
        'final_amount' => 300.00,
        'outstanding_balance' => 0.00,
    ]);

    $branchService = app(BranchAnalyticsService::class);
    $comparison = $branchService->compareBranches($clinic, now()->toDateString(), now()->toDateString());

    expect($comparison[$b1->id]['total_revenue'])->toBe(500.00)
        ->and($comparison[$b2->id]['total_revenue'])->toBe(300.00);
});
