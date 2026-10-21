<?php

use App\Models\Appointment;
use App\Models\Branch;
use App\Models\ClinicalConsultation;
use App\Models\ClinicalEncounter;
use App\Models\ClinicEmployee;
use App\Models\ClinicSpecialty;
use App\Models\DoctorAvailability;
use App\Models\PatientAlert;
use App\Models\PatientBranchActivity;
use App\Models\PatientDataConflict;
use App\Models\PatientMedicalHistory;
use App\Models\PatientProfile;
use App\Models\PatientSpecialty;
use App\Models\PatientVisit;
use App\Models\Practitioner;
use App\Services\EncounterManagementService;
use App\Services\Patient\PatientBranchActivityRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\ReceptionFixture;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-03 10:00', 'Asia/Kolkata'));
    $this->f = ReceptionFixture::make();
    $this->patient = $this->f->patient('Neha', 'Joshi', ['alternate_phone' => null]);
    $this->backfill = fn (array $options = []) => $this->artisan('patients:backfill-foundation', ['--clinic' => [$this->f->clinic->id], ...$options]);
});

test('the backfill consolidates profile fields without overwriting, and records every disagreement', function () {
    $profile = PatientProfile::query()->withoutGlobalScopes()->create(['clinic_id' => $this->f->clinic->id, 'patient_id' => $this->patient->id, 'pincode' => '560001']);
    DB::table('patient_profiles')->where('id', $profile->id)->update(['alternate_mobile' => '9000000009', 'secondary_phone' => '9000000008', 'first_name' => 'Nehha']);

    ($this->backfill)()->assertSuccessful();

    expect($this->patient->fresh()->alternate_phone)->toBe('9000000009')
        ->and(DB::table('patient_profiles')->where('id', $profile->id)->value('postal_code'))->toBe('560001')
        ->and(PatientDataConflict::query()->orderBy('field')->get(['field', 'source', 'canonical_value', 'other_value'])->toArray())->toBe([
            ['field' => 'alternate_phone', 'source' => 'patient_profiles.secondary_phone', 'canonical_value' => '9000000009', 'other_value' => '9000000008'],
            ['field' => 'first_name', 'source' => 'patient_profiles.first_name', 'canonical_value' => 'Neha', 'other_value' => 'Nehha'],
        ]);

    // Running it again changes nothing and records nothing twice.
    ($this->backfill)()->assertSuccessful();
    expect(PatientDataConflict::count())->toBe(2);
});

test('the backfill folds legacy medical fields into the canonical ones', function () {
    $history = PatientMedicalHistory::query()->withoutGlobalScopes()->create(['clinic_id' => $this->f->clinic->id, 'patient_id' => $this->patient->id, 'medical_conditions' => ['Asthma']]);
    DB::table('patient_medical_histories')->where('id', $history->id)->update(['chronic_conditions' => json_encode(['asthma', 'Hypertension']), 'medical_alerts' => json_encode(['Fall risk'])]);
    PatientAlert::query()->withoutGlobalScopes()->create(['clinic_id' => $this->f->clinic->id, 'patient_id' => $this->patient->id, 'type' => 'manual', 'description' => 'Pacemaker', 'severity' => 'high']);

    ($this->backfill)()->assertSuccessful();
    ($this->backfill)()->assertSuccessful();

    expect($history->fresh()->medical_conditions)->toBe(['Asthma', 'Hypertension'])
        ->and(PatientAlert::query()->where('type', 'manual')->orderBy('description')->pluck('description')->all())->toBe(['Fall risk', 'Pacemaker']);
});

test('the backfill builds branch activity and specialty enrolments from existing records', function () {
    $this->f->clinic->update(['specialties' => ['dental']]);
    PatientBranchActivity::query()->withoutGlobalScopes()->delete();
    PatientSpecialty::query()->withoutGlobalScopes()->delete();
    DB::table('appointments')->insert(['organization_id' => $this->f->clinic->organization_id, 'clinic_id' => $this->f->clinic->id, 'branch_id' => $this->f->branch->id, 'patient_id' => $this->patient->id, 'practitioner_id' => $this->f->doctorA->id, 'scheduled_at' => now(), 'duration_minutes' => 15, 'status' => 'scheduled', 'created_at' => now()->subDays(3), 'updated_at' => now()]);

    ($this->backfill)(['--dry-run' => true])->assertSuccessful();
    expect(PatientBranchActivity::count())->toBe(0);

    ($this->backfill)()->assertSuccessful();

    expect(PatientBranchActivity::sole())->first_source->toBe('appointment')->first_seen_at->toDateString()->toBe(now()->subDays(3)->toDateString())
        ->and(PatientSpecialty::sole())->source->toBe('backfill')->clinic_specialty_id->toBe(ClinicSpecialty::sole()->id);
});

test('a patient with no recorded activity is attributed only when the clinic has a single branch', function () {
    PatientBranchActivity::query()->withoutGlobalScopes()->delete();

    ($this->backfill)()->assertSuccessful();
    expect(PatientBranchActivity::sole())->branch_id->toBe($this->f->branch->id)->first_source->toBe('backfill');

    // With a second branch nothing is assumed: the patient is listed for the clinic instead.
    PatientBranchActivity::query()->withoutGlobalScopes()->delete();
    Branch::factory()->create(['clinic_id' => $this->f->clinic->id, 'name' => 'Second']);
    ($this->backfill)()->assertSuccessful()->expectsOutputToContain('Needs the clinic owner');
    expect(PatientBranchActivity::count())->toBe(0);
});

test('a clinic with no configured specialty gets no enrolments, only an owner action', function () {
    ($this->backfill)()->assertSuccessful()->expectsOutputToContain('activate the clinic\'s specialties');

    expect(ClinicSpecialty::count())->toBe(0)->and(PatientSpecialty::count())->toBe(0);
});

test('the backfill copies a consultation\'s end time onto an encounter without one, never over existing timing', function () {
    $encounter = ClinicalEncounter::query()->withoutGlobalScopes()->create(['clinic_id' => $this->f->clinic->id, 'branch_id' => $this->f->branch->id, 'patient_id' => $this->patient->id, 'doctor_id' => $this->f->doctorA->id, 'encounter_number' => 'ENC-T-1', 'status' => 'completed', 'started_at' => now()->subMinutes(50)]);
    ClinicalConsultation::create(['encounter_id' => $encounter->id, 'doctor_id' => $this->f->doctorA->id, 'status' => 'completed', 'started_at' => now()->subHour(), 'ended_at' => now()]);

    ($this->backfill)()->assertSuccessful();

    expect($encounter->fresh()->started_at->equalTo(now()->subMinutes(50)))->toBeTrue()
        ->and($encounter->fresh()->ended_at->equalTo(now()))->toBeTrue();
});

test('an encounter is attributed to one practitioner, the clinic employee, and points at a real visit', function () {
    $this->actingAs($this->f->owner)->withSession($this->f->session);
    $encounter = app(EncounterManagementService::class)->startEncounter($this->patient, $this->f->branch, $this->f->doctorA);

    expect($encounter->doctor)->toBeInstanceOf(ClinicEmployee::class)->id->toBe($this->f->doctorA->id)
        ->and($encounter->doctor->practitionerProfile)->toBeInstanceOf(Practitioner::class)
        ->and($encounter->getAttributes())->not->toHaveKey('provider_id');

    $visit = PatientVisit::create(['clinic_id' => $this->f->clinic->id, 'branch_id' => $this->f->branch->id, 'patient_id' => $this->patient->id, 'visit_date' => today(), 'registration_type' => 'new', 'queue_status' => 'not_queued', 'visit_status' => 'registered']);
    $encounter->update(['patient_visit_id' => $visit->id]);
    expect($encounter->fresh()->visit->is($visit))->toBeTrue()
        ->and(fn () => DB::table('clinical_encounters')->where('id', $encounter->id)->update(['patient_visit_id' => 999999]))->toThrow(QueryException::class);
});

test('availability belongs to the canonical practitioner profile', function () {
    $availability = DoctorAvailability::query()->withoutGlobalScopes()->forceCreate([
        'organization_id' => $this->f->clinic->organization_id, 'branch_id' => $this->f->branch->id, 'doctor_profile_id' => $this->f->doctorA->practitionerProfile->id,
        'day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '13:00', 'is_available' => true,
    ]);

    expect($availability->doctorProfile)->toBeInstanceOf(Practitioner::class)->clinic_employee_id->toBe($this->f->doctorA->id);
});

test('visits, appointments and encounters record branch activity as they are created', function () {
    PatientBranchActivity::query()->withoutGlobalScopes()->delete();
    Appointment::create(['clinic_id' => $this->f->clinic->id, 'branch_id' => $this->f->branch->id, 'patient_id' => $this->patient->id, 'practitioner_id' => $this->f->doctorA->id, 'scheduled_at' => now()->addDay(), 'duration_minutes' => 15, 'status' => 'scheduled']);
    $this->travel(2)->hours();
    PatientVisit::create(['clinic_id' => $this->f->clinic->id, 'branch_id' => $this->f->branch->id, 'patient_id' => $this->patient->id, 'visit_date' => today(), 'registration_type' => 'repeat', 'queue_status' => 'not_queued', 'visit_status' => 'registered']);

    $activity = PatientBranchActivity::sole();
    expect($activity->first_source)->toBe('appointment')
        ->and($activity->last_seen_at->equalTo(now()))->toBeTrue()
        ->and($activity->first_seen_at->lessThan($activity->last_seen_at))->toBeTrue();
});

test('batched branch activity widens the dates and keeps the source of the earliest sighting, like single writes', function () {
    $recorder = app(PatientBranchActivityRecorder::class);
    $clinicId = $this->f->clinic->id;
    $branchId = $this->f->branch->id;
    $recorder->record($clinicId, $this->patient->id, $branchId, now()->subDays(5), 'visit');
    $row = fn () => PatientBranchActivity::query()->withoutGlobalScopes()->where('patient_id', $this->patient->id)->sole();

    // An earlier sighting takes over first_seen and its source; a later one only moves last_seen.
    $recorder->recordMany($clinicId, [['patient_id' => $this->patient->id, 'branch_id' => $branchId, 'first_at' => now()->subDays(9), 'last_at' => now()->subDays(1)]], 'appointment');
    expect($row())->first_source->toBe('appointment')
        ->and($row()->first_seen_at->toDateString())->toBe(now()->subDays(9)->toDateString())
        ->and($row()->last_seen_at->toDateString())->toBe(now()->subDays(1)->toDateString());

    $recorder->recordMany($clinicId, [['patient_id' => $this->patient->id, 'branch_id' => $branchId, 'first_at' => now()->subDays(3), 'last_at' => now()->subDays(2)]], 'billing');
    expect($row())->first_source->toBe('appointment')
        ->and($row()->first_seen_at->toDateString())->toBe(now()->subDays(9)->toDateString())
        ->and($row()->last_seen_at->toDateString())->toBe(now()->subDays(1)->toDateString())
        ->and(PatientBranchActivity::query()->withoutGlobalScopes()->count())->toBe(1);
});
