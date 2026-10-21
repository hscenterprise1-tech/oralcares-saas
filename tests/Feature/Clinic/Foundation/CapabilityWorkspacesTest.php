<?php

use App\ClinicMembershipStatus;
use App\ClinicPermission;
use App\ClinicRole;
use App\Models\ClinicMembership;
use App\Models\Patient;
use App\Models\PatientBilling;
use App\Models\User;
use App\Services\ClinicContext;
use App\Services\ClinicRoleAssignmentService;
use App\Services\Patient\PatientSearch;
use App\Services\PaymentService;
use App\Services\Reception\PatientFlowConfigService;
use App\Support\ClinicAccess\ClinicModules;
use App\UserType;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\TreatmentFixture;

/**
 * Workspaces are composed from what a person may do, never from the name of
 * one role: a doctor who also covers the front office and billing works in
 * all three, each opened (and closed) by the server.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-10 10:00', 'Asia/Kolkata'));
    $this->t = TreatmentFixture::make();
    $this->f = $this->t->f;

    /**
     * A login holding these clinic roles at the branch, as a practitioner when asked.
     *
     * @param  list<ClinicRole>  $roles
     */
    $this->member = function (array $roles, bool $practitioner = false, array $extraPermissions = []): array {
        setPermissionsTeamId(null);
        foreach ($roles as $role) {
            Role::firstOrCreate(['name' => $role->value, 'guard_name' => 'web', 'branch_id' => null])
                ->syncPermissions(collect($role->permissions())->map(fn (string $permission) => Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']))->all());
        }
        $user = User::factory()->create(['user_type' => UserType::ClinicUser]);
        $membership = ClinicMembership::factory()->create([
            'clinic_id' => $this->f->clinic->id, 'user_id' => $user->id, 'is_primary_owner' => false,
            'has_all_branches_access' => true, 'status' => ClinicMembershipStatus::Active,
        ]);
        app(ClinicRoleAssignmentService::class)->syncRoles($user, array_map(fn (ClinicRole $role) => $role->value, $roles), [$this->f->branch]);
        if ($extraPermissions !== []) {
            setPermissionsTeamId($this->f->branch->id);
            $user->givePermissionTo(collect($extraPermissions)->map(fn (ClinicPermission $permission) => Permission::firstOrCreate(['name' => $permission->value, 'guard_name' => 'web']))->all());
            setPermissionsTeamId(null);
        }
        if ($practitioner) {
            $this->f->doctor('Dr. '.fake()->unique()->lastName(), $user);
        }

        return ['user' => $user, 'session' => [ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID => $membership->id, ClinicContext::SESSION_BRANCH_ID => $this->f->branch->id]];
    };
    $this->as = fn (array $member) => $this->actingAs($member['user'])->withSession($member['session']);
    $this->workspaces = function (array $member): array {
        $keys = [];
        ($this->as)($member)->get('/clinic/leave')->assertOk()->assertInertia(function (Assert $page) use (&$keys) {
            $keys = array_column($page->toArray()['props']['clinic']['workspaces'], 'key');
        });

        return $keys;
    };
});

test('a doctor works in Today only, and the front office and billing stay closed', function () {
    $doctor = ($this->member)([ClinicRole::Doctor], practitioner: true);

    expect(($this->workspaces)($doctor))->toBe(['today']);
    ($this->as)($doctor)->get('/clinic/reception')->assertForbidden();
    ($this->as)($doctor)->get('/clinic/billing')->assertForbidden();
    ($this->as)($doctor)->getJson('/clinic/reception/intake')->assertForbidden();
});

test('a doctor who also covers reception and billing works in all three, with no special role', function () {
    $member = ($this->member)([ClinicRole::Doctor, ClinicRole::Receptionist, ClinicRole::BillingStaff], practitioner: true);

    expect(($this->workspaces)($member))->toBe(['front_desk', 'today', 'billing']);
    ($this->as)($member)->get('/clinic/reception')->assertOk();
    ($this->as)($member)->get('/clinic/consultations')->assertOk();
    ($this->as)($member)->get('/clinic/billing')->assertOk();
    ($this->as)($member)->postJson('/clinic/reception/check-in', ['patient_id' => $this->f->patient()->id, 'practitioner_id' => $this->f->doctorA->id])->assertCreated();
});

test('billing is not taken away because a person has another workspace', function () {
    $member = ($this->member)([ClinicRole::Doctor, ClinicRole::BillingStaff], practitioner: true);

    expect(($this->workspaces)($member))->toBe(['today', 'billing']);
    ($this->as)($member)->get('/clinic/billing')->assertOk();
    ($this->as)($member)->get('/clinic/reception')->assertForbidden();
});

test('the front office is its own grant: a doctor given it works the desk without becoming a receptionist', function () {
    $member = ($this->member)([ClinicRole::Doctor], practitioner: true, extraPermissions: [ClinicPermission::FrontOffice]);

    expect(($this->workspaces)($member))->toBe(['front_desk', 'today']);
    ($this->as)($member)->get('/clinic/reception')->assertOk()
        // Registering a new patient is still its own permission; the desk says so rather than offering it.
        ->assertInertia(fn (Assert $page) => $page->where('can.register', false));
});

test('a receptionist works the front desk and billing; queue rights alone never open the desk', function () {
    expect(($this->workspaces)(($this->member)([ClinicRole::Receptionist])))->toBe(['front_desk', 'billing'])
        ->and(ClinicRole::Doctor->permissions())->toContain(ClinicPermission::QueueManage->value)
        ->and(ClinicRole::Doctor->permissions())->not->toContain(ClinicPermission::FrontOffice->value);
});

test('checking a booking in from the calendar is offered only to the front office, through the intake', function () {
    $receptionist = ($this->member)([ClinicRole::Receptionist]);
    $doctor = ($this->member)([ClinicRole::Doctor], practitioner: true);
    $can = function (array $member) {
        $value = null;
        ($this->as)($member)->get('/clinic/appointments')->assertOk()->assertInertia(function (Assert $page) use (&$value) {
            $value = $page->toArray()['props'];
        });

        return $value;
    };

    expect($can($receptionist)['can']['check_in'])->toBeTrue()
        ->and($can($doctor)['can']['check_in'])->toBeFalse()
        ->and($can($receptionist)['links'])->not->toHaveKey('checkIn');
});

test('queue order is the Queue module: without it nobody calls the next patient, and check-in still works', function () {
    $doctor = ($this->member)([ClinicRole::Doctor, ClinicRole::Receptionist], practitioner: true);
    app(ClinicModules::class)->override($this->f->clinic, 'queue', false, $this->f->owner);

    ($this->as)($doctor)->post('/clinic/queue/call-next')->assertForbidden();
    ($this->as)($doctor)->get('/clinic/consultations')->assertOk()->assertInertia(fn (Assert $page) => $page->where('links.call_next', null));
    ($this->as)($doctor)->postJson('/clinic/reception/check-in', ['patient_id' => $this->f->patient()->id, 'practitioner_id' => $this->f->doctorA->id])->assertCreated();
    ($this->as)($doctor)->get('/clinic/leave')->assertInertia(fn (Assert $page) => $page->where('clinic.clinic_modules.queue', false));

    app(ClinicModules::class)->override($this->f->clinic, 'queue', null, $this->f->owner);
    ($this->as)($doctor)->get('/clinic/consultations')->assertInertia(fn (Assert $page) => $page->whereNot('links.call_next', null));
});

test('every payment form offers only the methods the clinic accepts', function () {
    $this->f->clinic->update(['billing_config' => ['payment_methods' => ['cash', 'upi', 'insurance']]]);
    $clinic = $this->f->clinic->fresh();
    $values = fn (array $options) => array_column($options, 'value');

    expect($values(app(PaymentService::class)->methodOptions($clinic)))->toBe(['cash', 'upi', 'insurance'])
        ->and($values(app(PaymentService::class)->methodOptions($clinic, atDesk: true)))->toBe(['cash', 'upi']);

    app(PatientFlowConfigService::class)->updateCheckIn($clinic, ['payment' => 'at_check_in'], $this->f->owner);
    $this->f->doctorA->practitionerProfile()->withoutGlobalScopes()->update(['consultation_fee' => 400]);
    $patient = $this->f->patient();
    $asOwner = fn () => $this->actingAs($this->f->owner)->withSession($this->f->session);
    $asOwner()->postJson('/clinic/reception/check-in', ['patient_id' => $patient->id, 'practitioner_id' => $this->f->doctorA->id])->assertCreated();
    $bill = PatientBilling::query()->withoutGlobalScopes()->where('patient_id', $patient->id)->sole();

    expect($values($asOwner()->getJson("/clinic/patients/{$patient->id}/360/bills/{$bill->id}")->assertOk()->json('payment_methods')))->toBe(['cash', 'upi', 'insurance'])
        ->and($values($asOwner()->getJson('/clinic/reception/intake')->json('payment.methods')))->toBe(['cash', 'upi']);
});

test('one patient search: a typed number with spaces finds the patient, short queries find nothing, other clinics never', function () {
    $patient = $this->f->patient('Meera', 'Iyer', ['phone' => '9811100124']);
    Patient::create(['clinic_id' => TreatmentFixture::make('elsewhere')->f->clinic->id, 'first_name' => 'Meera', 'last_name' => 'Elsewhere', 'phone' => '9811100124', 'gender' => 'female']);
    $search = app(PatientSearch::class);

    expect($search->find($this->f->clinic, '98111 00124')->pluck('id')->all())->toBe([$patient->id])
        ->and($search->find($this->f->clinic, 'M'))->toBeEmpty()
        ->and($search->find($this->f->clinic, 'Meera')->pluck('id')->all())->toBe([$patient->id]);

    $this->actingAs($this->f->owner)->withSession($this->f->session)->getJson('/clinic/search/patients?q=98111-00124')->assertOk()->assertJsonPath('0.id', $patient->id)->assertJsonCount(1);
});
