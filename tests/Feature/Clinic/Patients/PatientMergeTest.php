<?php

use App\ClinicRole;
use App\Models\Appointment;
use App\Models\ConsentRecord;
use App\Models\DeletionRequest;
use App\Models\PatientMedicalHistory;
use App\Models\PatientMerge;
use App\Models\PatientTimelineEvent;
use App\Models\SecurityAuditLog;
use App\PatientStatus;
use App\Services\ClinicContext;
use App\Services\OwnerMode\OwnerMode;
use Carbon\CarbonImmutable;
use Tests\Support\ReceptionFixture;

/**
 * Merging a duplicate patient: a governed action. Staff with patient.merge
 * ask, the owner decides in Owner Mode, the history moves to the patient
 * kept, the duplicate stays as `merged`, and an immutable ledger records it.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-03 10:00', 'Asia/Kolkata'));
    $this->f = ReceptionFixture::make();
    $this->keep = $this->f->patient('Asha', 'Rao');
    $this->duplicate = $this->f->patient('Asha', 'Rao');
    [$receptionUser, $receptionSession] = $this->f->memberWithRole(ClinicRole::Receptionist);
    [$doctorUser, $doctorSession] = $this->f->memberWithRole(ClinicRole::Doctor);
    $this->asReception = fn () => $this->actingAs($receptionUser)->withSession($receptionSession);
    $this->asDoctor = fn () => $this->actingAs($doctorUser)->withSession($doctorSession);
    $this->asOwner = fn () => $this->actingAs($this->f->owner)->withSession($this->f->session);
    $this->asOwnerInOwnerMode = fn () => $this->actingAs($this->f->owner)->withSession([...$this->f->session, OwnerMode::SESSION_KEY => [
        'user_id' => $this->f->owner->id, 'clinic_id' => $this->f->clinic->id, 'membership_id' => $this->f->session[ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID],
        'method' => 'password', 'activated_at' => now()->getTimestamp(), 'last_seen_at' => now()->getTimestamp(),
    ]]);
    $this->merge = fn (int $survivorId, string $reason = 'Registered twice at the front desk') => [
        'action' => 'patient.merge', 'target_id' => $this->duplicate->id, 'reason' => $reason, 'payload' => ['survivor_patient_id' => $survivorId],
    ];
});

test('reception asks, the owner approves in Owner Mode, and the history moves to the patient kept', function () {
    $visit = $this->f->appointment($this->duplicate, $this->f->doctorA, now()->addDay());
    $consent = ConsentRecord::create(['clinic_id' => $this->f->clinic->id, 'patient_id' => $this->duplicate->id, 'consent_type' => 'treatment', 'status' => ConsentRecord::GRANTED, 'capture_method' => 'written', 'captured_at' => now()]);
    PatientMedicalHistory::create(['clinic_id' => $this->f->clinic->id, 'patient_id' => $this->duplicate->id, 'allergies' => ['Penicillin']]);
    PatientMedicalHistory::create(['clinic_id' => $this->f->clinic->id, 'patient_id' => $this->keep->id, 'allergies' => ['Latex']]);

    ($this->asReception)()->post('/clinic/approvals', ($this->merge)($this->keep->id))->assertSessionHasNoErrors();
    $request = DeletionRequest::sole();
    expect($request)->status->toBe(DeletionRequest::PENDING)->payload->toBe(['survivor_patient_id' => $this->keep->id])
        ->and($this->duplicate->fresh()->status)->toBe(PatientStatus::Active)
        ->and(Appointment::find($visit->id)->patient_id)->toBe($this->duplicate->id);

    ($this->asOwner)()->post("/clinic/approvals/{$request->id}/approve")->assertForbidden();
    ($this->asOwnerInOwnerMode)()->post("/clinic/approvals/{$request->id}/approve")->assertSessionHasNoErrors();

    $ledger = PatientMerge::sole();
    expect($request->fresh()->status)->toBe(DeletionRequest::EXECUTED)
        ->and($this->duplicate->fresh()->status)->toBe(PatientStatus::Merged)
        ->and(Appointment::find($visit->id)->patient_id)->toBe($this->keep->id)
        ->and(ConsentRecord::find($consent->id)->patient_id)->toBe($this->keep->id)
        ->and(PatientMedicalHistory::where('patient_id', $this->keep->id)->sole()->allergies)->toEqualCanonicalizing(['Latex', 'Penicillin'])
        ->and($ledger)->source_patient_id->toBe($this->duplicate->id)->survivor_patient_id->toBe($this->keep->id)->deletion_request_id->toBe($request->id)
        ->and($ledger->moved['appointments'])->toBe([$visit->id])
        ->and(SecurityAuditLog::where('event', 'patient.merged')->count())->toBe(1)
        ->and(PatientTimelineEvent::where('patient_id', $this->keep->id)->where('event_type', 'patient_merged')->exists())->toBeTrue();

    // The duplicate's page now leads to the patient kept.
    ($this->asOwner)()->get("/clinic/patients/{$this->duplicate->id}")->assertRedirect(route('clinic.patients.show', $this->keep->id));
});

test('the merge ledger can never be changed or removed', function () {
    ($this->asOwnerInOwnerMode)()->post('/clinic/approvals/direct', ($this->merge)($this->keep->id))->assertSessionHasNoErrors();
    $ledger = PatientMerge::sole();

    expect(fn () => $ledger->update(['survivor_patient_id' => $this->duplicate->id]))->toThrow(LogicException::class)
        ->and(fn () => $ledger->delete())->toThrow(LogicException::class)
        ->and(DeletionRequest::sole())->direct->toBeTrue()->status->toBe(DeletionRequest::EXECUTED);
});

test('only members with patient.merge may ask, and only the owner in Owner Mode may act directly', function () {
    ($this->asDoctor)()->post('/clinic/approvals', ($this->merge)($this->keep->id))->assertForbidden();
    ($this->asReception)()->post('/clinic/approvals/direct', ($this->merge)($this->keep->id))->assertForbidden();
    ($this->asOwner)()->post('/clinic/approvals/direct', ($this->merge)($this->keep->id))->assertForbidden();

    expect(DeletionRequest::count())->toBe(0)->and(PatientMerge::count())->toBe(0)
        ->and($this->duplicate->fresh()->status)->toBe(PatientStatus::Active);
});

test('a merge needs a different, working patient of the same clinic to keep', function () {
    $other = ReceptionFixture::make('other-clinic');
    $elsewhere = $other->patient('Asha', 'Rao');
    $archived = $this->f->patient('Old', 'Record', ['status' => PatientStatus::Archived]);

    ($this->asReception)()->post('/clinic/approvals', ($this->merge)($this->duplicate->id))->assertSessionHasErrors('governance');
    ($this->asReception)()->post('/clinic/approvals', ($this->merge)($elsewhere->id))->assertSessionHasErrors('governance');
    ($this->asReception)()->post('/clinic/approvals', ($this->merge)($archived->id))->assertSessionHasErrors('governance');
    ($this->asReception)()->post('/clinic/approvals', [...($this->merge)($this->keep->id), 'payload' => []])->assertSessionHasErrors('governance');

    // A payload is refused for an action that takes none.
    ($this->asOwnerInOwnerMode)()->post('/clinic/approvals/direct', ['action' => 'patient.archive', 'target_id' => $this->duplicate->id, 'reason' => 'Registered twice at the front desk', 'payload' => ['x' => 1]])
        ->assertSessionHasErrors('governance');

    expect(DeletionRequest::count())->toBe(0)->and($elsewhere->fresh()->clinic_id)->toBe($other->clinic->id)
        ->and($this->duplicate->fresh()->status)->toBe(PatientStatus::Active);
});

test('a patient already merged leaves the working lists and cannot be merged again', function () {
    ($this->asOwnerInOwnerMode)()->post('/clinic/approvals/direct', ($this->merge)($this->keep->id))->assertSessionHasNoErrors();
    $third = $this->f->patient('Asha', 'Rao');

    ($this->asOwnerInOwnerMode)()->post('/clinic/approvals/direct', ($this->merge)($third->id))->assertSessionHasErrors('governance');
    ($this->asOwner)()->getJson('/clinic/patients/directory?q=Asha')->assertOk()->assertJsonCount(2, 'data')
        ->assertJsonMissing(['id' => $this->duplicate->id]);
});
