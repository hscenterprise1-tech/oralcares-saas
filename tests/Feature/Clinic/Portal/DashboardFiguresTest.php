<?php

use App\AppointmentStatus;
use App\ClinicRole;
use App\Models\PatientVisit;
use App\PaymentMethod;
use App\Services\BillingService;
use App\Services\ClinicContext;
use App\Services\OwnerMode\OwnerMode;
use App\Services\PaymentService;
use App\Services\QueueStateMachine;
use App\Services\Reception\FrontDesk;
use App\Services\Reception\ReceptionCheckIn;
use App\Support\ClinicActions;
use App\Support\ClinicClock;
use App\Support\ClinicFrontend;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\TreatmentFixture;

/**
 * Each dashboard figure has one definition, shared with the list or report it
 * sums up: cancelled visits and bills do not count, "waiting" is the Front
 * Desk's waiting list, and money is the Revenue report's (paid that day, by
 * `paid_at`, less refunds that day).
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
    $this->checkIn = fn (string $first) => app(ReceptionCheckIn::class)->checkIn($this->f->clinic, $this->f->branch, $this->f->patient($first, 'Figure'), doctor: $this->f->doctorA);
    $this->bill = function (float $amount) {
        $this->t->actAsOwner();

        return app(BillingService::class)->createBill($this->f->clinic, $this->f->patient('Bill', 'Payer'), $this->f->branch, [['description' => 'Consultation', 'quantity' => 1, 'unit_price' => $amount]]);
    };
});

test('patients today leaves out cancelled visits', function () {
    ($this->checkIn)('Stays');
    app(QueueStateMachine::class)->cancel(($this->checkIn)('Leaves'));
    // Billing staff see "Patients today" among their figures (no queue figures take its place).
    [$user, $session] = $this->f->memberWithRole(ClinicRole::BillingStaff);

    expect(($this->dashboard)($this->actingAs($user)->withSession($session))['widgetData']['patients_today']['value'])->toBe(1);
});

test('waiting means the same on the figure, the queue preview and the Front Desk', function () {
    ($this->checkIn)('Waits');
    app(QueueStateMachine::class)->call(($this->checkIn)('Called'));
    app(QueueStateMachine::class)->start(($this->checkIn)('Seen'));

    $data = ($this->dashboard)(($this->asOwner)())['widgetData'];
    $desk = app(FrontDesk::class)->desk($this->f->clinic, $this->f->branch, $this->f->owner);

    expect($data['waiting_now']['value'])->toBe(2)
        ->and($data['todays_patients']['counts']['waiting'])->toBe(2)
        ->and($desk['counts']['waiting'])->toBe(2)
        ->and($data['with_doctor_now']['value'])->toBe(1)
        ->and($data['todays_patients']['counts']['in_room'])->toBe(1)
        ->and($data['todays_patients']['total'])->toBe(3);
});

test('today\'s patients preview says how many it leaves out, and links to no page that is not built', function () {
    foreach (range(1, 10) as $i) {
        ($this->checkIn)("Queue{$i}");
    }

    $props = ($this->dashboard)(($this->asOwner)());
    $queue = collect($props['widgets'])->firstWhere('key', 'todays_patients');

    expect($props['widgetData']['todays_patients']['rows'])->toHaveCount(8)
        ->and($props['widgetData']['todays_patients']['total'])->toBe(10)
        ->and($queue['action'])->toBeNull();
});

test('upcoming appointments are the bookings still to arrive, the same number the figure gives', function () {
    $patient = $this->f->patient('Booked', 'Ahead');
    $this->f->appointment($patient, $this->f->doctorA, ClinicClock::now()->addHour());
    $this->f->appointment($patient, $this->f->doctorA, ClinicClock::now()->subHour());
    $this->f->appointment($patient, $this->f->doctorA, ClinicClock::now()->addHours(2), AppointmentStatus::Cancelled);
    $this->f->appointment($patient, $this->f->doctorA, ClinicClock::now()->subHours(2), AppointmentStatus::Arrived);

    // Appointments is the fourth figure for whoever does not see the money: reception without billing rights.
    [$user, $session] = $this->f->memberWithRole(ClinicRole::Receptionist);
    setPermissionsTeamId(null);
    Role::findByName(ClinicRole::Receptionist->value, 'web')->revokePermissionTo(['billing.view', 'payment.collect']);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $data = ($this->dashboard)($this->actingAs($user)->withSession($session))['widgetData'];

    expect($data['appointments_today']['value'])->toBe(3)
        ->and($data['appointments_today']['detail'])->toBe('2 still to arrive')
        ->and($data['upcoming_appointments']['total'])->toBe(2)
        // The one whose time has passed is marked late; it comes first.
        ->and(array_column($data['upcoming_appointments']['items'], 'status'))->toBe(['late', 'scheduled']);
});

test('collections count a payment on the day it was paid, not the day it was entered', function () {
    $payments = app(PaymentService::class);
    $paidYesterday = $payments->recordPayment(($this->bill)(1000)->fresh(), PaymentMethod::Cash, 300);
    $paidYesterday->forceFill(['paid_at' => now()->subDay()])->save();
    $enteredYesterday = $payments->recordPayment(($this->bill)(1000)->fresh(), PaymentMethod::Upi, 200);
    DB::table('patient_billing_payments')->where('id', $enteredYesterday->id)->update(['created_at' => now()->subDay()]);

    expect(($this->dashboard)(($this->asOwner)())['widgetData']['collections_today']['value'])->toEqual(200);
});

test('collections are net of the day\'s refunds, as the Revenue report counts them', function () {
    $bill = ($this->bill)(1000);
    app(PaymentService::class)->recordPayment($bill->fresh(), PaymentMethod::Cash, 500);
    app(PaymentService::class)->recordRefund($bill->fresh(), 200, 'Partly refunded');

    $collections = ($this->dashboard)(($this->asOwner)())['widgetData']['collections_today'];

    expect($collections['value'])->toEqual(300)->and($collections['detail'])->toBe('Net of refunds');
});

test('today\'s pending payments are the open bills of today\'s visits only: no cancelled bill, no older visit\'s balance', function () {
    $this->t->actAsOwner();
    $billing = app(BillingService::class);
    $billFor = fn (PatientVisit $visit, float $amount) => $billing->createBill($this->f->clinic, $visit->patient()->withoutGlobalScopes()->first(), $this->f->branch,
        [['description' => 'Consultation', 'quantity' => 1, 'unit_price' => $amount, 'source' => $visit]]);
    $visitOf = fn (string $first) => PatientVisit::withoutGlobalScopes()->findOrFail(($this->checkIn)($first)->patient_visit_id);

    $unpaid = $billFor($visitOf('Unpaid'), 1000);
    $part = $billFor($visitOf('Part'), 800);
    app(PaymentService::class)->recordPayment($part->fresh(), PaymentMethod::Cash, 300);
    $billing->cancelBill($billFor($visitOf('Cancelled'), 400), reason: 'Entered twice');
    // A balance from yesterday's visit, and one not tied to any visit, are the Billing page's.
    $yesterday = $visitOf('Yesterday');
    $yesterday->forceFill(['visit_date' => ClinicClock::today()->subDay()->toDateString()])->save();
    $billFor($yesterday, 700);
    ($this->bill)(900);

    $props = ($this->dashboard)(($this->asOwner)());
    $dues = $props['widgetData']['pending_payments'];

    expect($dues['total'])->toBe(2)->and($dues['due'])->toEqual(1500)
        ->and(collect($dues['items'])->keyBy('id')->map(fn (array $row) => [$row['amount'], $row['paid'], $row['balance'], $row['status']])->all())->toEqual([
            $unpaid->id => [1000, 0, 1000, 'unpaid'],
            $part->id => [800, 300, 500, 'partially_paid'],
        ])
        // Listed once: not repeated in Needs attention.
        ->and(collect($props['widgetData']['needs_attention']['items'])->pluck('id'))->not->toContain('pending_payments');

    // Patient-level money only for the billing audience: a doctor gets neither the widget nor its data.
    [$user, $session] = $this->f->memberWithRole(ClinicRole::Doctor);
    $doctor = ($this->dashboard)($this->actingAs($user)->withSession($session));
    expect(array_column($doctor['widgets'], 'key'))->not->toContain('pending_payments')
        ->and($doctor['widgetData'])->not->toHaveKey('pending_payments');
});

test('the Quick Actions card: New Case and Repeat Case side by side, Book Appointment beneath, and Billing only where it works', function () {
    $props = ($this->dashboard)(($this->asOwner)());

    expect(collect($props['actions'])->map(fn (array $action) => [$action['key'], $action['label'], $action['tile'], $action['tone']])->all())->toBe([
        ['new_case', 'New Case', 'large', 'brand'],
        ['repeat_case', 'Repeat Case', 'large', 'blue'],
        ['new_appointment', 'Book Appointment', 'row', 'amber'],
    ])
        // A tile is its icon and name: no description is sent.
        ->and(collect($props['actions'])->every(fn (array $action) => ! array_key_exists('description', $action)))->toBeTrue();

    // Billing is declared (violet row, needs payment.collect) but its page is not built, so no one is offered it yet.
    $billing = collect(ClinicActions::ACTIONS)->firstWhere('key', 'collect_payment');
    expect($billing)->toMatchArray(['dashboard_label' => 'Billing', 'tile' => 'row', 'tone' => 'violet', 'route' => 'clinic.billing.index'])
        ->and($billing['requires']['permissions'])->toBe(['payment.collect'])
        ->and(ClinicFrontend::available('clinic.billing.index'))->toBeFalse();

    // The page lays the zones out as approved: figures 60%, Quick Actions 40%; large tiles two to a row.
    $page = file_get_contents(resource_path('js/pages/ClinicPortal/Dashboard.tsx'));
    expect($page)->toContain('lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]')->toContain("large.length > 1 && 'grid-cols-2'")
        ->not->toMatch('/[\'"](Receptionist|Doctor|Billing Staff|Clinic Admin)[\'"]/');
});

test('reception sees patients, waiting, with doctor and the fourth figure its access allows', function () {
    [$user, $session] = $this->f->memberWithRole(ClinicRole::Receptionist);
    $figures = fn () => collect(($this->dashboard)($this->actingAs($user)->withSession($session))['widgets'])->where('zone', 'figures')->pluck('key')->all();

    expect($figures())->toBe(['patients_today', 'waiting_now', 'with_doctor_now', 'collections_today']);

    setPermissionsTeamId(null);
    Role::findByName(ClinicRole::Receptionist->value, 'web')->revokePermissionTo(['billing.view', 'payment.collect']);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $user->unsetRelation('roles')->unsetRelation('permissions');

    expect($figures())->toBe(['patients_today', 'waiting_now', 'with_doctor_now', 'appointments_today']);
});

test('the dashboard has no Owner Mode prompt; the account menu keeps it, and the owner\'s money and plan still need it', function () {
    expect(file_get_contents(resource_path('js/pages/ClinicPortal/Dashboard.tsx')))->not->toContain('OwnerModeDialog')->not->toContain('Enter Owner Mode')
        ->and(file_get_contents(resource_path('js/clinic/shell/AccountMenu.tsx')))->toContain('Enter Owner Mode');

    // The owner, signed in but not in Owner Mode: no money widgets, no plan, no staff-dashboard settings.
    $props = ($this->dashboard)(($this->asOwner)());
    expect(array_column($props['widgets'], 'key'))->not->toContain('owner_finance', 'owner_security')
        ->and($props['clinic']['owner_subscription'])->toBeNull()
        ->and($props['visibility'])->toBeNull();

    // Reception never gets them, Owner Mode or not.
    [$user, $session] = $this->f->memberWithRole(ClinicRole::Receptionist);
    $reception = ($this->dashboard)($this->actingAs($user)->withSession($session));
    expect(array_column($reception['widgets'], 'key'))->not->toContain('owner_finance', 'owner_security')
        ->and($reception['clinic']['owner_subscription'])->toBeNull()
        ->and($reception['clinic']['owner_mode']['available'])->toBeFalse();
});

test('no quick action or widget link leads to a page that is not built, even in Owner Mode', function () {
    $now = now()->getTimestamp();
    $ownerMode = [OwnerMode::SESSION_KEY => ['user_id' => $this->f->owner->id, 'clinic_id' => $this->f->clinic->id,
        'membership_id' => $this->f->session[ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID], 'method' => 'password', 'activated_at' => $now, 'last_seen_at' => $now]];

    $props = ($this->dashboard)($this->actingAs($this->f->owner)->withSession([...$this->f->session, ...$ownerMode]));

    expect(array_column($props['actions'], 'key'))->toBe(['new_case', 'repeat_case', 'new_appointment'])
        // The shared command list still names them; the dashboard offers only what works.
        ->and(array_column($props['clinic']['actions'], 'key'))->toContain('collect_payment', 'owner_settings')
        ->and(collect($props['widgets'])->pluck('action.href')->filter()->unique()->values()->all())->toBe(['/clinic/appointments?view=day'])
        ->and($props['visibility'])->not->toBeNull();
});

test('previews stop at 8 patients, 4 appointments and 5 bills, and say how many there are in all', function () {
    $this->t->actAsOwner();
    $billing = app(BillingService::class);
    foreach (range(1, 10) as $i) {
        $item = ($this->checkIn)("Many{$i}");
        if ($i <= 7) {
            $visit = PatientVisit::withoutGlobalScopes()->findOrFail($item->patient_visit_id);
            $billing->createBill($this->f->clinic, $visit->patient()->withoutGlobalScopes()->first(), $this->f->branch,
                [['description' => 'Consultation', 'quantity' => 1, 'unit_price' => 100 * $i, 'source' => $visit]]);
        }
    }
    $patient = $this->f->patient('Booked', 'Often');
    foreach (range(1, 6) as $i) {
        $this->f->appointment($patient, $this->f->doctorA, ClinicClock::now()->addMinutes(20 * $i));
    }

    $data = ($this->dashboard)(($this->asOwner)())['widgetData'];

    expect($data['todays_patients']['rows'])->toHaveCount(8)->and($data['todays_patients']['total'])->toBe(10)
        ->and($data['upcoming_appointments']['items'])->toHaveCount(4)->and($data['upcoming_appointments']['total'])->toBe(6)
        // In time order, the earliest first.
        ->and(array_column($data['upcoming_appointments']['items'], 'time'))->toBe(collect(range(1, 4))->map(fn (int $i) => ClinicClock::now()->addMinutes(20 * $i)->format('h:i A'))->all())
        ->and($data['pending_payments']['items'])->toHaveCount(5)->and($data['pending_payments']['total'])->toBe(7)
        // The balance due is over every bill, not just the five shown.
        ->and($data['pending_payments']['due'])->toEqual(2800);
});

test('View all opens the full list where it exists: today on the Calendar; the Reception and Billing lists are not built', function () {
    $widgets = collect(($this->dashboard)(($this->asOwner)())['widgets'])->keyBy('key');

    expect($widgets['upcoming_appointments']['action'])->toBe(['label' => 'View all', 'href' => '/clinic/appointments?view=day'])
        ->and($widgets['todays_patients']['action'])->toBeNull()
        ->and($widgets['pending_payments']['action'])->toBeNull();
    ($this->asOwner)()->get('/clinic/appointments?view=day')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('ClinicPortal/Appointments/Index', true)->where('view', 'day'));
});

test('Clinic Overview is only for whoever runs the clinic; its money and security need Owner Mode', function () {
    $overview = fn ($as) => collect(($this->dashboard)($as)['widgets'])->where('zone', 'management')->pluck('key')->values()->all();
    $member = fn (ClinicRole $role) => $this->f->memberWithRole($role);

    foreach ([ClinicRole::Receptionist, ClinicRole::BillingStaff, ClinicRole::Doctor] as $role) {
        [$user, $session] = $member($role);
        expect($overview($this->actingAs($user)->withSession($session)))->toBe([]);
    }
    [$admin, $adminSession] = $member(ClinicRole::ClinicAdmin);
    expect($overview($this->actingAs($admin)->withSession($adminSession)))->toBe([])
        ->and($overview(($this->asOwner)()))->toBe([]);

    $now = now()->getTimestamp();
    $ownerMode = [OwnerMode::SESSION_KEY => ['user_id' => $this->f->owner->id, 'clinic_id' => $this->f->clinic->id,
        'membership_id' => $this->f->session[ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID], 'method' => 'password', 'activated_at' => $now, 'last_seen_at' => $now]];
    expect($overview($this->actingAs($this->f->owner)->withSession([...$this->f->session, ...$ownerMode])))->toBe(['owner_finance', 'owner_security']);
});
