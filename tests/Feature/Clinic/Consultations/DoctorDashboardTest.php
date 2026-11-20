<?php

use App\AppointmentStatus;
use App\ClinicRole;
use App\Models\ClinicalEncounter;
use App\Models\ClinicQueueItem;
use App\Models\PatientAlert;
use App\QueueItemStatus;
use App\Services\Reception\ReceptionCheckIn;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\ReceptionFixture;

/*
 * The Doctor Dashboard (Today): the practitioner's day from the one queue,
 * beside their appointments panel for a chosen day; and a consultation started
 * from Patient 360 for a patient checked in today runs through their queue
 * entry, so the visit, the queue and the encounter stay one record.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-15 10:00', 'Asia/Kolkata'));
    $this->f = ReceptionFixture::make();
    [$this->doctorUser, $this->doctorSession] = $this->f->memberWithRole(ClinicRole::Doctor);
    $this->me = $this->f->doctor('Dr. Me', $this->doctorUser);
    $this->asDoctor = fn () => $this->actingAs($this->doctorUser)->withSession($this->doctorSession);
    $this->patient = $this->f->patient('Ravi', 'Kumar');
    $this->dashboard = function (array $query = []) {
        $props = null;
        ($this->asDoctor)()->get(route('clinic.consultations.index', $query))->assertOk()
            ->assertInertia(function (Assert $page) use (&$props) {
                $page->component('ClinicPortal/Today/Index', false);
                $props = $page->toArray()['props'];
            });

        return $props;
    };
});

test('the doctor\'s home is the dashboard: the day\'s queue beside their own appointments, with alerts', function () {
    app(ReceptionCheckIn::class)->checkIn($this->f->clinic, $this->f->branch, $this->patient, doctor: $this->me);
    PatientAlert::create(['clinic_id' => $this->f->clinic->id, 'patient_id' => $this->patient->id, 'type' => 'manual', 'severity' => 'critical', 'description' => 'On blood thinners']);
    $this->f->appointment($this->patient, $this->me, CarbonImmutable::parse('2026-10-15 11:30', 'Asia/Kolkata'));
    $this->f->appointment($this->f->patient('Other', 'Doctor'), $this->f->doctorA, CarbonImmutable::parse('2026-10-15 12:00', 'Asia/Kolkata'));
    $this->f->appointment($this->f->patient('Gone', 'Away'), $this->me, CarbonImmutable::parse('2026-10-15 12:30', 'Asia/Kolkata'), AppointmentStatus::Cancelled);

    ($this->asDoctor)()->get('/clinic/dashboard')->assertRedirect(route('clinic.consultations.index'));
    $props = ($this->dashboard)();

    expect($props['today']['waiting'])->toHaveCount(1)
        ->and($props['today']['waiting'][0]['patient']['uhid'])->toBe($this->patient->uhid)
        ->and($props['today']['waiting'][0]['links']['start'])->not->toBeNull()
        ->and($props['schedule']['date'])->toBe('2026-10-15')
        // Only this doctor's bookings, cancelled ones left out.
        ->and($props['schedule']['appointments'])->toHaveCount(1)
        ->and($props['schedule']['appointments'][0]['patient']['uhid'])->toBe($this->patient->uhid)
        ->and(collect($props['schedule']['appointments'][0]['safety'])->pluck('label'))->toContain('On blood thinners')
        ->and($props['links']['calendar'])->toContain('date=2026-10-15');
});

test('the appointments panel follows the chosen day, and the queue stays today\'s', function () {
    app(ReceptionCheckIn::class)->checkIn($this->f->clinic, $this->f->branch, $this->patient, doctor: $this->me);
    $this->f->appointment($this->f->patient('Next', 'Day'), $this->me, CarbonImmutable::parse('2026-10-16 09:30', 'Asia/Kolkata'));

    $props = ($this->dashboard)(['date' => '2026-10-16']);

    expect($props['schedule']['date'])->toBe('2026-10-16')
        ->and($props['schedule']['today'])->toBe('2026-10-15')
        ->and(collect($props['schedule']['appointments'])->pluck('patient.name')->all())->toBe(['Next Day'])
        ->and($props['today']['waiting'])->toHaveCount(1);
    // A malformed or far-off date falls back to today.
    expect(($this->dashboard)(['date' => 'not-a-date'])['schedule']['date'])->toBe('2026-10-15')
        ->and(($this->dashboard)(['date' => '2030-01-01'])['schedule']['date'])->toBe('2026-10-15');
});

test('starting from Patient 360 for a checked-in patient runs through their queue entry: one visit, one encounter, no longer waiting', function () {
    $item = app(ReceptionCheckIn::class)->checkIn($this->f->clinic, $this->f->branch, $this->patient, doctor: $this->me);

    ($this->asDoctor)()->post(route('clinic.consultations.start-for-patient', $this->patient))->assertRedirect()->assertSessionHasNoErrors();
    // A second start (a double click, the dashboard's Resume) opens the same consultation.
    ($this->asDoctor)()->post(route('clinic.consultations.start-for-patient', $this->patient))->assertRedirect();

    $encounter = ClinicalEncounter::withoutGlobalScopes()->where('patient_id', $this->patient->id)->sole();
    $item = $item->fresh();
    expect($item->encounter_id)->toBe($encounter->id)
        ->and($item->status)->toBe(QueueItemStatus::InConsultation)
        ->and($encounter->patient_visit_id)->toBe($item->patient_visit_id)
        ->and($encounter->doctor_id)->toBe($this->me->id)
        ->and(($this->dashboard)()['today']['waiting'])->toHaveCount(0)
        ->and(($this->dashboard)()['today']['in_consultation'][0]['encounter']['id'])->toBe($encounter->id);
});

test('a patient waiting for another doctor is not taken from Patient 360, and nothing is opened', function () {
    app(ReceptionCheckIn::class)->checkIn($this->f->clinic, $this->f->branch, $this->patient, doctor: $this->f->doctorA);

    ($this->asDoctor)()->post(route('clinic.consultations.start-for-patient', $this->patient))
        ->assertSessionHasErrors(['consultation' => 'This patient is waiting for another doctor today. Ask the front desk to reassign them first.']);

    expect(ClinicalEncounter::withoutGlobalScopes()->count())->toBe(0)
        ->and(ClinicQueueItem::query()->sole()->status)->toBe(QueueItemStatus::Waiting);
});

test('a patient not checked in today gets a consultation of its own, with no visit', function () {
    ($this->asDoctor)()->post(route('clinic.consultations.start-for-patient', $this->patient))->assertRedirect()->assertSessionHasNoErrors();

    expect(ClinicalEncounter::withoutGlobalScopes()->sole()->patient_visit_id)->toBeNull();
});

test('a finished consultation does not come back as waiting', function () {
    $item = app(ReceptionCheckIn::class)->checkIn($this->f->clinic, $this->f->branch, $this->patient, doctor: $this->me);
    ($this->asDoctor)()->post(route('clinic.consultations.start-for-patient', $this->patient));
    $encounter = ClinicalEncounter::withoutGlobalScopes()->sole();
    ($this->asDoctor)()->patchJson("/clinic/consultations/{$encounter->id}/draft", ['lock_version' => 0, 'chief_complaint' => 'Pain'])->assertOk();
    ($this->asDoctor)()->post("/clinic/consultations/{$encounter->id}/finalize")->assertSessionHasNoErrors();

    $today = ($this->dashboard)()['today'];
    expect($item->fresh()->status)->toBe(QueueItemStatus::Completed)
        ->and($today['waiting'])->toHaveCount(0)
        ->and($today['completed'][0]['encounter']['status'])->toBe('signed');
    // Starting again from Patient 360 opens a new, unlinked consultation; it never re-queues the finished visit.
    ($this->asDoctor)()->post(route('clinic.consultations.start-for-patient', $this->patient));
    expect($item->fresh()->status)->toBe(QueueItemStatus::Completed);
});
