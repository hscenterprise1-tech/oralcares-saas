<?php

use App\AppointmentStatus;
use App\BranchStatus;
use App\ClinicMembershipStatus;
use App\ClinicRole;
use App\Contracts\MalwareScannerInterface;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\ClinicalProcedure;
use App\Models\ClinicDepartment;
use App\Models\ClinicEmployee;
use App\Models\ClinicEmployeeInvitation;
use App\Models\ClinicMembership;
use App\Models\CompanySubscription;
use App\Models\Leave;
use App\Models\Practitioner;
use App\Models\PractitionerQualification;
use App\Models\PractitionerSchedule;
use App\Models\StaffDocument;
use App\Models\StaffDocumentRequirement;
use App\Models\StaffDocumentType;
use App\Models\User;
use App\Services\ClinicContext;
use App\Services\ClinicRoleAssignmentService;
use App\Services\Security\Malware\FakeMalwareScanner;
use App\UserType;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\ReceptionFixture;

/**
 * Staff and practitioner management end to end: the staff list and record,
 * the practitioner profile (qualifications, specialty, registration),
 * services, branches, schedules, the leave workflow, staff documents, and
 * how the appointment engine follows all of it. Plus the clinic, branch and
 * permission boundaries around each.
 *
 * The clock is frozen on Monday 28 Sep 2026, 10:00 clinic time.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-28 10:00', 'Asia/Kolkata'));
    Storage::fake('private');
    Storage::fake('quarantine');
    app()->instance(MalwareScannerInterface::class, new FakeMalwareScanner);

    $this->f = ReceptionFixture::make();
    [$this->admin, $this->adminSession] = $this->f->memberWithRole(ClinicRole::ClinicAdmin);
});

function asAdmin()
{
    return test()->actingAs(test()->admin)->withSession(test()->adminSession);
}

function staffUrl(ClinicEmployee $employee, string $suffix = ''): string
{
    return route('admin.employees.show', $employee).$suffix;
}

/**
 * Free slot starts for one provider on one day.
 *
 * @return list<string>
 */
function slotsFor(ClinicEmployee $provider, string $date, ?int $serviceId = null): array
{
    return collect(asAdmin()->getJson('/clinic/appointments/availability?'.http_build_query(array_filter([
        'provider_id' => $provider->id, 'service_id' => $serviceId, 'from' => $date, 'days' => 1, 'limit' => 120,
    ])))->assertOk()->json('slots'))->pluck('start')->all();
}

/**
 * A member who manages staff at one branch only.
 *
 * @return array{0: User, 1: array<string, int>}
 */
function branchLimitedAdmin(Branch $branch): array
{
    $f = test()->f;
    $user = User::factory()->create(['user_type' => UserType::ClinicUser]);
    $membership = ClinicMembership::factory()->create([
        'clinic_id' => $f->clinic->id, 'user_id' => $user->id, 'status' => ClinicMembershipStatus::Active, 'has_all_branches_access' => false,
    ]);
    $membership->assignedBranches()->sync([$branch->id]);
    app(ClinicRoleAssignmentService::class)->syncRoles($user, [ClinicRole::ClinicAdmin->value], [$branch]);

    return [$user, [ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID => $membership->id, ClinicContext::SESSION_BRANCH_ID => $branch->id]];
}

// ─── Staff list and record ──────────────────────────────────

test('adding a doctor creates their practitioner profile at the chosen branches', function () {
    asAdmin()->post(route('admin.employees.store'), [
        'name' => 'Dr. Priya Shah', 'phone' => '9800000001', 'email' => 'priya@clinic.test', 'status' => 'active',
        'staff_type' => 'dentist', 'joining_date' => '2026-09-01', 'branches' => [$this->f->branch->id], 'primary_branch_id' => $this->f->branch->id,
    ])->assertSessionHasNoErrors();

    $priya = ClinicEmployee::where('name', 'Dr. Priya Shah')->sole();
    expect($priya->staff_type)->toBe('dentist')
        ->and($priya->primary_branch_id)->toBe($this->f->branch->id)
        ->and(Practitioner::withoutGlobalScopes()->where('clinic_employee_id', $priya->id)->value('profession'))->toBe('Dentist');
});

test('adding a receptionist does not create a practitioner profile', function () {
    asAdmin()->post(route('admin.employees.store'), ['name' => 'Priya Patel', 'status' => 'active', 'staff_type' => 'receptionist'])->assertSessionHasNoErrors();

    $priya = ClinicEmployee::where('name', 'Priya Patel')->sole();
    expect(Practitioner::withoutGlobalScopes()->where('clinic_employee_id', $priya->id)->exists())->toBeFalse();
});

test('an email already used by a colleague in this clinic is refused', function () {
    ClinicEmployee::create(['clinic_id' => $this->f->clinic->id, 'name' => 'First Hire', 'email' => 'shared@clinic.test', 'status' => 'active']);

    asAdmin()->post(route('admin.employees.store'), ['name' => 'Second Hire', 'email' => 'shared@clinic.test', 'status' => 'active', 'staff_type' => 'receptionist'])
        ->assertSessionHasErrors('email');
});

test('the staff list shows status tabs and no contact details', function () {
    ClinicEmployee::create(['clinic_id' => $this->f->clinic->id, 'name' => 'Old Hand', 'email' => 'old@clinic.test', 'phone' => '9811111111', 'status' => 'resigned']);
    ClinicEmployee::create(['clinic_id' => $this->f->clinic->id, 'name' => 'Paused Person', 'status' => 'suspended']);

    asAdmin()->get(route('admin.employees.index'))->assertInertia(fn ($page) => $page
        ->component('ClinicPortal/Staff/Index', false)
        ->where('counts.archived', 1)
        ->where('counts.suspended', 1)
        ->where('employees', fn ($rows) => collect($rows)->every(fn ($row) => ! array_key_exists('phone', $row) && ! array_key_exists('email', $row))));

    asAdmin()->get(route('admin.employees.index', ['q' => 'Paused']))->assertInertia(fn ($page) => $page->has('employees', 1)->where('employees.0.name', 'Paused Person'));
});

test('editing a staff member saves their details and department', function () {
    $department = ClinicDepartment::create(['clinic_id' => $this->f->clinic->id, 'name' => 'Orthodontics', 'key' => 'ortho', 'is_active' => true]);

    asAdmin()->put(route('admin.employees.update', $this->f->doctorA), [
        'name' => 'Dr. Asha Mehta', 'email' => 'asha@clinic.test', 'status' => 'active', 'staff_type' => 'dentist',
        'gender' => 'female', 'department_id' => $department->id, 'notes' => 'Prefers mornings',
    ])->assertRedirect(staffUrl($this->f->doctorA));

    $asha = $this->f->doctorA->fresh();
    expect($asha->gender)->toBe('female')
        ->and($asha->notes)->toBe('Prefers mornings')
        ->and($asha->departments()->pluck('clinic_departments.id')->all())->toBe([$department->id]);
});

test('staff are deactivated, reactivated and archived, never deleted, and not by themselves', function () {
    asAdmin()->patch(staffUrl($this->f->doctorB, '/status'), ['status' => 'inactive'])->assertSessionHasNoErrors();
    expect($this->f->doctorB->fresh()->status->value)->toBe('inactive');

    asAdmin()->patch(staffUrl($this->f->doctorB, '/status'), ['status' => 'active'])->assertSessionHasNoErrors();
    asAdmin()->patch(staffUrl($this->f->doctorB, '/status'), ['status' => 'resigned'])->assertSessionHasNoErrors();
    expect($this->f->doctorB->fresh()->status->value)->toBe('resigned');

    $self = ClinicEmployee::create(['clinic_id' => $this->f->clinic->id, 'name' => 'Me', 'status' => 'active', 'user_id' => $this->admin->id]);
    asAdmin()->patch(staffUrl($self, '/status'), ['status' => 'inactive'])->assertSessionHasErrors('status');
});

test('a staff member is invited from their profile', function () {
    // Seat limits read the clinic's subscription; without one invites are refused.
    CompanySubscription::factory()->create(['clinic_id' => $this->f->clinic->id]);
    $nurse = ClinicEmployee::create(['clinic_id' => $this->f->clinic->id, 'name' => 'Nina Nurse', 'email' => 'nina@clinic.test', 'status' => 'active', 'staff_type' => 'nurse']);

    asAdmin()->post(route('admin.invitations.store'), ['clinic_employee_id' => $nurse->id, 'email' => 'nina@clinic.test'])->assertSessionHasNoErrors();

    expect(ClinicEmployeeInvitation::where('clinic_employee_id', $nurse->id)->exists())->toBeTrue();
    asAdmin()->get(staffUrl($nurse))->assertInertia(fn ($page) => $page->component('ClinicPortal/Staff/Show', false)->where('employee.invitation.email', 'nina@clinic.test')->where('practitioner', null));
});

// ─── Practitioner profile ───────────────────────────────────

test('qualifications are structured records, and the highest heads the profile', function () {
    asAdmin()->post(staffUrl($this->f->doctorA, '/qualifications'), ['qualification' => 'BDS', 'degree_type' => 'bachelor', 'institution' => 'Govt Dental College', 'year' => 2012])->assertSessionHasNoErrors();
    asAdmin()->post(staffUrl($this->f->doctorA, '/qualifications'), ['qualification' => 'MDS', 'degree_type' => 'master', 'specialization' => 'Orthodontics', 'year' => 2016])->assertSessionHasNoErrors();
    asAdmin()->post(staffUrl($this->f->doctorA, '/qualifications'), ['qualification' => 'X', 'degree_type' => 'made_up'])->assertSessionHasErrors('degree_type');

    asAdmin()->get(staffUrl($this->f->doctorA))->assertInertia(fn ($page) => $page
        ->has('practitioner.qualifications', 2)
        ->where('practitioner.headline', 'MDS (Orthodontics)'));

    $bds = PractitionerQualification::where('qualification', 'BDS')->sole();
    asAdmin()->put(staffUrl($this->f->doctorA, "/qualifications/{$bds->id}"), ['qualification' => 'BDS', 'degree_type' => 'bachelor', 'verification_status' => 'verified'])->assertSessionHasNoErrors();
    expect($bds->fresh()->verification_status)->toBe('verified');

    asAdmin()->delete(staffUrl($this->f->doctorB, "/qualifications/{$bds->id}"))->assertNotFound();
});

test('specialties come from the clinic catalogue, new ones are added to it, and registration is tracked', function () {
    asAdmin()->put(staffUrl($this->f->doctorA, '/professional'), [
        'profession' => 'Orthodontist',
        'primary_specialty' => ['id' => null, 'name' => 'Orthodontics'],
        'additional_specialties' => [['id' => null, 'name' => 'Implantology'], ['id' => null, 'name' => 'orthodontics']],
        'registration_number' => 'A-12345', 'registration_authority' => 'Gujarat State Dental Council', 'registration_state' => 'Gujarat',
        'registration_type' => 'permanent', 'registration_date' => '2016-07-01', 'registration_expires_at' => '2026-10-20',
    ])->assertSessionHasNoErrors();

    $profile = Practitioner::withoutGlobalScopes()->where('clinic_employee_id', $this->f->doctorA->id)->sole();
    expect($profile->specialty)->toBe('Orthodontics')
        ->and($profile->profession)->toBe('Orthodontist')
        ->and($profile->specialties()->withoutGlobalScopes()->pluck('name')->all())->toBe(['Implantology']);

    asAdmin()->get(staffUrl($this->f->doctorA))->assertInertia(fn ($page) => $page
        ->where('practitioner.primary_specialty.name', 'Orthodontics')
        ->where('practitioner.registration.number', 'A-12345')
        ->where('practitioner.registration.expiry_state', 'expiring_soon'));
});

// ─── Services, branches, schedules: the appointment engine follows ─

test('a practitioner is only bookable for the services they provide', function () {
    $consult = ClinicalProcedure::create(['clinic_id' => $this->f->clinic->id, 'code' => 'CON', 'name' => 'Consultation', 'category' => 'General', 'default_duration_minutes' => 30, 'is_active' => true]);
    $rct = ClinicalProcedure::create(['clinic_id' => $this->f->clinic->id, 'code' => 'RCT', 'name' => 'Root canal', 'category' => 'Endo', 'default_duration_minutes' => 60, 'is_active' => true]);

    asAdmin()->put(staffUrl($this->f->doctorA, '/services'), ['services' => [$consult->id]])->assertSessionHasNoErrors();

    expect(slotsFor($this->f->doctorA, '2026-09-29', $rct->id))->toBe([])
        ->and(slotsFor($this->f->doctorA, '2026-09-29', $consult->id))->not->toBe([])
        ->and(slotsFor($this->f->doctorB, '2026-09-29', $rct->id))->not->toBe([]);

    asAdmin()->post('/clinic/appointments', [
        'patient_id' => $this->f->patient()->id, 'practitioner_id' => $this->f->doctorA->id, 'service_id' => $rct->id, 'scheduled_at' => '2026-09-29 11:00:00',
    ])->assertSessionHasErrors(['practitioner_id' => 'Dr. Asha Mehta does not provide Root canal.']);

    asAdmin()->get('/clinic/appointments')->assertInertia(fn ($page) => $page
        ->where('providers.0.service_ids', [$consult->id])
        ->where('providers.1.service_ids', []));

    asAdmin()->put(staffUrl($this->f->doctorA, '/services'), ['services' => [$consult->id + 999]])->assertSessionHasErrors('services.0');
});

test('a paused branch placement takes the practitioner off that branch\'s calendar', function () {
    $east = Branch::factory()->create(['clinic_id' => $this->f->clinic->id, 'name' => 'East', 'status' => BranchStatus::Active]);

    asAdmin()->put(staffUrl($this->f->doctorA, '/branches'), [
        'placements' => [['branch_id' => $this->f->branch->id, 'is_active' => false], ['branch_id' => $east->id, 'is_active' => true]],
        'primary_branch_id' => $east->id,
    ])->assertSessionHasNoErrors();

    expect($this->f->doctorA->fresh()->primary_branch_id)->toBe($east->id)
        ->and(slotsFor($this->f->doctorA, '2026-09-29'))->toBe([]);
});

test('removing every branch from someone who signs in is refused, so they are not locked out', function () {
    $user = User::factory()->create(['user_type' => UserType::ClinicUser]);
    $membership = ClinicMembership::factory()->create([
        'clinic_id' => $this->f->clinic->id, 'user_id' => $user->id, 'status' => ClinicMembershipStatus::Active, 'has_all_branches_access' => false,
    ]);
    $membership->assignedBranches()->sync([$this->f->branch->id]);
    $this->f->doctorB->update(['user_id' => $user->id]);

    asAdmin()->put(staffUrl($this->f->doctorB, '/branches'), ['placements' => []])->assertSessionHasErrors('placements');
    asAdmin()->put(route('admin.employees.update', $this->f->doctorB), ['name' => $this->f->doctorB->name, 'status' => 'active', 'branches' => []])
        ->assertSessionHasErrors('branches');

    expect($membership->assignedBranches()->pluck('branches.id')->all())->toBe([$this->f->branch->id]);
});

test('a manager limited to one branch cannot place staff at another', function () {
    $east = Branch::factory()->create(['clinic_id' => $this->f->clinic->id, 'name' => 'East', 'status' => BranchStatus::Active]);
    [$user, $session] = branchLimitedAdmin($this->f->branch);
    $this->f->doctorA->branches()->sync([$this->f->branch->id]);

    $this->actingAs($user)->withSession($session)->put(staffUrl($this->f->doctorA, '/branches'), [
        'placements' => [['branch_id' => $east->id, 'is_active' => true]],
    ])->assertSessionHasErrors('placements.0.branch_id');
});

test('working hours are saved per branch with split shifts, and booking follows them', function () {
    $east = Branch::factory()->create(['clinic_id' => $this->f->clinic->id, 'name' => 'East', 'status' => BranchStatus::Active]);
    $this->f->doctorA->branches()->sync([$this->f->branch->id, $east->id]);

    asAdmin()->put(staffUrl($this->f->doctorA, '/schedule'), [
        'branch_id' => $this->f->branch->id,
        'days' => ['2' => [['start' => '09:00', 'end' => '10:00'], ['start' => '15:00', 'end' => '16:00']], '3' => []],
    ])->assertSessionHasNoErrors();
    asAdmin()->put(staffUrl($this->f->doctorA, '/schedule'), [
        'branch_id' => $east->id,
        'days' => ['2' => [['start' => '12:00', 'end' => '13:00']]],
    ])->assertSessionHasNoErrors();

    expect(PractitionerSchedule::withoutGlobalScopes()->where('clinic_employee_id', $this->f->doctorA->id)->count())->toBe(3)
        ->and(slotsFor($this->f->doctorA, '2026-09-29'))->toBe([
            '2026-09-29T09:00:00', '2026-09-29T09:15:00', '2026-09-29T09:30:00',
            '2026-09-29T15:00:00', '2026-09-29T15:15:00', '2026-09-29T15:30:00',
        ]);

    asAdmin()->put(staffUrl($this->f->doctorA, '/schedule'), [
        'branch_id' => $this->f->branch->id,
        'days' => ['2' => [['start' => '09:00', 'end' => '12:00'], ['start' => '11:00', 'end' => '13:00']]],
    ])->assertSessionHasErrors('days.2');
});

// ─── Leave ──────────────────────────────────────────────────

test('a doctor requests leave; approval blocks booking and lists the affected appointments', function () {
    [$doctorUser, $doctorSession] = $this->f->memberWithRole(ClinicRole::Doctor);
    $this->f->doctorA->update(['user_id' => $doctorUser->id]);
    $booked = $this->f->appointment($this->f->patient(), $this->f->doctorA, CarbonImmutable::parse('2026-09-30 11:00'));

    $this->actingAs($doctorUser)->withSession($doctorSession)->post(route('clinic.leave.store'), [
        'leave_type' => 'casual', 'start_date' => '2026-09-30', 'end_date' => '2026-10-01', 'reason' => 'Conference',
    ])->assertSessionHasNoErrors();
    $leave = Leave::sole();
    expect($leave->status)->toBe(Leave::REQUESTED)
        ->and($leave->employee_id)->toBe($this->f->doctorA->id)
        ->and(slotsFor($this->f->doctorA, '2026-09-30'))->not->toBe([]);

    $this->actingAs($doctorUser)->withSession($doctorSession)->post(route('clinic.leave.approve', $leave))->assertForbidden();

    asAdmin()->post(route('clinic.leave.approve', $leave), ['note' => 'Enjoy'])
        ->assertSessionHas('success', 'Leave approved. 1 appointment is affected and needs review.');

    expect($leave->fresh()->status)->toBe(Leave::APPROVED)
        ->and($leave->fresh()->decided_by)->toBe($this->admin->id)
        ->and(slotsFor($this->f->doctorA, '2026-09-30'))->toBe([])
        ->and($booked->fresh()->status)->toBe(AppointmentStatus::Scheduled);

    asAdmin()->get(route('clinic.leave.index', ['leave' => $leave->id]))->assertInertia(fn ($page) => $page
        ->component('ClinicPortal/Leave/Index', false)
        ->where('leaves.0.affected', 1)
        ->has('affected', 1)
        ->where('affected.0.id', $booked->id));
});

test('leave can be rejected, cannot overlap, and is cancelled by its owner', function () {
    [$doctorUser, $doctorSession] = $this->f->memberWithRole(ClinicRole::Doctor);
    $this->f->doctorA->update(['user_id' => $doctorUser->id]);
    $as = fn () => $this->actingAs($doctorUser)->withSession($doctorSession);

    $as()->post(route('clinic.leave.store'), ['leave_type' => 'sick', 'start_date' => '2026-10-05', 'end_date' => '2026-10-06'])->assertSessionHasNoErrors();
    $as()->post(route('clinic.leave.store'), ['leave_type' => 'sick', 'start_date' => '2026-10-06', 'end_date' => '2026-10-07'])->assertSessionHasErrors('start_date');
    $as()->post(route('clinic.leave.store'), ['leave_type' => 'sick', 'start_date' => '2026-09-20', 'end_date' => '2026-09-21'])->assertSessionHasErrors('start_date');

    $leave = Leave::sole();
    asAdmin()->post(route('clinic.leave.reject', $leave), ['note' => 'Short staffed'])->assertSessionHasNoErrors();
    expect($leave->fresh()->status)->toBe(Leave::REJECTED);

    $as()->post(route('clinic.leave.store'), ['leave_type' => 'personal', 'start_date' => '2026-10-12', 'end_date' => '2026-10-12'])->assertSessionHasNoErrors();
    $second = Leave::where('status', Leave::REQUESTED)->sole();
    $as()->post(route('clinic.leave.cancel', $second))->assertSessionHasNoErrors();
    expect($second->fresh()->status)->toBe(Leave::CANCELLED);
});

test('staff only see their own leave; requesting for someone else needs approval rights', function () {
    [$doctorUser, $doctorSession] = $this->f->memberWithRole(ClinicRole::Doctor);
    $this->f->doctorA->update(['user_id' => $doctorUser->id]);
    Leave::create(['clinic_id' => $this->f->clinic->id, 'employee_id' => $this->f->doctorB->id, 'leave_type' => 'casual', 'status' => 'requested', 'start_date' => '2026-10-10', 'end_date' => '2026-10-10']);

    $this->actingAs($doctorUser)->withSession($doctorSession)->get(route('clinic.leave.index'))->assertInertia(fn ($page) => $page->has('leaves', 0)->where('can.approve', false));
    $this->actingAs($doctorUser)->withSession($doctorSession)->post(route('clinic.leave.store'), [
        'employee_id' => $this->f->doctorB->id, 'leave_type' => 'casual', 'start_date' => '2026-10-15', 'end_date' => '2026-10-15',
    ])->assertForbidden();
});

// ─── Documents ──────────────────────────────────────────────

test('documents are uploaded privately, checked against the checklist, verified and kept on rejection', function () {
    $file = UploadedFile::fake()->createWithContent('degree.pdf', '%PDF-1.4 degree');
    asAdmin()->get(staffUrl($this->f->doctorA))->assertOk();
    $degree = StaffDocumentType::withoutGlobalScopes()->where('clinic_id', $this->f->clinic->id)->where('key', 'degree_certificate')->sole();

    asAdmin()->post(staffUrl($this->f->doctorA, '/documents'), ['document_type_id' => $degree->id, 'file' => $file])->assertSessionHasNoErrors();
    $document = StaffDocument::sole();
    expect($document->status)->toBe(StaffDocument::PENDING)
        ->and($document->uploaded_by)->toBe($this->admin->id);
    Storage::disk('private')->assertExists($document->file_path);

    asAdmin()->get(staffUrl($this->f->doctorA, "/documents/{$document->id}"))->assertOk();

    asAdmin()->post(staffUrl($this->f->doctorA, "/documents/{$document->id}/reject"), ['reason' => 'Blurry scan', 'request_replacement' => true])->assertSessionHasNoErrors();
    expect($document->fresh()->status)->toBe(StaffDocument::REJECTED)->and($document->fresh()->replacement_requested)->toBeTrue();

    asAdmin()->post(staffUrl($this->f->doctorA, '/documents'), ['document_type_id' => $degree->id, 'file' => UploadedFile::fake()->createWithContent('degree2.pdf', '%PDF-1.4 clear')])->assertSessionHasNoErrors();
    $replacement = StaffDocument::latest('id')->first();
    asAdmin()->post(staffUrl($this->f->doctorA, "/documents/{$replacement->id}/verify"))->assertSessionHasNoErrors();

    expect(StaffDocument::count())->toBe(2)
        ->and($document->fresh()->status)->toBe(StaffDocument::REPLACED)
        ->and($replacement->fresh()->status)->toBe(StaffDocument::VERIFIED);

    asAdmin()->get(staffUrl($this->f->doctorA))->assertInertia(fn ($page) => $page
        ->where('documents.items', fn ($items) => collect($items)->firstWhere('type.key', 'degree_certificate')['state'] === 'verified'
            && collect($items)->firstWhere('type.key', 'degree_certificate')['history'][0]['status'] === 'replaced'));
});

test('an infected upload is refused and nothing is stored', function () {
    asAdmin()->get(staffUrl($this->f->doctorA))->assertOk();
    $type = StaffDocumentType::withoutGlobalScopes()->where('key', 'government_id')->sole();

    asAdmin()->post(staffUrl($this->f->doctorA, '/documents'), ['document_type_id' => $type->id, 'file' => UploadedFile::fake()->createWithContent('id.pdf', FakeMalwareScanner::TEST_MARKER)])
        ->assertSessionHasErrors('file');

    expect(StaffDocument::count())->toBe(0);
});

test('expired and expiring documents are flagged, and required documents are counted per staff type', function () {
    asAdmin()->get(staffUrl($this->f->doctorA))->assertOk();
    $registration = StaffDocumentType::withoutGlobalScopes()->where('key', 'registration_certificate')->sole();
    $id = StaffDocumentType::withoutGlobalScopes()->where('key', 'government_id')->sole();

    asAdmin()->post(staffUrl($this->f->doctorA, '/documents'), ['document_type_id' => $registration->id, 'file' => UploadedFile::fake()->createWithContent('reg.pdf', '%PDF reg'), 'issued_on' => '2021-01-01', 'expires_on' => '2026-09-01']);
    asAdmin()->post(staffUrl($this->f->doctorA, '/documents'), ['document_type_id' => $id->id, 'file' => UploadedFile::fake()->createWithContent('id.pdf', '%PDF id'), 'expires_on' => '2026-10-15']);
    StaffDocument::query()->update(['status' => StaffDocument::VERIFIED]);

    asAdmin()->get(staffUrl($this->f->doctorA))->assertInertia(fn ($page) => $page
        ->where('documents.items', fn ($items) => collect($items)->firstWhere('type.key', 'registration_certificate')['state'] === 'expired'
            && collect($items)->firstWhere('type.key', 'government_id')['state'] === 'expiring_soon')
        ->where('documents.summary.required', 3)
        ->where('documents.summary.complete', 1));

    $receptionist = ClinicEmployee::create(['clinic_id' => $this->f->clinic->id, 'name' => 'Rita Desk', 'status' => 'active', 'staff_type' => 'receptionist']);
    asAdmin()->get(staffUrl($receptionist))->assertInertia(fn ($page) => $page
        ->where('documents.items', fn ($items) => collect($items)->pluck('type.key')->doesntContain('degree_certificate'))
        ->where('documents.summary.required', 1));
});

test('the owner sets which documents each staff type needs', function () {
    asAdmin()->get(route('admin.staff-documents.settings'))->assertInertia(fn ($page) => $page->component('ClinicPortal/Staff/DocumentSettings', false));
    $address = StaffDocumentType::withoutGlobalScopes()->where('key', 'address_proof')->sole();

    asAdmin()->put(route('admin.staff-documents.update'), ['types' => [[
        'id' => $address->id, 'is_active' => true, 'has_expiry' => false, 'requirements' => ['receptionist' => 'required', 'dentist' => 'not_applicable'],
    ]]])->assertSessionHasNoErrors();

    expect(StaffDocumentRequirement::where('staff_document_type_id', $address->id)->where('staff_type', 'receptionist')->value('requirement'))->toBe('required');

    asAdmin()->put(route('admin.staff-documents.update'), ['types' => [[
        'id' => $address->id, 'is_active' => true, 'has_expiry' => false, 'requirements' => ['receptionist' => 'mandatory'],
    ]]])->assertSessionHasErrors('types.0.requirements.receptionist');
});

// ─── Permissions and isolation ──────────────────────────────

test('verifying documents needs its own permission', function () {
    $role = Role::firstOrCreate(['name' => 'Staff Manager', 'guard_name' => 'web', 'branch_id' => null]);
    $role->syncPermissions([Permission::firstOrCreate(['name' => 'staff.manage', 'guard_name' => 'web'])]);
    $user = User::factory()->create(['user_type' => UserType::ClinicUser]);
    $membership = ClinicMembership::factory()->create(['clinic_id' => $this->f->clinic->id, 'user_id' => $user->id, 'status' => ClinicMembershipStatus::Active, 'has_all_branches_access' => true]);
    setPermissionsTeamId($this->f->branch->id);
    $user->assignRole($role);
    setPermissionsTeamId(null);
    $session = [ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID => $membership->id, ClinicContext::SESSION_BRANCH_ID => $this->f->branch->id];

    asAdmin()->get(staffUrl($this->f->doctorA))->assertOk();
    $type = StaffDocumentType::withoutGlobalScopes()->where('key', 'government_id')->sole();
    $this->actingAs($user)->withSession($session)->post(staffUrl($this->f->doctorA, '/documents'), ['document_type_id' => $type->id, 'file' => UploadedFile::fake()->createWithContent('id.pdf', '%PDF id')])->assertSessionHasNoErrors();
    $document = StaffDocument::sole();

    $this->actingAs($user)->withSession($session)->post(staffUrl($this->f->doctorA, "/documents/{$document->id}/verify"))->assertForbidden();
    $this->actingAs($user)->withSession($session)->put(staffUrl($this->f->doctorA, '/services'), ['services' => []])->assertForbidden();
    expect($document->fresh()->status)->toBe(StaffDocument::PENDING);
});

test('staff without staff.manage reach no staff record or document', function () {
    [$reception, $session] = $this->f->memberWithRole(ClinicRole::Receptionist);
    asAdmin()->get(staffUrl($this->f->doctorA))->assertOk();
    $type = StaffDocumentType::withoutGlobalScopes()->where('key', 'government_id')->sole();
    asAdmin()->post(staffUrl($this->f->doctorA, '/documents'), ['document_type_id' => $type->id, 'file' => UploadedFile::fake()->createWithContent('id.pdf', '%PDF id')]);
    $document = StaffDocument::sole();
    $as = fn () => $this->actingAs($reception)->withSession($session);

    $as()->get(route('admin.employees.index'))->assertForbidden();
    $as()->get(staffUrl($this->f->doctorA))->assertForbidden();
    $as()->get(staffUrl($this->f->doctorA, "/documents/{$document->id}"))->assertForbidden();
    $as()->get(route('clinic.leave.index'))->assertOk()->assertInertia(fn ($page) => $page->where('can.approve', false));
});

test('another clinic\'s staff and documents are out of reach', function () {
    $other = ReceptionFixture::make('other-clinic');
    $otherDoc = StaffDocument::forceCreate([
        'clinic_id' => $other->clinic->id, 'clinic_employee_id' => $other->doctorA->id,
        'staff_document_type_id' => StaffDocumentType::create(['clinic_id' => $other->clinic->id, 'key' => 'government_id', 'name' => 'Government ID', 'audience' => 'all'])->id,
        'title' => 'ID', 'file_path' => 'x/id.pdf', 'file_name' => 'id.pdf', 'file_size_bytes' => 10, 'file_sha256' => str_repeat('a', 64), 'scanner' => 'fake', 'status' => 'pending_verification',
    ]);
    $otherLeave = Leave::withoutGlobalScopes()->create(['clinic_id' => $other->clinic->id, 'employee_id' => $other->doctorA->id, 'leave_type' => 'casual', 'status' => 'requested', 'start_date' => '2026-10-10', 'end_date' => '2026-10-10']);

    foreach ([
        ['GET', staffUrl($other->doctorA)],
        ['PUT', staffUrl($other->doctorA, '/services'), ['services' => []]],
        ['PUT', staffUrl($other->doctorA, '/professional'), ['profession' => 'Hacked']],
        ['GET', staffUrl($other->doctorA, "/documents/{$otherDoc->id}")],
        ['POST', staffUrl($other->doctorA, "/documents/{$otherDoc->id}/verify")],
        ['POST', route('clinic.leave.approve', $otherLeave->id)],
    ] as $request) {
        expect(asAdmin()->json($request[0], $request[1], $request[2] ?? [])->status())->toBeIn([403, 404]);
    }

    expect($otherDoc->fresh()->status)->toBe('pending_verification')
        ->and($otherLeave->fresh()->status)->toBe('requested');
});

test('a manager limited to one branch does not see staff placed only at another', function () {
    $east = Branch::factory()->create(['clinic_id' => $this->f->clinic->id, 'name' => 'East', 'status' => BranchStatus::Active]);
    $this->f->doctorA->branches()->sync([$this->f->branch->id]);
    $this->f->doctorB->branches()->sync([$east->id]);
    [$user, $session] = branchLimitedAdmin($this->f->branch);

    $this->actingAs($user)->withSession($session)->get(route('admin.employees.index'))
        ->assertInertia(fn ($page) => $page->where('employees', fn ($rows) => collect($rows)->pluck('id')->contains($this->f->doctorA->id) && ! collect($rows)->pluck('id')->contains($this->f->doctorB->id)));
    $this->actingAs($user)->withSession($session)->get(staffUrl($this->f->doctorB))->assertForbidden();
});
