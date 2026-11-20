<?php

use App\AppointmentStatus;
use App\ClinicRole;
use App\Models\Appointment;
use App\Models\ClinicalProcedure;
use App\Models\ClinicQueue;
use App\Models\ClinicQueueItem;
use App\Models\Notification;
use App\Models\PatientFollowUp;
use App\Models\TreatmentPlan;
use App\PatientStatus;
use App\Services\BillingService;
use App\Services\Communication\PatientMessaging;
use App\Services\Patient\FollowUps;
use App\Services\Patient\PatientAutomation;
use App\Support\ClinicClock;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Tests\Support\ReceptionFixture;

/**
 * Patient automation: missed bookings become no-shows by themselves, a
 * completed dental plan books its recall, and a deleted patient receives no
 * new care.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-26 15:00', 'Asia/Kolkata'));
    $this->f = ReceptionFixture::make();
    $this->patient = $this->f->patient('Meera', 'Iyer');
    $this->automation = app(PatientAutomation::class);
    $this->plan = fn () => TreatmentPlan::query()->create([
        'clinic_id' => $this->f->clinic->id, 'patient_id' => $this->patient->id, 'title' => 'Fillings', 'status' => TreatmentPlan::COMPLETED, 'completed_at' => now(),
    ]);
});

test('a booking nobody checked in for is marked a no-show once the clinic\'s wait has passed, and only then', function () {
    // Bookings are clinic wall-clock times.
    $missed = $this->f->appointment($this->patient, $this->f->doctorA, ClinicClock::now()->subHour());
    $stillWithin = $this->f->appointment($this->patient, $this->f->doctorA, ClinicClock::now()->subMinutes(10));
    $later = $this->f->appointment($this->patient, $this->f->doctorA, ClinicClock::now()->addHour());
    $cameIn = $this->f->appointment($this->patient, $this->f->doctorA, ClinicClock::now()->subHours(2));
    $queue = ClinicQueue::query()->withoutGlobalScopes()->firstOrCreate(['clinic_id' => $this->f->clinic->id, 'branch_id' => $this->f->branch->id, 'queue_date' => now()->toDateString()], ['name' => 'Main']);
    ClinicQueueItem::create(['clinic_queue_id' => $queue->id, 'appointment_id' => $cameIn->id, 'position' => 1, 'status' => 'waiting']);

    expect($this->automation->markMissedAppointments($this->f->clinic))->toBe(1)
        ->and(Appointment::find($missed->id)->status)->toBe(AppointmentStatus::NoShow)
        ->and(Appointment::find($stillWithin->id)->status)->toBe(AppointmentStatus::Scheduled)
        ->and(Appointment::find($later->id)->status)->toBe(AppointmentStatus::Scheduled)
        ->and(Appointment::find($cameIn->id)->status)->toBe(AppointmentStatus::Scheduled)
        // Running again changes nothing.
        ->and($this->automation->markMissedAppointments($this->f->clinic))->toBe(0);
});

test('a clinic that turned automatic no-shows off keeps its bookings as they are', function () {
    $this->f->clinic->update(['appointment_config' => ['auto_no_show' => false]]);
    $missed = $this->f->appointment($this->patient, $this->f->doctorA, ClinicClock::now()->subHour());

    expect($this->automation->markMissedAppointments($this->f->clinic->fresh()))->toBe(0)
        ->and(Appointment::find($missed->id)->status)->toBe(AppointmentStatus::Scheduled);
});

test('a completed dental plan books the recall six months later, once', function () {
    $recall = $this->automation->scheduleRecall(($this->plan)());

    expect($recall)->not->toBeNull()
        ->and($recall->due_on->toDateString())->toBe('2027-04-26')
        ->and($recall->reason)->toBe(PatientAutomation::RECALL_REASON)
        ->and($recall->status)->toBe(PatientFollowUp::DUE)
        // A second plan finished while a visit is already coming books nothing more.
        ->and($this->automation->scheduleRecall(($this->plan)()))->toBeNull()
        ->and(PatientFollowUp::query()->withoutGlobalScopes()->where('patient_id', $this->patient->id)->count())->toBe(1);
});

test('a clinic can set the recall interval or turn recalls off', function () {
    $this->f->clinic->update(['communication_config' => ['recall_months' => 0]]);
    expect($this->automation->scheduleRecall(($this->plan)()))->toBeNull();

    $this->f->clinic->update(['communication_config' => ['recall_months' => 3]]);
    expect($this->automation->scheduleRecall(($this->plan)())->due_on->toDateString())->toBe('2027-01-26');
});

test('a deleted patient gets no recall and no new follow-up', function () {
    $this->patient->update(['status' => PatientStatus::Deleted]);

    expect($this->automation->scheduleRecall(($this->plan)()))->toBeNull()
        ->and(fn () => app(FollowUps::class)->add($this->patient->fresh(), '2026-11-02', 'Review', null, $this->f->branch->id))->toThrow(ValidationException::class, 'was deleted');
});

test('an overdue balance is reminded again at 15 and 30 days, each stage once', function () {
    $procedure = ClinicalProcedure::create(['clinic_id' => $this->f->clinic->id, 'code' => 'CON', 'name' => 'Consultation', 'category' => 'General', 'is_active' => true]);
    $bill = app(BillingService::class)->createBill($this->f->clinic, $this->patient, $this->f->branch, [['clinical_procedure_id' => $procedure->id, 'unit_price' => 900]]);
    $messages = app(PatientMessaging::class);
    $keys = fn () => Notification::query()->where('clinic_id', $this->f->clinic->id)->where('event_key', 'like', "payment_overdue:{$bill->id}%")->pluck('event_key')->sort()->values()->all();

    foreach ([7, 7, 15, 15, 30] as $stage) {
        $messages->balanceOpen($bill, overdue: true, stage: $stage);
    }

    expect($keys())->toBe(["payment_overdue:{$bill->id}", "payment_overdue:{$bill->id}:15", "payment_overdue:{$bill->id}:30"]);
});

test('the owner sees and saves the automation settings; reception may not open them', function () {
    $asOwner = fn () => $this->actingAs($this->f->owner)->withSession($this->f->session);

    $asOwner()->get('/clinic/admin/settings/automation')->assertOk()
        ->assertInertia(fn ($page) => $page->component('ClinicPortal/Settings/Automation', false)
            ->where('patient.auto_no_show', true)->where('patient.recall_months', 6)->has('whatsapp.automations'));

    $asOwner()->put('/clinic/admin/settings/automation', [
        'auto_no_show' => false, 'no_show_after_minutes' => 45, 'recall_months' => 12,
        'whatsapp_enabled' => true, 'require_opt_in' => true, 'automations' => ['appointment_reminder'],
    ])->assertSessionHasNoErrors();

    $clinic = $this->f->clinic->fresh();
    expect($clinic->appointment_config['auto_no_show'])->toBeFalse()
        ->and($clinic->appointment_config['no_show_after_minutes'])->toBe(45)
        ->and($this->automation->recallMonths($clinic))->toBe(12);

    [$reception, $session] = $this->f->memberWithRole(ClinicRole::Receptionist);
    $this->actingAs($reception)->withSession($session)->get('/clinic/admin/settings/automation')->assertForbidden();
});
