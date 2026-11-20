<?php

use App\BranchStatus;
use App\ClinicRole;
use App\Models\Branch;
use App\Models\ClinicalEncounter;
use App\Models\ClinicEmployee;
use App\Models\ClinicQueueItem;
use App\Models\PatientSessionPackage;
use App\Models\PatientTreatment;
use App\Models\PatientVisit;
use App\Models\PhysioSession;
use App\Models\SecurityAuditLog;
use App\Models\SessionPackageUsage;
use App\Services\Billing\EncounterCheckout;
use App\Services\Clinical\EncounterWorkspace;
use App\Services\Physio\PhysioRecord;
use App\Services\Physio\SessionPackages;
use App\Services\QueueStateMachine;
use App\Services\Reception\ReceptionCheckIn;
use App\Services\Treatment\PatientTreatments;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\TreatmentFixture;

/**
 * Physiotherapy run from the front desk, for therapists who do not sign in:
 * the desk sees the patient's treatments, sessions and package balances,
 * and confirms that a session took place — the only moment a package
 * session is used, and only from that treatment's own package. Confirming
 * is its own permission: no notes, no diagnosis, no sign-off.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-12 10:00', 'Asia/Kolkata'));
    $this->t = TreatmentFixture::make();
    $this->f = $this->t->f;
    // The therapist has no login.
    $this->therapist = $this->t->physio;
    expect($this->therapist->user_id)->toBeNull();
    $this->therapist->practitionerProfile()->withoutGlobalScopes()->update(['consultation_fee' => 400]);

    [$this->receptionist, $this->receptionSession] = $this->f->memberWithRole(ClinicRole::Receptionist);
    $this->desk = ClinicEmployee::create(['clinic_id' => $this->f->clinic->id, 'name' => 'Desk Riya', 'status' => 'active', 'user_id' => $this->receptionist->id, 'email' => 'desk-riya@example.com']);
    $this->asReception = fn () => $this->actingAs($this->receptionist)->withSession($this->receptionSession);

    $this->patient = $this->f->patient('Meera', 'Joshi');
    $owner = $this->t->actAsOwner();
    $treatments = app(PatientTreatments::class);
    $this->knee = $treatments->startAdHoc($this->patient, 'physiotherapy', 'Knee Rehabilitation', $this->therapist, $this->f->branch, $owner, ['sessions_planned' => 8]);
    $this->back = $treatments->startAdHoc($this->patient, 'physiotherapy', 'Back Rehabilitation', $this->therapist, $this->f->branch, $owner);
    $this->package = app(SessionPackages::class)->sell($this->knee, $this->f->branch, ['name' => 'Knee 6-pack', 'sessions_total' => 6, 'price' => 3000], $this->therapist);

    $this->checkIn = fn () => app(ReceptionCheckIn::class)->checkIn($this->f->clinic, $this->f->branch, $this->patient->fresh(), doctor: $this->therapist);
    $this->confirm = fn (ClinicQueueItem $item, array $body) => ($this->asReception)()->postJson("/clinic/reception/queue/{$item->id}/physio-session", ['confirm' => true, ...$body]);
    $this->balance = fn () => app(SessionPackages::class)->balance($this->package->fresh());
});

test('the desk confirms a session for a therapist without a login: one session, paid once from the treatment\'s own package, visit finished, audited', function () {
    $item = ($this->checkIn)();
    // Checking in uses nothing.
    expect(($this->balance)())->toBe(6);

    $body = ['patient_treatment_id' => $this->knee->id, 'package_id' => $this->package->id, 'idempotency_key' => 'desk-confirm-0001'];
    ($this->confirm)($item, $body)->assertCreated()->assertJsonPath('session.number', 1);
    // A double tap (same key) and a later retry (no key) change nothing.
    ($this->confirm)($item, $body)->assertSuccessful();
    ($this->confirm)($item, ['patient_treatment_id' => $this->knee->id, 'package_id' => $this->package->id])->assertSuccessful();

    $session = PhysioSession::query()->withoutGlobalScopes()->sole();
    expect($session->status)->toBe(PhysioSession::COMPLETED)
        ->and($session->therapist_id)->toBe($this->therapist->id)
        ->and($session->completed_by)->toBe($this->desk->id)
        ->and($session->package_id)->toBe($this->package->id)
        ->and($session->subjective)->toBeNull()
        ->and(($this->balance)())->toBe(5)
        ->and(SessionPackageUsage::query()->withoutGlobalScopes()->where('entry_type', SessionPackageUsage::CONSUME)->count())->toBe(1)
        ->and($this->knee->fresh()->sessions_completed)->toBe(1);

    // The visit is finished; the consultation is the session's container, in the therapist's name, unsigned.
    $encounter = ClinicalEncounter::query()->withoutGlobalScopes()->findOrFail($session->encounter_id);
    expect($item->fresh()->status->value)->toBe('completed')
        ->and(PatientVisit::query()->withoutGlobalScopes()->findOrFail($item->patient_visit_id)->visit_status)->toBe('completed')
        ->and($encounter->doctor_id)->toBe($this->therapist->id)
        ->and($encounter->isFinalized())->toBeFalse()
        ->and(SecurityAuditLog::query()->where('event', 'physio.session.confirmed_at_desk')->count())->toBe(1);

    // The package paid for this consultation: nothing more to charge for it.
    expect(collect(app(EncounterCheckout::class)->lines($encounter->fresh()))->pluck('key'))->not->toContain('consultation');
});

test('without a package nothing is taken from one, and the consultation stays billable', function () {
    $item = ($this->checkIn)();
    ($this->confirm)($item, ['patient_treatment_id' => $this->knee->id])->assertCreated();

    $session = PhysioSession::query()->withoutGlobalScopes()->sole();
    expect($session->package_id)->toBeNull()
        ->and(($this->balance)())->toBe(6)
        ->and(collect(app(EncounterCheckout::class)->lines(ClinicalEncounter::query()->withoutGlobalScopes()->findOrFail($session->encounter_id)))->pluck('key'))->toContain('consultation');
});

test('another treatment\'s package is refused: no session used, no fee waived', function () {
    $item = ($this->checkIn)();

    ($this->confirm)($item, ['patient_treatment_id' => $this->back->id, 'package_id' => $this->package->id])
        ->assertStatus(422)->assertJsonPath('message', 'That package belongs to a different treatment.');

    expect(($this->balance)())->toBe(6)
        ->and(PhysioSession::query()->withoutGlobalScopes()->where('status', PhysioSession::COMPLETED)->count())->toBe(0)
        ->and($item->fresh()->status->value)->toBe('waiting');
});

test('a missed visit or an unconfirmed draft uses nothing; a patient no longer in the queue cannot be confirmed', function () {
    $item = ($this->checkIn)();
    app(QueueStateMachine::class)->cancel($item);
    ($this->confirm)($item, ['patient_treatment_id' => $this->knee->id, 'package_id' => $this->package->id])->assertStatus(422);

    // A therapist who does sign in opens a draft and leaves it: nothing is used.
    [$user, $session] = $this->f->memberWithRole(ClinicRole::Doctor);
    $therapist = $this->f->doctor('Mr. Signed In', $user);
    $therapist->practitionerProfile()->withoutGlobalScopes()->update(['specialty' => 'Physiotherapist']);
    $this->t->actAs($user, $session);
    $this->travel(1)->days();
    $next = app(ReceptionCheckIn::class)->checkIn($this->f->clinic, $this->f->branch, $this->patient->fresh(), doctor: $therapist);
    $encounter = app(EncounterWorkspace::class)->startFromQueue($next, $therapist);
    app(PhysioRecord::class)->openSession($this->knee, $encounter, $therapist, $user);

    expect(($this->balance)())->toBe(6)
        ->and(SessionPackageUsage::query()->withoutGlobalScopes()->count())->toBe(0)
        ->and(PhysioSession::query()->withoutGlobalScopes()->sole()->status)->toBe(PhysioSession::DRAFT);
});

test('reversing a desk-confirmed session needs a reason and gives the package session back exactly once', function () {
    $item = ($this->checkIn)();
    ($this->confirm)($item, ['patient_treatment_id' => $this->knee->id, 'package_id' => $this->package->id])->assertCreated();
    $session = PhysioSession::query()->withoutGlobalScopes()->sole();
    $owner = $this->t->actAsOwner();
    $physio = app(PhysioRecord::class);

    expect(fn () => $physio->reverseSession($session, ' ', $this->therapist, $owner))->toThrow(InvalidArgumentException::class);
    $physio->reverseSession($session, 'Confirmed for the wrong patient', $this->therapist, $owner);
    expect(fn () => $physio->reverseSession($session->fresh(), 'Again', $this->therapist, $owner))->toThrow(DomainException::class);

    expect(($this->balance)())->toBe(6)
        ->and(SessionPackageUsage::query()->withoutGlobalScopes()->where('entry_type', SessionPackageUsage::REVERSAL)->count())->toBe(1)
        ->and($this->knee->fresh()->sessions_completed)->toBe(0);
});

test('only members who may confirm sessions can; confirming grants no clinical writing or signing', function () {
    $item = ($this->checkIn)();
    $body = ['patient_treatment_id' => $this->knee->id, 'package_id' => $this->package->id];

    foreach ([ClinicRole::Doctor, ClinicRole::BillingStaff] as $role) {
        [$user, $session] = $this->f->memberWithRole($role);
        $this->actingAs($user)->withSession($session)->postJson("/clinic/reception/queue/{$item->id}/physio-session", ['confirm' => true, ...$body])->assertForbidden();
    }
    // The confirmation must be explicit.
    ($this->asReception)()->postJson("/clinic/reception/queue/{$item->id}/physio-session", $body)->assertStatus(422)->assertJsonValidationErrors('confirm');
    expect(($this->balance)())->toBe(6);

    ($this->confirm)($item, $body)->assertCreated();
    $encounter = PhysioSession::query()->withoutGlobalScopes()->sole()->encounter_id;
    // Notes, the session form and signing stay clinical.
    ($this->asReception)()->post("/clinic/consultations/{$encounter}/notes", ['body' => 'Felt better'])->assertForbidden();
    ($this->asReception)()->post("/clinic/consultations/{$encounter}/physio/session", ['patient_treatment_id' => $this->knee->id, 'subjective' => 'x'])->assertForbidden();
    ($this->asReception)()->post("/clinic/consultations/{$encounter}/finalize")->assertForbidden();
});

test('another clinic\'s queue entry or treatment cannot be confirmed here', function () {
    $other = TreatmentFixture::make('other-physio');
    $theirPatient = $other->f->patient('Their', 'Patient');
    $theirItem = app(ReceptionCheckIn::class)->checkIn($other->f->clinic, $other->f->branch, $theirPatient, doctor: $other->physio);
    $item = ($this->checkIn)();
    $owner = $other->actAsOwner();
    $theirTreatment = app(PatientTreatments::class)->startAdHoc($theirPatient, 'physiotherapy', 'Their knee', $other->physio, $other->f->branch, $owner);

    ($this->confirm)($theirItem, ['patient_treatment_id' => $this->knee->id])->assertNotFound();
    ($this->confirm)($item, ['patient_treatment_id' => $theirTreatment->id])->assertNotFound();
    ($this->asReception)()->getJson("/clinic/reception/patients/{$theirPatient->id}/physio-summary")->assertNotFound();
});

test('a queue entry at another branch of the clinic is not confirmed from this one', function () {
    $north = Branch::factory()->create(['clinic_id' => $this->f->clinic->id, 'name' => 'North', 'is_main' => false, 'status' => BranchStatus::Active]);
    $item = app(ReceptionCheckIn::class)->checkIn($this->f->clinic, $north, $this->patient->fresh(), doctor: $this->therapist);

    ($this->confirm)($item, ['patient_treatment_id' => $this->knee->id, 'package_id' => $this->package->id])->assertNotFound();
    expect(($this->balance)())->toBe(6);
});

test('the check-in summary: treatments, sessions done of planned, package balance and amount due — and safe when there is nothing', function () {
    $item = ($this->checkIn)();
    ($this->confirm)($item, ['patient_treatment_id' => $this->knee->id, 'package_id' => $this->package->id])->assertCreated();

    $physio = ($this->asReception)()->getJson("/clinic/reception/patients/{$this->patient->id}/physio-summary")->assertOk()->json('physio');
    $knee = collect($physio['treatments'])->firstWhere('id', $this->knee->id);
    expect($knee)->toMatchArray(['title' => 'Knee Rehabilitation', 'sessions_planned' => 8, 'sessions_completed' => 1])
        ->and($knee['packages'])->toBe([['id' => $this->package->id, 'name' => 'Knee 6-pack', 'sessions_total' => 6, 'remaining' => 5, 'valid_until' => $this->package->valid_until?->toDateString()]])
        ->and(collect($physio['treatments'])->firstWhere('id', $this->back->id)['packages'])->toBe([])
        ->and($physio['amount_due'])->toEqual(3000)
        // Not in today's queue any more: nothing to confirm.
        ->and($physio['confirm'])->toBeNull();

    $nobody = $this->f->patient('No', 'Treatment');
    expect(($this->asReception)()->getJson("/clinic/reception/patients/{$nobody->id}/physio-summary")->assertOk()->json('physio'))
        ->toMatchArray(['treatments' => [], 'amount_due' => 0, 'confirm' => null]);
});

test('Patient 360 carries the same summary, with the confirm action only while the patient is queued here', function () {
    ($this->checkIn)();
    $overview = [];
    ($this->asReception)()->get("/clinic/patients/{$this->patient->id}")->assertOk()->assertInertia(function (Assert $page) use (&$overview) {
        $overview = $page->toArray()['props']['overview'];
    });

    expect(collect($overview['physio_care']['treatments'])->pluck('title')->sort()->values()->all())->toBe(['Back Rehabilitation', 'Knee Rehabilitation'])
        ->and($overview['physio_care']['confirm']['therapist'])->toBe($this->therapist->name)
        ->and($overview['physio_care']['confirm']['url'])->toContain('/physio-session');
});

test('a dental-only clinic gets no physiotherapy summary or action', function () {
    $this->f->clinic->update(['specialties' => ['dental']]);
    $dentalPatient = $this->f->patient('Only', 'Dental');

    expect(($this->asReception)()->getJson("/clinic/reception/patients/{$dentalPatient->id}/physio-summary")->assertOk()->json('physio'))->toBeNull();
    expect(PatientTreatment::query()->withoutGlobalScopes()->count())->toBe(2)
        ->and(PatientSessionPackage::query()->withoutGlobalScopes()->count())->toBe(1);
});

test('the desk records who gave the session and a short note, changing the queued therapist when it was someone else', function () {
    $other = $this->f->doctor('Ms. Second Therapist');
    $other->practitionerProfile()->withoutGlobalScopes()->update(['specialty' => 'Physiotherapist']);
    $item = ($this->checkIn)();

    ($this->confirm)($item, [
        'patient_treatment_id' => $this->knee->id, 'therapist_id' => $other->id,
        'treatment_performed' => 'IFT 15 min, quadriceps strengthening', 'pain_before' => 6, 'pain_after' => 3, 'patient_response' => 'Walking easier',
    ])->assertCreated();

    $session = PhysioSession::query()->withoutGlobalScopes()->sole();
    expect($session->therapist_id)->toBe($other->id)
        ->and($session->treatment_performed)->toBe('IFT 15 min, quadriceps strengthening')
        ->and([$session->pain_before, $session->pain_after])->toBe([6, 3])
        ->and($session->patient_response)->toBe('Walking easier')
        ->and($item->fresh()->doctor_id)->toBe($other->id);
});

test('the desk starts a physiotherapy programme for a therapist without a login, and nothing else', function () {
    $patient = $this->f->patient('Ravi', 'Kumar');

    ($this->asReception)()->postJson("/clinic/reception/patients/{$patient->id}/physio-programme", ['title' => 'Shoulder rehab', 'sessions_planned' => 10, 'therapist_id' => $this->therapist->id])
        ->assertCreated()->assertJsonPath('physio.treatments.0.title', 'Shoulder rehab')->assertJsonPath('physio.treatments.0.sessions_planned', 10);

    $programme = PatientTreatment::query()->withoutGlobalScopes()->where('patient_id', $patient->id)->sole();
    expect($programme->specialty_key)->toBe('physiotherapy')
        ->and($programme->practitioner_id)->toBe($this->therapist->id);

    // The desk's session permission starts physiotherapy only, never another specialty.
    $this->actingAs($this->receptionist);
    expect(fn () => app(PatientTreatments::class)->startAdHoc($patient, 'dental', 'Fillings', $this->therapist, $this->f->branch, $this->receptionist, ['at_desk' => true]))
        ->toThrow(Exception::class);
});

test('the desk summary lists today\'s physiotherapists and where a programme starts', function () {
    ($this->asReception)()->getJson("/clinic/reception/patients/{$this->patient->id}/physio-summary")
        ->assertOk()
        ->assertJsonPath('physio.therapists.0.id', $this->therapist->id)
        ->assertJsonPath('physio.start_url', route('clinic.reception.physio-programme', $this->patient->id));
});
