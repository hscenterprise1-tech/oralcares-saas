<?php

use App\Models\ClinicalEncounter;
use App\Models\ClinicalOrder;
use App\Models\ClinicalOrderItem;
use App\Models\Organization;
use App\Models\Patient;
use App\Models\ProcedureMaster;
use App\Models\User;
use App\Services\DiagnosticResultService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('can store a draft diagnostic result with JSON observations', function () {
    $organization = Organization::factory()->create();
    $patient = Patient::factory()->create(['organization_id' => $organization->id]);
    $encounter = ClinicalEncounter::factory()->create(['organization_id' => $organization->id, 'clinic_id' => $patient->clinic_id, 'patient_id' => $patient->id]);
    $doctor = User::factory()->create(['organization_id' => $organization->id]);

    $procedure = ProcedureMaster::factory()->create(['organization_id' => $organization->id, 'code' => 'LAB01']);

    $order = ClinicalOrder::create([
        'organization_id' => $organization->id,
        'encounter_id' => $encounter->id,
        'order_number' => 'ORD-'.rand(1000, 9999),
        'order_type' => 'laboratory',
        'patient_id' => $patient->id,
        'ordered_by' => $doctor->id,
        'status' => 'placed',
    ]);

    $orderItem = ClinicalOrderItem::create([
        'clinical_order_id' => $order->id,
        'item_code' => $procedure->code,
        'item_name' => $procedure->name,
        'status' => 'pending',
    ]);

    $service = new DiagnosticResultService;

    $content = [
        'hemoglobin' => ['value' => 14.5, 'unit' => 'g/dL', 'flag' => 'normal'],
        'wbc' => ['value' => 11.2, 'unit' => '10^3/uL', 'flag' => 'high'],
    ];

    $result = $service->storeDraft($orderItem, $content, 'laboratory', $doctor);

    expect($result->status)->toBe('draft')
        ->and($result->result_type)->toBe('laboratory')
        ->and($result->content['hemoglobin']['value'])->toBe(14.5)
        ->and($result->content['wbc']['flag'])->toBe('high');
});

it('completes the parent order item when finalizing a result', function () {
    $organization = Organization::factory()->create();
    $patient = Patient::factory()->create(['organization_id' => $organization->id]);
    $encounter = ClinicalEncounter::factory()->create(['organization_id' => $organization->id, 'clinic_id' => $patient->clinic_id, 'patient_id' => $patient->id]);
    $doctor = User::factory()->create(['organization_id' => $organization->id]);
    $procedure = ProcedureMaster::factory()->create(['organization_id' => $organization->id]);

    $order = ClinicalOrder::create([
        'organization_id' => $organization->id,
        'encounter_id' => $encounter->id,
        'order_number' => 'ORD-'.rand(1000, 9999),
        'order_type' => 'imaging',
        'patient_id' => $patient->id,
        'ordered_by' => $doctor->id,
        'status' => 'placed',
    ]);

    $orderItem = ClinicalOrderItem::create([
        'clinical_order_id' => $order->id,
        'item_code' => $procedure->code,
        'item_name' => $procedure->name,
        'status' => 'in_progress',
    ]);

    $service = new DiagnosticResultService;
    $result = $service->storeDraft($orderItem, ['text' => 'Normal X-Ray'], 'imaging');

    // Finalize
    $finalizedResult = $service->verifyAndFinalize($result, $doctor);

    expect($finalizedResult->status)->toBe('final')
        ->and($finalizedResult->verified_by)->toBe($doctor->id)
        ->and($orderItem->fresh()->status)->toBe('completed'); // Asserts side effect
});

it('can attach files polymorphically to a result', function () {
    $organization = Organization::factory()->create();
    $patient = Patient::factory()->create(['organization_id' => $organization->id]);
    $encounter = ClinicalEncounter::factory()->create(['organization_id' => $organization->id, 'clinic_id' => $patient->clinic_id, 'patient_id' => $patient->id]);
    $procedure = ProcedureMaster::factory()->create(['organization_id' => $organization->id]);

    $order = ClinicalOrder::create([
        'organization_id' => $organization->id,
        'encounter_id' => $encounter->id,
        'order_number' => 'ORD-'.rand(1000, 9999),
        'order_type' => 'imaging',
        'patient_id' => $patient->id,
        'ordered_by' => User::factory()->create()->id,
        'status' => 'placed',
    ]);

    $orderItem = ClinicalOrderItem::create([
        'clinical_order_id' => $order->id,
        'item_code' => $procedure->code,
        'item_name' => $procedure->name,
        'status' => 'pending',
    ]);

    $service = new DiagnosticResultService;
    $result = $service->storeDraft($orderItem, ['text' => 'Report pending'], 'imaging');

    $attachment = $service->attachFile(
        $result,
        'https://pacs.clinic.com/viewer/12345',
        'dicom_link',
        'Chest X-Ray DICOM'
    );

    expect($attachment->attachable_type)->toBe(get_class($result))
        ->and($attachment->attachable_id)->toBe($result->id)
        ->and($attachment->file_type)->toBe('dicom_link')
        ->and($result->attachments()->count())->toBe(1);
});
