<?php

use App\AppointmentSource;
use App\ClinicRole;
use App\Jobs\DispatchDuePatientMessages;
use App\Models\Notification;
use App\Models\NotificationTemplate;
use App\Models\PatientFollowUp;
use App\Services\Appointments\AppointmentBookingService;
use App\Services\Clinical\EncounterWorkspace;
use App\Services\ClinicalOrderService;
use App\Services\ClinicalPrescriptionService;
use App\Services\Communication\PatientMessaging;
use App\Services\NotificationService;
use App\Services\Patient\FollowUps;
use Carbon\CarbonImmutable;
use Tests\Support\ReceptionFixture;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-02 10:00', 'Asia/Kolkata'));
    $this->f = ReceptionFixture::make();
    [$this->doctorUser] = $this->f->memberWithRole(ClinicRole::Doctor);
    $this->me = $this->f->doctor('Dr. Me', $this->doctorUser);
    $this->patient = $this->f->patient('Rahul', 'Sharma');
    $this->actingAs($this->doctorUser);
    $this->book = fn (string $at) => app(AppointmentBookingService::class)->book($this->f->clinic, $this->f->branch, $this->patient, $this->me, [
        'scheduled_at' => CarbonImmutable::parse($at), 'duration_minutes' => 15, 'source' => AppointmentSource::cases()[0],
    ], $this->doctorUser);
    $this->messages = fn () => Notification::query()->where('clinic_id', $this->f->clinic->id)->orderBy('id')->get();
});

test('booking confirms now and reminds the day before, from the clinic\'s own editable template', function () {
    $appointment = ($this->book)('2026-10-05 11:00');

    $messages = ($this->messages)();
    expect($messages->pluck('event_key')->all())->toBe(["appointment_confirmed:{$appointment->id}", "appointment_reminder:{$appointment->id}:202610051100"])
        ->and($messages[0]->recipient_type)->toBe('patient')
        ->and($messages[0]->recipient_id)->toBe($this->patient->id)
        ->and($messages[0]->scheduled_at)->toBeNull()
        ->and($messages[1]->scheduled_at->format('Y-m-d H:i'))->toBe('2026-10-04 11:00')
        ->and($messages[0]->metadata['placeholders']['doctor'])->toBe('Dr. Me')
        ->and(NotificationTemplate::where('clinic_id', $this->f->clinic->id)->pluck('code')->sort()->values()->all())->toBe(['appointment_confirmed', 'appointment_reminder']);
});

test('rescheduling withdraws the old reminder and sets a new one; cancelling withdraws it', function () {
    $appointment = ($this->book)('2026-10-05 11:00');
    $service = app(AppointmentBookingService::class);

    $service->reschedule($this->f->clinic, $this->f->branch, $appointment, ['scheduled_at' => CarbonImmutable::parse('2026-10-06 12:00'), 'duration_minutes' => 15, 'practitioner' => $this->me], AppointmentBookingService::SCOPE_THIS, $this->doctorUser);

    $byKey = ($this->messages)()->keyBy('event_key');
    expect($byKey["appointment_reminder:{$appointment->id}:202610051100"]->status)->toBe('cancelled')
        ->and($byKey["appointment_reminder:{$appointment->id}:202610061200"]->status)->toBe('pending')
        ->and($byKey->has("appointment_rescheduled:{$appointment->id}:202610061200"))->toBeTrue();

    $service->cancel($this->f->clinic, $appointment->fresh(), 'Patient travelling', AppointmentBookingService::SCOPE_THIS, $this->doctorUser);
    $byKey = ($this->messages)()->keyBy('event_key');
    expect($byKey["appointment_reminder:{$appointment->id}:202610061200"]->status)->toBe('cancelled')
        ->and($byKey->has("appointment_cancelled:{$appointment->id}"))->toBeTrue();
});

test('the same event never queues a second message', function () {
    $appointment = ($this->book)('2026-10-05 11:00');
    $messaging = app(PatientMessaging::class);

    $messaging->appointmentsBooked([$appointment]);
    $messaging->appointmentsBooked([$appointment]);

    expect(($this->messages)())->toHaveCount(2);
});

test('signing with a follow-up, a prescription and a reviewed report each tell the patient', function () {
    $workspace = app(EncounterWorkspace::class);
    $encounter = $workspace->startForPatient($this->patient, $this->f->branch, $this->me);
    $workspace->autosave($encounter, ['chief_complaint' => 'Review', 'follow_up_on' => '2026-10-16'], 0);
    $rx = app(ClinicalPrescriptionService::class)->issuePrescription($encounter, [], [['drug_name' => 'Paracetamol', 'dosage' => '1 tab', 'frequency' => 'SOS', 'duration' => '3 days']], $this->me);
    $order = app(ClinicalOrderService::class)->placeOrder($encounter, ['priority' => 'routine'], [['item_name' => 'CBC', 'category' => 'lab']], $this->me);
    $item = $order->items()->sole();
    app(ClinicalOrderService::class)->recordResult($item, null, ['summary' => 'Normal']);
    app(ClinicalOrderService::class)->review($item, $this->me);
    $workspace->finalize($encounter, $this->me);

    $byKey = ($this->messages)()->keyBy('event_key');
    expect($byKey->has("prescription:{$rx->id}"))->toBeTrue()
        ->and($byKey->has("report:{$item->id}"))->toBeTrue()
        ->and($followUp = PatientFollowUp::where('encounter_id', $encounter->id)->sole())->not->toBeNull()
        ->and($byKey["follow_up:{$followUp->id}:20261016"]->scheduled_at->format('Y-m-d H:i'))->toBe('2026-10-15 09:00')
        ->and($byKey["follow_up:{$followUp->id}:20261016"]->metadata['placeholders']['when'])->toBe('16 Oct 2026');
});

test('rescheduling a follow-up moves its reminder; completing it withdraws the reminder', function () {
    $followUp = app(FollowUps::class)->add($this->patient, '2026-10-20', 'Suture removal', $this->me, $this->f->branch->id);
    app(FollowUps::class)->reschedule($followUp, '2026-10-22');

    $byKey = ($this->messages)()->keyBy('event_key');
    expect($byKey["follow_up:{$followUp->id}:20261020"]->status)->toBe('cancelled')
        ->and($byKey["follow_up:{$followUp->id}:20261022"]->status)->toBe('pending');

    app(FollowUps::class)->complete($followUp);
    expect(Notification::where('event_key', "follow_up:{$followUp->id}:20261022")->value('status'))->toBe('cancelled');
});

test('the dispatcher sends what is due, leaves later messages and withdrawn ones alone, and never sends twice', function () {
    $appointment = ($this->book)('2026-10-05 11:00');

    (new DispatchDuePatientMessages)->handle(app(NotificationService::class));
    $byKey = ($this->messages)()->keyBy('event_key');
    expect($byKey["appointment_confirmed:{$appointment->id}"]->status)->toBe(NotificationService::SKIPPED)
        ->and($byKey["appointment_reminder:{$appointment->id}:202610051100"]->status)->toBe('pending');

    $this->travelTo(CarbonImmutable::parse('2026-10-04 11:01', 'Asia/Kolkata'));
    (new DispatchDuePatientMessages)->handle(app(NotificationService::class));
    (new DispatchDuePatientMessages)->handle(app(NotificationService::class));

    $reminder = Notification::where('event_key', "appointment_reminder:{$appointment->id}:202610051100")->sole();
    expect($reminder->status)->toBe(NotificationService::SKIPPED)
        ->and($reminder->retry_count)->toBe(0);
});

test('a prospect booked without a patient record gets no message', function () {
    app(AppointmentBookingService::class)->book($this->f->clinic, $this->f->branch, null, $this->me, [
        'scheduled_at' => CarbonImmutable::parse('2026-10-05 11:00'), 'duration_minutes' => 15, 'source' => AppointmentSource::cases()[0],
        'prospect' => ['name' => 'New Person', 'phone' => '9876543210'],
    ], $this->doctorUser);

    expect(($this->messages)())->toHaveCount(0);
});
