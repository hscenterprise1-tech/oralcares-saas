<?php

use App\ClinicRole;
use App\Exceptions\OwnerModeRequired;
use App\Models\Patient;
use App\PaymentMethod;
use App\Services\BillingService;
use App\Services\OwnerMode\OwnerMode;
use App\Services\PaymentService;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\ReceptionFixture;

/**
 * Owner and Doctor Mode, enforced on the server.
 *
 * An owner who does not treat patients holds the owner's privileges from sign-in. An owner who also
 * works as a doctor works in Doctor Mode, with a doctor's access to their own patients, until they
 * enter Owner Mode for clinic-wide work or other doctors' patients. Leaving Owner Mode, or letting
 * it expire, restores Doctor Mode. A Doctor or Admin role never makes anyone the owner.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-23 10:00', 'Asia/Kolkata'));
    config(['security.owner_mode' => ['idle_timeout_minutes' => 15, 'lifetime_minutes' => 60, 'fresh_verification_minutes' => 5]]);
    $this->f = ReceptionFixture::make('owner-doctor-mode');

    // Two patients, each assigned to a doctor: the owner's own, and Dr. Asha Mehta's.
    $this->ownerAsDoctor = $this->f->doctor('Dr. Owner', $this->f->owner);
    $this->mine = $this->f->patient('Mine', 'Patient', ['current_practitioner_id' => $this->ownerAsDoctor->id]);
    $this->theirs = $this->f->patient('Their', 'Patient', ['current_practitioner_id' => $this->f->doctorA->id]);
    $this->bill = app(BillingService::class)->createBill($this->f->clinic, $this->theirs, $this->f->branch, [['description' => 'Scaling', 'quantity' => 1, 'unit_price' => 900]]);
    app(PaymentService::class)->recordPayment($this->bill->fresh(), PaymentMethod::Cash, 900);

    $this->asOwner = fn () => $this->actingAs($this->f->owner)->withSession($this->f->session);
    $this->writeAllergy = fn (Patient $patient) => $this->putJson("/clinic/patients/{$patient->id}/clinical-entries/allergy", ['entries' => [['label' => 'Penicillin', 'severity' => 'critical']]]);
    $this->inertia = ['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest'];
});

test('in Doctor Mode the owner works with their own patients, and nothing clinic-wide or of another doctor\'s', function () {
    ($this->asOwner)();

    // Their own assigned patient: ordinary clinical work, no prompt.
    ($this->writeAllergy)($this->mine)->assertOk();

    // Another doctor's patient: a doctor's rule, not the owner's.
    ($this->writeAllergy)($this->theirs)->assertForbidden();

    // Clinic-wide pages and data, by URL and by API: offered Owner Mode, never carried out.
    $this->getJson('/clinic/billing/summary')->assertForbidden()->assertHeader(OwnerModeRequired::HEADER, 'inactive')->assertJsonPath('owner_mode.executed', false);
    $this->getJson('/clinic/billing')->assertForbidden()->assertHeader(OwnerModeRequired::HEADER, 'inactive');
    $this->get('/clinic/admin/employees')->assertForbidden();
    // In Doctor Mode their home is the Doctor Dashboard, offering Owner Mode.
    $this->followingRedirects()->get('/clinic/dashboard')->assertInertia(fn (Assert $page) => $page->component('ClinicPortal/Today/Index', false)
        ->where('clinic.owner_mode', fn ($mode) => $mode['available'] === true && $mode['active'] === false && $mode['owner'] === true));
});

test('Owner Mode opens other doctors\' patients and the clinic\'s management; leaving it restores Doctor Mode', function () {
    ($this->asOwner)()->post('/clinic/owner-mode', ['password' => 'password'])->assertSessionHasNoErrors();

    ($this->writeAllergy)($this->theirs)->assertOk();
    $this->get('/clinic/billing/summary')->assertOk();
    $this->getJson("/clinic/billing/bills/{$this->bill->id}")->assertOk();
    $this->get('/clinic/dashboard')->assertInertia(fn (Assert $page) => $page->component('ClinicPortal/CommandCenter/Index', false));

    $this->delete('/clinic/owner-mode')->assertRedirect('/clinic/dashboard');

    ($this->writeAllergy)($this->theirs)->assertForbidden();
    $this->get('/clinic/billing/summary')->assertForbidden();
    ($this->writeAllergy)($this->mine)->assertOk();
});

test('when Owner Mode expires the owner is back in Doctor Mode, on the server', function () {
    ($this->asOwner)()->post('/clinic/owner-mode', ['password' => 'password'])->assertSessionHasNoErrors();
    ($this->writeAllergy)($this->theirs)->assertOk();

    $this->travel(16)->minutes();

    ($this->writeAllergy)($this->theirs)->assertForbidden();
    $this->getJson('/clinic/billing')->assertForbidden()->assertJsonPath('owner_mode.reason', 'inactive');
    ($this->writeAllergy)($this->mine)->assertOk();
    expect(session(OwnerMode::SESSION_KEY))->toBeNull();
});

test('a Doctor or Admin role never brings Owner Mode or the owner\'s reach', function (ClinicRole $role) {
    [$user, $session] = $this->f->memberWithRole($role);
    $as = fn () => $this->actingAs($user)->withSession($session);

    $as()->post('/clinic/owner-mode', ['password' => 'password'])->assertForbidden();
    $as()->followingRedirects()->get('/clinic/dashboard')->assertInertia(fn (Assert $page) => $page->where('clinic.owner_mode.available', false)->where('clinic.owner_mode.owner', false));
    expect(session(OwnerMode::SESSION_KEY))->toBeNull();

    if ($role === ClinicRole::Doctor) {
        // A doctor writes only for their own or unassigned patients.
        ($this->writeAllergy)($this->theirs)->assertForbidden();
    }
})->with([
    'doctor' => ClinicRole::Doctor,
    'clinic admin' => ClinicRole::ClinicAdmin,
]);

test('an owner who does not treat patients works across the clinic with no mode and no repeated password', function () {
    $plain = ReceptionFixture::make('owner-no-practice-mode');
    $patient = $plain->patient('Any', 'Patient', ['current_practitioner_id' => $plain->doctorA->id]);
    $as = fn () => $this->actingAs($plain->owner)->withSession($plain->session);

    foreach (range(1, 4) as $step) {
        $as()->putJson("/clinic/patients/{$patient->id}/clinical-entries/allergy", ['entries' => [['label' => 'Latex', 'severity' => 'critical']]])->assertOk();
        $as()->get('/clinic/billing/summary')->assertOk();
        $as()->get('/clinic/dashboard')->assertInertia(fn (Assert $page) => $page->component('ClinicPortal/CommandCenter/Index', false)
            ->where('sections.money.state', 'ready')->where('clinic.owner_mode.available', false));
        $this->travel(20)->minutes();
    }

    expect(session(OwnerMode::SESSION_KEY))->toBeNull();
});
