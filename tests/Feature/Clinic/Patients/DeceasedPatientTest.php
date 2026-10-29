<?php

use App\AppointmentStatus;
use App\BranchStatus;
use App\ClinicMembershipStatus;
use App\ClinicRole;
use App\Jobs\DispatchDuePatientMessages;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\ClinicMembership;
use App\Models\DeletionRequest;
use App\Models\Notification;
use App\Models\PatientFollowUp;
use App\Models\PatientTimelineEvent;
use App\Models\SecurityAuditLog;
use App\Models\User;
use App\PatientStatus;
use App\PaymentMethod;
use App\Services\Appointments\AppointmentBookingService;
use App\Services\BillingService;
use App\Services\Clinical\EncounterWorkspace;
use App\Services\ClinicalOrderService;
use App\Services\ClinicalPrescriptionService;
use App\Services\ClinicContext;
use App\Services\ClinicRoleAssignmentService;
use App\Services\Communication\PatientMessageEligibility;
use App\Services\Communication\PatientMessaging;
use App\Services\EncounterManagementService;
use App\Services\FrontDeskService;
use App\Services\Governance\Actions\RecordDeath;
use App\Services\NotificationService;
use App\Services\Patient\FollowUps;
use App\Services\Patient\PatientBranchActivityRecorder;
use App\Services\PaymentService;
use App\Services\TreatmentPlanningService;
use App\Services\VisitCheckInService;
use App\UserType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\ReceptionFixture;

/**
 * Recording a patient's death: a governed action. Staff who edit patients
 * ask, the owner decides (or records it directly), with a date and a reason;
 * nothing is deleted. Afterwards no new care, booking, charge or message
 * reaches the patient, while the record stays readable and existing bills
 * can still be paid and refunded. A death recorded in error is corrected the
 * same governed way, and both stay in the patient's history.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-26 10:00', 'Asia/Kolkata'));
    $this->f = ReceptionFixture::make();
    $this->patient = $this->f->patient('Meera', 'Iyer', ['date_of_birth' => '1985-04-02']);
    app(PatientBranchActivityRecorder::class)->record($this->f->clinic->id, $this->patient->id, $this->f->branch->id, now(), 'registration');
    $this->asOwner = fn () => $this->actingAs($this->f->owner)->withSession(withOwnerMode($this->f->session));
    $this->asRole = function (ClinicRole $role) {
        [$user, $session] = $this->f->memberWithRole($role);

        return fn () => $this->actingAs($user)->withSession($session);
    };
    $this->record = fn (array $overrides = []) => [
        'action' => 'patient.record_death', 'target_id' => $this->patient->id, 'reason' => 'Family informed the clinic by phone',
        'payload' => ['date_of_death' => '2026-10-25'], ...$overrides,
    ];
    $this->correct = fn () => ['action' => 'patient.correct_death', 'target_id' => $this->patient->id, 'reason' => 'Recorded on the wrong patient by mistake', 'payload' => []];
    $this->recordDirectly = fn () => ($this->asOwner)()->post('/clinic/approvals/direct', ($this->record)())->assertSessionHasNoErrors();
    $this->refused = function (Closure $attempt): void {
        expect($attempt)->toThrow(ValidationException::class, 'is recorded as deceased on');
    };
    $this->page = function ($as): array {
        $props = [];
        $as()->get("/clinic/patients/{$this->patient->id}")->assertOk()->assertInertia(function (Assert $page) use (&$props) {
            $props = $page->toArray()['props'];
        });

        return $props;
    };
});

test('the owner records a death directly, with who and when, and the history says so', function () {
    ($this->recordDirectly)();

    $patient = $this->patient->fresh();
    expect($patient->status)->toBe(PatientStatus::Deceased)
        ->and($patient->date_of_death->toDateString())->toBe('2026-10-25')
        ->and($patient->death_recorded_by)->toBe($this->f->owner->id)
        ->and($patient->death_recorded_at->toIso8601String())->toBe(now()->toIso8601String())
        ->and(DeletionRequest::sole())->status->toBe(DeletionRequest::EXECUTED)->direct->toBeTrue()->reason->toBe('Family informed the clinic by phone')
        ->and(SecurityAuditLog::where('event', 'deletion.executed')->count())->toBe(1)
        ->and(PatientTimelineEvent::where('patient_id', $patient->id)->where('event_type', 'patient_death_recorded')->sole()->context)
        ->toMatchArray(['date_of_death' => '2026-10-25', 'reason' => 'Family informed the clinic by phone']);
});

test('reception and clinic admins ask; nothing changes until the owner approves', function (ClinicRole $role) {
    ($this->asRole)($role)()->post('/clinic/approvals', ($this->record)())->assertSessionHasNoErrors();
    $request = DeletionRequest::sole();
    expect($request->status)->toBe(DeletionRequest::PENDING)->and($this->patient->fresh()->status)->toBe(PatientStatus::Active);

    // A second request for the same patient waits on the first.
    ($this->asRole)($role)()->post('/clinic/approvals', ($this->record)())->assertSessionHasErrors('governance');

    ($this->asOwner)()->post("/clinic/approvals/{$request->id}/approve")->assertSessionHasNoErrors();
    expect($request->fresh()->status)->toBe(DeletionRequest::EXECUTED)
        ->and($this->patient->fresh())->status->toBe(PatientStatus::Deceased)->death_recorded_by->toBe($this->f->owner->id)
        ->and(SecurityAuditLog::whereIn('event', ['deletion.requested', 'deletion.approved', 'deletion.executed'])->count())->toBeGreaterThanOrEqual(2);
})->with([ClinicRole::Receptionist, ClinicRole::ClinicAdmin]);

test('doctors and billing staff may not ask, and only the owner records directly', function () {
    ($this->asRole)(ClinicRole::Doctor)()->post('/clinic/approvals', ($this->record)())->assertForbidden();
    ($this->asRole)(ClinicRole::BillingStaff)()->post('/clinic/approvals', ($this->record)())->assertForbidden();
    ($this->asRole)(ClinicRole::Receptionist)()->post('/clinic/approvals/direct', ($this->record)())->assertForbidden();

    expect(DeletionRequest::count())->toBe(0)->and($this->patient->fresh()->status)->toBe(PatientStatus::Active);
});

test('the date of death is required, real, not in the future and not before birth, and a reason is needed', function (array $overrides) {
    ($this->asOwner)()->post('/clinic/approvals/direct', ($this->record)($overrides))->assertSessionHasErrors();

    expect(DeletionRequest::count())->toBe(0)->and($this->patient->fresh()->status)->toBe(PatientStatus::Active);
})->with([
    'no date' => [['payload' => []]],
    'not a date' => [['payload' => ['date_of_death' => '2026-02-30']]],
    'tomorrow' => [['payload' => ['date_of_death' => '2026-10-27']]],
    'before birth' => [['payload' => ['date_of_death' => '1985-04-01']]],
    'short reason' => [['reason' => 'Died']],
]);

test('a death cannot be recorded twice, nor on a merged record', function () {
    ($this->recordDirectly)();
    ($this->asOwner)()->post('/clinic/approvals/direct', ($this->record)())->assertSessionHasErrors('governance');

    $merged = $this->f->patient('Old', 'Record', ['status' => PatientStatus::Merged]);
    ($this->asOwner)()->post('/clinic/approvals/direct', ($this->record)(['target_id' => $merged->id]))->assertSessionHasErrors('governance');

    expect(DeletionRequest::count())->toBe(1)->and($merged->fresh()->status)->toBe(PatientStatus::Merged);
});

test('recording a death cancels upcoming bookings and open follow-ups, and keeps past ones', function () {
    $past = $this->f->appointment($this->patient, $this->f->doctorA, now()->subDays(3), AppointmentStatus::Completed);
    $upcoming = $this->f->appointment($this->patient, $this->f->doctorA, now()->addDays(2));
    $followUp = app(FollowUps::class)->add($this->patient, '2026-11-02', 'Review', null, $this->f->branch->id);

    ($this->recordDirectly)();

    expect(Appointment::find($upcoming->id))->status->toBe(AppointmentStatus::Cancelled)
        ->and(Appointment::find($upcoming->id)->cancellation_reason)->toBe(RecordDeath::CANCELLATION_REASON)
        ->and(Appointment::find($past->id)->status)->toBe(AppointmentStatus::Completed)
        ->and(PatientFollowUp::query()->withoutGlobalScopes()->find($followUp->id)->status)->not->toBe(PatientFollowUp::DUE);
});

test('after a death is recorded, new visits, bookings, consultations, orders, follow-ups and charges are refused', function () {
    $this->actingAs($this->f->owner);
    $booked = $this->f->appointment($this->patient, $this->f->doctorA, now()->addHour());
    // A consultation that started before the death was recorded.
    $encounter = app(EncounterWorkspace::class)->startForPatient($this->patient, $this->f->branch, $this->f->doctorA);
    // The patient died later the same day.
    ($this->asOwner)()->post('/clinic/approvals/direct', ($this->record)(['payload' => ['date_of_death' => '2026-10-26']]))->assertSessionHasNoErrors();
    $patient = $this->patient->fresh();

    ($this->asOwner)()->post('/clinic/reception/check-in', ['patient_id' => $patient->id])->assertSessionHasErrors('patient_id');
    ($this->refused)(fn () => app(AppointmentBookingService::class)->book($this->f->clinic, $this->f->branch, $patient, $this->f->doctorA, ['scheduled_at' => now()->addDay()], $this->f->owner));
    ($this->refused)(fn () => app(AppointmentBookingService::class)->linkPatient($this->f->clinic, $booked, $patient, $this->f->owner));
    ($this->refused)(fn () => app(EncounterWorkspace::class)->startForPatient($patient, $this->f->branch, $this->f->doctorA));
    ($this->refused)(fn () => app(ClinicalPrescriptionService::class)->draftFor($encounter, $this->f->doctorA));
    ($this->refused)(fn () => app(ClinicalOrderService::class)->placeOrder($encounter, [], [['name' => 'CBC']], $this->f->doctorA));
    ($this->refused)(fn () => app(FollowUps::class)->add($patient, '2026-11-02', 'Review', null, $this->f->branch->id));
    ($this->refused)(fn () => app(BillingService::class)->createBill($this->f->clinic, $patient, $this->f->branch, [['description' => 'Filling', 'quantity' => 1, 'unit_price' => 900]]));

    // Care given on or before the date of death is still billed.
    $bill = app(BillingService::class)->createBill($this->f->clinic, $patient, $this->f->branch, [['description' => 'Consultation', 'quantity' => 1, 'unit_price' => 500]], $encounter);
    expect((float) $bill->final_amount)->toEqual(500.0);
});

test('existing records stay readable, and existing bills can still be paid and refunded', function () {
    $bill = app(BillingService::class)->createBill($this->f->clinic, $this->patient, $this->f->branch, [['description' => 'Filling', 'quantity' => 1, 'unit_price' => 2000]]);
    $first = app(PaymentService::class)->recordPayment($bill->fresh(), PaymentMethod::Cash, 1000);
    ($this->recordDirectly)();

    ($this->asOwner)()->get("/clinic/patients/{$this->patient->id}")->assertOk();
    ($this->asOwner)()->get("/clinic/patients/{$this->patient->id}/360/visits")->assertOk();

    app(PaymentService::class)->recordPayment($bill->fresh(), PaymentMethod::Cash, 1000);
    app(PaymentService::class)->recordRefund($bill->fresh(), 300, 'Overcharged on the first visit', null, $first);

    $bill = $bill->fresh();
    expect((float) $bill->paid_amount)->toEqual(2000.0)->and((float) $bill->refunded_amount)->toEqual(300.0);
});

test('messages waiting for the patient are skipped with the reason, and nothing new is queued', function () {
    $bill = app(BillingService::class)->createBill($this->f->clinic, $this->patient, $this->f->branch, [['description' => 'Filling', 'quantity' => 1, 'unit_price' => 400]]);
    $appointment = $this->f->appointment($this->patient, $this->f->doctorA, CarbonImmutable::parse('2026-10-28 17:30'));
    app(PatientMessaging::class)->appointmentsBooked([$appointment]);
    $waiting = Notification::query()->where('recipient_type', 'patient')->where('recipient_id', $this->patient->id)->pluck('id');
    expect($waiting)->not->toBeEmpty();

    ($this->recordDirectly)();
    (new DispatchDuePatientMessages)->handle(app(NotificationService::class));

    expect(Notification::query()->whereIn('id', $waiting)->pluck('status')->unique()->values()->all())->toBe([NotificationService::SKIPPED])
        ->and(Notification::query()->whereIn('id', $waiting)->pluck('failed_reason')->unique()->values()->all())->toBe([PatientMessageEligibility::DECEASED_REASON]);

    // Paying an existing bill is still recorded, but no receipt or other message waits to go.
    app(PaymentService::class)->recordPayment($bill->fresh(), PaymentMethod::Cash, 400);
    expect(Notification::query()->where('recipient_type', 'patient')->where('recipient_id', $this->patient->id)->where('status', '!=', NotificationService::SKIPPED)->count())->toBe(0);
});

test('a death recorded in error is corrected by the same governed route, and both stay in the history', function () {
    $upcoming = $this->f->appointment($this->patient, $this->f->doctorA, now()->addDays(2));
    ($this->recordDirectly)();

    // Correcting is asked for like recording; doctors may not.
    ($this->asRole)(ClinicRole::Doctor)()->post('/clinic/approvals', ($this->correct)())->assertForbidden();
    ($this->asRole)(ClinicRole::Receptionist)()->post('/clinic/approvals', ($this->correct)())->assertSessionHasNoErrors();
    expect($this->patient->fresh()->status)->toBe(PatientStatus::Deceased);

    ($this->asOwner)()->post('/clinic/approvals/'.DeletionRequest::where('action_type', 'patient.correct_death')->sole()->id.'/approve')->assertSessionHasNoErrors();

    $patient = $this->patient->fresh();
    expect($patient)->status->toBe(PatientStatus::Active)->date_of_death->toBeNull()->death_recorded_by->toBeNull()
        ->and(PatientTimelineEvent::where('patient_id', $patient->id)->whereIn('event_type', ['patient_death_recorded', 'patient_death_corrected'])->pluck('event_type')->all())
        ->toEqualCanonicalizing(['patient_death_recorded', 'patient_death_corrected'])
        ->and(PatientTimelineEvent::where('event_type', 'patient_death_corrected')->sole()->context)->toMatchArray(['previous_date_of_death' => '2026-10-25'])
        // Bookings cancelled when the death was recorded are not brought back.
        ->and(Appointment::find($upcoming->id)->status)->toBe(AppointmentStatus::Cancelled);

    // Care is possible again.
    $this->actingAs($this->f->owner);
    app(FollowUps::class)->add($patient, '2026-11-02', 'Review', null, $this->f->branch->id);

    // Correcting a patient who is not recorded as deceased is refused.
    ($this->asOwner)()->post('/clinic/approvals/direct', ($this->correct)())->assertSessionHasErrors('governance');
});

test('another clinic, and a member limited to another branch, cannot record the death', function () {
    $other = ReceptionFixture::make('other-clinic');
    $this->actingAs($other->owner)->withSession(withOwnerMode($other->session))->post('/clinic/approvals/direct', ($this->record)())->assertSessionHasErrors('governance');

    $north = Branch::factory()->create(['clinic_id' => $this->f->clinic->id, 'name' => 'North', 'is_main' => false, 'status' => BranchStatus::Active]);
    $user = User::factory()->create(['user_type' => UserType::ClinicUser]);
    $membership = ClinicMembership::factory()->create(['clinic_id' => $this->f->clinic->id, 'user_id' => $user->id, 'has_all_branches_access' => false, 'status' => ClinicMembershipStatus::Active]);
    DB::table('branch_clinic_membership')->insert(['clinic_id' => $this->f->clinic->id, 'clinic_membership_id' => $membership->id, 'branch_id' => $north->id]);
    $this->f->memberWithRole(ClinicRole::Receptionist);
    app(ClinicRoleAssignmentService::class)->syncRoles($user, [ClinicRole::Receptionist->value], [$north]);
    $this->actingAs($user)->withSession([ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID => $membership->id, ClinicContext::SESSION_BRANCH_ID => $north->id])
        ->post('/clinic/approvals', ($this->record)())->assertForbidden();

    expect(DeletionRequest::count())->toBe(0)->and($this->patient->fresh()->status)->toBe(PatientStatus::Active);
});

test('Patient 360 shows the recorded death, and offers recording or correcting only to those allowed', function () {
    expect(($this->page)($this->asOwner))
        ->patient->deceased->toBeNull()
        ->lens->governance->{'patient.record_death'}->toBe('direct');
    expect(($this->page)(($this->asRole)(ClinicRole::Receptionist))['lens']['governance']['patient.record_death'])->toBe('request');
    expect(($this->page)(($this->asRole)(ClinicRole::Doctor))['lens']['governance']['patient.record_death'])->toBeNull();
    expect(($this->page)(($this->asRole)(ClinicRole::BillingStaff))['lens']['governance']['patient.correct_death'])->toBeNull();

    ($this->recordDirectly)();

    $props = ($this->page)(($this->asRole)(ClinicRole::Doctor));
    expect($props['patient']['status'])->toBe('deceased')
        ->and($props['patient']['deceased'])->toMatchArray(['on' => '2026-10-25', 'recorded_by' => $this->f->owner->name])
        ->and($props['lens']['governance']['patient.correct_death'])->toBeNull();
});

test('reception\'s patient search shows the recorded death', function () {
    ($this->recordDirectly)();

    ($this->asRole)(ClinicRole::Receptionist)()->getJson('/clinic/reception/check-in/search?q=Meera')->assertOk()
        ->assertJsonPath('patients.0.id', $this->patient->id)->assertJsonPath('patients.0.status', 'deceased');
});

test('the older write paths refuse new care for a deceased patient too', function () {
    $this->actingAs($this->f->owner);
    ($this->recordDirectly)();
    $patient = $this->patient->fresh();

    ($this->refused)(fn () => app(EncounterManagementService::class)->startEncounter($patient, $this->f->branch, $this->f->doctorA));
    ($this->refused)(fn () => app(VisitCheckInService::class)->checkIn($this->f->clinic, $this->f->branch, $patient, $this->f->doctorA));
    ($this->refused)(fn () => app(TreatmentPlanningService::class)->createTreatmentPlan($patient, $this->f->clinic, 'Plan', []));
    ($this->refused)(fn () => app(FrontDeskService::class)->addWalkIn($patient, null, $this->f->branch->id));
});
