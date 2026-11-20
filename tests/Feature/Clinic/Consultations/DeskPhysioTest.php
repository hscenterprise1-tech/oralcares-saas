<?php

use App\ClinicRole;
use App\Models\ClinicalEncounter;
use App\Models\ClinicEmployee;
use App\Models\ClinicQueueItem;
use App\Models\PatientSessionPackage;
use App\Models\PatientTreatment;
use App\Services\Physio\PhysioCatalogue;
use App\Services\Reception\ReceptionCheckIn;
use App\Services\Reception\VisitContext;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\TreatmentFixture;

/**
 * The desk runs a physiotherapy visit for a therapist who has no login: it
 * opens the visit from the queue and works it in the therapist's workspace,
 * in the therapist's name. A therapist who can sign in does their own work,
 * and no other specialty is opened this way.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-05 10:00', 'Asia/Kolkata'));
    $this->t = TreatmentFixture::make();
    $this->f = $this->t->f;
    [$this->deskUser, $this->deskSession] = $this->f->memberWithRole(ClinicRole::Receptionist);
    ClinicEmployee::create(['clinic_id' => $this->f->clinic->id, 'user_id' => $this->deskUser->id, 'name' => 'Front Desk', 'email' => 'desk@example.com', 'status' => 'active']);
    $this->asDesk = fn () => $this->actingAs($this->deskUser)->withSession($this->deskSession);

    // The therapist without a login, and one who has a login.
    $this->therapist = $this->f->doctor('Neha Rao');
    $this->therapist->practitionerProfile()->withoutGlobalScopes()->update(['specialty' => 'Physiotherapist']);
    [$therapistUser] = $this->f->memberWithRole(ClinicRole::Doctor);
    $this->signedIn = $this->f->doctor('Amit Shah', $therapistUser);
    $this->signedIn->practitionerProfile()->withoutGlobalScopes()->update(['specialty' => 'Physiotherapist']);

    app(PhysioCatalogue::class)->save($this->f->clinic, [
        'treatments' => [['key' => 'ift', 'label' => 'IFT', 'minutes' => 15, 'price' => 150], ['key' => 'exercise', 'label' => 'Exercise', 'minutes' => 20, 'price' => 200]],
        'addons' => [],
        'problems' => [['key' => 'knee_pain', 'label' => 'Knee pain', 'sided' => true, 'types' => [
            ['key' => 'full', 'label' => 'Full treatment', 'treatments' => ['ift', 'exercise'], 'price' => 300],
        ], 'packages' => [['id' => 'k10', 'name' => 'Knee 10', 'sessions' => 10, 'price' => 2500, 'valid_days' => 30, 'types' => ['full']]]]],
    ], $this->t->actAsOwner());

    $this->checkIn = fn (ClinicEmployee $doctor, string $specialty = 'physiotherapy'): ClinicQueueItem => app(ReceptionCheckIn::class)->checkIn(
        $this->f->clinic, $this->f->branch, $this->f->patient('Ravi', 'Kumar'), doctor: $doctor,
        context: VisitContext::fromInput($this->f->clinic, ['visit_specialty' => $specialty, 'visit_reason' => 'Knee pain']),
    );
    $this->open = fn (ClinicQueueItem $item) => ($this->asDesk)()->post("/clinic/reception/physio/queue/{$item->id}/open");
});

test('the desk opens the visit from the queue and runs it in the therapist\'s name: plan, package, session', function () {
    $item = ($this->checkIn)($this->therapist);
    ($this->open)($item)->assertRedirect();
    $encounter = ClinicalEncounter::query()->withoutGlobalScopes()->sole();
    expect([$encounter->doctor_id, $encounter->specialty])->toBe([$this->therapist->id, 'physiotherapy']);

    ($this->asDesk)()->get("/clinic/front-desk/physio/{$encounter->id}")->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('ClinicPortal/Consultations/Physio')->where('desk.therapist', 'Neha Rao')->where('can_edit', true)->where('visit.reason', 'Knee pain'));

    $base = "/clinic/front-desk/physio/{$encounter->id}";
    ($this->asDesk)()->post("{$base}/plan", ['problem' => 'knee_pain', 'side' => 'right', 'type' => 'full', 'sessions' => 10, 'start' => true])->assertSessionHasNoErrors();
    $knee = PatientTreatment::query()->withoutGlobalScopes()->sole();
    ($this->asDesk)()->post("{$base}/courses/{$knee->id}/package", ['package' => 'k10'])->assertRedirect()->assertSessionHasNoErrors();
    ($this->asDesk)()->post("{$base}/finish", ['courses' => [['id' => $knee->id, 'done' => true]]])->assertSessionHasNoErrors();

    expect($knee->fresh()->status)->toBe(PatientTreatment::IN_PROGRESS)
        ->and(PatientSessionPackage::withoutGlobalScopes()->sole()->sessions_used)->toBe(1)
        ->and($encounter->fresh()->isEditable())->toBeFalse();
    // A finished visit is read only, at the desk as for the therapist.
    ($this->asDesk)()->post("{$base}/plan", ['course_id' => $knee->id, 'sessions' => 12])->assertForbidden();
});

test('the desk does not open or run the visit of a therapist who can sign in, nor another specialty', function () {
    ($this->open)(($this->checkIn)($this->signedIn))->assertForbidden();
    expect(ClinicalEncounter::query()->withoutGlobalScopes()->count())->toBe(0);

    // A dental visit of a practitioner without a login is not a physiotherapy one.
    $dentist = $this->f->doctor('Dr. Tooth');
    ($this->open)(($this->checkIn)($dentist, 'dental'))->assertForbidden();
    expect(ClinicalEncounter::query()->withoutGlobalScopes()->count())->toBe(0);
});

test('the desk\'s own path is refused for a therapist\'s visit once they can sign in', function () {
    ($this->open)(($this->checkIn)($this->therapist))->assertRedirect();
    $encounter = ClinicalEncounter::query()->withoutGlobalScopes()->sole();
    [$newUser] = $this->f->memberWithRole(ClinicRole::Doctor);
    $this->therapist->forceFill(['user_id' => $newUser->id])->save();

    ($this->asDesk)()->get("/clinic/front-desk/physio/{$encounter->id}")->assertForbidden();
    ($this->asDesk)()->post("/clinic/front-desk/physio/{$encounter->id}/plan", ['problem' => 'knee_pain', 'side' => 'left', 'type' => 'full', 'start' => false])->assertForbidden();
    expect(PatientTreatment::query()->withoutGlobalScopes()->count())->toBe(0);
});

test('a finished visit is collected from its row: a package bill by its balance, a visit\'s charges billed and paid in one step', function () {
    $props = function () {
        $props = null;
        ($this->asDesk)()->get('/clinic/reception')->assertOk()->assertInertia(function (Assert $page) use (&$props) {
            $props = $page->toArray()['props'];
        });

        return $props;
    };
    $finish = function (string $first, ?string $package) {
        $item = app(ReceptionCheckIn::class)->checkIn($this->f->clinic, $this->f->branch, $this->f->patient($first, 'Desk'), doctor: $this->therapist,
            context: VisitContext::fromInput($this->f->clinic, ['visit_specialty' => 'physiotherapy', 'visit_reason' => 'Knee pain']));
        ($this->open)($item)->assertRedirect();
        $encounter = ClinicalEncounter::query()->withoutGlobalScopes()->where('patient_id', $item->patientVisit()->withoutGlobalScopes()->value('patient_id'))->sole();
        $base = "/clinic/front-desk/physio/{$encounter->id}";
        ($this->asDesk)()->post("{$base}/plan", ['problem' => 'knee_pain', 'side' => 'right', 'type' => 'full', 'sessions' => 10, 'start' => true, 'package' => $package])->assertSessionHasNoErrors();
        $knee = PatientTreatment::query()->withoutGlobalScopes()->where('patient_id', $encounter->patient_id)->sole();
        ($this->asDesk)()->post("{$base}/finish", ['courses' => [['id' => $knee->id, 'done' => true]]])->assertSessionHasNoErrors();

        return $encounter;
    };

    // A package sold during the visit: its bill is the visit's, collected from the row by its balance.
    $withPackage = $finish('Pack', 'k10');
    $row = collect($props()['desk']['done'])->firstWhere('patient.name', 'Pack Desk');
    expect($row['payment'])->toBe('due')
        ->and($row['collect'])->toMatchArray(['amount' => 2500.0, 'bills' => false, 'encounter_id' => $withPackage->id]);
    ($this->asDesk)()->post($row['collect']['url'], ['method' => 'cash', 'amount' => 2500])->assertSessionHasNoErrors();

    // Paying per visit: the visit's charges are billed at the clinic's prices and paid in one step.
    $finish('Visit', null);
    $row = collect($props()['desk']['done'])->firstWhere('patient.name', 'Visit Desk');
    expect($row['payment'])->toBe('not_billed')->and($row['collect'])->toMatchArray(['amount' => 300.0, 'bills' => true]);
    ($this->asDesk)()->post($row['collect']['url'], ['method' => 'cash', 'amount' => 300])->assertSessionHasNoErrors();

    $done = collect($props()['desk']['done']);
    expect($done->firstWhere('patient.name', 'Pack Desk')['payment'])->toBe('paid')->and($done->firstWhere('patient.name', 'Pack Desk')['collect'])->toBeNull()
        ->and($done->firstWhere('patient.name', 'Visit Desk')['payment'])->toBe('paid');
});

test('a registered patient\'s booking is checked in from its row; a prospect has no check-in link', function () {
    $patient = $this->f->patient('Booked', 'Desk');
    $booking = $this->f->appointment($patient, $this->therapist, CarbonImmutable::parse('2026-11-05 11:00'));
    $prospect = $this->f->appointment($patient, $this->therapist, CarbonImmutable::parse('2026-11-05 12:00'));
    $prospect->forceFill(['patient_id' => null, 'prospect_name' => 'New Person', 'prospect_phone' => '9000000999'])->save();

    $upcoming = null;
    ($this->asDesk)()->get('/clinic/reception')->assertOk()->assertInertia(function (Assert $page) use (&$upcoming) {
        $upcoming = collect($page->toArray()['props']['desk']['upcoming']);
    });
    $row = $upcoming->firstWhere('id', $booking->id);
    expect($row['links']['check_in'])->toBe(route('clinic.reception.check-in'))
        ->and($upcoming->firstWhere('id', $prospect->id)['links'])->not->toHaveKey('check_in');

    ($this->asDesk)()->post($row['links']['check_in'], ['appointment_id' => $booking->id])->assertRedirect()->assertSessionHasNoErrors();
    expect(ClinicQueueItem::query()->withoutGlobalScopes()->where('appointment_id', $booking->id)->exists())->toBeTrue();
});

test('the desk collects from the finished visit\'s own page: the package bill and earlier balances, nothing while the visit runs', function () {
    $page = function (int $encounterId) {
        $props = null;
        ($this->asDesk)()->get("/clinic/front-desk/physio/{$encounterId}")->assertOk()->assertInertia(function (Assert $page) use (&$props) {
            $props = $page->toArray()['props'];
        });

        return $props;
    };

    ($this->open)(($this->checkIn)($this->therapist))->assertRedirect();
    $encounter = ClinicalEncounter::query()->withoutGlobalScopes()->sole();
    $base = "/clinic/front-desk/physio/{$encounter->id}";
    expect($page($encounter->id)['payment'])->toBeNull();

    ($this->asDesk)()->post("{$base}/plan", ['problem' => 'knee_pain', 'side' => 'right', 'type' => 'full', 'sessions' => 10, 'start' => true, 'package' => 'k10'])->assertSessionHasNoErrors();
    $knee = PatientTreatment::query()->withoutGlobalScopes()->sole();
    ($this->asDesk)()->post("{$base}/finish", ['courses' => [['id' => $knee->id, 'done' => true]]])->assertSessionHasNoErrors();

    // The package sold during the visit is the visit's own bill, collected here by its balance.
    $payment = $page($encounter->id)['payment'];
    expect($payment['items'])->toHaveCount(1)
        ->and($payment['items'][0])->toMatchArray(['label' => 'This visit', 'amount' => 2500.0, 'bills' => false])
        ->and(collect($payment['methods'])->pluck('value')->all())->toContain('cash');
    ($this->asDesk)()->post($payment['items'][0]['url'], ['method' => 'cash', 'amount' => 2500])->assertSessionHasNoErrors();

    expect($page($encounter->id)['payment']['items'])->toBe([]);
});
