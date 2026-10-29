<?php

use App\AppointmentStatus;
use App\BranchStatus;
use App\ClinicMembershipStatus;
use App\ClinicRole;
use App\Models\Branch;
use App\Models\ClinicalProcedure;
use App\Models\ClinicMembership;
use App\Models\PatientMerge;
use App\Models\PatientVisit;
use App\Models\User;
use App\Services\BillingService;
use App\Services\Clinical\EncounterWorkspace;
use App\Services\ClinicContext;
use App\Services\ClinicRoleAssignmentService;
use App\Services\Patient\FollowUps;
use App\Services\Patient\PatientBranchActivityRecorder;
use App\Support\ClinicFrontend;
use App\UserType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\ReceptionFixture;

/**
 * Patient 360's first slice: the page is built and linked from the
 * directory, each role opens it on the Overview with only built sections
 * and actions offered, the header follows the contact rule, and the
 * Overview carries only what the member may see — from real records,
 * never links to screens that do not exist yet.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-26 10:00', 'Asia/Kolkata'));
    $this->f = ReceptionFixture::make();
    $this->patient = $this->f->patient('Meera', 'Iyer', ['uhid' => 'UHID-2026-0300', 'age' => 41, 'phone' => '9811122334', 'email' => 'meera@example.com', 'current_practitioner_id' => $this->f->doctorA->id]);
    app(PatientBranchActivityRecorder::class)->record($this->f->clinic->id, $this->patient->id, $this->f->branch->id, now(), 'registration');
    $this->asOwner = fn () => $this->actingAs($this->f->owner)->withSession($this->f->session);
    $this->asRole = function (ClinicRole $role) {
        [$user, $session] = $this->f->memberWithRole($role);

        return fn () => $this->actingAs($user)->withSession($session);
    };
    $this->page = function ($as): array {
        $props = [];
        $as()->get("/clinic/patients/{$this->patient->id}")->assertOk()->assertInertia(function (Assert $page) use (&$props) {
            $page->component('ClinicPortal/Patients/Show');
            $props = $page->toArray()['props'];
        });

        return $props;
    };
    $this->overview = function ($as): array {
        $overview = [];
        $as()->get("/clinic/patients/{$this->patient->id}")->assertInertia(function (Assert $page) use (&$overview) {
            $page->loadDeferredProps(function (Assert $reload) use (&$overview) {
                $overview = $reload->toArray()['props']['overview'];
            });
        });

        return $overview;
    };
});

test('the page is built and the directory links each patient to it', function () {
    expect(ClinicFrontend::available('clinic.patients.show'))->toBeTrue()
        ->and(ClinicFrontend::PATIENT_360_SECTIONS)->toBe(['overview', 'visits', 'clinical', 'dental', 'physio', 'treatment', 'appointments', 'prescriptions', 'investigations', 'billing', 'documents', 'consents', 'communication', 'follow-ups', 'timeline', 'requests', 'audit']);

    ($this->asOwner)()->get('/clinic/patients')->assertInertia(fn (Assert $page) => $page
        ->where('links.profile', route('clinic.patients.show', ['patient' => '__ID__'])));
});

test('every role that may view patients opens on the Overview, with only built sections and actions offered', function (ClinicRole $role, array $built) {
    $props = ($this->page)(($this->asRole)($role));

    expect($props['open_section'])->toBe('overview')
        ->and(collect($props['sections'])->where('built', true)->pluck('key')->all())->toBe($built)
        // Only actions with a working screen: check-in, Edit, Print, Assign doctor, Messages and Add follow-up (their sections).
        ->and(collect($props['actions'])->where('available', true)->pluck('key')->diff(['check_in', 'edit', 'print', 'transfer', 'communication', 'follow_up'])->values()->all())->toBe([])
        ->and($props['links']['index'])->toBe(route('clinic.patients.index'))
        ->and($props['patient']['practitioner'])->toBe('Dr. Asha Mehta');
})->with([
    // In each persona's own order (`Patient360SectionPolicy::ORDER`), built sections only.
    'doctor' => [ClinicRole::Doctor, ['overview', 'visits', 'clinical', 'prescriptions', 'investigations', 'treatment', 'follow-ups', 'appointments', 'documents', 'consents', 'billing', 'timeline', 'communication', 'requests']],
    'reception' => [ClinicRole::Receptionist, ['overview', 'visits', 'appointments', 'follow-ups', 'billing', 'investigations', 'documents', 'consents', 'communication', 'timeline', 'requests']],
    // Billing works with the money, not the visit history.
    'billing' => [ClinicRole::BillingStaff, ['billing', 'overview', 'communication', 'timeline', 'requests']],
    'admin' => [ClinicRole::ClinicAdmin, ['overview', 'visits', 'clinical', 'appointments', 'treatment', 'prescriptions', 'investigations', 'billing', 'documents', 'consents', 'follow-ups', 'timeline', 'communication', 'requests', 'audit']],
]);

test('the header sends the full mobile and email only to members whose work is contacting patients', function () {
    $billing = ($this->page)(($this->asRole)(ClinicRole::BillingStaff))['patient'];
    expect($billing['phone'])->toBeNull()->and($billing['email'])->toBeNull()->and($billing['phone_last_digits'])->toBe('2334');

    foreach ([ClinicRole::Receptionist, ClinicRole::Doctor] as $role) {
        $patient = ($this->page)(($this->asRole)($role))['patient'];
        expect($patient['phone'])->toBe('9811122334')->and($patient['email'])->toBe('meera@example.com');
    }
});

test('the overview is built from the patient\'s real records and only what each role may see', function () {
    $this->f->appointment($this->patient, $this->f->doctorB, CarbonImmutable::parse('2026-10-28 11:00'), AppointmentStatus::Scheduled);
    app(FollowUps::class)->add($this->patient, '2026-10-30', 'Review', null, $this->f->branch->id);
    $procedure = ClinicalProcedure::create(['clinic_id' => $this->f->clinic->id, 'code' => 'CON', 'name' => 'Consultation', 'category' => 'General', 'is_active' => true]);
    app(BillingService::class)->createBill($this->f->clinic, $this->patient, $this->f->branch, [['clinical_procedure_id' => $procedure->id, 'unit_price' => 750]]);
    $this->actingAs($this->f->owner);
    app(EncounterWorkspace::class)->startForPatient($this->patient, $this->f->branch, $this->f->doctorA);

    $owner = ($this->overview)($this->asOwner);
    expect($owner['next']['appointment']['practitioner'])->toBe('Dr. Bala Iyer')
        ->and($owner['next']['follow_up'])->toMatchArray(['due_on' => '2026-10-30', 'reason' => 'Review', 'overdue' => false])
        ->and($owner['next']['payments'])->toHaveCount(1)
        ->and($owner['current']['open_consultation'])->not->toBeNull()
        // The consultation screen is built: the latest consultation links to it.
        ->and($owner['recent']['consultation']['url'])->toBe(route('clinic.consultations.show', $owner['recent']['consultation']['id']));
    expect(($this->page)($this->asOwner)['state']['outstanding'])->toEqual(750.0);

    // Reception: what is coming up, never the clinical record; billing: the money, never the appointments.
    $reception = ($this->overview)(($this->asRole)(ClinicRole::Receptionist));
    expect($reception['next']['appointment'])->not->toBeNull()
        ->and($reception['recent']['consultation'])->toBeNull()
        ->and($reception['current']['open_consultation'])->toBeNull();
    $billing = ($this->asRole)(ClinicRole::BillingStaff);
    expect(($this->overview)($billing)['next']['appointment'])->toBeNull()
        ->and(($this->page)($billing)['context']['clinical'])->toBeFalse()
        ->and(($this->page)($billing)['state']['outstanding'])->toEqual(750.0);
});

test('a member without the right to view patients, another clinic, and another branch are all refused', function () {
    $noAccess = User::factory()->create(['user_type' => UserType::ClinicUser]);
    $bare = ClinicMembership::factory()->create(['clinic_id' => $this->f->clinic->id, 'user_id' => $noAccess->id, 'has_all_branches_access' => true, 'status' => ClinicMembershipStatus::Active]);
    $this->actingAs($noAccess)->withSession([ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID => $bare->id, ClinicContext::SESSION_BRANCH_ID => $this->f->branch->id])
        ->get("/clinic/patients/{$this->patient->id}")->assertForbidden();

    $other = ReceptionFixture::make('other-clinic');
    $this->actingAs($other->owner)->withSession($other->session)->get("/clinic/patients/{$this->patient->id}")->assertForbidden();

    // A member limited to another branch: this patient has no activity there.
    $north = Branch::factory()->create(['clinic_id' => $this->f->clinic->id, 'name' => 'North', 'is_main' => false, 'status' => BranchStatus::Active]);
    $user = User::factory()->create(['user_type' => UserType::ClinicUser]);
    $membership = ClinicMembership::factory()->create(['clinic_id' => $this->f->clinic->id, 'user_id' => $user->id, 'has_all_branches_access' => false, 'status' => ClinicMembershipStatus::Active]);
    DB::table('branch_clinic_membership')->insert(['clinic_id' => $this->f->clinic->id, 'clinic_membership_id' => $membership->id, 'branch_id' => $north->id]);
    $this->f->memberWithRole(ClinicRole::Receptionist);
    app(ClinicRoleAssignmentService::class)->syncRoles($user, [ClinicRole::Receptionist->value], [$north]);
    $this->actingAs($user)->withSession([ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID => $membership->id, ClinicContext::SESSION_BRANCH_ID => $north->id])
        ->get("/clinic/patients/{$this->patient->id}")->assertForbidden();
});

test('the header names the last visit\'s date and branch, from the visit itself', function () {
    expect(($this->page)($this->asOwner)['patient'])->toMatchArray(['last_visit_on' => null, 'branch' => null]);

    PatientVisit::withoutGlobalScopes()->create([
        'clinic_id' => $this->f->clinic->id, 'branch_id' => $this->f->branch->id, 'patient_id' => $this->patient->id,
        'registration_type' => 'repeat', 'visit_date' => '2026-10-24', 'visit_status' => 'completed', 'queue_status' => 'completed',
    ]);

    expect(($this->page)($this->asOwner)['patient'])->toMatchArray(['last_visit_on' => '2026-10-24', 'branch' => 'Main Branch']);
});

test('a merged record opens the patient it was merged into', function () {
    $duplicate = $this->f->patient('Meera', 'Duplicate', ['status' => 'merged']);
    PatientMerge::create(['clinic_id' => $this->f->clinic->id, 'source_patient_id' => $duplicate->id, 'survivor_patient_id' => $this->patient->id, 'moved' => [], 'conflicts' => [], 'merged_by' => $this->f->owner->id, 'merged_at' => now()]);

    ($this->asOwner)()->get("/clinic/patients/{$duplicate->id}")->assertRedirect(route('clinic.patients.show', $this->patient->id));
});
