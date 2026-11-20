<?php

use App\BranchStatus;
use App\ClinicRole;
use App\Models\Appointment;
use App\Models\Branch;
use App\Services\Support\SupportTicketService;
use Carbon\CarbonImmutable;
use Tests\Support\ReceptionFixture;

/**
 * The top bar's search finds only what the signed-in member may open: each
 * group answers to its destination's own rule on the server, so a group the
 * member may not open is absent from the response, not hidden in the page.
 * Nothing from another clinic, or another branch's Calendar, is ever sent.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-12 10:00', 'Asia/Kolkata'));
    $this->f = ReceptionFixture::make('search-clinic');
    $this->patient = $this->f->patient('Kavya', 'Rao', ['phone' => '9876500011']);
    $this->appointment = $this->f->appointment($this->patient, $this->f->doctorA, CarbonImmutable::parse('2026-10-14 11:30'));
    $this->search = function (array $member, string $q): array {
        $response = $this->actingAs($member[0])->withSession($member[1])->getJson('/clinic/search?q='.urlencode($q))->assertOk();

        return collect($response->json('groups'))->keyBy('key')->all();
    };
    $this->owner = [$this->f->owner, $this->f->session];
});

test('the front desk finds the patient (to start their Repeat Case) and the appointment, opened on its day in the Calendar', function () {
    $groups = ($this->search)($this->f->memberWithRole(ClinicRole::Receptionist), 'Kavya');

    expect(array_keys($groups))->toBe(['patients', 'appointments'])
        ->and($groups['patients']['items'][0]['action'])->toMatchArray(['type' => 'repeat_case'])
        ->and($groups['patients']['items'][0]['action']['patient']['id'])->toBe($this->patient->id)
        ->and($groups['appointments']['items'][0]['href'])->toContain('view=day')
        ->toContain('date=2026-10-14')
        ->toContain("appointment={$this->appointment->id}");
});

test('a doctor finds appointments but no patients, because a patient has nowhere to open for them yet', function () {
    [$user, $session] = $this->f->memberWithRole(ClinicRole::Doctor);
    $this->f->doctor('Dr. Search', $user);

    $groups = ($this->search)([$user, $session], 'Kavya');

    expect(array_keys($groups))->toBe(['appointments']);
});

test('billing staff, who may not open the Calendar or the intake, find neither patients nor appointments', function () {
    $groups = ($this->search)($this->f->memberWithRole(ClinicRole::BillingStaff), 'Kavya');

    expect($groups)->not->toHaveKey('patients')->not->toHaveKey('appointments');
});

test('pages are the navigation\'s own: only what this member may open, and only screens that exist', function () {
    $reception = $this->f->memberWithRole(ClinicRole::Receptionist);
    $billing = $this->f->memberWithRole(ClinicRole::BillingStaff);
    $titles = fn (array $groups) => array_column($groups['pages']['items'] ?? [], 'title');

    expect($titles(($this->search)($reception, 'appoint')))->toBe(['Appointments'])
        ->and($titles(($this->search)($billing, 'appoint')))->toBe([])
        ->and($titles(($this->search)($reception, 'support')))->toBe(['Support'])
        // Billing (the ledger) is built; billing staff and the desk (full billing since 2026-11-05) may open it.
        ->and($titles(($this->search)($billing, 'billing')))->toBe(['Billing'])
        ->and($titles(($this->search)($reception, 'billing')))->toBe(['Billing'])
        // Reports is built: the owner may open it, the desk may not.
        ->and($titles(($this->search)($this->owner, 'reports')))->toBe(['Reports'])
        ->and($titles(($this->search)($reception, 'reports')))->toBe([]);
});

test('nothing comes from another clinic, or from another branch\'s Calendar', function () {
    $other = ReceptionFixture::make('other-search-clinic');
    $other->appointment($other->patient('Kavya', 'Shah'), $other->doctorA, CarbonImmutable::parse('2026-10-14 09:00'));
    $elsewhere = Branch::factory()->create(['clinic_id' => $this->f->clinic->id, 'name' => 'Second', 'status' => BranchStatus::Active]);
    $hidden = Appointment::create([
        'clinic_id' => $this->f->clinic->id, 'branch_id' => $elsewhere->id, 'patient_id' => $this->patient->id,
        'practitioner_id' => $this->f->doctorA->id, 'scheduled_at' => CarbonImmutable::parse('2026-10-15 09:00'), 'duration_minutes' => 15, 'status' => 'scheduled',
    ]);

    $groups = ($this->search)($this->owner, 'Kavya');

    expect(array_column($groups['patients']['items'], 'title'))->toBe(['Kavya Rao'])
        ->and(collect($groups['appointments']['items'])->pluck('href')->join(' '))->toContain("appointment={$this->appointment->id}")
        ->not->toContain("appointment={$hidden->id}");
});

test('support requests follow the Support page\'s rule: a member finds their own, the owner the clinic\'s, nobody another clinic\'s', function () {
    $desk = app(SupportTicketService::class);
    $reception = $this->f->memberWithRole(ClinicRole::Receptionist);
    $mine = $desk->open(['clinic_id' => $this->f->clinic->id, 'subject' => 'Printer fault at the desk'], $reception[0]);
    $owners = $desk->open(['clinic_id' => $this->f->clinic->id, 'subject' => 'Printer fault in billing'], $this->f->owner);
    $other = ReceptionFixture::make('other-support-search');
    $desk->open(['clinic_id' => $other->clinic->id, 'subject' => 'Printer fault elsewhere'], $other->owner);

    $ids = fn (array $groups) => collect($groups['support']['items'] ?? [])->pluck('key')->sort()->values()->all();

    expect($ids(($this->search)($reception, 'printer')))->toBe(["support-{$mine->id}"])
        ->and($ids(($this->search)($this->owner, 'printer')))->toBe(collect(["support-{$mine->id}", "support-{$owners->id}"])->sort()->values()->all())
        ->and($ids(($this->search)($this->owner, $mine->reference)))->toBe(["support-{$mine->id}"]);
});

test('a typed date opens the Calendar on that day; wildcards are matched literally', function () {
    $groups = ($this->search)($this->owner, '14/10/2026');

    expect($groups['appointments']['items'][0])->toMatchArray(['title' => 'Appointments on Wed, 14 Oct 2026'])
        ->and($groups['appointments']['items'][0]['href'])->toContain('date=2026-10-14');

    expect(($this->search)($this->owner, '%%'))->toBe([]);
});

test('the search needs a signed-in clinic member', function () {
    $this->getJson('/clinic/search?q=Kavya')->assertUnauthorized();
});
