<?php

use App\AttendanceSource;
use App\AttendanceStatus;
use App\LeaveBalanceEntryType;
use App\LeaveDayPortion;
use App\LeaveRequestStatus;
use App\Models\CompanyAttendanceRecord;
use App\Models\CompanyDepartment;
use App\Models\CompanyEmployee;
use App\Models\CompanyHoliday;
use App\Models\CompanyLeaveRequest;
use App\Models\CompanyLeaveType;
use App\Models\CompanyWorkingDay;
use App\Models\PayrollPeriod;
use App\Models\User;
use App\PayrollPeriodStatus;
use App\Services\People\AttendanceService;
use App\Services\People\LeaveBalanceService;
use App\Services\People\LeaveRequestService;
use App\Services\People\PayrollService;
use App\Services\People\WorkingCalendarService;
use App\UserType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

/**
 * HQ attendance and leave.
 *
 * Entirely Company-side. The clinic `leaves` and `shifts` tables are not read
 * or written by any of this — `leaves.employee_id` is a foreign key to
 * `clinic_employees`, so a Company employee cannot be stored there even if the
 * two domains meant the same thing, and they do not.
 *
 * Most of what these tests pin is what the system refuses to assume: no working
 * week until one is configured, no leave type until one is defined, no accrual
 * without somebody recording it, no day-fraction for a part day, and no effect
 * on pay from any of it.
 */
function hqPermissions(): void
{
    foreach ([
        'view-attendance', 'manage-attendance',
        'view-leave', 'manage-leave', 'approve-leave',
        'view-payroll', 'manage-payroll', 'approve-payroll',
    ] as $name) {
        Permission::findOrCreate($name, 'web');
    }
}

function hqUser(array $permissions = []): User
{
    $user = User::factory()->create(['user_type' => UserType::CompanyUser]);

    if ($permissions !== []) {
        $user->givePermissionTo($permissions);
    }

    return $user->fresh();
}

function hqEmployee(?User $user = null, array $overrides = []): CompanyEmployee
{
    $department = CompanyDepartment::firstOrCreate(['code' => 'OPS'], ['name' => 'Operations', 'status' => 'active']);

    return CompanyEmployee::create(array_merge([
        'user_id' => ($user ?? hqUser())->id,
        'employee_code' => 'EMP-'.fake()->unique()->numerify('####'),
        'designation' => 'Analyst',
        'department_id' => $department->id,
        'employment_status' => 'active',
        'joining_date' => '2026-01-01',
    ], $overrides));
}

function leaveType(array $overrides = []): CompanyLeaveType
{
    return CompanyLeaveType::create(array_merge([
        'code' => 'ANN-'.fake()->unique()->numerify('###'),
        'name' => 'Annual leave',
        'is_paid' => true,
        'requires_approval' => true,
        'allows_half_day' => true,
        'is_active' => true,
    ], $overrides));
}

beforeEach(function () {
    setPermissionsTeamId(0);
    hqPermissions();

    $this->admin = User::factory()->create(['user_type' => UserType::MasterAdmin]);
    $this->hr = hqUser(['view-attendance', 'manage-attendance', 'view-leave', 'manage-leave', 'approve-leave']);
    $this->reader = hqUser(['view-attendance', 'view-leave']);
});

/* ══════════════════════════ the clinic boundary ═════════════════════════ */

it('never touches the clinic leave and shift tables', function () {
    $employee = hqEmployee();
    $type = leaveType();

    app(LeaveRequestService::class)->request(
        $employee, $type, Carbon::parse('2026-10-05'), Carbon::parse('2026-10-06'),
    );

    app(AttendanceService::class)->checkIn($employee);

    /*
     * The two domains are separate by construction, not by convention:
     * `leaves.employee_id` is constrained to `clinic_employees`.
     */
    expect(DB::table('leaves')->count())->toBe(0)
        ->and(DB::table('shifts')->count())->toBe(0)
        ->and(CompanyLeaveRequest::count())->toBe(1)
        ->and(CompanyAttendanceRecord::count())->toBe(1);
});

/* ═══════════════════════════ super admin bypass ═════════════════════════ */

it('lets the super admin open every attendance and leave surface holding nothing', function () {
    expect($this->admin->getAllPermissions())->toBeEmpty();

    $employee = hqEmployee();

    foreach ([
        route('company.people.attendance.index'),
        route('company.people.attendance.show', $employee),
        route('company.people.leave.index'),
        route('company.people.leave.balances'),
        route('company.people.leave-types.index'),
        route('company.people.working-calendar.index'),
    ] as $url) {
        $this->actingAs($this->admin)->get($url)->assertSuccessful();
    }
});

/* ═════════════════════ read / write / approve split ═════════════════════ */

it('refuses a reader the right to record attendance', function () {
    $employee = hqEmployee();

    $this->actingAs($this->reader)->get(route('company.people.attendance.index'))->assertOk();

    $this->actingAs($this->reader)
        ->post(route('company.people.attendance.store'), [
            'employee_id' => $employee->id,
            'work_date' => '2026-09-07',
            'status' => AttendanceStatus::Absent->value,
        ])
        ->assertForbidden();

    expect(CompanyAttendanceRecord::count())->toBe(0);
});

it('refuses a reader the right to raise or configure leave', function () {
    $employee = hqEmployee();
    $type = leaveType();

    $this->actingAs($this->reader)
        ->post(route('company.people.leave.store'), [
            'employee_id' => $employee->id,
            'leave_type_id' => $type->id,
            'start_date' => '2026-10-01',
            'end_date' => '2026-10-01',
        ])
        ->assertForbidden();

    $this->actingAs($this->reader)
        ->post(route('company.people.leave-types.store'), ['code' => 'X', 'name' => 'Sneaky'])
        ->assertForbidden();

    expect(CompanyLeaveRequest::count())->toBe(0)
        ->and(CompanyLeaveType::count())->toBe(1);
});

/* ═════════════════════════════ employee scope ═══════════════════════════ */

it('shows a plain employee themselves and their own reports, and nobody else', function () {
    $managerUser = hqUser();
    $manager = hqEmployee($managerUser);
    $report = hqEmployee(null, ['manager_id' => $manager->id]);
    $stranger = hqEmployee();

    $type = leaveType();
    $service = app(LeaveRequestService::class);

    foreach ([$manager, $report, $stranger] as $employee) {
        $service->request($employee, $type, Carbon::parse('2026-10-01'), Carbon::parse('2026-10-01'));
    }

    $this->actingAs($managerUser)
        ->get(route('company.people.leave.index'))
        ->assertInertia(fn ($page) => $page->has('requests.data', 2));
});

it('refuses a plain employee another person attendance detail', function () {
    $viewerUser = hqUser();
    hqEmployee($viewerUser);
    $stranger = hqEmployee();

    $this->actingAs($viewerUser)
        ->get(route('company.people.attendance.show', $stranger))
        ->assertForbidden();
});

it('shows nothing to a user with no employee record and no permission', function () {
    hqEmployee();
    $outsider = hqUser();

    $this->actingAs($outsider)
        ->get(route('company.people.leave.index'))
        ->assertInertia(fn ($page) => $page->has('requests.data', 0));
});

/* ════════════════════════ approval: manager and HR ══════════════════════ */

it('lets the real manager approve, and records that it was the manager', function () {
    $managerUser = hqUser();
    $manager = hqEmployee($managerUser);
    $report = hqEmployee(null, ['manager_id' => $manager->id]);

    $leave = app(LeaveRequestService::class)->request(
        $report, leaveType(), Carbon::parse('2026-10-05'), Carbon::parse('2026-10-06'),
    );

    $this->actingAs($managerUser)
        ->post(route('company.people.leave.decide', $leave), ['decision' => 'approved'])
        ->assertRedirect();

    $leave = $leave->fresh();

    expect($leave->status)->toBe(LeaveRequestStatus::Approved)
        ->and($leave->decided_via)->toBe('manager')
        ->and($leave->decided_by)->toBe($managerUser->id);
});

it('refuses a manager who is not this person manager', function () {
    $otherManagerUser = hqUser();
    $otherManager = hqEmployee($otherManagerUser);
    hqEmployee(null, ['manager_id' => $otherManager->id]);

    /* Somebody else's report entirely. */
    $employee = hqEmployee(null, ['manager_id' => hqEmployee()->id]);

    $leave = app(LeaveRequestService::class)->request(
        $employee, leaveType(), Carbon::parse('2026-10-05'), Carbon::parse('2026-10-05'),
    );

    $this->actingAs($otherManagerUser)
        ->post(route('company.people.leave.decide', $leave), ['decision' => 'approved'])
        ->assertForbidden();

    expect($leave->fresh()->status)->toBe(LeaveRequestStatus::Pending);
});

it('falls back to HR when the employee has no manager at all', function () {
    /*
     * Four of the nine people on record have no manager. Without this fallback
     * their requests would be permanently undecidable.
     */
    $employee = hqEmployee();

    expect($employee->manager_id)->toBeNull();

    $leave = app(LeaveRequestService::class)->request(
        $employee, leaveType(), Carbon::parse('2026-11-02'), Carbon::parse('2026-11-02'),
    );

    $this->actingAs($this->hr)
        ->post(route('company.people.leave.decide', $leave), ['decision' => 'approved'])
        ->assertRedirect();

    expect($leave->fresh()->decided_via)->toBe('hr');
});

it('refuses to let anybody approve their own leave', function () {
    /*
     * Checked before either route is considered, so holding `approve-leave` —
     * or somehow being your own manager — does not get around it.
     */
    $selfApprover = $this->hr;
    $employee = hqEmployee($selfApprover);
    $employee->update(['manager_id' => $employee->id]);

    $leave = app(LeaveRequestService::class)->request(
        $employee->fresh(), leaveType(), Carbon::parse('2026-10-05'), Carbon::parse('2026-10-05'),
    );

    $this->actingAs($selfApprover)
        ->post(route('company.people.leave.decide', $leave), ['decision' => 'approved'])
        ->assertForbidden();

    expect($leave->fresh()->status)->toBe(LeaveRequestStatus::Pending);
});

/* ═════════════════════════════ leave balance ════════════════════════════ */

it('records every balance movement as its own ledger entry', function () {
    $employee = hqEmployee();
    $type = leaveType();
    $balances = app(LeaveBalanceService::class);

    $balance = $balances->balanceFor($employee, $type, 2026);
    $balances->record($balance, LeaveBalanceEntryType::Opening, 12, Carbon::parse('2026-01-01'));

    expect($balance->fresh()->available())->toBe(12.0);

    $leave = app(LeaveRequestService::class)->request(
        $employee, $type, Carbon::parse('2026-10-05'), Carbon::parse('2026-10-07'),
    );

    app(LeaveRequestService::class)->approve($leave, $this->hr, 'hr');

    expect((float) $balance->fresh()->used)->toBe(3.0)
        ->and($balance->fresh()->available())->toBe(9.0);

    app(LeaveRequestService::class)->cancel($leave->fresh(), $this->hr);

    expect($balance->fresh()->available())->toBe(12.0)
        ->and($leave->balanceEntries()->count())->toBe(2);
});

it('lets a balance go negative rather than inventing a policy that blocks it', function () {
    /*
     * Whether a request may exceed what is left is a decision nobody has made.
     * Refusing it here would make that decision silently; the overdraft is
     * reported instead.
     */
    $employee = hqEmployee();
    $type = leaveType();

    $leave = app(LeaveRequestService::class)->request(
        $employee, $type, Carbon::parse('2026-10-05'), Carbon::parse('2026-10-09'),
    );

    app(LeaveRequestService::class)->approve($leave, $this->hr, 'hr');

    $balance = app(LeaveBalanceService::class)->balanceFor($employee, $type, 2026);

    expect($balance->available())->toBe(-5.0);
});

/* ══════════════════ accrual and carry-forward foundation ════════════════ */

it('accrues nothing on its own', function () {
    $employee = hqEmployee();
    $type = leaveType();

    $balance = app(LeaveBalanceService::class)->balanceFor($employee, $type, 2026);

    /*
     * No rate, no frequency, no scheduled job. An untouched balance is zero,
     * because nothing in this system has been told what anybody is entitled to.
     */
    expect($balance->entitled())->toBe(0.0)
        ->and($balance->entries()->count())->toBe(0);
});

it('records accrual and carry-forward as entries that roll into their own columns', function () {
    $employee = hqEmployee();
    $type = leaveType();
    $balances = app(LeaveBalanceService::class);

    $balance = $balances->balanceFor($employee, $type, 2026);

    $balances->record($balance, LeaveBalanceEntryType::CarryForward, 4, Carbon::parse('2026-01-01'), 'From 2025.');
    $balances->record($balance, LeaveBalanceEntryType::Accrual, 1.5, Carbon::parse('2026-02-01'));
    $balances->record($balance, LeaveBalanceEntryType::Adjustment, -0.5, Carbon::parse('2026-03-01'), 'Correction.');

    $balance = $balance->fresh();

    expect((float) $balance->carried_forward)->toBe(4.0)
        ->and((float) $balance->accrued)->toBe(1.5)
        ->and((float) $balance->adjusted)->toBe(-0.5)
        ->and($balance->entitled())->toBe(5.0)
        ->and($balance->entries()->count())->toBe(3);
});

it('lets HR record a balance entry through the route', function () {
    $employee = hqEmployee();
    $type = leaveType();

    $this->actingAs($this->hr)
        ->post(route('company.people.leave-balances.entries.store'), [
            'employee_id' => $employee->id,
            'leave_type_id' => $type->id,
            'period_year' => 2026,
            'entry_type' => LeaveBalanceEntryType::CarryForward->value,
            'days' => 6,
            'reason' => 'Carried from last year.',
        ])
        ->assertRedirect();

    expect(app(LeaveBalanceService::class)->balanceFor($employee, $type, 2026)->entitled())->toBe(6.0);
});

/* ═════════════════════════ half day and part day ════════════════════════ */

it('counts a half day as half and a full day as one', function () {
    $employee = hqEmployee();
    $type = leaveType();

    $leave = app(LeaveRequestService::class)->request(
        $employee, $type, Carbon::parse('2026-10-05'), Carbon::parse('2026-10-06'), null,
        ['2026-10-06' => ['portion' => 'half', 'half_period' => 'second_half']],
    );

    expect((float) $leave->total_days)->toBe(1.5)
        ->and($leave->days)->toHaveCount(2)
        ->and($leave->days[1]->portion)->toBe(LeaveDayPortion::Half)
        ->and($leave->days[1]->half_period)->toBe('second_half');
});

it('records a part day in hours and never converts it into a day', function () {
    /*
     * Turning hours into a fraction of a day needs a standard working day, and
     * nothing in this system establishes one. Inventing eight would set company
     * policy from a constant.
     */
    $employee = hqEmployee();
    $type = leaveType();

    $leave = app(LeaveRequestService::class)->request(
        $employee, $type, Carbon::parse('2026-10-05'), Carbon::parse('2026-10-05'), null,
        ['2026-10-05' => ['portion' => 'part', 'hours' => 3]],
    );

    expect((float) $leave->total_days)->toBe(0.0)
        ->and((float) $leave->total_part_day_hours)->toBe(3.0)
        ->and((float) $leave->days[0]->day_value)->toBe(0.0);
});

it('refuses a half day on a type that does not allow one', function () {
    $employee = hqEmployee();
    $type = leaveType(['allows_half_day' => false]);

    expect(fn () => app(LeaveRequestService::class)->request(
        $employee, $type, Carbon::parse('2026-10-05'), Carbon::parse('2026-10-05'), null,
        ['2026-10-05' => ['portion' => 'half']],
    ))->toThrow(InvalidArgumentException::class);
});

/* ═══════════════════════════ attendance clock ═══════════════════════════ */

it('records a check-in and a check-out with the minutes between them', function () {
    $user = hqUser();
    $employee = hqEmployee($user, ['timezone' => 'Asia/Kolkata']);

    Carbon::setTestNow(Carbon::parse('2026-09-07 03:30:00', 'UTC'));
    $this->actingAs($user)->post(route('company.people.attendance.clock'), ['action' => 'in'])->assertRedirect();

    Carbon::setTestNow(Carbon::parse('2026-09-07 12:30:00', 'UTC'));
    $this->actingAs($user)->post(route('company.people.attendance.clock'), ['action' => 'out'])->assertRedirect();

    Carbon::setTestNow();

    $record = CompanyAttendanceRecord::sole();

    expect($record->status)->toBe(AttendanceStatus::Present)
        ->and($record->worked_minutes)->toBe(540)
        ->and($record->source)->toBe(AttendanceSource::Employee);
});

it('keeps the first arrival when somebody checks in twice', function () {
    $user = hqUser();
    $employee = hqEmployee($user);

    Carbon::setTestNow(Carbon::parse('2026-09-07 03:00:00', 'UTC'));
    app(AttendanceService::class)->checkIn($employee);

    Carbon::setTestNow(Carbon::parse('2026-09-07 05:00:00', 'UTC'));
    app(AttendanceService::class)->checkIn($employee->fresh());

    Carbon::setTestNow();

    expect(CompanyAttendanceRecord::sole()->check_in_at->format('H:i'))->toBe('03:00');
});

it('refuses a check-out with no check-in', function () {
    $employee = hqEmployee();

    expect(fn () => app(AttendanceService::class)->checkOut($employee))
        ->toThrow(InvalidArgumentException::class);
});

it('refuses to log attendance for a user with no employee record', function () {
    $outsider = hqUser();

    $this->actingAs($outsider)
        ->post(route('company.people.attendance.clock'), ['action' => 'in'])
        ->assertForbidden();
});

/* ═══════════════════════════ timezone handling ══════════════════════════ */

it('dates the day in the employee own timezone, not the server', function () {
    /*
     * The same instant is two different dates. Kiritimati is UTC+14, so noon in
     * London on the 7th is already the 8th there — and their working day
     * belongs to the 8th.
     */
    $employee = hqEmployee(null, ['timezone' => 'Pacific/Kiritimati']);

    Carbon::setTestNow(Carbon::parse('2026-09-07 12:00:00', 'UTC'));
    $record = app(AttendanceService::class)->checkIn($employee);
    Carbon::setTestNow();

    expect($record->work_date->toDateString())->toBe('2026-09-08')
        ->and($record->timezone)->toBe('Pacific/Kiritimati');
});

it('falls back to the application timezone for an employee who has none', function () {
    /* Three of the nine people on record carry no timezone of their own. */
    $employee = hqEmployee(null, ['timezone' => null]);

    expect($employee->resolvedTimezone())->toBe((string) config('app.timezone'));

    $record = app(AttendanceService::class)->checkIn($employee);

    expect($record->timezone)->toBe((string) config('app.timezone'));
});

/* ═══════════════════════════ correction audit ═══════════════════════════ */

it('keeps a row for every field a correction changed, with who and why', function () {
    $employee = hqEmployee();
    $record = app(AttendanceService::class)->mark(
        $employee, Carbon::parse('2026-09-07'), AttendanceStatus::Absent, AttendanceSource::Manager, $this->hr,
    );

    $this->actingAs($this->hr)
        ->post(route('company.people.attendance.correct', $record), [
            'reason' => 'They were here; the check-in was never logged.',
            'status' => AttendanceStatus::Present->value,
            'notes' => 'Confirmed with the team.',
        ])
        ->assertRedirect();

    $record = $record->fresh('corrections');

    expect($record->status)->toBe(AttendanceStatus::Present)
        ->and($record->corrections)->toHaveCount(2)
        ->and($record->corrections->pluck('field')->all())->toBe(['status', 'notes'])
        ->and($record->corrections->first()->old_value)->toBe('absent')
        ->and($record->corrections->first()->new_value)->toBe('present')
        ->and($record->corrections->first()->corrected_by)->toBe($this->hr->id);
});

it('refuses a correction with no reason', function () {
    $employee = hqEmployee();
    $record = app(AttendanceService::class)->mark(
        $employee, Carbon::parse('2026-09-07'), AttendanceStatus::Absent, AttendanceSource::Manager, $this->hr,
    );

    $this->actingAs($this->hr)
        ->post(route('company.people.attendance.correct', $record), [
            'status' => AttendanceStatus::Present->value,
        ])
        ->assertSessionHasErrors('reason');

    expect($record->fresh()->status)->toBe(AttendanceStatus::Absent);
});

/* ════════════════════ working days and holidays ═════════════════════════ */

it('refuses to guess the working week until somebody configures it', function () {
    $calendar = app(WorkingCalendarService::class);

    expect($calendar->isConfigured())->toBeFalse()
        ->and($calendar->isWorkingDay(Carbon::parse('2026-09-07')))->toBeNull()
        ->and($calendar->workingDaysBetween(Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30')))->toBeNull();

    /* Every one of the seven days is unanswered, not defaulted to true. */
    expect(collect($calendar->weeklyPattern())->pluck('is_working')->filter()->count())->toBe(0);
});

it('answers from the configured week once it is set', function () {
    $this->actingAs($this->hr)
        ->put(route('company.people.working-calendar.pattern.update'), [
            'pattern' => [0 => false, 1 => true, 2 => true, 3 => true, 4 => true, 5 => true, 6 => false],
        ])
        ->assertRedirect();

    $calendar = app(WorkingCalendarService::class);

    expect(CompanyWorkingDay::count())->toBe(7)
        ->and($calendar->isConfigured())->toBeTrue()
        /* 2026-09-07 is a Monday, 2026-09-06 a Sunday. */
        ->and($calendar->isWorkingDay(Carbon::parse('2026-09-07')))->toBeTrue()
        ->and($calendar->isWorkingDay(Carbon::parse('2026-09-06')))->toBeFalse();
});

it('treats a holiday as a non-working day', function () {
    app(WorkingCalendarService::class)->setWeeklyPattern([0 => false, 1 => true, 2 => true, 3 => true, 4 => true, 5 => true, 6 => false]);

    $this->actingAs($this->hr)
        ->post(route('company.people.working-calendar.holidays.store'), [
            'holiday_date' => '2026-10-02',
            'name' => 'Founder\'s Day',
        ])
        ->assertRedirect();

    $calendar = app(WorkingCalendarService::class);

    /* 2026-10-02 is a Friday — a working day but for the holiday. */
    expect($calendar->isWorkingDay(Carbon::parse('2026-10-02')))->toBeFalse()
        ->and($calendar->holidayOn(Carbon::parse('2026-10-02'))->name)->toBe('Founder\'s Day');
});

it('keeps a holiday that has already passed', function () {
    /*
     * Attendance was recorded against it. Deleting it would change what those
     * days meant.
     */
    $holiday = CompanyHoliday::create(['holiday_date' => now()->subYear()->toDateString(), 'name' => 'Last year']);

    $this->actingAs($this->hr)
        ->delete(route('company.people.working-calendar.holidays.destroy', $holiday))
        ->assertSessionHasErrors('holiday');

    expect(CompanyHoliday::count())->toBe(1);
});

it('reports the month with holidays and weekly offs resolved from the calendar', function () {
    app(WorkingCalendarService::class)->setWeeklyPattern([0 => false, 1 => true, 2 => true, 3 => true, 4 => true, 5 => true, 6 => false]);
    CompanyHoliday::create(['holiday_date' => '2026-10-02', 'name' => 'Founder\'s Day']);

    $employee = hqEmployee(null, ['timezone' => 'Asia/Kolkata']);

    $summary = app(AttendanceService::class)->monthlySummary($employee, 2026, 10);

    expect($summary['calendar_configured'])->toBeTrue()
        ->and($summary['days'])->toHaveCount(31)
        ->and($summary['counts']['holiday'])->toBe(1)
        ->and($summary['counts']['weekly_off'])->toBeGreaterThan(0)
        /* Nothing logged is "unrecorded", never silently "absent". */
        ->and($summary['counts']['absent'])->toBe(0);
});

/* ═══════════════════════════ payroll boundary ═══════════════════════════ */

it('changes no payroll figure when leave is approved', function () {
    $employee = hqEmployee();
    $type = leaveType(['is_paid' => false]);

    app(PayrollService::class)->setCompensation($employee, [
        'base_salary' => 100000,
        'effective_from' => '2026-01-01',
    ]);

    $leave = app(LeaveRequestService::class)->request(
        $employee, $type, Carbon::parse('2026-10-05'), Carbon::parse('2026-10-09'),
    );
    app(LeaveRequestService::class)->approve($leave, $this->hr, 'hr');

    $period = PayrollPeriod::create([
        'code' => 'PR-OCT', 'label' => 'October 2026',
        'period_start' => '2026-10-01', 'period_end' => '2026-10-31',
        'status' => PayrollPeriodStatus::Draft,
    ]);

    app(PayrollService::class)->calculate($period, $this->admin);

    $entry = $period->fresh()->entries()->sole();

    /*
     * Five days of *unpaid* leave, and the salary is untouched. Attendance and
     * leave are payroll-ready and payroll-neutral: `is_paid` is recorded and
     * read by nothing, and no deduction line exists.
     */
    expect((float) $entry->gross_amount)->toBe(100000.0)
        ->and((float) $entry->net_amount)->toBe(100000.0)
        ->and($entry->lines->pluck('component_code')->all())->toBe(['BASE']);
});

it('holds no attendance or leave reference in the payroll service', function () {
    $source = File::get(app_path('Services/People/PayrollService.php'));

    expect($source)->not->toContain('CompanyLeaveRequest')
        ->and($source)->not->toContain('CompanyAttendanceRecord')
        ->and($source)->not->toContain('AttendanceService');
});

/* ═════════════════════════════ page contracts ═══════════════════════════ */

it('sends the attendance overview its day summary and scoped rows', function () {
    $employee = hqEmployee();
    app(AttendanceService::class)->checkIn($employee);

    $this->actingAs($this->admin)
        ->get(route('company.people.attendance.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Company/People/AttendanceOverview')
            ->has('records', 1)
            ->has('summary.counts')
            ->where('can.manage', true)
        );
});

it('sends the leave overview only what this reader may decide on', function () {
    $employee = hqEmployee();
    app(LeaveRequestService::class)->request(
        $employee, leaveType(), Carbon::parse('2026-10-05'), Carbon::parse('2026-10-05'),
    );

    $this->actingAs($this->hr)
        ->get(route('company.people.leave.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Company/People/LeaveOverview')
            ->has('requests.data', 1)
            ->has('awaitingMe', 1)
        );
});

it('sends the working calendar its unconfigured state honestly', function () {
    $this->actingAs($this->admin)
        ->get(route('company.people.working-calendar.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Company/People/WorkingCalendar')
            ->where('configured', false)
            ->has('pattern', 7)
        );
});
