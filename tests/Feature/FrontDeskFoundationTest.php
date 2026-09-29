<?php

use App\Models\Appointment;
use App\Models\Branch;
use App\Models\ClinicEmployee;
use App\Models\Organization;
use App\Models\Patient;
use App\Models\User;
use App\Services\FrontDeskService;
use App\VisitStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('can check in an appointment and start a consultation', function () {
    $organization = Organization::factory()->create();
    $patient = Patient::factory()->create(['organization_id' => $organization->id]);
    $provider = User::factory()->create(['organization_id' => $organization->id]);
    $employee = ClinicEmployee::create([
        'clinic_id' => $patient->clinic_id,
        'user_id' => $provider->id,
        'name' => 'Dr. Provider',
        'email' => $provider->email,
    ]);

    $branch = Branch::factory()->create([
        'clinic_id' => $patient->clinic_id,
        'organization_id' => $organization->id,
    ]);

    $this->actingAs($provider);

    $appointment = Appointment::create([
        'organization_id' => $organization->id,
        'clinic_id' => $patient->clinic_id,
        'branch_id' => $branch->id,
        'patient_id' => $patient->id,
        'provider_id' => $employee->id,
        'scheduled_at' => now(),
        'status' => 'scheduled',
    ]);

    $service = new FrontDeskService;

    // 1. Check in
    $queue = $service->checkInAppointment($appointment);

    expect($appointment->status->value)->toBe('checked_in')
        ->and($queue->status)->toBe('waiting')
        ->and($queue->patient_id)->toBe($patient->id)
        ->and($queue->appointment_id)->toBe($appointment->id);

    // 2. Start Consultation
    $encounter = $service->startConsultation($queue);

    expect($queue->status)->toBe('in_consultation')
        ->and($queue->called_time)->not->toBeNull()
        ->and($encounter->status)->toBe(VisitStatus::InProgress)
        ->and($encounter->patient_id)->toBe($patient->id)
        ->and($encounter->doctor_id)->toBe($employee->id);
});

it('can add a walk in directly to the queue', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->create(['organization_id' => $organization->id]);
    $patient = Patient::factory()->create(['organization_id' => $organization->id]);

    $this->actingAs($user);

    $service = new FrontDeskService;

    $queue = $service->addWalkIn($patient, $user);

    expect($queue->status)->toBe('waiting')
        ->and($queue->appointment_id)->toBeNull()
        ->and($queue->patient_id)->toBe($patient->id)
        ->and($queue->provider_id)->toBe($user->id);
});
