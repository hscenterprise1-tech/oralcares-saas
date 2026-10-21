<?php

use App\ClinicRole;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\ReceptionFixture;

/**
 * Phase 3, Step 2: the Clinic Portal has no application chrome. No header,
 * navigation bar, pills, menu, sidebar, search launcher or account control;
 * a signed-in page begins with its own content. Walk-ins start from the
 * Calendar with the approved New Case / Repeat Case buttons, for exactly the
 * people the server already lets check in. Removing chrome changes no
 * permission.
 */
beforeEach(function () {
    $this->f = ReceptionFixture::make('chromeless');
    $this->as = fn (array $member) => $this->actingAs($member[0])->withSession($member[1]);
    $this->member = function (ClinicRole $role): array {
        $member = $this->f->memberWithRole($role);
        if ($role === ClinicRole::Doctor) {
            $this->f->doctor('Dr. Chromeless', $member[0]);
        }

        return $member;
    };
    $this->calendarCan = function ($as): array {
        $can = [];
        $as->get('/clinic/appointments')->assertOk()->assertInertia(function (Assert $page) use (&$can) {
            $page->component('ClinicPortal/Appointments/Index', true);
            $can = $page->toArray()['props']['can'];
        });

        return $can;
    };
});

test('the portal frame renders no header, navigation, menu, search launcher or account control', function () {
    $shell = file_get_contents(resource_path('js/clinic/shell/ClinicPortalShell.tsx'));

    foreach (['TopNavigation', 'MobileNavigation', 'CommandPalette', 'useCommandShortcut', 'AccountMenu', 'NotificationsMenu', 'BranchSwitcher', 'OwnerModeDialog', '<header', '<nav'] as $chrome) {
        expect($shell)->not->toContain($chrome);
    }

    // No page brings a navigation component of its own back.
    $pages = collect(array_merge(
        glob(resource_path('js/pages/ClinicPortal/**/*.tsx')) ?: [],
        glob(resource_path('js/pages/ClinicPortal/*.tsx')) ?: [],
        glob(resource_path('js/pages/ClinicPortal/*/*/*.tsx')) ?: [],
        glob(resource_path('js/pages/Clinic/*/*.tsx')) ?: [],
    ))->unique();
    expect($pages)->not->toBeEmpty();
    foreach ($pages as $page) {
        expect(file_get_contents($page))->not->toMatch('/\b(TopNavigation|MobileNavigation|CommandPalette|NotificationsMenu|AccountMenu)\b/');
    }
});

test('walk-ins start from the Calendar for whoever may check in: the front desk and the owner, not a doctor', function () {
    $receptionist = ($this->member)(ClinicRole::Receptionist);
    $doctorMember = ($this->member)(ClinicRole::Doctor);
    $reception = ($this->calendarCan)(($this->as)($receptionist));
    $owner = ($this->calendarCan)(($this->as)([$this->f->owner, $this->f->session]));
    $doctor = ($this->calendarCan)(($this->as)($doctorMember));

    expect($reception['check_in'])->toBeTrue()->and($reception['register_patient'])->toBeTrue()
        ->and($owner['check_in'])->toBeTrue()
        ->and($doctor['check_in'])->toBeFalse();

    // The intake behind the buttons is still the server's to allow.
    ($this->as)($receptionist)->getJson('/clinic/reception/intake')->assertOk();
    ($this->as)($doctorMember)->getJson('/clinic/reception/intake')->assertForbidden();
});

test('removing the chrome changed no permission: billing staff are still refused the Calendar and the intake', function () {
    $billing = ($this->member)(ClinicRole::BillingStaff);

    ($this->as)($billing)->get('/clinic/appointments')->assertForbidden();
    ($this->as)($billing)->getJson('/clinic/reception/intake')->assertForbidden();
    ($this->as)($billing)->get('/clinic/billing')->assertOk();
});

test('signing out still ends the session on the server, with no control left in a header', function () {
    ($this->as)([$this->f->owner, $this->f->session])->post('/clinic/logout')->assertRedirect(route('clinic.login'));

    $this->assertGuest();
    $this->get('/clinic/appointments')->assertRedirect();
});
