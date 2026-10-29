<?php

use App\BranchStatus;
use App\ClinicMembershipStatus;
use App\ClinicRole;
use App\Models\Branch;
use App\Models\ClinicMembership;
use App\Models\Patient;
use App\Models\PatientVisit;
use App\Models\User;
use App\PatientStatus;
use App\Services\ClinicContext;
use App\Services\ClinicRoleAssignmentService;
use App\Services\Patient\PatientBranchActivityRecorder;
use App\UserType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\TreatmentFixture;

/**
 * The directory's role views: who starts where (Reception on Today, a
 * doctor on their assigned patients, the owner on everyone), what Today
 * means (an attended visit on the clinic's local date), the assigned doctor
 * and branch on each patient, and the contact rule — all decided on the
 * server, never by what the page shows.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-17 10:00', 'Asia/Kolkata'));
    $this->t = TreatmentFixture::make();
    $this->f = $this->t->f;
    $this->asOwner = fn () => $this->actingAs($this->f->owner)->withSession($this->f->session);
    $this->props = function ($as, string $query = ''): array {
        $props = [];
        $as->get('/clinic/patients'.$query)->assertOk()->assertInertia(function (Assert $page) use (&$props) {
            $page->component('ClinicPortal/Patients/Index', true);
            $props = $page->toArray()['props'];
        });

        return $props;
    };
    $this->names = fn (array $props): array => collect($props['patients']['data'])->pluck('name')->sort()->values()->all();
    // A visit on a clinic-local date (wall-clock digits, as check-in writes them).
    $this->visit = function (Patient $patient, string $date, string $status = 'checked_in', ?int $doctorId = null, ?int $branchId = null): PatientVisit {
        return PatientVisit::withoutGlobalScopes()->create([
            'clinic_id' => $this->f->clinic->id, 'branch_id' => $branchId ?? $this->f->branch->id, 'patient_id' => $patient->id,
            'doctor_id' => $doctorId, 'registration_type' => 'new', 'visit_date' => $date, 'visit_status' => $status, 'queue_status' => 'waiting',
        ]);
    };
    $this->as = fn (User $user, array $session) => $this->actingAs($user)->withSession($session);
    // A patient registered at a moment on the clinic's clock (`created_at` is the registration).
    $this->registeredAt = function (string $clinicTime, string $first, string $last, array $attributes = []): Patient {
        $now = now();
        $this->travelTo(CarbonImmutable::parse($clinicTime, 'Asia/Kolkata'));
        $patient = $this->f->patient($first, $last, $attributes);
        $this->travelTo($now);

        return $patient;
    };
});

test('reception opens on today\'s patients and can switch to everyone it may see', function () {
    [$reception, $session] = $this->f->memberWithRole(ClinicRole::Receptionist);
    ($this->visit)($this->f->patient('Asha', 'Today'), '2026-10-17');
    ($this->registeredAt)('2026-10-16 18:00', 'Bina', 'Earlier');

    $today = ($this->props)(($this->as)($reception, $session));
    expect($today['audience'])->toBe('reception')
        ->and($today['filters']['view'])->toBe('today')
        ->and($today['options']['views'])->toBe(['today', 'all'])
        ->and(($this->names)($today))->toBe(['Asha Today'])
        ->and($today['patients']['data'][0]['today_visit'])->toBe(['branch' => 'Main Branch', 'status' => 'checked_in']);

    $all = ($this->props)(($this->as)($reception, $session), '?view=all');
    expect($all['filters']['view'])->toBe('all')->and(($this->names)($all))->toBe(['Asha Today', 'Bina Earlier']);
});

test('today is everyone who attended on the clinic\'s own date, new or returning: not a bare registration, a cancelled visit or yesterday', function () {
    ($this->visit)($this->f->patient('Seen', 'Today'), '2026-10-17', 'completed');
    ($this->visit)($this->f->patient('With', 'Doctor'), '2026-10-17', 'in_progress');
    // Registered on an earlier day and back today: attended.
    ($this->visit)(($this->registeredAt)('2026-09-01 11:00', 'Came', 'Back'), '2026-10-17', 'checked_in');
    // Registered today but never checked in: not an attendee.
    $this->f->patient('Only', 'Registered');
    ($this->visit)($this->f->patient('Not', 'Checked'), '2026-10-17', 'registered');
    ($this->visit)($this->f->patient('Called', 'Off'), '2026-10-17', 'cancelled');
    ($this->visit)($this->f->patient('Came', 'Yesterday'), '2026-10-16');

    expect(($this->names)(($this->props)(($this->asOwner)(), '?view=today')))->toBe(['Came Back', 'Seen Today', 'With Doctor'])
        // All patients is the whole directory, attended or not.
        ->and(($this->props)(($this->asOwner)(), '?view=all')['patients']['data'])->toHaveCount(7);
});

test('just after local midnight, today is the clinic\'s new day, not the server\'s UTC date', function () {
    // 00:30 in the clinic is still the previous day in UTC.
    $this->travelTo(CarbonImmutable::parse('2026-10-18 00:30', 'Asia/Kolkata'));
    ($this->visit)($this->f->patient('Early', 'Bird'), '2026-10-18');
    ($this->visit)($this->f->patient('Late', 'Evening'), '2026-10-17');

    $props = ($this->props)(($this->asOwner)(), '?view=today');
    expect(($this->names)($props))->toBe(['Early Bird'])->and($props['today'])->toBe('2026-10-18');
});

test('the owner opens on everyone at the clinic, with today one tap away and no "my patients"', function () {
    $assigned = $this->f->patient('Asha', 'Assigned', ['current_practitioner_id' => $this->f->doctorA->id]);
    $this->f->patient('Bina', 'Unassigned');
    ($this->visit)($assigned, '2026-10-17');

    $props = ($this->props)(($this->asOwner)());
    expect($props['audience'])->toBe('owner')
        ->and($props['filters']['view'])->toBe('all')
        ->and($props['options']['views'])->toBe(['all', 'today'])
        ->and(($this->names)($props))->toBe(['Asha Assigned', 'Bina Unassigned'])
        // Asking for a doctor's own list falls back to the owner's default.
        ->and(($this->props)(($this->asOwner)(), '?view=mine')['filters']['view'])->toBe('all');
});

test('a doctor opens on the patients assigned to them; today is their own visits; all is everyone they may open', function () {
    [$user, $session] = $this->f->memberWithRole(ClinicRole::Doctor);
    $me = $this->f->doctor('Dr. Own Patients', $user);
    $mine = $this->f->patient('Mine', 'Assigned', ['current_practitioner_id' => $me->id]);
    $theirs = $this->f->patient('Their', 'Patient', ['current_practitioner_id' => $this->f->doctorA->id]);
    ($this->visit)($mine, '2026-10-17', 'checked_in', $this->f->doctorA->id);
    ($this->visit)($theirs, '2026-10-17', 'checked_in', $me->id);

    $default = ($this->props)(($this->as)($user, $session));
    expect($default['audience'])->toBe('doctor')
        ->and($default['filters']['view'])->toBe('mine')
        ->and($default['options']['views'])->toBe(['mine', 'today', 'all'])
        ->and(($this->names)($default))->toBe(['Mine Assigned'])
        // In their own list another doctor cannot be asked for.
        ->and(($this->names)(($this->props)(($this->as)($user, $session), "?view=mine&practitioner_id={$this->f->doctorA->id}")))->toBe(['Mine Assigned'])
        // Today: the visits to this doctor, whoever the patient is assigned to.
        ->and(($this->names)(($this->props)(($this->as)($user, $session), '?view=today')))->toBe(['Their Patient'])
        ->and(($this->names)(($this->props)(($this->as)($user, $session), '?view=all')))->toBe(['Mine Assigned', 'Their Patient']);
});

test('a doctor limited to one branch sees nobody from another branch in any view', function () {
    $north = Branch::factory()->create(['clinic_id' => $this->f->clinic->id, 'name' => 'North', 'is_main' => false, 'status' => BranchStatus::Active]);
    $recorder = app(PatientBranchActivityRecorder::class);
    $user = User::factory()->create(['user_type' => UserType::ClinicUser]);
    $membership = ClinicMembership::factory()->create(['clinic_id' => $this->f->clinic->id, 'user_id' => $user->id, 'has_all_branches_access' => false, 'status' => ClinicMembershipStatus::Active]);
    DB::table('branch_clinic_membership')->insert(['clinic_id' => $this->f->clinic->id, 'clinic_membership_id' => $membership->id, 'branch_id' => $north->id]);
    $this->f->memberWithRole(ClinicRole::Doctor);
    app(ClinicRoleAssignmentService::class)->syncRoles($user, [ClinicRole::Doctor->value], [$north]);
    $me = $this->f->doctor('Dr. North', $user);
    $session = [ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID => $membership->id, ClinicContext::SESSION_BRANCH_ID => $north->id];

    $northern = $this->f->patient('North', 'Patient');
    $recorder->record($this->f->clinic->id, $northern->id, $north->id, now(), 'registration');
    // Assigned to this doctor and seen by them today, but only at the main branch.
    $elsewhere = $this->f->patient('Main', 'Patient', ['current_practitioner_id' => $me->id]);
    $recorder->record($this->f->clinic->id, $elsewhere->id, $this->f->branch->id, now(), 'registration');
    ($this->visit)($elsewhere, '2026-10-17', 'checked_in', $me->id);

    foreach (['mine', 'today'] as $view) {
        expect(($this->props)(($this->as)($user, $session), "?view={$view}")['patients']['data'])->toBe([]);
    }
    expect(($this->names)(($this->props)(($this->as)($user, $session), '?view=all')))->toBe(['North Patient'])
        ->and(($this->props)(($this->as)($user, $session), "?view=all&branch_id={$this->f->branch->id}")['patients']['data'])->toBe([]);
});

test('each patient carries the assigned doctor, or none, and the doctor filter uses the same assignment', function () {
    $this->f->patient('Asha', 'Assigned', ['current_practitioner_id' => $this->f->doctorA->id]);
    $this->f->patient('Bina', 'Other', ['current_practitioner_id' => $this->f->doctorB->id]);
    $this->f->patient('Chetan', 'Unassigned');

    $rows = collect(($this->props)(($this->asOwner)())['patients']['data'])->keyBy('name');
    expect($rows['Asha Assigned']['assigned_doctor'])->toBe(['id' => $this->f->doctorA->id, 'name' => 'Dr. Asha Mehta', 'at_clinic' => true])
        ->and($rows['Chetan Unassigned']['assigned_doctor'])->toBeNull()
        ->and(($this->names)(($this->props)(($this->asOwner)(), "?practitioner_id={$this->f->doctorA->id}")))->toBe(['Asha Assigned'])
        ->and(($this->names)(($this->props)(($this->asOwner)(), '?practitioner_id=none')))->toBe(['Chetan Unassigned']);

    $this->f->doctorB->update(['status' => 'inactive']);
    expect(collect(($this->props)(($this->asOwner)())['patients']['data'])->firstWhere('name', 'Bina Other')['assigned_doctor']['at_clinic'])->toBeFalse();
});

test('the branch shown is where the patient was last seen among the member\'s branches, with how many others', function () {
    $north = Branch::factory()->create(['clinic_id' => $this->f->clinic->id, 'name' => 'North', 'is_main' => false, 'status' => BranchStatus::Active]);
    $recorder = app(PatientBranchActivityRecorder::class);
    $both = $this->f->patient('Both', 'Branches');
    $recorder->record($this->f->clinic->id, $both->id, $this->f->branch->id, now()->subDays(3), 'visit');
    $recorder->record($this->f->clinic->id, $both->id, $north->id, now()->subDay(), 'visit');
    $this->f->patient('Never', 'Seen');
    ($this->visit)($both, '2026-10-17', 'checked_in', null, $north->id);

    $rows = collect(($this->props)(($this->asOwner)())['patients']['data'])->keyBy('name');
    expect($rows['Both Branches']['last_branch'])->toBe('North')
        ->and($rows['Both Branches']['other_branch_count'])->toBe(1)
        ->and($rows['Never Seen']['last_branch'])->toBeNull()
        ->and($rows['Never Seen']['last_activity_at'])->toBeNull();

    // Today, the branch is today's visit's; the branch filter narrows Today by that visit.
    expect(($this->props)(($this->asOwner)(), '?view=today')['patients']['data'][0]['today_visit']['branch'])->toBe('North')
        ->and(($this->props)(($this->asOwner)(), "?view=today&branch_id={$this->f->branch->id}")['patients']['data'])->toBe([]);
});

test('the full mobile number and email reach only members whose work is contacting patients', function () {
    $this->f->patient('Asha', 'Menon', ['phone' => '9811122334', 'email' => 'asha@example.com']);
    [$billing, $billingSession] = $this->f->memberWithRole(ClinicRole::BillingStaff);
    [$reception, $receptionSession] = $this->f->memberWithRole(ClinicRole::Receptionist);

    $billed = ($this->props)(($this->as)($billing, $billingSession), '?view=all')['patients']['data'][0];
    expect($billed['phone'])->toBeNull()->and($billed['email'])->toBeNull()->and($billed['phone_last_digits'])->toBe('2334');
    $this->actingAs($billing)->withSession($billingSession)->getJson('/clinic/patients/directory')->assertOk()
        ->assertJsonPath('data.0.phone', null)->assertJsonPath('data.0.email', null)->assertJsonPath('data.0.phone_last_digits', '2334');

    $desk = ($this->props)(($this->as)($reception, $receptionSession), '?view=all')['patients']['data'][0];
    expect($desk['phone'])->toBe('9811122334')->and($desk['email'])->toBe('asha@example.com');
});

test('search, filters and pages work inside Today and keep to it', function () {
    foreach (range(1, 27) as $i) {
        $this->travel(1)->minutes();
        ($this->visit)($this->f->patient('Visitor', sprintf('No%02d', $i)), '2026-10-17');
    }
    ($this->registeredAt)('2026-10-16 12:00', 'Visitor', 'Absent');

    $first = ($this->props)(($this->asOwner)(), '?view=today');
    expect($first['patients']['data'])->toHaveCount(25)->and($first['patients']['data'][0]['name'])->toBe('Visitor No27');
    $second = ($this->props)(($this->asOwner)(), '?view=today&cursor='.urlencode($first['patients']['next_cursor']));
    expect(collect($second['patients']['data'])->pluck('name')->all())->toBe(['Visitor No02', 'Visitor No01'])
        ->and(($this->names)(($this->props)(($this->asOwner)(), '?view=today&q=Absent')))->toBe([])
        ->and(($this->names)(($this->props)(($this->asOwner)(), '?view=all&q=Absent')))->toBe(['Visitor Absent']);
});

test('there is no active / inactive / archived workflow: every patient record is listed whatever an old row says, and only merged duplicates stay out', function () {
    $this->f->patient('Asha', 'Current');
    $this->f->patient('Legacy', 'Inactive', ['status' => PatientStatus::Inactive]);
    $this->f->patient('Late', 'Patient', ['status' => PatientStatus::Deceased]);
    $this->f->patient('Old', 'Duplicate', ['status' => PatientStatus::Merged]);

    $props = ($this->props)(($this->asOwner)());
    expect($props['options']['statuses'])->toBe([])
        ->and($props['filters']['status'])->toBe('')
        ->and(($this->names)($props))->toBe(['Asha Current', 'Late Patient', 'Legacy Inactive'])
        // The recorded death still marks its row; it is a record, not a workflow state.
        ->and(collect($props['patients']['data'])->firstWhere('name', 'Late Patient')['status'])->toBe('deceased');

    // A status in an old link — any value — is ignored: the same patients, no error.
    foreach (['active', 'inactive', 'archived', 'merged', 'dormant'] as $status) {
        expect(($this->names)(($this->props)(($this->asOwner)(), "?status={$status}")))->toBe(['Asha Current', 'Late Patient', 'Legacy Inactive']);
    }
    // An old "inactive" record is an ordinary patient: searchable, and opens in Patient 360.
    $legacy = Patient::query()->withoutGlobalScopes()->where('first_name', 'Legacy')->firstOrFail();
    expect(($this->names)(($this->props)(($this->asOwner)(), '?q=Legacy')))->toBe(['Legacy Inactive']);
    ($this->asOwner)()->get("/clinic/patients/{$legacy->id}")->assertOk();
});

test('a page with doctors, branches and today\'s visits costs the same queries at any size', function () {
    foreach (range(1, 12) as $i) {
        $this->travel(1)->minutes();
        $patient = $this->f->patient("Patient{$i}", 'Test', ['current_practitioner_id' => $i % 2 ? $this->f->doctorA->id : $this->f->doctorB->id]);
        app(PatientBranchActivityRecorder::class)->record($this->f->clinic->id, $patient->id, $this->f->branch->id, now(), 'visit');
        ($this->visit)($patient, '2026-10-17');
    }
    $count = function (int $perPage) {
        DB::flushQueryLog();
        DB::enableQueryLog();
        ($this->asOwner)()->getJson("/clinic/patients/directory?view=today&per_page={$perPage}")->assertOk()->assertJsonCount($perPage, 'data');
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    $count(1);

    expect($count(12))->toBe($count(2));
});
