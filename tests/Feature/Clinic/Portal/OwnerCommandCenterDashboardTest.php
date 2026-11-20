<?php

use App\BillingStatus;
use App\BranchStatus;
use App\ClinicRole;
use App\Models\Branch;
use App\Models\ClinicEmployee;
use App\Models\ClinicExpense;
use App\Models\ClinicMembership;
use App\Models\CompanySubscription;
use App\Models\Leave;
use App\PaymentMethod;
use App\Services\BillingService;
use App\Services\Business\ClinicExpenses;
use App\Services\Business\ClinicPayroll;
use App\Services\ClinicContext;
use App\Services\PaymentService;
use App\Services\ResourceLimitService;
use App\Support\ClinicAccess\ClinicAccess;
use App\Support\Dashboard\DashboardData;
use App\Support\Dashboard\OwnerCommandCenter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\ReceptionFixture;

/**
 * The Owner Command Center: the home of whoever runs the clinic.
 *
 * It shows the day's operations, money, spending, what waits on a decision,
 * people and plan, security and the recent sensitive actions. Every linked
 * figure is the count or total of the list it opens. The owner-only blocks are
 * sent only to the owner in Owner Mode, and arrive locked and empty otherwise.
 * Staff never receive them.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-22 10:00', 'Asia/Kolkata'));
    $this->f = ReceptionFixture::make('owner-command-center');
    $this->subscription = CompanySubscription::factory()->create([
        'clinic_id' => $this->f->clinic->id, 'max_users' => 5, 'max_doctors' => 2, 'max_staff' => 3, 'max_branches' => 1,
    ]);
    $this->category = app(ClinicExpenses::class)->categories($this->f->clinic)->firstWhere('name', 'Rent');
    $this->expense = fn (string $status, float $amount, array $attributes = []) => ClinicExpense::query()->withoutGlobalScopes()->create([
        'clinic_id' => $this->f->clinic->id, 'branch_id' => $this->f->branch->id, 'category_id' => $this->category->id,
        'title' => "{$status} expense", 'amount' => $amount, 'spent_on' => '2026-10-10', 'status' => $status, 'created_by' => $this->f->owner->id, ...$attributes,
    ]);
    $this->props = function (callable $as, string $url = '/clinic/dashboard'): array {
        $props = [];
        $as()->get($url)->assertOk()->assertInertia(function (Assert $page) use (&$props) {
            $props = $page->toArray()['props'];
        });

        return $props;
    };
    $this->sections = function (callable $as): array {
        $props = ($this->props)($as);
        expect($props)->toHaveKey('sections');

        return $props['sections'];
    };
    $this->asOwnerInOwnerMode = fn () => $this->actingAs($this->f->owner)->withSession(withOwnerMode($this->f->session));
    $this->attention = fn (array $sections) => collect($sections['decisions']['items'] ?? [])->keyBy('id');
});

test('the owner and an administrator land on the Command Center; other roles do not', function () {
    ($this->asOwnerInOwnerMode)()->get('/clinic/dashboard')->assertInertia(fn (Assert $page) => $page->component('ClinicPortal/CommandCenter/Index', false)->where('is_owner', true));

    [$admin, $session] = $this->f->memberWithRole(ClinicRole::ClinicAdmin);
    $this->actingAs($admin)->withSession($session)->get('/clinic/dashboard')
        ->assertInertia(fn (Assert $page) => $page->component('ClinicPortal/CommandCenter/Index', false)->where('is_owner', false));

    // Everyone else goes to their own home: the Front Desk, Billing or the Calendar.
    foreach ([ClinicRole::Receptionist, ClinicRole::BillingStaff, ClinicRole::Doctor] as $role) {
        [$user, $memberSession] = $this->f->memberWithRole($role);
        $this->actingAs($user)->withSession($memberSession)->get('/clinic/dashboard')->assertRedirect();
        expect($this->actingAs($user)->withSession($memberSession)->get('/clinic/dashboard')->headers->get('Location'))->not->toEndWith('/clinic/dashboard');
    }
});

test('People & plan shows logins against the plan as enforced, profiles without a login, and who is on leave today', function () {
    $this->f->memberWithRole(ClinicRole::Doctor);
    ClinicEmployee::create(['clinic_id' => $this->f->clinic->id, 'name' => 'Payroll Only', 'email' => 'payroll.only@clinic.test', 'status' => 'active']);
    Leave::query()->withoutGlobalScopes()->create([
        'clinic_id' => $this->f->clinic->id, 'employee_id' => $this->f->doctorA->id, 'leave_type' => 'casual', 'status' => Leave::APPROVED,
        'start_date' => '2026-10-21', 'end_date' => '2026-10-23',
    ]);
    $withoutLogin = ClinicEmployee::query()->withoutGlobalScopes()->where('clinic_id', $this->f->clinic->id)->whereNull('user_id')->count();

    $people = ($this->sections)($this->asOwnerInOwnerMode)['people'];

    // One doctor login; the owner holds a staff login; two logins of five.
    expect($people['plan']['name'])->toBe($this->subscription->plan->name)
        ->and(collect($people['seats'])->mapWithKeys(fn (array $seat) => [$seat['key'] => [$seat['used'], $seat['allowed']]])->all())->toBe([
            'doctors' => [1, 2], 'staff' => [1, 3], 'logins' => [2, 5], 'branches' => [1, 1],
        ])
        ->and($people['without_login'])->toBe($withoutLogin)
        ->and($people['on_leave_today'])->toBe(1);
});

test('a branch counts against the plan whatever organisation its row names', function () {
    Branch::factory()->create(['clinic_id' => $this->f->clinic->id, 'name' => 'Unlinked', 'status' => BranchStatus::Active, 'organization_id' => null]);

    $seats = collect(($this->sections)($this->asOwnerInOwnerMode)['people']['seats'])->keyBy('key');

    expect($seats['branches']['used'])->toBe(2)
        ->and(app(ResourceLimitService::class)->canCreateBranch($this->f->clinic))->toBeFalse();
});

test('every money figure is the total of the Billing list it opens', function () {
    $bill = app(BillingService::class)->createBill($this->f->clinic, $this->f->patient('Money', 'Card'), $this->f->branch, [['description' => 'Crown', 'quantity' => 1, 'unit_price' => 2000]]);
    app(PaymentService::class)->recordPayment($bill->fresh(), PaymentMethod::Cash, 1000);
    ($this->asOwnerInOwnerMode)()->post("/clinic/billing/bills/{$bill->id}/adjustments", ['amount' => 150, 'reason' => 'Senior citizen concession'])->assertSessionHasNoErrors();
    ($this->asOwnerInOwnerMode)()->post("/clinic/billing/bills/{$bill->id}/refunds", ['amount' => 100, 'reason' => 'Overcharged'])->assertSessionHasNoErrors();
    // Earlier this month: in the month's figures, not today's.
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00', 'Asia/Kolkata'));
    $older = app(BillingService::class)->createBill($this->f->clinic, $this->f->patient('Early', 'Month'), $this->f->branch, [['description' => 'Scaling', 'quantity' => 1, 'unit_price' => 800]]);
    app(PaymentService::class)->recordPayment($older->fresh(), PaymentMethod::Upi, 800);
    $this->travelTo(CarbonImmutable::parse('2026-10-22 10:00', 'Asia/Kolkata'));

    $money = ($this->sections)($this->asOwnerInOwnerMode)['money'];
    expect($money['today']['billed']['value'])->toEqual(2000)->and($money['month']['billed']['value'])->toEqual(2800)
        ->and($money['today']['collected']['value'])->toEqual(1000)->and($money['month']['collected']['value'])->toEqual(1800)
        ->and($money['month']['net_collected']['value'])->toEqual(1700)
        ->and($money['month']['adjusted']['value'])->toEqual(150)
        ->and($money['outstanding']['value'])->toEqual(850);

    $total = fn (string $href, string $key) => ($this->props)($this->asOwnerInOwnerMode, $href)['totals'][$key];
    foreach (['today', 'month'] as $period) {
        expect($total($money[$period]['billed']['href'], 'billed'))->toEqual($money[$period]['billed']['value'])
            ->and($total($money[$period]['collected']['href'], 'collected'))->toEqual($money[$period]['collected']['value'])
            ->and($total($money[$period]['refunded']['href'], 'refunded'))->toEqual($money[$period]['refunded']['value'])
            ->and($total($money[$period]['adjusted']['href'], 'adjusted'))->toEqual($money[$period]['adjusted']['value']);
    }
    expect($total($money['outstanding']['href'], 'outstanding'))->toEqual($money['outstanding']['value']);
});

test('collections by payment method add up to the collected figure, each matching the Collections list for that method', function () {
    $bill = app(BillingService::class)->createBill($this->f->clinic, $this->f->patient('Split', 'Payer'), $this->f->branch, [['description' => 'Crown', 'quantity' => 1, 'unit_price' => 3000]]);
    app(PaymentService::class)->recordPayment($bill->fresh(), PaymentMethod::Cash, 1000);
    app(PaymentService::class)->recordPayment($bill->fresh(), PaymentMethod::Upi, 1500);
    app(PaymentService::class)->recordPayment($bill->fresh(), PaymentMethod::Upi, 250);
    // A refund is its own figure: it never comes off a method's collections.
    ($this->asOwnerInOwnerMode)()->post("/clinic/billing/bills/{$bill->id}/refunds", ['amount' => 100, 'reason' => 'Overcharged'])->assertSessionHasNoErrors();

    $today = ($this->sections)($this->asOwnerInOwnerMode)['money']['today'];
    $methods = collect($today['methods'])->keyBy('key');

    expect($methods->keys()->all())->toBe(['upi', 'cash'])
        ->and($methods['upi'])->toMatchArray(['label' => 'UPI', 'amount' => 1750.0, 'count' => 2])
        ->and($methods['cash'])->toMatchArray(['label' => 'Cash', 'amount' => 1000.0, 'count' => 1])
        ->and($methods->sum('amount'))->toEqual($today['collected']['value'])
        ->and($today['unused_methods'])->toContain('Card', 'Bank Transfer')->not->toContain('Cash', 'UPI', 'Payment Gateway', 'Insurance');

    foreach ($methods as $method) {
        expect(($this->props)($this->asOwnerInOwnerMode, $method['href'])['totals']['collected'])->toEqual($method['amount']);
    }
});

test('the money trend gives billed and collected for each of the last 30 clinic days, each day matching the Billing lists', function () {
    $bill = app(BillingService::class)->createBill($this->f->clinic, $this->f->patient('Trend', 'Today'), $this->f->branch, [['description' => 'Crown', 'quantity' => 1, 'unit_price' => 2000]]);
    app(PaymentService::class)->recordPayment($bill->fresh(), PaymentMethod::Cash, 1000);
    // A cancelled bill carries no money on any day.
    $cancelled = app(BillingService::class)->createBill($this->f->clinic, $this->f->patient('Trend', 'Cancelled'), $this->f->branch, [['description' => 'Void', 'quantity' => 1, 'unit_price' => 999]]);
    DB::table('patient_billings')->where('id', $cancelled->id)->update(['status' => BillingStatus::Cancelled->value]);
    // Late on the 21st and just after midnight on the 22nd in the clinic: both are the 21st in UTC.
    $this->travelTo(CarbonImmutable::parse('2026-10-21 23:50', 'Asia/Kolkata'));
    $late = app(BillingService::class)->createBill($this->f->clinic, $this->f->patient('Trend', 'Late'), $this->f->branch, [['description' => 'Scaling', 'quantity' => 1, 'unit_price' => 600]]);
    app(PaymentService::class)->recordPayment($late->fresh(), PaymentMethod::Upi, 600);
    $this->travelTo(CarbonImmutable::parse('2026-10-22 00:10', 'Asia/Kolkata'));
    app(PaymentService::class)->recordPayment($bill->fresh(), PaymentMethod::Cash, 200);
    $this->travelTo(CarbonImmutable::parse('2026-10-22 10:00', 'Asia/Kolkata'));

    $money = ($this->sections)($this->asOwnerInOwnerMode)['money'];
    $trend = collect($money['trend'])->keyBy('date');

    expect($trend)->toHaveCount(OwnerCommandCenter::TREND_DAYS)
        ->and($trend->keys()->first())->toBe('2026-09-23')->and($trend->keys()->last())->toBe('2026-10-22')
        ->and($trend['2026-10-21']['billed'])->toEqual(600)->and($trend['2026-10-21']['collected'])->toEqual(600)
        ->and($trend['2026-10-22']['billed'])->toEqual(2000)->and($trend['2026-10-22']['collected'])->toEqual(1200)
        // Today's point is today's figure; the month's points add up to the month's.
        ->and($trend['2026-10-22']['billed'])->toEqual($money['today']['billed']['value'])
        ->and($trend->filter(fn (array $day, string $date) => $date >= '2026-10-01')->sum('collected'))->toEqual($money['month']['collected']['value']);

    $total = fn (array $query, string $key) => ($this->props)($this->asOwnerInOwnerMode, route('clinic.billing.index', $query, false))['totals'][$key];
    foreach (['2026-10-21', '2026-10-22'] as $day) {
        expect($total(['from' => $day, 'to' => $day], 'billed'))->toEqual($trend[$day]['billed'])
            ->and($total(['view' => 'collections', 'from' => $day, 'to' => $day], 'collected'))->toEqual($trend[$day]['collected']);
    }
});

test('the owner sees the plan as the platform enforces it, the workforce and what the clinic may use', function () {
    $people = ($this->sections)($this->asOwnerInOwnerMode)['people'];
    $active = ClinicEmployee::query()->withoutGlobalScopes()->where('clinic_id', $this->f->clinic->id)->where('status', 'active');

    expect($people['subscription']['state'])->toBeIn(['active', 'trial'])
        ->and($people['subscription']['plan'])->toBe($this->subscription->plan->name)
        ->and($people['workforce']['staff'])->toBe((clone $active)->count())
        ->and($people['workforce']['practitioners'])->toBe((clone $active)->whereHas('practitionerProfile', fn ($q) => $q->withoutGlobalScopes()->where('is_active', true))->count())
        ->and($people['workforce']['all_branches'] + $people['workforce']['some_branches'])->toBe(ClinicMembership::query()->withoutGlobalScopes()->where('clinic_id', $this->f->clinic->id)->where('status', 'active')->count())
        ->and(collect($people['modules'])->pluck('key')->all())->toBe(collect(app(ClinicAccess::class)->clinicModules())->filter()->keys()->all())
        ->and($people['specialties'])->not->toBeEmpty();

    // With no subscription on record, the plan says so and no seat limit is invented.
    $this->subscription->delete();
    $people = ($this->sections)($this->asOwnerInOwnerMode)['people'];
    expect($people['subscription']['state'])->toBe('none')->and($people['subscription']['plan'])->toBeNull()
        ->and(collect($people['seats'])->pluck('allowed')->unique()->all())->toBe([null]);
});

test('the Command Center is a management view: it carries no patient-intake quick actions', function () {
    expect(($this->props)($this->asOwnerInOwnerMode))->not->toHaveKey('actions');

    [$admin, $session] = $this->f->memberWithRole(ClinicRole::ClinicAdmin);
    expect(($this->props)(fn () => $this->actingAs($admin)->withSession($session)))->not->toHaveKey('actions');
});

test('spending counts every expense waiting on someone, and each count opens exactly those, however old', function () {
    ($this->expense)('pending', 300);
    ($this->expense)('pending', 200, ['spent_on' => '2026-06-02']);
    ($this->expense)('approved', 1000, ['spent_on' => '2025-12-30']);
    ($this->expense)('paid', 500);

    $sections = ($this->sections)($this->asOwnerInOwnerMode);
    expect($sections['spending']['to_decide'])->toMatchArray(['count' => 2, 'amount' => 500.0])
        ->and($sections['spending']['to_pay'])->toMatchArray(['count' => 1, 'amount' => 1000.0]);

    foreach (['to_decide', 'to_pay'] as $bucket) {
        expect(($this->props)($this->asOwnerInOwnerMode, $sections['spending'][$bucket]['href'])['expenses']['total'])->toBe($sections['spending'][$bucket]['count']);
    }
    // The decision inbox agrees and opens the same lists.
    $items = ($this->attention)($sections);
    expect($items['expenses_to_decide']['count'])->toBe(2)->and($items['expenses_to_decide']['href'])->toBe($sections['spending']['to_decide']['href']);
});

test("this month's spending matches the Expenses list: approved and paid only, dated this month", function () {
    ($this->expense)('paid', 500);
    ($this->expense)('approved', 1200);
    ($this->expense)('pending', 300);
    ($this->expense)('rejected', 900);
    // Last month: not this month's spending.
    ($this->expense)('paid', 4000, ['spent_on' => '2026-09-28']);

    $month = ($this->sections)($this->asOwnerInOwnerMode)['spending']['month'];
    $list = ($this->props)($this->asOwnerInOwnerMode, $month['href'])['summary'];

    expect($month['spent'])->toEqual(1700)->and($month['paid'])->toEqual(500)
        ->and($month['spent'])->toEqual($list['spent'])->and($month['paid'])->toEqual($list['paid'])
        ->and($month['categories'])->toEqual([['name' => 'Rent', 'total' => 1700]])
        ->and(collect($list['by_category'])->firstWhere('name', 'Rent')['total'])->toEqual($month['categories'][0]['total']);
});

test('what waits on a decision: expenses to decide and to pay, payroll to finalise, each linking to its page', function () {
    ($this->expense)('pending', 300);
    ($this->expense)('pending', 200);
    ($this->expense)('approved', 1000);
    app(ClinicPayroll::class)->open($this->f->clinic, '2026-10', $this->f->owner);

    $items = ($this->attention)(($this->sections)($this->asOwnerInOwnerMode));

    expect($items['expenses_to_decide'])->toMatchArray(['count' => 2, 'href' => '/clinic/expenses?status=pending&period=all'])
        ->and($items['expenses_to_pay'])->toMatchArray(['count' => 1, 'href' => '/clinic/expenses?status=approved&period=all'])
        ->and($items['payroll_to_finalise'])->toMatchArray(['count' => 1, 'href' => '/clinic/payroll']);
});

test('recent sensitive actions name what happened and who did it', function () {
    $bill = app(BillingService::class)->createBill($this->f->clinic, $this->f->patient('Audit', 'Trail'), $this->f->branch, [['description' => 'Filling', 'quantity' => 1, 'unit_price' => 900]]);
    app(PaymentService::class)->recordPayment($bill->fresh(), PaymentMethod::Cash, 900);
    ($this->asOwnerInOwnerMode)()->post("/clinic/billing/bills/{$bill->id}/refunds", ['amount' => 100, 'reason' => 'Overcharged'])->assertSessionHasNoErrors();

    $activity = ($this->sections)($this->asOwnerInOwnerMode)['activity'];

    expect($activity['total'])->toBe(1)
        ->and($activity['items'][0])->toMatchArray(['primary' => 'Refund recorded', 'secondary' => $this->f->owner->name, 'status' => 'info']);
});

test('owner-only blocks go to the owner from sign-in, never to staff, and staff see only the decisions they may take', function () {
    ($this->expense)('pending', 300);
    ($this->expense)('approved', 1000);

    // An owner who does not treat patients, signed in with no mode: every owner-only block.
    $plain = ($this->sections)(fn () => $this->actingAs($this->f->owner)->withSession($this->f->session));
    foreach (OwnerCommandCenter::OWNER_SECTIONS as $section) {
        expect($plain[$section]['state'])->toBe('ready');
    }

    // A clinic administrator: expense decisions and spending, but no owner block at all.
    [$admin, $adminSession] = $this->f->memberWithRole(ClinicRole::ClinicAdmin);
    $adminSections = ($this->sections)(fn () => $this->actingAs($admin)->withSession($adminSession));
    expect(array_intersect(array_keys($adminSections), OwnerCommandCenter::OWNER_SECTIONS))->toBe([])
        ->and(($this->attention)($adminSections)->keys()->all())->toContain('expenses_to_decide', 'expenses_to_pay')
        ->and($adminSections['spending']['to_decide']['count'])->toBe(1);

    // Front desk and billing staff never reach the Command Center: they are sent to their own home.
    foreach ([[ClinicRole::Receptionist, 'clinic.reception'], [ClinicRole::BillingStaff, 'clinic.billing.index']] as [$role, $home]) {
        [$user, $session] = $this->f->memberWithRole($role);
        $this->actingAs($user)->withSession($session)->get('/clinic/dashboard')->assertRedirect(route($home));
    }
});

test('an administrator working at one branch counts only that branch\'s expenses', function () {
    $east = Branch::factory()->create(['clinic_id' => $this->f->clinic->id, 'name' => 'East', 'status' => BranchStatus::Active]);
    ($this->expense)('pending', 300);
    ($this->expense)('pending', 200, ['branch_id' => $east->id]);
    ($this->expense)('pending', 100, ['branch_id' => null]);

    [$admin, $session] = $this->f->memberWithRole(ClinicRole::ClinicAdmin);
    $membership = ClinicMembership::query()->withoutGlobalScopes()->findOrFail($session[ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID]);
    $membership->update(['has_all_branches_access' => false]);
    $membership->assignBranch($this->f->branch);

    $branchOnly = ($this->sections)(fn () => $this->actingAs($admin)->withSession($session));
    $clinicWide = ($this->sections)($this->asOwnerInOwnerMode);

    expect(($this->attention)($branchOnly)['expenses_to_decide']['count'])->toBe(1)
        ->and($branchOnly['spending']['to_decide']['count'])->toBe(1)
        ->and(($this->attention)($clinicWide)['expenses_to_decide']['count'])->toBe(3)
        ->and($clinicWide['spending']['to_decide']['count'])->toBe(3);
});

test('a block that fails to load reports itself without taking the page down', function () {
    $data = Mockery::mock(DashboardData::class, [app(ClinicContext::class), app(ClinicAccess::class)])->makePartial();
    $data->shouldReceive('source')->with('ownerSecurity')->andThrow(new RuntimeException('Audit log unavailable'));
    $data->shouldReceive('source')->with(Mockery::not('ownerSecurity'))->passthru();
    $this->app->instance(DashboardData::class, $data);

    $sections = ($this->sections)($this->asOwnerInOwnerMode);

    expect($sections['security'])->toBe(['state' => 'error'])
        ->and($sections['activity']['state'])->toBe('ready')
        ->and($sections['money']['state'])->toBe('ready');
});
