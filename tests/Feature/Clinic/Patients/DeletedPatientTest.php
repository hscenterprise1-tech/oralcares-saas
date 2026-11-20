<?php

use App\AppointmentStatus;
use App\ClinicRole;
use App\Models\Appointment;
use App\Models\DeletionRequest;
use App\Models\PatientFollowUp;
use App\Models\PatientTimelineEvent;
use App\PatientStatus;
use App\Services\Governance\Actions\DeletePatient;
use App\Services\Patient\FollowUps;
use App\Services\Patient\PatientBranchActivityRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\ReceptionFixture;

/**
 * Deleting a patient: a governed soft delete. Staff who edit patients ask,
 * the owner decides (or deletes directly), with a reason. The record leaves
 * every working list and no longer opens, upcoming bookings and follow-ups
 * are closed, and nothing is erased from the database.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-26 10:00', 'Asia/Kolkata'));
    $this->f = ReceptionFixture::make();
    $this->patient = $this->f->patient('Meera', 'Iyer');
    app(PatientBranchActivityRecorder::class)->record($this->f->clinic->id, $this->patient->id, $this->f->branch->id, now(), 'registration');
    $this->asOwner = fn () => $this->actingAs($this->f->owner)->withSession(withOwnerMode($this->f->session));
    $this->asRole = function (ClinicRole $role) {
        [$user, $session] = $this->f->memberWithRole($role);

        return fn () => $this->actingAs($user)->withSession($session);
    };
    $this->delete = fn (array $overrides = []) => ['action' => 'patient.delete', 'target_id' => $this->patient->id, 'reason' => 'Duplicate registration by mistake', 'payload' => [], ...$overrides];
});

test('the owner deletes directly: the record is kept but leaves the list and no longer opens', function () {
    ($this->asOwner)()->post('/clinic/approvals/direct', ($this->delete)())->assertSessionHasNoErrors();

    expect($this->patient->fresh()->status)->toBe(PatientStatus::Deleted)
        ->and(DeletionRequest::sole())->status->toBe(DeletionRequest::EXECUTED)->direct->toBeTrue()
        ->and(PatientTimelineEvent::query()->where('patient_id', $this->patient->id)->where('event_type', 'patient_deleted')->exists())->toBeTrue();

    ($this->asOwner)()->get("/clinic/patients/{$this->patient->id}")->assertRedirect(route('clinic.patients.index'));
    ($this->asRole)(ClinicRole::Receptionist)()->getJson('/clinic/reception/check-in/search?q=Meera')->assertOk()->assertJsonCount(0, 'patients');
});

test('reception asks; nothing changes until the owner approves', function () {
    ($this->asRole)(ClinicRole::Receptionist)()->post('/clinic/approvals', ($this->delete)())->assertSessionHasNoErrors();
    $request = DeletionRequest::sole();
    expect($request->status)->toBe(DeletionRequest::PENDING)->and($this->patient->fresh()->status)->toBe(PatientStatus::Active);

    ($this->asOwner)()->post("/clinic/approvals/{$request->id}/approve")->assertSessionHasNoErrors();
    expect($request->fresh()->status)->toBe(DeletionRequest::EXECUTED)->and($this->patient->fresh()->status)->toBe(PatientStatus::Deleted);
});

test('doctors may not ask, only the owner deletes directly, and a reason is needed', function () {
    ($this->asRole)(ClinicRole::Doctor)()->post('/clinic/approvals', ($this->delete)())->assertForbidden();
    ($this->asRole)(ClinicRole::Receptionist)()->post('/clinic/approvals/direct', ($this->delete)())->assertForbidden();
    ($this->asOwner)()->post('/clinic/approvals/direct', ($this->delete)(['reason' => 'Dup']))->assertSessionHasErrors();

    expect(DeletionRequest::count())->toBe(0)->and($this->patient->fresh()->status)->toBe(PatientStatus::Active);
});

test('deleting cancels upcoming bookings and open follow-ups and keeps past ones; a merged record cannot be deleted', function () {
    $past = $this->f->appointment($this->patient, $this->f->doctorA, now()->subDays(3), AppointmentStatus::Completed);
    $upcoming = $this->f->appointment($this->patient, $this->f->doctorA, now()->addDays(2));
    $followUp = app(FollowUps::class)->add($this->patient, '2026-11-02', 'Review', null, $this->f->branch->id);

    ($this->asOwner)()->post('/clinic/approvals/direct', ($this->delete)())->assertSessionHasNoErrors();

    expect(Appointment::find($upcoming->id))->status->toBe(AppointmentStatus::Cancelled)
        ->and(Appointment::find($upcoming->id)->cancellation_reason)->toBe(DeletePatient::CANCELLATION_REASON)
        ->and(Appointment::find($past->id)->status)->toBe(AppointmentStatus::Completed)
        ->and(PatientFollowUp::query()->withoutGlobalScopes()->find($followUp->id)->status)->not->toBe(PatientFollowUp::DUE);

    $merged = $this->f->patient('Old', 'Record', ['status' => PatientStatus::Merged]);
    ($this->asOwner)()->post('/clinic/approvals/direct', ($this->delete)(['target_id' => $merged->id]))->assertSessionHasErrors('governance');
    expect($merged->fresh()->status)->toBe(PatientStatus::Merged);
});

test('the deceased-status removal turns recorded deaths into current patients, keeps the date on the timeline, and closes open death requests', function () {
    DB::table('patients')->where('id', $this->patient->id)->update(['status' => 'deceased', 'date_of_death' => '2026-10-20', 'death_recorded_at' => now(), 'death_recorded_by' => $this->f->owner->id]);
    $open = DeletionRequest::query()->forceCreate([
        'clinic_id' => $this->f->clinic->id, 'patient_id' => $this->patient->id, 'action_type' => 'patient.record_death', 'target_type' => 'patient',
        'target_id' => $this->patient->id, 'target_label' => 'Patient Meera Iyer', 'reason' => 'Family informed the clinic', 'status' => DeletionRequest::PENDING,
        'requester_user_id' => $this->f->owner->id, 'target_fingerprint' => 'x', 'open_key' => "patient.record_death:{$this->patient->id}", 'thread' => [],
    ]);

    $migration = require database_path('migrations/2026_11_02_165411_remove_patient_deceased_status.php');
    $migration->up();

    $patient = DB::table('patients')->where('id', $this->patient->id)->first();
    $event = PatientTimelineEvent::query()->where('patient_id', $this->patient->id)->where('event_type', 'patient_death_cleared')->sole();
    expect($patient->status)->toBe('active')
        ->and($patient->date_of_death)->toBeNull()
        ->and($event->context['date_of_death'])->toStartWith('2026-10-20')
        ->and($open->fresh()->status)->toBe('cancelled');

    $migration->down();
    expect(DB::table('patients')->where('id', $this->patient->id)->value('status'))->toBe('deceased')
        ->and($open->fresh()->status)->toBe(DeletionRequest::PENDING);
});
