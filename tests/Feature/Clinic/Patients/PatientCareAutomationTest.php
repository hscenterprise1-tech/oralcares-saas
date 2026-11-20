<?php

use App\Models\ClinicalEncounter;
use App\Models\Notification;
use App\Models\PatientDoctorAssignment;
use App\Models\PatientProcedure;
use App\Models\User;
use App\Services\Patient\ClinicMorningDigest;
use App\Services\Patient\PatientAutomation;
use App\Shared\Notification\Models\EnterpriseNotification;
use App\Support\ClinicClock;
use App\UserType;
use Carbon\CarbonImmutable;
use Tests\Support\ReceptionFixture;

/**
 * Group 3 automation: the first consultation assigns a doctor, a procedure
 * that was done sends its care advice, and doctors and the owner get one
 * morning summary a day.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-26 08:30', 'Asia/Kolkata'));
    $this->f = ReceptionFixture::make();
    $this->doctorUser = User::factory()->create(['user_type' => UserType::ClinicUser]);
    $this->doctor = $this->f->doctor('Dr. Kiran Rao', $this->doctorUser);
    $this->patient = $this->f->patient('Meera', 'Iyer');
    $this->encounter = fn (array $attributes = []) => ClinicalEncounter::query()->withoutGlobalScopes()->forceCreate([
        'clinic_id' => $this->f->clinic->id, 'branch_id' => $this->f->branch->id, 'patient_id' => $this->patient->id, 'doctor_id' => $this->doctor->id,
        'encounter_number' => 'ENC-'.fake()->unique()->numerify('######'), 'status' => 'in_progress', 'started_at' => now(), ...$attributes,
    ]);
    $this->procedure = fn (ClinicalEncounter $encounter, string $name, string $status) => PatientProcedure::query()->create([
        'organization_id' => $this->f->clinic->organization_id, 'clinic_id' => $this->f->clinic->id, 'encounter_id' => $encounter->id,
        'patient_id' => $this->patient->id, 'name' => $name, 'status' => $status,
    ]);
    $this->aftercare = fn () => Notification::query()->where('event_key', 'like', 'aftercare:%')->pluck('event_key')->all();
});

test('a clinic that turned assignment on gets the first consultation\'s doctor assigned to a patient who has none, with history', function () {
    $this->f->clinic->update(['communication_config' => ['auto_assign_doctor' => true]]);
    ($this->encounter)();

    expect($this->patient->fresh()->current_practitioner_id)->toBe($this->doctor->id)
        ->and(PatientDoctorAssignment::query()->where('patient_id', $this->patient->id)->value('reason'))->toBe(PatientAutomation::ASSIGN_REASON);
});

test('a patient who already has a doctor keeps them, and assignment is off unless the clinic turns it on', function () {
    $other = $this->f->patient('Ravi', 'Kumar');
    ($this->encounter)(['patient_id' => $other->id]);
    expect($other->fresh()->current_practitioner_id)->toBeNull();

    $this->f->clinic->update(['communication_config' => ['auto_assign_doctor' => true]]);
    $this->patient->forceFill(['current_practitioner_id' => $this->f->doctorB->id])->save();
    ($this->encounter)();
    expect($this->patient->fresh()->current_practitioner_id)->toBe($this->f->doctorB->id);
});

test('nothing is assigned when no one can be recorded as assigning', function () {
    $this->f->clinic->update(['communication_config' => ['auto_assign_doctor' => true]]);
    $other = $this->f->patient('Ravi', 'Kumar');
    ($this->encounter)(['patient_id' => $other->id, 'doctor_id' => $this->f->doctorA->id]);
    expect($other->fresh()->current_practitioner_id)->toBeNull();
});

test('a completed extraction sends its care advice once; a planned one sends nothing until it is done', function () {
    $encounter = ($this->encounter)();
    ($this->procedure)($encounter, 'Extraction of 46', 'completed');
    expect(($this->aftercare)())->toBe(["aftercare:{$encounter->id}:extraction"]);

    $later = ($this->encounter)();
    $planned = ($this->procedure)($later, 'Root canal treatment', 'planned');
    expect(($this->aftercare)())->toHaveCount(1);

    $planned->update(['status' => 'completed']);
    expect(($this->aftercare)())->toContain("aftercare:{$later->id}:root_canal")->toHaveCount(2);
});

test('the morning summary goes once a day to a doctor with work and to the owner', function () {
    $this->f->appointment($this->patient, $this->doctor, ClinicClock::now()->setTime(11, 0));
    $digest = app(ClinicMorningDigest::class);

    expect($digest->run($this->f->clinic))->toBe(2)
        ->and(EnterpriseNotification::query()->where('notifiable_id', $this->doctorUser->id)->pluck('title')->all())->toBe(['Good morning — 1 booking today'])
        ->and(EnterpriseNotification::query()->where('notifiable_id', $this->f->owner->id)->count())->toBe(1)
        // Dr. Asha Mehta has nothing today and hears nothing; a second run sends nothing more.
        ->and($digest->run($this->f->clinic))->toBe(0);
});

test('a clinic that turned the morning summary off gets none', function () {
    $this->f->appointment($this->patient, $this->doctor, ClinicClock::now()->setTime(11, 0));
    $this->f->clinic->update(['communication_config' => ['morning_digest' => false]]);

    expect(app(ClinicMorningDigest::class)->run($this->f->clinic->fresh()))->toBe(0)
        ->and(EnterpriseNotification::query()->count())->toBe(0);
});
