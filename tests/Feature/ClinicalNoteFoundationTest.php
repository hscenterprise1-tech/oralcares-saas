<?php

use App\Models\Branch;
use App\Models\ClinicalEncounter;
use App\Models\Organization;
use App\Models\Patient;
use App\Models\User;
use App\Services\ClinicalNoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('creates a draft note and signs it', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->create(['organization_id' => $organization->id]);
    $patient = Patient::factory()->create(['organization_id' => $organization->id]);

    $encounter = ClinicalEncounter::create([
        'organization_id' => $organization->id,
        'patient_id' => $patient->id,
        'branch_id' => Branch::factory()->create(['organization_id' => $organization->id, 'clinic_id' => $patient->clinic_id])->id,
        'encounter_number' => 'ENC-123',
        'status' => 'in_progress',
        'started_at' => now(),
    ]);

    $this->actingAs($user);

    $service = new ClinicalNoteService;

    // Draft
    $note = $service->createDraft($encounter, [
        'note_type' => 'general',
        'content' => ['text' => 'Patient looks fine'],
    ]);

    expect($note->status)->toBe('draft')
        ->and($note->content['text'])->toBe('Patient looks fine');

    // Sign
    $signedNote = $service->signNote($note);

    expect($signedNote->status)->toBe('signed')
        ->and($signedNote->signed_at)->not->toBeNull()
        ->and($patient->timelineEvents()->where('event_type', 'note_signed')->count())->toBe(1);
});

it('prevents editing signed notes', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->create(['organization_id' => $organization->id]);
    $patient = Patient::factory()->create(['organization_id' => $organization->id]);

    $encounter = ClinicalEncounter::create([
        'organization_id' => $organization->id,
        'patient_id' => $patient->id,
        'branch_id' => Branch::factory()->create(['organization_id' => $organization->id, 'clinic_id' => $patient->clinic_id])->id,
        'encounter_number' => 'ENC-124',
        'status' => 'in_progress',
        'started_at' => now(),
    ]);

    $this->actingAs($user);

    $service = new ClinicalNoteService;

    $note = $service->createDraft($encounter, [
        'note_type' => 'general',
        'content' => ['text' => 'Patient looks fine'],
    ]);

    $service->signNote($note);

    expect(fn () => $service->updateDraft($note, ['content' => ['text' => 'Changed']]))
        ->toThrow(ValidationException::class);
});
