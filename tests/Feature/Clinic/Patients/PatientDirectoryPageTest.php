<?php

use App\BranchStatus;
use App\ClinicMembershipStatus;
use App\ClinicRole;
use App\Models\Branch;
use App\Models\ClinicMembership;
use App\Models\User;
use App\Services\ClinicContext;
use App\Services\ClinicRoleAssignmentService;
use App\Services\Patient\PatientBranchActivityRecorder;
use App\Support\ClinicFrontend;
use App\UserType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\TreatmentFixture;

/**
 * The directory page itself: a real screen (not the Unavailable
 * placeholder), linked from the navigation, and only linking onward to
 * patient pages that are built. Who sees which patients is the server's.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-17 10:00', 'Asia/Kolkata'));
    $this->t = TreatmentFixture::make();
    $this->f = $this->t->f;
    $this->asOwner = fn () => $this->actingAs($this->f->owner)->withSession($this->f->session);
    $this->props = function ($as, string $url = '/clinic/patients'): array {
        $props = [];
        $as->get($url)->assertOk()->assertInertia(function (Assert $page) use (&$props) {
            $page->component('ClinicPortal/Patients/Index', true);
            $props = $page->toArray()['props'];
        });

        return $props;
    };
});

test('the directory is a built page in the navigation, and links only to patient pages that exist', function () {
    $this->f->patient('Asha', 'Menon');
    $props = ($this->props)(($this->asOwner)());

    $patients = collect($props['clinic']['navigation'])->firstWhere('key', 'patients');
    expect(ClinicFrontend::available('clinic.patients.index'))->toBeTrue()
        ->and($patients['available'])->toBeTrue()
        ->and($patients['href'])->toBe(route('clinic.patients.index', [], false))
        ->and($props['links']['index'])->toBe(route('clinic.patients.index'))
        // The profile and the registration page come in later phases: not linked until then.
        ->and($props['links']['profile'])->toBe(ClinicFrontend::available('clinic.patients.show') ? route('clinic.patients.show', ['patient' => '__ID__']) : null)
        ->and($props['links']['create'])->toBe(ClinicFrontend::available('clinic.patients.create') ? route('clinic.patients.create') : null)
        ->and(collect($props['patients']['data'])->pluck('name')->all())->toBe(['Asha Menon']);
});

test('a member without the right to view patients is refused the page', function () {
    $user = User::factory()->create(['user_type' => UserType::ClinicUser]);
    $membership = ClinicMembership::factory()->create(['clinic_id' => $this->f->clinic->id, 'user_id' => $user->id, 'has_all_branches_access' => true, 'status' => ClinicMembershipStatus::Active]);

    $this->actingAs($user)->withSession([ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID => $membership->id, ClinicContext::SESSION_BRANCH_ID => $this->f->branch->id])
        ->get('/clinic/patients')->assertForbidden();
});

test('a member limited to one branch sees only that branch\'s patients on the page, and a filter cannot widen it', function () {
    $north = Branch::factory()->create(['clinic_id' => $this->f->clinic->id, 'name' => 'North', 'is_main' => false, 'status' => BranchStatus::Active]);
    $recorder = app(PatientBranchActivityRecorder::class);
    $recorder->record($this->f->clinic->id, $this->f->patient('Main', 'Patient')->id, $this->f->branch->id, now(), 'registration');
    $recorder->record($this->f->clinic->id, $this->f->patient('North', 'Patient')->id, $north->id, now(), 'registration');

    $user = User::factory()->create(['user_type' => UserType::ClinicUser]);
    $membership = ClinicMembership::factory()->create(['clinic_id' => $this->f->clinic->id, 'user_id' => $user->id, 'has_all_branches_access' => false, 'status' => ClinicMembershipStatus::Active]);
    DB::table('branch_clinic_membership')->insert(['clinic_id' => $this->f->clinic->id, 'clinic_membership_id' => $membership->id, 'branch_id' => $north->id]);
    $this->f->memberWithRole(ClinicRole::Receptionist);
    app(ClinicRoleAssignmentService::class)->syncRoles($user, [ClinicRole::Receptionist->value], [$north]);
    $asNorth = fn () => $this->actingAs($user)->withSession([ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID => $membership->id, ClinicContext::SESSION_BRANCH_ID => $north->id]);

    expect(collect(($this->props)($asNorth())['patients']['data'])->pluck('name')->all())->toBe(['North Patient'])
        ->and(($this->props)($asNorth(), "/clinic/patients?branch_id={$this->f->branch->id}")['patients']['data'])->toBe([])
        ->and(collect(($this->props)(($this->asOwner)())['patients']['data'])->pluck('name')->sort()->values()->all())->toBe(['Main Patient', 'North Patient']);
});

test('another clinic\'s directory shows none of these patients', function () {
    $this->f->patient('Rajesh', 'Patel');
    $other = TreatmentFixture::make('other-clinic');

    expect(($this->props)($this->actingAs($other->f->owner)->withSession($other->f->session))['patients']['data'])->toBe([]);
});

test('the page walks the directory by cursor, newest first, with no overlap and no total', function () {
    foreach (range(1, 27) as $i) {
        $this->travel(1)->minutes();
        $this->f->patient('Patient', sprintf('No%02d', $i));
    }

    $first = ($this->props)(($this->asOwner)());
    expect($first['patients']['data'])->toHaveCount(25)
        ->and($first['patients']['data'][0]['name'])->toBe('Patient No27')
        ->and($first['patients']['next_cursor'])->not->toBeNull()
        ->and($first['patients'])->not->toHaveKey('total');

    $second = ($this->props)(($this->asOwner)(), '/clinic/patients?cursor='.urlencode($first['patients']['next_cursor']));
    expect(collect($second['patients']['data'])->pluck('name')->all())->toBe(['Patient No02', 'Patient No01'])
        ->and($second['patients']['next_cursor'])->toBeNull()
        ->and($second['patients']['prev_cursor'])->not->toBeNull();
});
