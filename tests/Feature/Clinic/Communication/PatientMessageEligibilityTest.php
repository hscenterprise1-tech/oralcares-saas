<?php

use App\Jobs\DispatchDuePatientMessages;
use App\Jobs\SendClinicWhatsAppMessage;
use App\Models\ClinicWhatsAppMessage;
use App\Models\ClinicWhatsAppSettings;
use App\Models\ConsentRecord;
use App\Models\Notification;
use App\Models\Patient;
use App\Models\PatientFollowUp;
use App\Models\PatientTimelineEvent;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppTemplate;
use App\PatientStatus;
use App\Services\ClinicContext;
use App\Services\Communication\PatientMessageEligibility;
use App\Services\Communication\PatientMessaging;
use App\Services\Marketing\WhatsAppManager;
use App\Services\NotificationService;
use App\Services\OwnerMode\OwnerMode;
use App\Services\WhatsApp\ClinicWhatsApp;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\ReceptionFixture;

/**
 * A patient who may not be contacted — merged into another record or
 * deleted — is never messaged: nothing is queued for them, a
 * message queued earlier is skipped (with its reason, not failed) when it
 * falls due or just before WhatsApp would send it, and merging stops what is
 * still waiting. Everyone else is messaged as before.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-07 10:00', 'Asia/Kolkata'));
    Http::preventStrayRequests();
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.ELIG1']]])]);
    $this->f = ReceptionFixture::make('eligibility-clinic');
    $this->connection = WhatsAppConnection::create(['mode' => WhatsAppConnection::SHARED, 'label' => 'OralCares common', 'display_phone_number' => '919000000001',
        'phone_number_id' => 'PN-SHARED', 'business_account_id' => 'WABA-1', 'access_token' => 'EAA-shared', 'status' => 'active']);
    $this->whatsApp = function (array $codes) {
        foreach ($codes as $code) {
            WhatsAppTemplate::create(['whatsapp_connection_id' => $this->connection->id, 'automation_key' => $code, 'name' => "oc_{$code}",
                'body' => ClinicWhatsApp::catalog()[$code]['body'], 'status' => 'approved']);
        }
        ClinicWhatsAppSettings::query()->withoutGlobalScopes()->updateOrCreate(['clinic_id' => $this->f->clinic->id],
            ['enabled' => true, 'mode' => 'common', 'automations' => array_fill_keys($codes, true), 'require_opt_in' => true]);
    };
    $this->patientWhoAgreed = function (string $first) {
        $patient = $this->f->patient($first, 'Rao');
        ConsentRecord::query()->withoutGlobalScopes()->create(['clinic_id' => $patient->clinic_id, 'patient_id' => $patient->id, 'consent_type' => 'communication',
            'status' => ConsentRecord::GRANTED, 'capture_method' => 'verbal', 'captured_at' => now(), 'signed_at' => now()]);

        return $patient;
    };
    $this->book = function (Patient $patient) {
        $appointment = $this->f->appointment($patient, $this->f->doctorA, CarbonImmutable::parse('2026-10-09 17:30'));
        app(PatientMessaging::class)->appointmentsBooked([$appointment]);

        return $appointment;
    };
    $this->messagesFor = fn (Patient $patient) => Notification::query()->where('recipient_type', 'patient')->where('recipient_id', $patient->id)->orderBy('id')->get();
    $this->mark = fn (Patient $patient, PatientStatus $status) => $patient->forceFill(['status' => $status])->save();
    $this->dispatch = fn () => (new DispatchDuePatientMessages)->handle(app(NotificationService::class));
    $this->asOwnerInOwnerMode = fn () => $this->actingAs($this->f->owner)->withSession([...$this->f->session, OwnerMode::SESSION_KEY => [
        'user_id' => $this->f->owner->id, 'clinic_id' => $this->f->clinic->id, 'membership_id' => $this->f->session[ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID],
        'method' => 'password', 'activated_at' => now()->getTimestamp(), 'last_seen_at' => now()->getTimestamp(),
    ]]);
});

test('a current patient is still queued and sent, whatever their legacy status', function (PatientStatus $status) {
    ($this->whatsApp)(['appointment_confirmed']);
    $patient = ($this->patientWhoAgreed)('Asha');
    ($this->mark)($patient, $status);

    $appointment = ($this->book)($patient);
    ($this->dispatch)();

    $confirmation = Notification::query()->where('event_key', "appointment_confirmed:{$appointment->id}")->sole();
    expect($confirmation->status)->toBe('sent')
        ->and(ClinicWhatsAppMessage::query()->withoutGlobalScopes()->where('notification_id', $confirmation->id)->value('status'))->toBe(ClinicWhatsAppMessage::SENT);
})->with([
    'active' => [PatientStatus::Active],
    'inactive' => [PatientStatus::Inactive],
]);

test('nothing is queued for a patient who may not be contacted, and their timeline records no message', function (PatientStatus $status) {
    $patient = $this->f->patient('Ravi', 'Menon');
    ($this->mark)($patient, $status);

    ($this->book)($patient);

    expect(($this->messagesFor)($patient))->toBeEmpty()
        ->and(PatientTimelineEvent::query()->where('patient_id', $patient->id)->where('event_type', 'message_queued')->exists())->toBeFalse();
})->with([
    'merged' => [PatientStatus::Merged],
    'deleted' => [PatientStatus::Deleted],
]);

test('messages queued before the patient was deleted are skipped when due, with the reason, and stay skipped on later runs', function () {
    $patient = $this->f->patient('Ravi', 'Menon');
    $appointment = ($this->book)($patient);
    ($this->mark)($patient, PatientStatus::Deleted);

    ($this->dispatch)();
    // The day-before reminder falls due later; it is checked again then.
    $this->travelTo(CarbonImmutable::parse('2026-10-08 18:00', 'Asia/Kolkata'));
    ($this->dispatch)();
    ($this->dispatch)();

    $byKey = ($this->messagesFor)($patient)->keyBy('event_key');
    expect($byKey["appointment_confirmed:{$appointment->id}"])
        ->status->toBe(NotificationService::SKIPPED)
        ->failed_reason->toBe(PatientMessageEligibility::DELETED_REASON)
        ->sent_at->toBeNull()
        ->retry_count->toBe(0);
    expect($byKey["appointment_reminder:{$appointment->id}:202610091730"])
        ->status->toBe(NotificationService::SKIPPED)
        ->failed_reason->toBe(PatientMessageEligibility::DELETED_REASON);
    Http::assertNothingSent();
});

test('a WhatsApp message waiting for its send job is not handed to the provider once the patient is deleted', function () {
    Queue::fake([SendClinicWhatsAppMessage::class]);
    ($this->whatsApp)(['appointment_confirmed']);
    $patient = ($this->patientWhoAgreed)('Asha');
    ($this->book)($patient);
    ($this->dispatch)();
    $delivery = ClinicWhatsAppMessage::query()->withoutGlobalScopes()->sole();
    ($this->mark)($patient, PatientStatus::Deleted);

    (new SendClinicWhatsAppMessage($delivery->id))->handle(app(WhatsAppManager::class));

    expect($delivery->fresh())
        ->status->toBe(ClinicWhatsAppMessage::SKIPPED)
        ->failure_reason->toBe(PatientMessageEligibility::DELETED_REASON);
    expect(Notification::query()->find($delivery->notification_id))
        ->status->toBe(NotificationService::SKIPPED)
        ->failed_reason->toBe(PatientMessageEligibility::DELETED_REASON);
    Http::assertNothingSent();
});

test('a failed WhatsApp delivery is not retried for a merged patient, and is recorded as skipped rather than failed', function () {
    Queue::fake([SendClinicWhatsAppMessage::class]);
    ($this->whatsApp)(['appointment_confirmed']);
    $patient = ($this->patientWhoAgreed)('Asha');
    ($this->book)($patient);
    ($this->dispatch)();
    $delivery = ClinicWhatsAppMessage::query()->withoutGlobalScopes()->sole();
    $delivery->update(['status' => ClinicWhatsAppMessage::FAILED, 'failure_reason' => 'Provider timed out.']);
    Notification::query()->whereKey($delivery->notification_id)->update(['status' => 'failed', 'failed_reason' => 'Provider timed out.']);
    ($this->mark)($patient, PatientStatus::Merged);

    $retried = app(ClinicWhatsApp::class)->retry($delivery, $this->f->owner);

    expect($retried)->toBeFalse()
        ->and($delivery->fresh()->status)->toBe(ClinicWhatsAppMessage::SKIPPED)
        ->and($delivery->fresh()->failure_reason)->toBe(PatientMessageEligibility::MERGED_REASON)
        ->and(Notification::query()->find($delivery->notification_id)->status)->toBe(NotificationService::SKIPPED);
    // Only the original send was dispatched; the retry queued nothing.
    Queue::assertPushed(SendClinicWhatsAppMessage::class, 1);
});

test('there is no archive to stop a patient\'s messages: asking for one is refused and their messages still wait to go', function () {
    $patient = $this->f->patient('Ravi', 'Menon');
    ($this->book)($patient);

    ($this->asOwnerInOwnerMode)()->post('/clinic/approvals/direct', ['action' => 'patient.archive', 'target_id' => $patient->id, 'reason' => 'Moved abroad permanently'])
        ->assertSessionHasErrors('action');

    expect($patient->fresh()->status)->toBe(PatientStatus::Active)
        ->and(($this->messagesFor)($patient)->pluck('status')->unique()->all())->not->toContain(NotificationService::SKIPPED);
});

test('merging a duplicate stops its waiting WhatsApp delivery before the history moves to the patient kept', function () {
    Queue::fake([SendClinicWhatsAppMessage::class]);
    ($this->whatsApp)(['appointment_confirmed']);
    $keep = $this->f->patient('Asha', 'Rao');
    $duplicate = ($this->patientWhoAgreed)('Asha');
    $appointment = ($this->book)($duplicate);
    ($this->dispatch)();
    $delivery = ClinicWhatsAppMessage::query()->withoutGlobalScopes()->sole();

    ($this->asOwnerInOwnerMode)()->post('/clinic/approvals/direct', [
        'action' => 'patient.merge', 'target_id' => $duplicate->id, 'reason' => 'Registered twice at the front desk', 'payload' => ['survivor_patient_id' => $keep->id],
    ])->assertSessionHasNoErrors();
    (new SendClinicWhatsAppMessage($delivery->id))->handle(app(WhatsAppManager::class));

    expect($duplicate->fresh()->status)->toBe(PatientStatus::Merged)
        // The delivery row moved with the history, but it never went out.
        ->and($delivery->fresh()->patient_id)->toBe($keep->id)
        ->and($delivery->fresh()->status)->toBe(ClinicWhatsAppMessage::SKIPPED)
        ->and($delivery->fresh()->failure_reason)->toBe(PatientMessageEligibility::MERGED_REASON)
        ->and(($this->messagesFor)($duplicate)->pluck('status', 'event_key')->all())->toBe([
            "appointment_confirmed:{$appointment->id}" => NotificationService::SKIPPED,
            "appointment_reminder:{$appointment->id}:202610091730" => NotificationService::SKIPPED,
        ]);
    Http::assertNothingSent();
});

test('the scheduled follow-up sweep queues a reminder for a current patient but not for a deleted one', function () {
    ($this->whatsApp)(['follow_up_due']);
    $current = $this->f->patient('Asha', 'Rao');
    $deceased = $this->f->patient('Ravi', 'Menon');
    ($this->mark)($deceased, PatientStatus::Deleted);
    $due = fn (Patient $patient) => PatientFollowUp::query()->withoutGlobalScopes()->create([
        'clinic_id' => $this->f->clinic->id, 'branch_id' => $this->f->branch->id, 'patient_id' => $patient->id, 'practitioner_id' => $this->f->doctorA->id,
        'due_on' => '2026-10-07', 'reason' => 'Review', 'status' => PatientFollowUp::DUE,
    ]);
    $currentFollowUp = $due($current);
    $due($deceased);

    $this->artisan('clinic:queue-scheduled-messages')->assertSuccessful();

    expect(($this->messagesFor)($current)->pluck('event_key')->all())->toBe(["follow_up_today:{$currentFollowUp->id}:20261007"])
        ->and(($this->messagesFor)($deceased))->toBeEmpty();
});
