<?php

use App\Events\XrayCaptured;
use App\Events\XrayCompleted;
use App\Events\XrayOrdered;
use App\Events\XrayReviewed;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicalEncounter;
use App\Models\ClinicEmployee;
use App\Models\Patient;
use App\Services\XrayMetadataService;
use App\Services\XrayOrderService;
use App\Services\XrayReviewService;
use App\Services\XrayStorageService;
use App\XrayCategory;
use App\XrayRegion;
use App\XrayStatus;
use Database\Seeders\SystemRoleTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(SystemRoleTemplateSeeder::class);
});

test('doctor can order X-Ray with tooth region mapping and sequential numbering', function () {
    Event::fake([XrayOrdered::class]);

    $clinic = Clinic::factory()->create();
    $branch = Branch::factory()->create(['clinic_id' => $clinic->id]);
    $patient = Patient::create(['clinic_id' => $clinic->id, 'patient_number' => 'PT-1', 'name' => 'John Doe']);
    $doctor = ClinicEmployee::create(['clinic_id' => $clinic->id, 'name' => 'Dr. House', 'email' => 'house@example.com']);

    $encounter = ClinicalEncounter::create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'branch_id' => $branch->id,
        'doctor_id' => $doctor->id,
        'encounter_number' => 'ENC-1',
        'status' => 'checked_in',
    ]);

    $orderService = app(XrayOrderService::class);

    $order = $orderService->createOrder(
        $patient,
        $encounter,
        $doctor,
        XrayCategory::OPG,
        XrayRegion::MultipleTeeth,
        [14, 15, 16],
        'Check bone density',
        'urgent'
    );

    expect($order->xray_number)->toBe("XR-{$clinic->id}-00001")
        ->and($order->status)->toBe(XrayStatus::Requested)
        ->and($order->category)->toBe(XrayCategory::OPG)
        ->and($order->requested_region)->toBe(XrayRegion::MultipleTeeth)
        ->and($order->requested_teeth)->toContain(14, 15, 16)
        ->and($order->priority)->toBe('urgent');

    Event::assertDispatched(XrayOrdered::class);
});

test('attaching versioned images using abstract storage promotes status to captured', function () {
    Event::fake([XrayCaptured::class]);

    $clinic = Clinic::factory()->create();
    $branch = Branch::factory()->create(['clinic_id' => $clinic->id]);
    $patient = Patient::create(['clinic_id' => $clinic->id, 'patient_number' => 'PT-1', 'name' => 'John Doe']);
    $doctor = ClinicEmployee::create(['clinic_id' => $clinic->id, 'name' => 'Dr. House', 'email' => 'house@example.com']);

    $encounter = ClinicalEncounter::create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'branch_id' => $branch->id,
        'doctor_id' => $doctor->id,
        'encounter_number' => 'ENC-1',
        'status' => 'checked_in',
    ]);

    $order = app(XrayOrderService::class)->createOrder(
        $patient,
        $encounter,
        $doctor,
        XrayCategory::Bitewing,
        XrayRegion::SingleTooth,
        [14]
    );

    // 1. Storage Abstraction
    $storage = app(XrayStorageService::class);
    $path = $storage->store('raw_image_data_binary_stream', 'molar_scan.png');
    expect($path)->toContain('s3://oralcares-imaging/xrays/');

    // 2. Attach Image Metadata
    $metadataService = app(XrayMetadataService::class);
    $attach1 = $metadataService->attachImage($order, 'molar_scan.png', $path, 'image/png', 204857, $doctor, 'First shot');

    expect($attach1->version)->toBe(1)
        ->and($order->fresh()->status)->toBe(XrayStatus::Captured)
        ->and($order->fresh()->file_path)->toBe($path);

    // 3. Attach Second Version
    $path2 = $storage->store('raw_image_data_version_2', 'molar_scan_v2.png');
    $attach2 = $metadataService->attachImage($order, 'molar_scan_v2.png', $path2, 'image/png', 206000, $doctor, 'Better contrast');

    expect($attach2->version)->toBe(2)
        ->and($order->fresh()->attachments)->toHaveCount(2);

    Event::assertDispatched(XrayCaptured::class);
});

test('review cycle registers findings and transitions status to complete', function () {
    Event::fake([XrayReviewed::class, XrayCompleted::class]);

    $clinic = Clinic::factory()->create();
    $branch = Branch::factory()->create(['clinic_id' => $clinic->id]);
    $patient = Patient::create(['clinic_id' => $clinic->id, 'patient_number' => 'PT-1', 'name' => 'John Doe']);
    $doctor = ClinicEmployee::create(['clinic_id' => $clinic->id, 'name' => 'Dr. House', 'email' => 'house@example.com']);

    $encounter = ClinicalEncounter::create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'branch_id' => $branch->id,
        'doctor_id' => $doctor->id,
        'encounter_number' => 'ENC-1',
        'status' => 'checked_in',
    ]);

    $order = app(XrayOrderService::class)->createOrder(
        $patient,
        $encounter,
        $doctor,
        XrayCategory::CBCT,
        XrayRegion::FullArch
    );

    $reviewService = app(XrayReviewService::class);

    // 1. Record Review
    $reviewService->recordReview($order, 'Deep caries on tooth 14', 'Advise extraction');
    expect($order->fresh()->status)->toBe(XrayStatus::Reviewed)
        ->and($order->fresh()->findings)->toBe('Deep caries on tooth 14')
        ->and($order->fresh()->impression)->toBe('Advise extraction')
        ->and($order->fresh()->reviewed_at)->not->toBeNull();
    Event::assertDispatched(XrayReviewed::class);

    // 2. Complete Order
    $reviewService->completeOrder($order);
    expect($order->fresh()->status)->toBe(XrayStatus::Completed);
    Event::assertDispatched(XrayCompleted::class);
});
