<?php

use App\ClinicRole;
use App\Services\ClinicContext;
use App\Services\OwnerMode\OwnerMode;
use App\Support\Dashboard\DashboardWidgets;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\TreatmentFixture;

/**
 * The Owner/Admin home is the Command Center, composed on the server by the
 * widget registry (`DashboardWidgets`). There is no separate staff dashboard
 * and no shared Clinic Overview: the management widgets belong to the
 * Owner/Admin composition alone.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-13 11:00', 'Asia/Kolkata'));
    $this->t = TreatmentFixture::make();
    $this->f = $this->t->f;
    $this->props = function ($as): array {
        $props = [];
        $as->get('/clinic/dashboard')->assertOk()->assertInertia(function (Assert $page) use (&$props) {
            $props = $page->toArray()['props'];
        });

        return $props;
    };
    $this->as = fn (ClinicRole $role) => (function () use ($role) {
        [$user, $session] = $this->f->memberWithRole($role);

        return $this->actingAs($user)->withSession($session);
    })();
    $this->ownerMode = function () {
        $now = now()->getTimestamp();

        return $this->actingAs($this->f->owner)->withSession([...$this->f->session, OwnerMode::SESSION_KEY => ['user_id' => $this->f->owner->id, 'clinic_id' => $this->f->clinic->id,
            'membership_id' => $this->f->session[ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID], 'method' => 'password', 'activated_at' => $now, 'last_seen_at' => $now]]);
    };
});

test('the Owner/Admin home is the Command Center; money, people and security only for the owner', function () {
    $sections = fn ($as) => ($this->props)($as)['sections'];
    $states = fn (array $sections) => array_map(fn (array $section) => $section['state'], $sections);
    $everything = ['today' => 'ready', 'money' => 'ready', 'spending' => 'ready', 'decisions' => 'ready', 'people' => 'ready', 'security' => 'ready', 'activity' => 'ready'];

    // The owner who does not treat patients has it all from sign-in, the same as with Owner Mode state.
    expect(array_keys($sections(($this->as)(ClinicRole::ClinicAdmin))))->toBe(['today', 'spending', 'decisions'])
        ->and($states($sections($this->actingAs($this->f->owner)->withSession($this->f->session))))->toBe($everything)
        ->and($states($sections(($this->ownerMode)())))->toBe($everything)
        // Doctors today was removed from the dashboard; it is no widget of any role.
        ->and(array_column(DashboardWidgets::WIDGETS, 'key'))->not->toContain('doctor_load');
});

test('there is no shared Clinic Overview: its widgets are the Owner/Admin composition\'s alone', function () {
    expect(array_keys(DashboardWidgets::ZONES))->not->toContain('overview')
        // The separate staff dashboard is gone: no page for it exists.
        ->and(file_exists(resource_path('js/pages/ClinicPortal/Dashboard.tsx')))->toBeFalse();

    $management = collect(DashboardWidgets::WIDGETS)->where('zone', 'management')->pluck('key')->all();
    expect($management)->toBe(['owner_finance', 'branch_activity', 'owner_people', 'owner_security', 'owner_activity']);
    foreach (DashboardWidgets::COMPOSITIONS as $workspace => $keys) {
        expect(array_intersect($management, $keys))->toBe($workspace === 'command_center' ? $management : []);
    }
});

test('every widget belongs to some composition', function () {
    $composed = array_merge(...array_values(DashboardWidgets::COMPOSITIONS));
    foreach (DashboardWidgets::WIDGETS as $widget) {
        expect($composed)->toContain($widget['key']);
    }
});
