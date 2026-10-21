<?php

use App\ClinicRole;
use App\Models\PatientVisit;
use App\Services\BillingService;
use App\Services\ClinicContext;
use App\Services\ClinicRoleAssignmentService;
use App\Services\OwnerMode\OwnerMode;
use App\Services\Reception\ReceptionCheckIn;
use App\Support\ClinicClock;
use App\Support\Dashboard\DashboardData;
use App\Support\Dashboard\DashboardWidgets;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\TreatmentFixture;

/**
 * Each role has its own dashboard, composed on the server by the workspace
 * that role works in (`DashboardWidgets::COMPOSITIONS`); a user gets the
 * union of theirs, each widget once, and every widget still passes its own
 * requirements. There is no shared Clinic Overview: its widgets belong to the
 * Owner/Admin composition alone. The three previews have a fixed capacity
 * that the page sizes them by.
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
    $this->keys = fn (array $props) => array_column($props['widgets'], 'key');
    $this->as = fn (ClinicRole $role) => (function () use ($role) {
        [$user, $session] = $this->f->memberWithRole($role);
        if ($role === ClinicRole::Doctor) {
            $this->f->doctor('Dr. Composed', $user);
        }

        return $this->actingAs($user)->withSession($session);
    })();
    $this->ownerMode = function () {
        $now = now()->getTimestamp();

        return $this->actingAs($this->f->owner)->withSession([...$this->f->session, OwnerMode::SESSION_KEY => ['user_id' => $this->f->owner->id, 'clinic_id' => $this->f->clinic->id,
            'membership_id' => $this->f->session[ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID], 'method' => 'password', 'activated_at' => $now, 'last_seen_at' => $now]]);
    };
});

test('Reception gets the front office: figures, today\'s patients and appointments, today\'s dues, what needs attention', function () {
    expect(($this->keys)(($this->props)(($this->as)(ClinicRole::Receptionist))))->toBe([
        'patients_today', 'waiting_now', 'with_doctor_now', 'collections_today',
        'todays_patients', 'upcoming_appointments',
        'pending_payments',
        'needs_attention', 'follow_ups_due',
    ]);
});

test('a Doctor gets their own queue and work, and nothing financial, administrative or owner-only', function () {
    $props = ($this->props)(($this->as)(ClinicRole::Doctor));

    expect(($this->keys)($props))->toBe([
        'my_waiting', 'my_seen', 'my_notes', 'appointments_today',
        'my_queue', 'upcoming_appointments',
        'needs_attention', 'follow_ups_due',
    ])
        ->and(array_keys($props['widgetData']))->not->toContain('collections_today', 'pending_payments', 'doctor_load', 'owner_finance', 'owner_security', 'branch_activity')
        ->and($props['clinic']['owner_subscription'])->toBeNull();
});

test('Billing gets collections and today\'s dues, and nothing clinical or owner-only', function () {
    $props = ($this->props)(($this->as)(ClinicRole::BillingStaff));

    expect(($this->keys)($props))->toBe(['patients_today', 'collections_today', 'pending_payments'])
        ->and(array_keys($props['widgetData']))->not->toContain('my_queue', 'todays_patients', 'doctor_load', 'owner_finance', 'owner_security');
});

test('the Owner/Admin dashboard adds the clinic-wide widgets; money and security only in Owner Mode', function () {
    $front = ['patients_today', 'waiting_now', 'with_doctor_now', 'collections_today', 'todays_patients', 'upcoming_appointments', 'pending_payments', 'needs_attention', 'follow_ups_due'];

    expect(($this->keys)(($this->props)(($this->as)(ClinicRole::ClinicAdmin))))->toBe($front)
        ->and(($this->keys)(($this->props)($this->actingAs($this->f->owner)->withSession($this->f->session))))->toBe($front)
        ->and(($this->keys)(($this->props)(($this->ownerMode)())))->toBe([...$front, 'owner_finance', 'owner_security'])
        // Doctors today was removed from the dashboard; it is no widget of any role.
        ->and(array_column(DashboardWidgets::WIDGETS, 'key'))->not->toContain('doctor_load');
});

test('there is no shared Clinic Overview: its widgets are the Owner/Admin composition\'s alone', function () {
    expect(array_keys(DashboardWidgets::ZONES))->not->toContain('overview')
        ->and(file_get_contents(resource_path('js/pages/ClinicPortal/Dashboard.tsx')))->not->toContain('Clinic overview');

    $management = collect(DashboardWidgets::WIDGETS)->where('zone', 'management')->pluck('key')->all();
    expect($management)->toBe(['owner_finance', 'branch_activity', 'owner_security']);
    foreach (DashboardWidgets::COMPOSITIONS as $workspace => $keys) {
        expect(array_intersect($management, $keys))->toBe($workspace === 'command_center' ? $management : []);
    }
});

test('every widget belongs to some role\'s dashboard, and a multi-role user gets each once', function () {
    $composed = array_merge(...array_values(DashboardWidgets::COMPOSITIONS));
    foreach (DashboardWidgets::WIDGETS as $widget) {
        expect($composed)->toContain($widget['key']);
    }

    // Reception and Doctor at once: both dashboards, nothing twice, still four figures.
    $this->f->memberWithRole(ClinicRole::Doctor); // the Doctor role exists in this clinic
    [$user, $session] = $this->f->memberWithRole(ClinicRole::Receptionist);
    $this->f->doctor('Dr. Desk', $user);
    app(ClinicRoleAssignmentService::class)->syncRoles($user, [ClinicRole::Receptionist->value, ClinicRole::Doctor->value], [$this->f->branch]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $user->unsetRelation('roles')->unsetRelation('permissions');
    $props = ($this->props)($this->actingAs($user)->withSession($session));
    $keys = ($this->keys)($props);

    expect($keys)->toBe(array_values(array_unique($keys)))
        ->and($keys)->toContain('todays_patients', 'my_queue', 'pending_payments')
        ->and(collect($props['widgets'])->where('zone', 'figures')->count())->toBe(4);
});

test('the previews hold 8 patients, 4 appointments and 5 bills, empty or overflowing', function () {
    $data = fn () => ($this->props)($this->actingAs($this->f->owner)->withSession($this->f->session))['widgetData'];

    // Empty: the same capacity, so the panels keep their size.
    $empty = $data();
    expect([$empty['todays_patients']['limit'], $empty['upcoming_appointments']['limit'], $empty['pending_payments']['limit']])->toBe([8, 4, 5])
        ->and([$empty['todays_patients']['total'], $empty['upcoming_appointments']['total'], $empty['pending_payments']['total']])->toBe([0, 0, 0]);

    $this->t->actAsOwner();
    foreach (range(1, 12) as $i) {
        $item = app(ReceptionCheckIn::class)->checkIn($this->f->clinic, $this->f->branch, $this->f->patient("Over{$i}", 'Limit'), doctor: $this->f->doctorA);
        $visit = PatientVisit::withoutGlobalScopes()->findOrFail($item->patient_visit_id);
        app(BillingService::class)->createBill($this->f->clinic, $visit->patient()->withoutGlobalScopes()->first(), $this->f->branch,
            [['description' => 'Consultation', 'quantity' => 1, 'unit_price' => 100, 'source' => $visit]]);
        $this->f->appointment($visit->patient()->withoutGlobalScopes()->first(), $this->f->doctorA, ClinicClock::now()->addMinutes(10 * $i));
    }

    $full = $data();
    expect(count($full['todays_patients']['rows']))->toBe(DashboardData::QUEUE_PREVIEW)->and($full['todays_patients']['total'])->toBe(12)
        ->and(count($full['upcoming_appointments']['items']))->toBe(DashboardData::APPOINTMENT_PREVIEW)->and($full['upcoming_appointments']['total'])->toBe(12)
        ->and(count($full['pending_payments']['items']))->toBe(DashboardData::DUES_PREVIEW)->and($full['pending_payments']['total'])->toBe(12)
        ->and($full['pending_payments']['due'])->toEqual(1200);
});
