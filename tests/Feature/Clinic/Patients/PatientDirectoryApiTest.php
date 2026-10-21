<?php

use App\BranchStatus;
use App\ClinicMembershipStatus;
use App\ClinicRole;
use App\Models\Branch;
use App\Models\ClinicMembership;
use App\Models\User;
use App\PatientStatus;
use App\Services\ClinicContext;
use App\Services\ClinicRoleAssignmentService;
use App\Services\Patient\PatientBranchActivityRecorder;
use App\Services\Patient\PatientSpecialties;
use App\Services\Treatment\PatientTreatments;
use App\UserType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\TreatmentFixture;

/**
 * The patient directory API: keyset pages, prefix search, specialty and
 * active-care filters, branch limits — and a page costs the same number of
 * queries whatever its size.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-03 10:00', 'Asia/Kolkata'));
    $this->t = TreatmentFixture::make();
    $this->f = $this->t->f;
    $this->url = '/clinic/patients/directory';
    $this->asOwner = fn () => $this->actingAs($this->f->owner)->withSession($this->f->session);
    // Registered a minute apart, oldest first.
    $this->register = function (string $first, string $last, array $attributes = []) {
        $this->travel(1)->minutes();

        return $this->f->patient($first, $last, $attributes);
    };
});

test('pages are keyset cursors, newest first, with no total and no overlap', function () {
    $names = collect(['Anil', 'Bina', 'Chetan', 'Divya', 'Esha'])->map(fn ($n) => ($this->register)($n, 'Rao')->full_name);

    $first = ($this->asOwner)()->getJson("{$this->url}?per_page=2")->assertOk()->assertJsonMissingPath('total')->assertJsonPath('per_page', 2);
    $second = ($this->asOwner)()->getJson("{$this->url}?per_page=2&cursor=".$first->json('next_cursor'))->assertOk();
    $third = ($this->asOwner)()->getJson("{$this->url}?per_page=2&cursor=".$second->json('next_cursor'))->assertOk()->assertJsonPath('next_cursor', null);

    expect([...$first->json('data.*.name'), ...$second->json('data.*.name'), ...$third->json('data.*.name')])
        ->toBe($names->reverse()->values()->all());
});

test('search matches the start of a name or number, never the middle', function () {
    ($this->register)('Rajesh', 'Patel', ['phone' => '9811100001']);
    ($this->register)('Meena', 'Shah', ['phone' => '9822200002']);

    ($this->asOwner)()->getJson("{$this->url}?q=Raj")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Rajesh Patel');
    ($this->asOwner)()->getJson("{$this->url}?q=Shah")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Meena Shah');
    ($this->asOwner)()->getJson("{$this->url}?q=jesh")->assertOk()->assertJsonCount(0, 'data');
    ($this->asOwner)()->getJson("{$this->url}?q=98222")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Meena Shah');
});

test('specialty and active-care filters, with active care shown on each row', function () {
    $owner = $this->t->actAsOwner();
    $treated = ($this->register)('Rajesh', 'Patel');
    $physio = ($this->register)('Meena', 'Shah');
    ($this->register)('Anil', 'Kumar');
    app(PatientTreatments::class)->start($treated, $this->t->rct(), $this->f->doctorA, $this->f->branch, $owner, ['targets' => ['tooth' => [['tooth_number' => 16]]]]);
    app(PatientSpecialties::class)->enroll($physio, 'physiotherapy', 'treatment', $owner);

    ($this->asOwner)()->getJson("{$this->url}?active_care=1")->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Rajesh Patel')
        ->assertJsonPath('data.0.active_care.0.title', 'Root Canal Treatment')
        ->assertJsonPath('data.0.active_care.0.progress.total', 3)
        ->assertJsonPath('data.0.specialties', ['dental']);
    ($this->asOwner)()->getJson("{$this->url}?specialty=physiotherapy")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Meena Shah');
    ($this->asOwner)()->getJson("{$this->url}?specialty=dental")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Rajesh Patel');
});

test('merged and archived records leave the working directory but stay findable by status', function () {
    ($this->register)('Rajesh', 'Patel');
    ($this->register)('Old', 'Record', ['status' => PatientStatus::Merged]);
    ($this->register)('Gone', 'Away', ['status' => PatientStatus::Archived]);

    ($this->asOwner)()->getJson($this->url)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Rajesh Patel');
    ($this->asOwner)()->getJson("{$this->url}?status=merged")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.status', 'merged');
});

test('a member limited to one branch sees only patients seen there, and cannot widen it with a filter', function () {
    $north = Branch::factory()->create(['clinic_id' => $this->f->clinic->id, 'name' => 'North', 'is_main' => false, 'status' => BranchStatus::Active]);
    $recorder = app(PatientBranchActivityRecorder::class);
    $main = ($this->register)('Main', 'Patient');
    $northern = ($this->register)('North', 'Patient');
    $recorder->record($this->f->clinic->id, $main->id, $this->f->branch->id, now(), 'registration');
    $recorder->record($this->f->clinic->id, $northern->id, $north->id, now(), 'registration');

    $user = User::factory()->create(['user_type' => UserType::ClinicUser]);
    $membership = ClinicMembership::factory()->create(['clinic_id' => $this->f->clinic->id, 'user_id' => $user->id, 'has_all_branches_access' => false, 'status' => ClinicMembershipStatus::Active]);
    DB::table('branch_clinic_membership')->insert(['clinic_id' => $this->f->clinic->id, 'clinic_membership_id' => $membership->id, 'branch_id' => $north->id]);
    $this->f->memberWithRole(ClinicRole::Receptionist);
    app(ClinicRoleAssignmentService::class)->syncRoles($user, [ClinicRole::Receptionist->value], [$north]);
    $asNorth = fn () => $this->actingAs($user)->withSession([ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID => $membership->id, ClinicContext::SESSION_BRANCH_ID => $north->id]);

    $asNorth()->getJson($this->url)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'North Patient');
    $asNorth()->getJson("{$this->url}?branch_id={$this->f->branch->id}")->assertOk()->assertJsonCount(0, 'data');
    ($this->asOwner)()->getJson("{$this->url}?branch_id={$north->id}")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'North Patient');
});

test('another clinic sees none of these patients', function () {
    ($this->register)('Rajesh', 'Patel');
    $other = TreatmentFixture::make('other-clinic');

    $this->actingAs($other->f->owner)->withSession($other->f->session)->getJson($this->url)->assertOk()->assertJsonCount(0, 'data');
});

test('a page of 25 costs the same queries as a page of 2: no per-row lookups', function () {
    $owner = $this->t->actAsOwner();
    $version = $this->t->rct();
    foreach (range(1, 12) as $i) {
        $patient = ($this->register)("Patient{$i}", 'Test');
        app(PatientTreatments::class)->start($patient, $version, $this->f->doctorA, $this->f->branch, $owner, ['targets' => ['tooth' => [['tooth_number' => 11 + ($i % 7)]]]]);
    }
    $count = function (int $perPage) {
        DB::flushQueryLog();
        DB::enableQueryLog();
        ($this->asOwner)()->getJson("{$this->url}?per_page={$perPage}")->assertOk()->assertJsonCount($perPage, 'data');
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    $count(1); // first request warms per-process caches (permissions, config)

    expect($count(12))->toBe($count(2));
});
