<?php

use App\ClinicRole;
use App\Services\ClinicContext;
use App\Services\OwnerMode\OwnerMode;
use App\Support\ClinicFrontend;
use App\Support\Dashboard\OwnerCommandCenter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\TreatmentFixture;

/**
 * `clinic.dashboard` is the Command Center, for whoever runs the clinic. There
 * is no separate staff dashboard: reception works from the Front Desk, billing
 * staff from Billing and practitioners from the Calendar, and anyone else who
 * opens this address is sent to their own home.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-13 11:00', 'Asia/Kolkata'));
    $this->t = TreatmentFixture::make();
    $this->f = $this->t->f;
    $this->asOwner = fn () => $this->actingAs($this->f->owner)->withSession($this->f->session);
    $this->dashboard = function ($as): array {
        $props = [];
        $as->get('/clinic/dashboard')->assertOk()->assertInertia(function (Assert $page) use (&$props) {
            $props = $page->toArray()['props'];
        });

        return $props;
    };
});

test('no dashboard asks an owner who does not treat patients for Owner Mode; their money and plan come from sign-in', function () {
    expect(file_get_contents(resource_path('js/pages/ClinicPortal/CommandCenter/Index.tsx')))->not->toContain('OwnerModeDialog')->not->toContain('Enter Owner Mode')
        // Owner Mode stays in the account menu, for an owner who also treats patients.
        ->and(file_get_contents(resource_path('js/clinic/shell/AccountMenu.tsx')))->toContain('Enter Owner Mode');

    // The owner, signed in, nothing more: every owner block and the plan (no staff-dashboard settings: there is no staff dashboard).
    $props = ($this->dashboard)(($this->asOwner)());
    foreach (OwnerCommandCenter::OWNER_SECTIONS as $section) {
        expect($props['sections'][$section]['state'])->toBe('ready');
    }
    expect($props['clinic']['owner_subscription'])->not->toBeNull()
        ->and($props['clinic']['owner_mode']['available'])->toBeFalse()
        ->and($props)->not->toHaveKey('visibility');

    // Reception never gets them, Owner Mode or not: its dashboard is the Front Desk.
    [$user, $session] = $this->f->memberWithRole(ClinicRole::Receptionist);
    $this->actingAs($user)->withSession($session)->get('/clinic/dashboard')->assertRedirect(route('clinic.reception'));
    $this->actingAs($user)->withSession($session)->get('/clinic/reception')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('ClinicPortal/FrontDesk/Index', false)
            ->where('clinic.owner_subscription', null)
            ->where('clinic.owner_mode.available', false));
});

test('no quick action or widget link leads to a page that is not built, even in Owner Mode', function () {
    $now = now()->getTimestamp();
    $ownerMode = [OwnerMode::SESSION_KEY => ['user_id' => $this->f->owner->id, 'clinic_id' => $this->f->clinic->id,
        'membership_id' => $this->f->session[ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID], 'method' => 'password', 'activated_at' => $now, 'last_seen_at' => $now]];

    $props = ($this->dashboard)($this->actingAs($this->f->owner)->withSession([...$this->f->session, ...$ownerMode]));

    // Every link the Command Center sends opens a page that exists.
    $hrefs = [];
    array_walk_recursive($props['sections'], function ($value, $key) use (&$hrefs) {
        if (in_array($key, ['href', 'summary_href'], true) && is_string($value)) {
            $hrefs[] = parse_url($value, PHP_URL_PATH);
        }
    });
    // A built page's path, as a pattern: a page with a parameter (a patient's record) matches any value of it.
    $built = collect(ClinicFrontend::AVAILABLE)->map(fn (string $route) => '#^/'.str_replace('PARAM', '[^/]+', preg_quote(preg_replace('/\{[^}]+\}/', 'PARAM', Route::getRoutes()->getByName($route)->uri()), '#')).'$#')->all();
    $unbuilt = collect(array_unique($hrefs))->reject(fn (string $path) => collect($built)->contains(fn (string $pattern) => preg_match($pattern, $path) === 1))->values()->all();

    // The Command Center is a management view: it offers no quick actions of its own (intake stays in the top bar).
    expect($props)->not->toHaveKey('actions')
        // The shared command list still names them; the dashboard offers only what works.
        ->and(array_column($props['clinic']['actions'], 'key'))->toContain('collect_payment', 'owner_settings')
        ->and($hrefs)->not->toBeEmpty()
        ->and($unbuilt)->toBe([]);
});

test('the Command Center is only for whoever runs the clinic; everyone else is sent to their own home', function () {
    $member = fn (ClinicRole $role) => $this->f->memberWithRole($role);

    foreach ([
        [ClinicRole::Receptionist, 'clinic.reception'],
        [ClinicRole::BillingStaff, 'clinic.billing.index'],
        [ClinicRole::Doctor, 'clinic.appointments.index'],
    ] as [$role, $home]) {
        [$user, $session] = $member($role);
        $this->actingAs($user)->withSession($session)->get('/clinic/dashboard')->assertRedirect(route($home));
    }

    // An administrator runs the clinic but is not its owner: no owner-only block, locked or otherwise.
    [$admin, $adminSession] = $member(ClinicRole::ClinicAdmin);
    $adminSections = ($this->dashboard)($this->actingAs($admin)->withSession($adminSession))['sections'];
    expect(array_intersect(array_keys($adminSections), OwnerCommandCenter::OWNER_SECTIONS))->toBe([]);

    $now = now()->getTimestamp();
    $ownerMode = [OwnerMode::SESSION_KEY => ['user_id' => $this->f->owner->id, 'clinic_id' => $this->f->clinic->id,
        'membership_id' => $this->f->session[ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID], 'method' => 'password', 'activated_at' => $now, 'last_seen_at' => $now]];
    $owner = ($this->dashboard)($this->actingAs($this->f->owner)->withSession([...$this->f->session, ...$ownerMode]))['sections'];
    foreach (OwnerCommandCenter::OWNER_SECTIONS as $section) {
        expect($owner[$section]['state'])->toBe('ready');
    }
});
