<?php

use App\AppointmentStatus;
use App\ClinicRole;
use App\Models\Appointment;
use App\Models\ClinicalSpecialty;
use App\Models\ClinicEmployee;
use App\Models\ClinicQueue;
use App\Models\ClinicQueueItem;
use App\Models\ClinicSettingAudit;
use App\Models\Patient;
use App\Models\PatientAlert;
use App\Models\PatientBilling;
use App\Models\PatientVisit;
use App\Services\Clinical\EncounterWorkspace;
use App\Services\Reception\PatientFlowConfigService;
use App\Support\Specialty\PracticeAreas;
use Carbon\CarbonImmutable;
use Tests\Support\TreatmentFixture;

/**
 * New Case and Repeat Case end to end: the doctor resolves the context, the
 * reasons and Quick Care follow it, priority and queue follow the clinic's
 * settings, and the server checks every piece again. One registration per
 * request key; billing stays with the canonical checkout.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-10 10:00', 'Asia/Kolkata'));
    $this->t = TreatmentFixture::make();
    $this->f = $this->t->f;
    $this->flow = app(PatientFlowConfigService::class);
    $this->asOwner = fn () => $this->actingAs($this->f->owner)->withSession($this->f->session);

    $this->practitioner = function (string $name, string $area): ClinicEmployee {
        $employee = $this->f->doctor($name);
        $keyed = ['organization_id' => $this->f->clinic->organization_id, 'practice_area_key' => $area];
        $entry = ClinicalSpecialty::withoutGlobalScopes()->where($keyed)->first()
            ?? ClinicalSpecialty::withoutGlobalScopes()->forceCreate([...$keyed, 'name' => app(PracticeAreas::class)->get($area)['label'], 'is_active' => true]);
        $employee->practitionerProfile()->withoutGlobalScopes()->update(['primary_specialty_id' => $entry->id, 'specialty' => $entry->name]);

        return $employee->fresh();
    };
    $this->raj = ($this->practitioner)('Dr. Raj Patel', 'general_dentistry');
    $this->priya = ($this->practitioner)('Dr. Priya Patel', 'orthodontics');
    $this->amit = ($this->practitioner)('Dr. Amit Shah', 'endodontics');
    $this->pooja = ($this->practitioner)('Dr. Pooja Rao', 'prosthodontics');
    $this->omar = ($this->practitioner)('Dr. Omar Khan', 'oral_maxillofacial_surgery');
    $this->neha = ($this->practitioner)('Dr. Neha Shah', 'sports_rehabilitation');

    $this->newCase = fn (array $fields = [], $as = null) => ($as ?? ($this->asOwner)())->postJson('/clinic/patients', [
        'surface' => 'new_case', 'first_name' => 'Harsh', 'last_name' => 'Patel', 'phone' => '9811100124', 'gender' => 'male', 'check_in' => true, ...$fields,
    ]);
    $this->checkIn = fn (array $body) => ($this->asOwner)()->postJson('/clinic/reception/check-in', ['patient_id' => $this->f->patient()->id, ...$body]);
});

// ---------------------------------------------------------------- Reasons follow the doctor

test('each doctor is offered their own reasons, and the server refuses the rest', function (string $who, string $allowed, string $refused) {
    ($this->checkIn)(['practitioner_id' => $this->{$who}->id, 'visit_reason_key' => $allowed])->assertCreated();
    ($this->checkIn)(['practitioner_id' => $this->{$who}->id, 'visit_reason_key' => $refused])->assertStatus(422)->assertJsonValidationErrors('visit_reason_key');
})->with([
    'BDS dentist' => ['raj', 'tooth_pain', 'braces_consultation'],
    'orthodontist' => ['priya', 'braces_consultation', 'tooth_pain'],
    'endodontist' => ['amit', 'rct_follow_up', 'braces_consultation'],
    'prosthodontist' => ['pooja', 'dentures', 'knee_pain'],
    'oral surgeon' => ['omar', 'wisdom_tooth', 'aligner_consultation'],
    'physiotherapist' => ['neha', 'knee_pain', 'tooth_pain'],
]);

test('a reason sets the visit type it implies; Other needs words', function () {
    ($this->checkIn)(['practitioner_id' => $this->priya->id, 'visit_reason_key' => 'retainer_check'])->assertCreated();
    $visit = PatientVisit::query()->latest('id')->first();
    expect([$visit->visit_reason_key, $visit->visit_reason, $visit->visit_type_key])->toBe(['retainer_check', 'Retainer check', 'follow_up']);

    ($this->checkIn)(['practitioner_id' => $this->priya->id, 'visit_reason_key' => 'other'])->assertStatus(422)->assertJsonValidationErrors('visit_reason');
    ($this->checkIn)(['practitioner_id' => $this->priya->id, 'visit_reason_key' => 'other', 'visit_reason' => 'Broken bracket'])->assertCreated();
    expect(PatientVisit::query()->latest('id')->first()->only(['visit_reason_key', 'visit_reason']))->toBe(['visit_reason_key' => 'other', 'visit_reason' => 'Broken bracket']);
});

test('the intake payload carries reasons with their areas, priorities and doctor fee, and no vitals', function () {
    $this->priya->practitionerProfile()->withoutGlobalScopes()->update(['consultation_fee' => 300]);
    $payload = ($this->asOwner)()->getJson('/clinic/reception/intake')->assertOk()->json();

    expect(collect($payload['catalogue']['visit_reasons'])->firstWhere('key', 'braces_consultation')['practice_area_keys'])->toBe(['orthodontics'])
        ->and($payload['quick_care'])->toBe(['allergy_status' => 'optional'])
        ->and(collect($payload['priorities'])->pluck('value')->first())->toBe('normal')
        ->and(collect($payload['doctors'])->firstWhere('name', 'Dr. Priya Patel')['fee'])->toEqual(300)
        ->and(collect($payload['catalogue']['alerts'])->pluck('key'))->toContain('fall_risk');
});

// ---------------------------------------------------------------- New Case end to end

test('New Case: doctor, reason, quick care, check-in — one transaction with every piece in place', function () {
    $response = ($this->newCase)([
        'doctor_id' => $this->priya->id,
        'visit_reason_key' => 'braces_consultation',
        'priority' => 'urgent',
        // Vitals are no longer part of the product: sent by an old client, they are dropped.
        'vitals' => ['bp_systolic' => 150, 'bp_diastolic' => 90],
        'allergy_status' => 'none_known',
        'alert_keys' => ['fall_risk'],
        'idempotency_key' => 'case-abc-123',
    ])->assertCreated()
        ->assertJsonPath('check_in.context.area.label', 'Orthodontics')
        ->assertJsonPath('check_in.reason', 'Braces consultation')
        ->assertJsonPath('check_in.priority.label', 'Urgent')
        ->assertJsonPath('check_in.position', 1);

    expect($response->json('check_in'))->not->toHaveKey('review')
        ->and(json_encode($response->json()))->not->toContain('hypertension');

    $patient = Patient::query()->where('phone', '9811100124')->sole();
    $visit = PatientVisit::query()->where('patient_id', $patient->id)->sole();
    expect($visit->intake_vitals)->toBeNull()
        ->and($visit->practice_area_key)->toBe('orthodontics')
        ->and($visit->visit_type_key)->toBe('consultation')
        ->and($patient->profile->allergy_status)->toBe('none_known')
        ->and(PatientAlert::query()->where('patient_id', $patient->id)->where('type', 'flag')->pluck('description')->all())->toBe(['Fall risk'])
        ->and(ClinicQueueItem::query()->sole()->priority->value)->toBe('urgent')
        // Billing stays with the canonical checkout after the visit.
        ->and(PatientBilling::query()->withoutGlobalScopes()->count())->toBe(0);

    // The consultation opened from this visit starts without vitals.
    $item = ClinicQueueItem::query()->sole();
    $this->t->actAsOwner();
    $encounter = app(EncounterWorkspace::class)->startFromQueue($item, $this->priya);
    expect($encounter->fresh()->vitals)->toBeNull();
});

test('vitals are never checked or stored, however they are sent', function () {
    ($this->newCase)(['doctor_id' => $this->raj->id, 'vitals' => ['spo2' => 140, 'pulse' => 'abc']])->assertCreated();
    ($this->newCase)(['doctor_id' => $this->raj->id, 'phone' => '9811100125', 'check_in' => false, 'vitals' => ['bp_systolic' => 120]])->assertCreated();

    expect(Patient::query()->count())->toBe(2)->and(PatientVisit::query()->whereNotNull('intake_vitals')->count())->toBe(0);
});

test('allergy status: "no known allergies" cannot sit beside allergies, and a required status needs an answer', function () {
    ($this->newCase)(['doctor_id' => $this->raj->id, 'allergies' => ['latex'], 'allergy_status' => 'none_known'])->assertStatus(422)->assertJsonValidationErrors('allergy_status');

    $this->flow->updateQuickCare($this->f->clinic, ['allergy_status' => 'required'], $this->f->owner);
    ($this->newCase)(['doctor_id' => $this->raj->id])->assertStatus(422)->assertJsonValidationErrors('allergy_status');
    ($this->newCase)(['doctor_id' => $this->raj->id, 'allergy_status' => 'none_known'])->assertCreated();
});

test('Register only saves the patient without a visit or queue entry', function () {
    ($this->newCase)(['check_in' => false])->assertCreated()->assertJsonPath('check_in', null);

    expect(Patient::query()->count())->toBe(1)->and(PatientVisit::query()->count())->toBe(0)->and(ClinicQueueItem::query()->count())->toBe(0);
});

test('a failed check-in rolls the registration back', function () {
    ClinicQueue::withoutGlobalScopes()->create(['clinic_id' => $this->f->clinic->id, 'branch_id' => $this->f->branch->id, 'queue_date' => '2026-10-10', 'name' => 'Closed', 'closed_at' => now()]);

    ($this->newCase)(['doctor_id' => $this->raj->id])->assertStatus(422);

    expect(Patient::query()->count())->toBe(0)->and(PatientVisit::query()->count())->toBe(0);
});

// ---------------------------------------------------------------- Duplicate submissions

test('one registration per request key: a repeat returns the first result', function () {
    $first = ($this->newCase)(['doctor_id' => $this->raj->id, 'idempotency_key' => 'desk-key-0001'])->assertCreated();
    $again = ($this->newCase)(['doctor_id' => $this->raj->id, 'idempotency_key' => 'desk-key-0001'])->assertCreated();

    expect($again->json('replayed'))->toBeTrue()
        ->and($again->json('patient.id'))->toBe($first->json('patient.id'))
        ->and(Patient::query()->count())->toBe(1)
        ->and(ClinicQueueItem::query()->count())->toBe(1);

    [$receptionist, $session] = $this->f->memberWithRole(ClinicRole::Receptionist);
    ($this->newCase)(['doctor_id' => $this->raj->id, 'idempotency_key' => 'desk-key-0001'], $this->actingAs($receptionist)->withSession($session))->assertStatus(409);

    // A failed attempt frees its key for the corrected retry.
    ($this->checkIn)(['practitioner_id' => $this->priya->id, 'visit_reason_key' => 'knee_pain', 'idempotency_key' => 'desk-key-0002'])->assertStatus(422);
    ($this->checkIn)(['practitioner_id' => $this->priya->id, 'visit_reason_key' => 'braces_consultation', 'idempotency_key' => 'desk-key-0002'])->assertCreated();
});

// ---------------------------------------------------------------- Priority and queue

test('an urgent patient goes ahead of an earlier normal one on the desk', function () {
    ($this->checkIn)(['practitioner_id' => $this->raj->id])->assertCreated();
    ($this->checkIn)(['practitioner_id' => $this->raj->id, 'priority' => 'urgent'])->assertCreated();

    ($this->asOwner)()->get('/clinic/reception')->assertInertia(fn ($page) => $page->where('desk.waiting.0.priority', 'urgent')->where('desk.waiting.1.priority', 'normal'));
});

test('priority policy is enforced on the server', function () {
    $this->flow->updateCheckIn($this->f->clinic, ['priorities' => ['urgent']], $this->f->owner);

    ($this->checkIn)(['practitioner_id' => $this->raj->id, 'priority' => 'emergency'])->assertStatus(422)->assertJsonValidationErrors('priority');
    ($this->checkIn)(['practitioner_id' => $this->raj->id, 'priority' => 'urgent'])->assertCreated()->assertJsonPath('check_in.priority.value', 'urgent');
    expect(ClinicSettingAudit::query()->where('section', 'check_in')->exists())->toBeTrue();
});

test('with queue positions switched off, check-in still lists the patient but shows no position', function () {
    $this->flow->updateCheckIn($this->f->clinic, ['queue' => false], $this->f->owner);

    ($this->checkIn)(['practitioner_id' => $this->raj->id])->assertCreated()->assertJsonPath('check_in.queue', false)->assertJsonPath('check_in.position', null);
    expect(ClinicQueueItem::query()->count())->toBe(1);
});

// ---------------------------------------------------------------- Bookings made before registration

test('New Case for a prospect booking registers and checks in that booking, once', function () {
    $booking = Appointment::create([
        'clinic_id' => $this->f->clinic->id, 'branch_id' => $this->f->branch->id, 'practitioner_id' => $this->priya->id,
        'prospect_name' => 'Harsh Patel', 'prospect_phone' => '9811100124', 'scheduled_at' => CarbonImmutable::parse('2026-10-10 11:00'), 'duration_minutes' => 15, 'status' => AppointmentStatus::Scheduled,
    ]);

    ($this->asOwner)()->getJson("/clinic/reception/intake?appointment={$booking->id}")->assertOk()
        ->assertJsonPath('booking.doctor_id', $this->priya->id)->assertJsonPath('booking.phone', '9811100124');

    // No doctor sent: the booking's practitioner decides the context.
    ($this->newCase)(['appointment_id' => $booking->id, 'visit_reason_key' => 'braces_consultation'])->assertCreated()
        ->assertJsonPath('check_in.doctor', 'Dr. Priya Patel')->assertJsonPath('check_in.context.source', 'appointment');

    $booking->refresh();
    expect($booking->patient_id)->toBe(Patient::query()->sole()->id)
        ->and($booking->status)->toBe(AppointmentStatus::CheckedIn)
        ->and(ClinicQueueItem::query()->sole()->appointment_id)->toBe($booking->id);

    // Claimed now: a second New Case cannot take it.
    ($this->newCase)(['appointment_id' => $booking->id, 'phone' => '9811100999', 'first_name' => 'Someone'])->assertNotFound();
});

test('another clinic\'s booking is not found', function () {
    $other = TreatmentFixture::make('other-clinic');
    $theirs = Appointment::create([
        'clinic_id' => $other->f->clinic->id, 'branch_id' => $other->f->branch->id, 'practitioner_id' => $other->f->doctorA->id,
        'prospect_name' => 'Elsewhere', 'prospect_phone' => '9811100555', 'scheduled_at' => CarbonImmutable::parse('2026-10-10 11:00'), 'duration_minutes' => 15, 'status' => AppointmentStatus::Scheduled,
    ]);

    ($this->newCase)(['appointment_id' => $theirs->id])->assertNotFound();
    ($this->asOwner)()->getJson("/clinic/reception/intake?appointment={$theirs->id}")->assertNotFound();
});

// ---------------------------------------------------------------- Owner settings

test('owners configure Quick Care; values are checked and audited; reception cannot', function () {
    // Vitals settings sent by an old screen are ignored; the rest is saved and audited.
    ($this->asOwner)()->put('/clinic/admin/settings/quick-care', [
        'vitals' => ['blood_sugar' => ['state' => 'required']],
        'temperature_unit' => 'c',
        'allergy_status' => 'required',
        'alerts' => false,
    ])->assertSessionHasNoErrors();

    expect($this->flow->quickCare($this->f->clinic->fresh()))->toBe(['allergy_status' => 'required', 'alerts' => false, 'alert_roles' => null])
        ->and($this->f->clinic->fresh()->patient_flow_config['quick_care'])->not->toHaveKey('vitals')
        ->and(ClinicSettingAudit::query()->where('section', 'quick_care')->exists())->toBeTrue();
    ($this->asOwner)()->getJson('/clinic/reception/intake')->assertJsonPath('catalogue.alerts', []);

    ($this->asOwner)()->put('/clinic/admin/settings/quick-care', ['allergy_status' => 'sometimes'])->assertSessionHasErrors('allergy_status');

    [$receptionist, $session] = $this->f->memberWithRole(ClinicRole::Receptionist);
    $this->actingAs($receptionist)->withSession($session)->put('/clinic/admin/settings/quick-care', ['alerts' => true])->assertForbidden();
});
