<?php

use App\Services\Patient\PatientDirectory;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\TreatmentFixture;

/**
 * The patient directory's order and ages: the newest registration first on
 * the page and across cursor pages, and each age worked out from the date of
 * birth on the clinic's own today — never the number typed at registration.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-03 10:00', 'Asia/Kolkata'));
    $this->t = TreatmentFixture::make();
    $this->f = $this->t->f;
    $this->asOwner = fn () => $this->actingAs($this->f->owner)->withSession($this->f->session);
    // Registered a minute apart, oldest first.
    $this->register = function (string $first, string $last = 'Rao', array $attributes = []) {
        $this->travel(1)->minutes();

        return $this->f->patient($first, $last, $attributes);
    };
    $this->agesByName = fn (array $rows) => collect($rows)->mapWithKeys(fn (array $row) => [$row['name'] => $row['age']])->all();
});

test('the directory page lists the latest registration first', function () {
    ($this->register)('Anil');
    ($this->register)('Bina');
    ($this->register)('Chetan');

    ($this->asOwner)()->get(route('clinic.patients.index', ['view' => 'all']))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('ClinicPortal/Patients/Index')
            ->where('patients.data.0.name', 'Chetan Rao')
            ->where('patients.data.1.name', 'Bina Rao')
            ->where('patients.data.2.name', 'Anil Rao'));
});

test('patients registered in the same instant keep one order across pages, none repeated or skipped', function () {
    // No time passes between these registrations: the id breaks the tie.
    $ids = collect(['Anil', 'Bina', 'Chetan', 'Divya', 'Esha'])->map(fn (string $first) => $this->f->patient($first, 'Rao')->id);
    $url = '/clinic/patients/directory?per_page=2';

    $seen = [];
    $cursor = null;
    do {
        $page = ($this->asOwner)()->getJson($url.($cursor ? '&cursor='.$cursor : ''))->assertOk();
        $seen = [...$seen, ...$page->json('data.*.id')];
        $cursor = $page->json('next_cursor');
    } while ($cursor !== null);

    expect($seen)->toBe($ids->sortDesc()->values()->all());
});

test('a search keeps the latest registration first', function () {
    ($this->register)('Meera', 'Iyer');
    ($this->register)('Mohan', 'Iyer');

    ($this->asOwner)()->getJson('/clinic/patients/directory?q=Iyer')->assertOk()
        ->assertJsonPath('data.0.name', 'Mohan Iyer')
        ->assertJsonPath('data.1.name', 'Meera Iyer');
});

test('the age is completed years from the date of birth: on, before and after the birthday', function () {
    // Today at the clinic is 3 October 2026.
    ($this->register)('Birthday', 'Today', ['date_of_birth' => '2000-10-03']);
    ($this->register)('Birthday', 'Tomorrow', ['date_of_birth' => '2000-10-04']);
    ($this->register)('Birthday', 'Yesterday', ['date_of_birth' => '2000-10-02']);
    ($this->register)('Birthday', 'Later', ['date_of_birth' => '2000-12-25']);

    $ages = ($this->agesByName)(($this->asOwner)()->getJson('/clinic/patients/directory')->assertOk()->json('data'));

    expect($ages['Birthday Today'])->toBe(26)
        ->and($ages['Birthday Tomorrow'])->toBe(25)
        ->and($ages['Birthday Yesterday'])->toBe(26)
        ->and($ages['Birthday Later'])->toBe(25);
});

test('without a date of birth there is no age, whatever was recorded at registration', function () {
    ($this->register)('No', 'Birthdate', ['date_of_birth' => null, 'age' => 40]);

    ($this->asOwner)()->getJson('/clinic/patients/directory')->assertOk()
        ->assertJsonPath('data.0.name', 'No Birthdate')
        ->assertJsonPath('data.0.age', null);
});

test('the age follows the clinic’s date, not the server’s', function () {
    // 20:00 UTC on 3 October is already 01:30 on 4 October at the clinic: today is the birthday there.
    $this->travelTo(CarbonImmutable::parse('2026-10-03 20:00', 'UTC'));
    $this->f->patient('Midnight', 'Birthday', ['date_of_birth' => '2000-10-04']);

    ($this->asOwner)()->getJson('/clinic/patients/directory')->assertOk()->assertJsonPath('data.0.age', 26);
});

test('a 29 February birthday counts from 1 March in other years, and a future date gives no age', function () {
    $born = CarbonImmutable::parse('2004-02-29');

    expect(PatientDirectory::ageOn($born, CarbonImmutable::parse('2026-02-28')))->toBe(21)
        ->and(PatientDirectory::ageOn($born, CarbonImmutable::parse('2026-03-01')))->toBe(22)
        ->and(PatientDirectory::ageOn($born, CarbonImmutable::parse('2028-02-29')))->toBe(24)
        ->and(PatientDirectory::ageOn(CarbonImmutable::parse('2027-01-01'), CarbonImmutable::parse('2026-10-03')))->toBeNull()
        ->and(PatientDirectory::ageOn(null, CarbonImmutable::parse('2026-10-03')))->toBeNull();
});
