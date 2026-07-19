<?php

use App\BillingStatus;
use App\Http\Resources\AnalyticsResource;
use App\Http\Resources\BillingResource;
use App\Http\Resources\EncounterResource;
use App\Http\Resources\InventoryResource;
use App\Http\Resources\LaboratoryResource;
use App\Http\Resources\PatientResource;
use App\Http\Resources\QueueResource;
use App\Http\Resources\WorkflowResource;
use App\Http\Resources\XrayResource;
use App\Models\AnalyticsSnapshot;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicalEncounter;
use App\Models\ClinicEmployee;
use App\Models\ClinicQueue;
use App\Models\InventoryItem;
use App\Models\LabOrder;
use App\Models\Patient;
use App\Models\PatientBilling;
use App\Models\WorkflowDefinition;
use App\Models\WorkflowRun;
use App\Models\XrayRecord;
use App\Services\ApiAuditService;
use App\Services\ApiGatewayService;
use App\Services\ApiResponseService;
use App\Services\WebhookService;
use Database\Seeders\SystemRoleTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(SystemRoleTemplateSeeder::class);
});

test('API key authentication token lifecycle validation', function () {
    $clinic = Clinic::factory()->create();
    $employee = ClinicEmployee::create(['clinic_id' => $clinic->id, 'name' => 'Dr. House', 'email' => 'house@example.com']);

    $gateway = app(ApiGatewayService::class);

    // 1. Generate token
    $rawToken = $gateway->generateToken($employee, 'Integration Key');
    expect($rawToken)->toStartWith('oc_');

    // 2. Validate token
    $resolved = $gateway->authenticateToken($rawToken);
    expect($resolved)->not->toBeNull()
        ->and($resolved->id)->toBe($employee->id);

    // 3. Failed/Incorrect token validation
    $invalid = $gateway->authenticateToken('oc_wrong');
    expect($invalid)->toBeNull();
});

test('API DTO resource standardizations mapping serialization', function () {
    $clinic = Clinic::factory()->create();
    $branch = Branch::factory()->create(['clinic_id' => $clinic->id]);
    $employee = ClinicEmployee::create(['clinic_id' => $clinic->id, 'name' => 'Dr. House', 'email' => 'house@example.com']);

    $patient = Patient::create([
        'clinic_id' => $clinic->id,
        'patient_number' => 'PT-100',
        'name' => 'Jane Doe',
        'gender' => 'Female',
        'date_of_birth' => '1995-05-15',
    ]);

    $encounter = ClinicalEncounter::create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'branch_id' => $branch->id,
        'doctor_id' => $employee->id,
        'encounter_number' => 'ENC-100',
        'status' => 'checked_in',
    ]);

    $billing = PatientBilling::create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'branch_id' => $branch->id,
        'doctor_id' => $employee->id,
        'billing_number' => 'INV-100',
        'status' => BillingStatus::Paid,
        'total_amount' => 500.00,
        'final_amount' => 500.00,
        'outstanding_balance' => 0.00,
    ]);

    $lab = LabOrder::create([
        'patient_id' => $patient->id,
        'encounter_id' => $encounter->id,
        'lab_order_number' => 'LB-100',
        'status' => 'requested',
    ]);

    $xray = XrayRecord::create([
        'patient_id' => $patient->id,
        'encounter_id' => $encounter->id,
        'xray_number' => 'XR-100',
        'status' => 'requested',
    ]);

    $inventory = InventoryItem::create([
        'clinic_id' => $clinic->id,
        'sku' => 'ITEM-100',
        'name' => 'Standard Dental Paste',
        'category' => 'paste',
        'unit' => 'tube',
    ]);

    $queue = ClinicQueue::create([
        'clinic_id' => $clinic->id,
        'branch_id' => $branch->id,
        'name' => 'General Queue A',
    ]);

    $def = WorkflowDefinition::create([
        'clinic_id' => $clinic->id,
        'branch_id' => $branch->id,
        'name' => 'Standard Flow',
        'is_active' => true,
    ]);

    $workflowRun = WorkflowRun::create([
        'clinic_id' => $clinic->id,
        'branch_id' => $branch->id,
        'workflow_definition_id' => $def->id,
        'encounter_id' => $encounter->id,
        'status' => 'active',
    ]);

    $analytics = AnalyticsSnapshot::create([
        'clinic_id' => $clinic->id,
        'branch_id' => $branch->id,
        'metric_key' => 'weekly_patient_count',
        'metric_value' => ['value' => 50],
        'dimension_date' => '2026-07-20',
        'calculated_at' => now(),
    ]);

    // Validate patient resource serialization attributes
    $patResource = (new PatientResource($patient))->toArray(request());
    expect($patResource)->toHaveKeys(['id', 'patient_number', 'name', 'gender', 'date_of_birth'])
        ->and($patResource['patient_number'])->toBe('PT-100');

    // Validate billing resource
    $billResource = (new BillingResource($billing))->toArray(request());
    expect($billResource['billing_number'])->toBe('INV-100')
        ->and($billResource['total_amount'])->toEqual(500.00);

    // Validate other Resources load cleanly
    expect((new EncounterResource($encounter))->toArray(request())['encounter_number'])->toBe('ENC-100')
        ->and((new LaboratoryResource($lab))->toArray(request())['lab_order_number'])->toBe('LB-100')
        ->and((new XrayResource($xray))->toArray(request())['xray_number'])->toBe('XR-100')
        ->and((new InventoryResource($inventory))->toArray(request())['sku'])->toBe('ITEM-100')
        ->and((new QueueResource($queue))->toArray(request())['name'])->toBe('General Queue A')
        ->and((new WorkflowResource($workflowRun))->toArray(request())['status'])->toBe('active')
        ->and((new AnalyticsResource($analytics))->toArray(request())['metric_key'])->toBe('weekly_patient_count');
});

test('pagination and unified API JSON response structures', function () {
    $responseService = app(ApiResponseService::class);

    // 1. Success wrapper format
    $response = $responseService->success(['msg' => 'Ready'], ['timestamp' => '2026-07-20']);
    $data = $response->getData(true);

    expect($data['success'])->toBeTrue()
        ->and($data['data']['msg'])->toBe('Ready')
        ->and($data['meta']['timestamp'])->toBe('2026-07-20')
        ->and($data['error'])->toBeNull();

    // 2. Paginated formatting
    $clinic = Clinic::factory()->create();
    $patient = Patient::create([
        'clinic_id' => $clinic->id,
        'patient_number' => 'PT-999',
        'name' => 'Jane',
    ]);
    $items = collect([$patient]);
    $paginator = new LengthAwarePaginator($items, 1, 15, 1);

    $paginatedResponse = $responseService->paginated($paginator, PatientResource::class);
    $paginatedData = $paginatedResponse->getData(true);

    expect($paginatedData['success'])->toBeTrue()
        ->and($paginatedData['meta']['pagination']['total'])->toBe(1)
        ->and($paginatedData['error'])->toBeNull();

    // 3. Error formatting
    $errorResponse = $responseService->error('Access denied', 403, ['reason' => 'Invalid permissions']);
    $errorData = $errorResponse->getData(true);

    expect($errorData['success'])->toBeFalse()
        ->and($errorData['error']['message'])->toBe('Access denied')
        ->and($errorData['error']['status_code'])->toBe(403)
        ->and($errorData['error']['details']['reason'])->toBe('Invalid permissions');
});

test('API audit logging logs request parameter maps scrubbing medical detail fields', function () {
    $clinic = Clinic::factory()->create();
    $employee = ClinicEmployee::create(['clinic_id' => $clinic->id, 'name' => 'Dr. House', 'email' => 'house@example.com']);

    $auditService = app(ApiAuditService::class);

    $log = $auditService->logRequest(
        $employee,
        '/api/v1/patients',
        'POST',
        '127.0.0.1',
        201,
        [
            'patient_id' => 12,
            'name' => 'Jane Doe',
            'notes' => 'Dental surgery needed',
        ]
    );

    expect($log->endpoint)->toBe('/api/v1/patients')
        ->and($log->status_code)->toBe(201)
        // Verify sensitive parameters are scrubbed
        ->and($log->payload_summary['patient_id'])->toBe('[SCRUBBED_SENSITIVE_DATA]')
        ->and($log->payload_summary['notes'])->toBe('[SCRUBBED_SENSITIVE_DATA]')
        // Non-sensitive data remains visible
        ->and($log->payload_summary['name'])->toBe('Jane Doe');
});

test('API gateway verifies rate limiting increments', function () {
    $gateway = app(ApiGatewayService::class);
    $key = 'rate_limit_test_client_1';

    // Allow requests below limit
    for ($i = 0; $i < 3; $i++) {
        expect($gateway->checkRateLimit($key, 5, 60))->toBeTrue();
    }

    // Limit exceeded check
    for ($i = 0; $i < 2; $i++) {
        $gateway->checkRateLimit($key, 5, 60);
    }

    expect($gateway->checkRateLimit($key, 5, 60))->toBeFalse();
});

test('OpenAPI documentation spec validation mapping endpoints', function () {
    $gateway = app(ApiGatewayService::class);
    $spec = $gateway->generateOpenApiSpec();

    expect($spec['openapi'])->toBe('3.0.0')
        ->and($spec['info']['title'])->toBe('OralCares Clinical HMS API')
        ->and($spec['paths'])->toHaveKey('/patients')
        ->and($spec['paths'])->toHaveKey('/encounters');
});

test('webhook event subscriptions and signature preparation', function () {
    $clinic = Clinic::factory()->create();
    $webhookService = app(WebhookService::class);

    // 1. Subscribe
    $sub = $webhookService->subscribe($clinic, 'PatientCreated', 'https://api.external.com/webhook', 'shh_secret');
    expect($sub->event_type)->toBe('PatientCreated')
        ->and($sub->target_url)->toBe('https://api.external.com/webhook');

    // 2. Trigger Event
    $deliveries = $webhookService->triggerEvent($clinic, 'PatientCreated', ['patient_name' => 'Jane Doe']);

    expect($deliveries)->toHaveCount(1)
        ->and($deliveries[0]['target_url'])->toBe('https://api.external.com/webhook')
        ->and($deliveries[0]['headers']['X-OralCares-Event'])->toBe('PatientCreated')
        ->and($deliveries[0]['headers'])->toHaveKey('X-OralCares-Signature');

    // 3. Unsubscribe
    $webhookService->unsubscribe($sub);
    expect($webhookService->triggerEvent($clinic, 'PatientCreated', []))->toHaveCount(0);
});
