<?php

use App\Models\DeletionRequest;
use App\Models\Patient;
use App\PatientStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\ReceptionFixture;

/**
 * Removing patient archiving converts what an archive left behind without
 * losing anything: an archived patient becomes a current patient with the
 * archive's details on their timeline, an open archive request is closed
 * with a note, decided requests stay as history, and rolling back restores
 * exactly what was there.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-25 10:00', 'Asia/Kolkata'));
    $this->f = ReceptionFixture::make();
    $this->migration = require database_path('migrations/2026_10_24_191407_remove_patient_archive.php');
    // Back to the schema before the removal, as an environment that still has archives.
    $this->migration->down();

    $this->archived = $this->f->patient('Old', 'Record');
    DB::table('patients')->where('id', $this->archived->id)->update([
        'status' => 'archived', 'archived_at' => '2026-10-10 09:00:00', 'archived_by' => $this->f->owner->id, 'archive_reason' => 'Moved abroad',
    ]);
    $this->current = $this->f->patient('Asha', 'Current');
    $this->merged = $this->f->patient('Gone', 'Duplicate', ['status' => PatientStatus::Merged]);

    $request = fn (Patient $patient, string $status, ?string $openKey) => DB::table('deletion_requests')->insertGetId([
        'clinic_id' => $this->f->clinic->id, 'patient_id' => $patient->id, 'requester_user_id' => $this->f->owner->id, 'action_type' => 'patient.archive',
        'target_type' => 'patient', 'target_id' => $patient->id, 'target_label' => $patient->full_name, 'target_fingerprint' => str_repeat('a', 64),
        'reason' => 'Registered by mistake', 'status' => $status, 'open_key' => $openKey, 'thread' => json_encode([]), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->openRequest = $request($this->current, DeletionRequest::PENDING, "patient.archive:patient:{$this->current->id}");
    $this->decidedRequest = $request($this->archived, DeletionRequest::EXECUTED, null);
});

afterEach(function () {
    // Leave the schema as the application expects it.
    if (Schema::hasColumn('patients', 'archived_at')) {
        $this->migration->up();
    }
});

test('archived patients become current patients, with the archive kept on their timeline, and open archive requests are closed', function () {
    $this->migration->convert();
    // Running it again changes nothing more.
    $this->migration->convert();

    expect(DB::table('patients')->where('id', $this->archived->id)->value('status'))->toBe('active')
        ->and(Patient::find($this->current->id)->status)->toBe(PatientStatus::Active)
        ->and(Patient::find($this->merged->id)->status)->toBe(PatientStatus::Merged)
        ->and(Patient::count())->toBe(3)
        ->and(DB::table('patient_timeline_events')->where('event_type', 'patient_archive_cleared')->count())->toBe(1);

    $event = DB::table('patient_timeline_events')->where('event_type', 'patient_archive_cleared')->sole();
    expect($event->patient_id)->toBe($this->archived->id)
        ->and(json_decode($event->context, true))->toMatchArray(['previous_status' => 'archived', 'archived_by' => $this->f->owner->id, 'archive_reason' => 'Moved abroad']);

    $open = DB::table('deletion_requests')->find($this->openRequest);
    expect($open->status)->toBe(DeletionRequest::CANCELLED)->and($open->open_key)->toBeNull()
        ->and(collect(json_decode($open->thread, true))->last()['note'])->toContain('Patient archiving was removed')
        // A request already carried out stays as it was: it is history.
        ->and(DB::table('deletion_requests')->find($this->decidedRequest)->status)->toBe(DeletionRequest::EXECUTED);
});

test('rolling back restores exactly the archive that was there', function () {
    $this->migration->convert();
    $this->migration->restore();

    $restored = DB::table('patients')->where('id', $this->archived->id)->first();
    expect($restored->status)->toBe('archived')
        ->and($restored->archive_reason)->toBe('Moved abroad')
        ->and((int) $restored->archived_by)->toBe($this->f->owner->id)
        ->and(DB::table('patients')->where('id', $this->current->id)->value('status'))->toBe('active')
        ->and(DB::table('patient_timeline_events')->where('event_type', 'patient_archive_cleared')->exists())->toBeFalse();

    $reopened = DB::table('deletion_requests')->find($this->openRequest);
    expect($reopened->status)->toBe(DeletionRequest::PENDING)
        ->and($reopened->open_key)->toBe("patient.archive:patient:{$this->current->id}")
        ->and(json_decode($reopened->thread, true))->toBe([]);
});

test('after the migration the archive columns and the archive-only permission are gone, and rolling back brings them back', function () {
    $this->migration->up();

    expect(Schema::hasColumn('patients', 'archived_at'))->toBeFalse()
        ->and(Schema::hasColumn('patients', 'archived_by'))->toBeFalse()
        ->and(Schema::hasColumn('patients', 'archive_reason'))->toBeFalse()
        ->and(Schema::hasColumn('patients', 'status'))->toBeTrue()
        ->and(DB::table(config('permission.table_names.permissions'))->where('name', 'patient.delete')->exists())->toBeFalse();

    $this->migration->down();
    expect(Schema::hasColumns('patients', ['archived_at', 'archived_by', 'archive_reason']))->toBeTrue()
        ->and(DB::table(config('permission.table_names.permissions'))->where('name', 'patient.delete')->exists())->toBeTrue();
});

test('a formerly archived patient is found in the directory, opens in Patient 360, and the archive history still reads', function () {
    DB::table('patient_timeline_events')->insert([
        'organization_id' => $this->f->clinic->organization_id, 'clinic_id' => $this->f->clinic->id, 'patient_id' => $this->archived->id,
        'actor_id' => $this->f->owner->id, 'event_type' => 'patient_archived', 'context' => json_encode(['label' => 'Old Record', 'reason' => 'Moved abroad']),
        'created_at' => now()->subDays(15), 'updated_at' => now()->subDays(15),
    ]);
    $this->migration->convert();
    $asOwner = fn () => $this->actingAs($this->f->owner)->withSession($this->f->session);

    $asOwner()->getJson('/clinic/patients/directory?q=Old')->assertOk()->assertJsonPath('data.0.id', $this->archived->id)->assertJsonPath('data.0.status', 'active');
    $asOwner()->get("/clinic/patients/{$this->archived->id}")->assertOk();

    $timeline = collect($asOwner()->getJson("/clinic/patients/{$this->archived->id}/360/timeline")->assertOk()->json('data'))->keyBy('type');
    expect($timeline['patient_archived']['title'])->toBe('Patient archived')
        ->and($timeline['patient_archive_cleared']['title'])->toBe('Returned to current patients');

    // The cancelled request and the one carried out before the removal are listed under a readable name.
    $requests = collect($asOwner()->getJson("/clinic/patients/{$this->current->id}/360/requests")->assertOk()->json('data'));
    expect($requests->pluck('action')->all())->toBe(['Archive patient (no longer available)'])
        ->and($requests->first()['status'])->toBe(DeletionRequest::CANCELLED);
    $asOwner()->get('/clinic/approvals?tab=decided')->assertOk()->assertInertia(fn ($page) => $page
        ->where('requests.data.0.action_label', 'Archive patient (no longer available)')
        ->where('requests.data.1.action_label', 'Archive patient (no longer available)'));
    // Deciding a closed archive request is refused cleanly, not an error.
    $this->actingAs($this->f->owner)->withSession($this->f->session)->post("/clinic/approvals/{$this->openRequest}/approve")->assertSessionHasErrors('governance');
});

test('rolling back does not undo a change made after the migration, such as a merge', function () {
    $this->migration->convert();
    DB::table('patients')->where('id', $this->archived->id)->update(['status' => 'merged']);

    $this->migration->restore();

    $row = DB::table('patients')->where('id', $this->archived->id)->first();
    expect($row->status)->toBe('merged')->and($row->archive_reason)->toBe('Moved abroad');
});
