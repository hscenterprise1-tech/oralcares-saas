<?php

use App\AppointmentStatus;
use App\BranchStatus;
use App\ClinicPermission;
use App\ClinicRole;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\ClinicEmployee;
use App\Models\ClinicQueueItem;
use App\Models\Patient;
use App\Models\PatientFollowUp;
use App\Services\Clinical\EncounterWorkspace;
use App\Services\ClinicRoleAssignmentService;
use App\Services\Reception\FrontDesk;
use App\Services\Reception\ReceptionCheckIn;
use App\Services\Reception\VisitContext;
use App\Support\ClinicAccess\ClinicModules;
use App\VisitPriority;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\TreatmentFixture;

/**
 * The Reception Control Center: every section is composed from what the
 * person may do (billing, queue, bookings, follow-ups), never from the name
 * of their role; its figures are this branch's day only; and the queue
 * controls exist only with the Queue module, on the server as on the page.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-11 10:00', 'Asia/Kolkata'));
    $this->t = TreatmentFixture::make();
    $this->f = $this->t->f;
    [$this->receptionUser, $this->receptionSession] = $this->f->memberWithRole(ClinicRole::Receptionist);
    ClinicEmployee::create(['clinic_id' => $this->f->clinic->id, 'user_id' => $this->receptionUser->id, 'name' => 'Asha Desk', 'email' => 'asha@example.com', 'status' => 'active']);
    [$this->doctorUser] = $this->f->memberWithRole(ClinicRole::Doctor);
    $this->doctor = $this->f->doctor('Dr. Rehab', $this->doctorUser);
    $this->doctor->practitionerProfile()->withoutGlobalScopes()->update(['specialty' => 'Physiotherapist', 'consultation_fee' => 500]);
    $this->asReception = fn () => $this->actingAs($this->receptionUser)->withSession($this->receptionSession);
    $this->checkIn = fn (string $first, ?Patient $patient = null, $branch = null, ?Appointment $appointment = null): ClinicQueueItem => app(ReceptionCheckIn::class)->checkIn(
        $this->f->clinic, $branch ?? $this->f->branch, $patient ?? $this->f->patient($first, 'Desk'), appointment: $appointment, doctor: $this->doctor,
        context: VisitContext::fromInput($this->f->clinic, ['visit_specialty' => 'physiotherapy', 'visit_reason' => 'Knee pain']),
    );
    $this->page = function (?callable $as = null): array {
        $props = [];
        ($as ?? $this->asReception)()->get('/clinic/reception')->assertOk()->assertInertia(function (Assert $page) use (&$props) {
            $props = $page->toArray();
        });

        return $props;
    };
    $this->book = fn (Patient $patient, string $at, AppointmentStatus $status = AppointmentStatus::Scheduled, ?Branch $branch = null) => Appointment::create([
        'clinic_id' => $this->f->clinic->id, 'branch_id' => ($branch ?? $this->f->branch)->id, 'patient_id' => $patient->id,
        'practitioner_id' => $this->doctor->id, 'scheduled_at' => CarbonImmutable::parse($at, 'Asia/Kolkata'), 'duration_minutes' => 15, 'status' => $status,
    ]);
});

test('a receptionist\'s Control Center is named Reception, with every section their rights open', function () {
    $page = ($this->page)();

    expect($page['props']['can'])->toBe(['register' => true, 'appointments' => true, 'book' => true, 'queue' => true])
        ->and($page['props']['billing'])->not->toBeNull()
        ->and($page['props']['links']['book'])->toContain('book=1')
        // "View all" opens the Queue page's stage tab when the module is on.
        ->and($page['props']['links']['queue'])->toEndWith('/clinic/queue')
        // Follow-ups load after the page, behind a skeleton.
        ->and($page['props'])->not->toHaveKey('follow_ups');
    $visit = ($this->asReception)()->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request())])->get('/clinic/reception');
    expect(collect($visit->json('deferredProps'))->flatten()->all())->toContain('follow_ups');

    $navigation = collect($page['props']['clinic']['navigation']);
    expect($navigation->firstWhere('key', 'front_desk')['label'])->toBe('Queue')
        ->and($navigation->pluck('label')->all())->not->toContain('Front Desk')
        ->and($navigation->pluck('key')->all())->not->toContain('check_in', 'patient_search');
});

test('the owner reaches it as the Queue, beside the Calendar', function () {
    $this->actingAs($this->f->owner)->withSession($this->f->session)->get('/clinic/reception')->assertOk()
        ->assertInertia(fn (Assert $page) => expect(collect($page->toArray()['props']['clinic']['navigation'])->firstWhere('key', 'front_desk')['label'])->toBe('Queue'));
});

test('a front office without billing or booking rights sees no money, no follow-ups and no booking action', function () {
    setPermissionsTeamId(null);
    Role::findByName(ClinicRole::Receptionist->value, 'web')->revokePermissionTo([
        ClinicPermission::BillingView->value, ClinicPermission::BillingCreate->value, ClinicPermission::PaymentCollect->value, ClinicPermission::AppointmentCreate->value,
    ]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $item = ($this->checkIn)('Ravi');
    $this->actingAs($this->doctorUser);
    $encounter = app(EncounterWorkspace::class)->startFromQueue($item, $this->doctor);
    app(EncounterWorkspace::class)->autosave($encounter, ['chief_complaint' => 'Knee pain'], 0);
    app(EncounterWorkspace::class)->finalize($encounter->fresh(), $this->doctor);

    $page = ($this->page)();

    expect($page['props']['billing'])->toBeNull()
        ->and($page['props']['follow_ups'])->toBeNull()
        ->and($page['props']['can']['book'])->toBeFalse()
        ->and($page['props']['links']['book'])->toBeNull()
        ->and($page['props']['desk']['done'][0]['payment'])->toBeNull();
});

test('with billing, a finished visit says where its money stands', function () {
    $item = ($this->checkIn)('Ravi');
    $this->actingAs($this->doctorUser);
    $encounter = app(EncounterWorkspace::class)->startFromQueue($item, $this->doctor);
    app(EncounterWorkspace::class)->autosave($encounter, ['chief_complaint' => 'Knee pain'], 0);
    app(EncounterWorkspace::class)->finalize($encounter->fresh(), $this->doctor);

    expect(($this->page)()['props']['desk']['done'][0]['payment'])->toBe('not_billed');

    $collect = ($this->page)()['props']['billing']['ready'][0]['links']['collect'];
    ($this->asReception)()->post($collect, ['method' => 'cash', 'amount' => 200])->assertSessionHasNoErrors();

    expect(($this->page)()['props']['desk']['done'][0]['payment'])->toBe('due');
});

test('without the Queue module nobody calls from the desk, on the page or the server; a patient leaving still works', function () {
    $item = ($this->checkIn)('Ravi');
    app(ClinicModules::class)->override($this->f->clinic, 'queue', false, $this->f->owner);

    expect(($this->page)()['props']['can']['queue'])->toBeFalse()
        ->and(($this->page)()['props']['links']['queue'])->toBeNull();
    ($this->asReception)()->patch("/clinic/reception/queue/{$item->id}/status", ['action' => 'call'])->assertForbidden();
    ($this->asReception)()->patch("/clinic/reception/queue/{$item->id}/status", ['action' => 'cancel'])->assertRedirect();
    ($this->asReception)()->get('/clinic/reception/list/waiting')->assertOk()->assertInertia(fn (Assert $page) => $page->where('can.queue', false));
});

test('the day\'s figures: walk-ins against bookings, bookings to come, late ones apart, cancelled ones gone', function () {
    $booked = $this->f->patient('Meera', 'Booked');
    $bookedAppointment = ($this->book)($booked, '2026-10-11 09:45', AppointmentStatus::Scheduled);
    ($this->checkIn)('Meera', $booked, appointment: $bookedAppointment);
    ($this->checkIn)('Walk', null);
    ($this->checkIn)('Walk2', null);
    $soon = ($this->book)($this->f->patient('Soon', 'Later'), '2026-10-11 11:30');
    $late = ($this->book)($this->f->patient('Late', 'Comer'), '2026-10-11 09:00');
    ($this->book)($this->f->patient('Gone', 'Away'), '2026-10-11 12:00', AppointmentStatus::Cancelled);

    $desk = ($this->page)()['props']['desk'];

    expect($desk['counts'])->toMatchArray(['today' => 3, 'walk_ins' => 2, 'waiting' => 3, 'appointments' => 3, 'booked_not_arrived' => 2])
        ->and(collect($desk['waiting'])->pluck('source', 'patient.name')->all())->toMatchArray(['Meera Booked' => 'appointment', 'Walk Desk' => 'walk_in'])
        ->and(array_column($desk['upcoming'], 'id'))->toBe([$soon->id])
        ->and(array_column($desk['attention']['late_bookings']['rows'], 'id'))->toBe([$late->id]);
});

test('nothing from another branch reaches this desk: patients, bookings or follow-ups', function () {
    $east = Branch::factory()->create(['clinic_id' => $this->f->clinic->id, 'name' => 'East', 'is_main' => false, 'status' => BranchStatus::Active]);
    ($this->checkIn)('Elsewhere', null, $east);
    ($this->book)($this->f->patient('East', 'Booking'), '2026-10-11 11:00', branch: $east);
    PatientFollowUp::query()->withoutGlobalScopes()->create([
        'clinic_id' => $this->f->clinic->id, 'branch_id' => $east->id, 'patient_id' => $this->f->patient()->id, 'due_on' => '2026-10-11', 'status' => PatientFollowUp::DUE,
    ]);

    $desk = ($this->page)()['props']['desk'];
    $followUps = app(FrontDesk::class)->followUps($this->f->clinic, $this->f->branch);

    expect($desk['counts'])->toMatchArray(['today' => 0, 'waiting' => 0, 'appointments' => 0])
        ->and($desk['upcoming'])->toBe([])
        ->and($followUps['count'])->toBe(['overdue' => 0, 'today' => 0]);
});

test('follow-ups due: today and overdue, still to book, most urgent first', function () {
    $make = fn (string $due, string $status = PatientFollowUp::DUE, int $priority = 0) => PatientFollowUp::query()->withoutGlobalScopes()->create([
        'clinic_id' => $this->f->clinic->id, 'branch_id' => $this->f->branch->id, 'patient_id' => $this->f->patient()->id,
        'due_on' => $due, 'status' => $status, 'priority' => $priority, 'reason' => 'Review',
    ]);
    $today = $make('2026-10-11');
    $urgentOverdue = $make('2026-10-08', priority: 2);
    $make('2026-10-12');
    $make('2026-10-10', PatientFollowUp::BOOKED);

    $followUps = app(FrontDesk::class)->followUps($this->f->clinic, $this->f->branch);

    expect($followUps['count'])->toBe(['overdue' => 1, 'today' => 1])
        ->and(array_column($followUps['rows'], 'id'))->toBe([$urgentOverdue->id, $today->id])
        ->and($followUps['rows'][0])->toMatchArray(['priority' => 'urgent', 'days_overdue' => 3]);
});

// ─── Direct navigation: every destination this person may open, no workspace menu ───

/** The top navigation's direct destinations for this login. */
function navigationKeys(callable $as): array
{
    $keys = [];
    $as()->get('/clinic/leave')->assertOk()->assertInertia(function (Assert $page) use (&$keys) {
        $keys = array_column($page->toArray()['props']['clinic']['navigation'], 'key');
    });

    return $keys;
}

test('a receptionist reaches the Dashboard, Calendar, Patients, Queue and Billing from the sidebar', function () {
    expect(navigationKeys($this->asReception))->toBe(['dashboard', 'appointments', 'patients', 'front_desk', 'billing', 'follow_ups', 'notifications', 'support']);
    ($this->asReception)()->get('/clinic/queue')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('ClinicPortal/FrontDesk/List', false)
        ->where('list', 'waiting')
        ->where('tabs.0.key', 'waiting'));
});

test('without billing rights Billing is not offered, and typing its address is refused', function () {
    setPermissionsTeamId(null);
    Role::findByName(ClinicRole::Receptionist->value, 'web')->revokePermissionTo([ClinicPermission::BillingView->value, ClinicPermission::PaymentCollect->value]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect(navigationKeys($this->asReception))->not->toContain('billing');
    ($this->asReception)()->get('/clinic/billing')->assertForbidden();
});

test('the Queue is a destination only with the module and the right: not when the module is off, never for a doctor alone', function () {
    $asDoctor = fn () => $this->actingAs($this->doctorUser)->withSession($this->receptionSession);
    expect(navigationKeys($asDoctor))->not->toContain('queue');
    $asDoctor()->get('/clinic/queue')->assertForbidden();

    app(ClinicModules::class)->override($this->f->clinic, 'queue', false, $this->f->owner);
    expect(navigationKeys($this->asReception))->not->toContain('queue');
    ($this->asReception)()->get('/clinic/queue')->assertForbidden();
});

test('a doctor who also bills sees Billing in the sidebar, and Consultations still opens', function () {
    [$user, $session] = $this->f->memberWithRole(ClinicRole::Doctor);
    setPermissionsTeamId(null);
    Role::firstOrCreate(['name' => ClinicRole::BillingStaff->value, 'guard_name' => 'web', 'branch_id' => null])
        ->syncPermissions(collect(ClinicRole::BillingStaff->permissions())->map(fn (string $name) => Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']))->all());
    app(ClinicRoleAssignmentService::class)->syncRoles($user, [ClinicRole::Doctor->value, ClinicRole::BillingStaff->value], [$this->f->branch]);
    $this->f->doctor('Dr. Billing', $user);
    $as = fn () => $this->actingAs($user)->withSession($session);

    expect(navigationKeys($as))->toContain('billing')->not->toContain('front_desk', 'queue');
    $as()->get('/clinic/consultations')->assertOk();
    $as()->get('/clinic/billing')->assertOk();
});

test('raising a waiting patient to an emergency and back restores the level the desk chose, not "normal"', function () {
    $item = ($this->checkIn)('Ravi');

    ($this->asReception)()->patch("/clinic/reception/queue/{$item->id}/priority", ['priority' => 'urgent'])
        ->assertSessionHas('success', "{$item->patientVisit->patient->short_name}'s priority is now urgent.");
    ($this->asReception)()->patch("/clinic/reception/queue/{$item->id}/priority", ['priority' => 'emergency'])
        ->assertSessionHas('success', "{$item->patientVisit->patient->short_name} marked as an emergency.");
    ($this->asReception)()->patch("/clinic/reception/queue/{$item->id}/priority", ['priority' => 'urgent']);

    expect($item->fresh()->priority)->toBe(VisitPriority::Urgent);
});
