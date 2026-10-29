<?php

use App\BranchStatus;
use App\ClinicMembershipStatus;
use App\ClinicRole;
use App\Models\Branch;
use App\Models\ClinicMembership;
use App\Models\Patient;
use App\Models\PatientTreatment;
use App\Models\PatientVisit;
use App\Models\User;
use App\Services\ClinicContext;
use App\Services\ClinicRoleAssignmentService;
use App\Services\Patient\PatientBranchActivityRecorder;
use App\Services\Treatment\PatientTreatments;
use App\UserType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\TreatmentFixture;

/**
 * What the directory says about each patient beyond identity: the case of
 * their latest attended visit (New or Repeat, as check-in classified it) and
 * their treatment progress (Ongoing or Complete, from `patient_treatments`)
 * — two separate things, neither of them the queue — and that Today lists a
 * patient once however many visits they had.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-17 10:00', 'Asia/Kolkata'));
    $this->t = TreatmentFixture::make();
    $this->f = $this->t->f;
    $this->url = '/clinic/patients/directory';
    $this->asOwner = fn () => $this->actingAs($this->f->owner)->withSession($this->f->session);
    // A visit as check-in writes it: the patient's first is new, every later one repeat (`ReceptionCheckIn`).
    $this->visit = function (Patient $patient, string $date, string $status = 'checked_in'): PatientVisit {
        $earlier = PatientVisit::withoutGlobalScopes()->where('patient_id', $patient->id)->exists();

        return PatientVisit::withoutGlobalScopes()->create([
            'clinic_id' => $this->f->clinic->id, 'branch_id' => $this->f->branch->id, 'patient_id' => $patient->id,
            'registration_type' => $earlier ? 'repeat' : 'new', 'visit_date' => $date, 'visit_status' => $status, 'queue_status' => 'waiting',
        ]);
    };
    $this->rows = fn (string $query = '') => collect(($this->asOwner)()->getJson($this->url.$query)->assertOk()->json('data'))->keyBy('name');
});

test('the case is the latest attended visit\'s: new on the first visit, repeat when the patient returns — even after registering long ago', function () {
    $first = $this->f->patient('First', 'Visit');
    ($this->visit)($first, '2026-10-17');
    $returning = $this->f->patient('Returning', 'Patient');
    ($this->visit)($returning, '2026-09-01', 'completed');
    ($this->visit)($returning, '2026-10-17');
    // Registered, never seen: no case yet.
    $this->f->patient('Never', 'Seen');

    $today = ($this->rows)('?view=today');
    expect($today->keys()->sort()->values()->all())->toBe(['First Visit', 'Returning Patient'])
        ->and($today['First Visit']['case_type'])->toBe('new')
        ->and($today['Returning Patient']['case_type'])->toBe('repeat');
    expect(($this->rows)()['Never Seen']['case_type'])->toBeNull();
});

test('a visit that was not attended does not change the case', function () {
    $patient = $this->f->patient('Once', 'Seen');
    ($this->visit)($patient, '2026-10-10', 'completed');
    ($this->visit)($patient, '2026-10-17', 'cancelled');

    expect(($this->rows)()['Once Seen']['case_type'])->toBe('new')
        ->and(($this->rows)('?view=today'))->toHaveCount(0);
});

test('several visits today are still one patient, showing the latest visit\'s case', function () {
    $patient = $this->f->patient('Twice', 'Today');
    ($this->visit)($patient, '2026-10-17', 'completed');
    ($this->visit)($patient, '2026-10-17', 'checked_in');

    $rows = ($this->asOwner)()->getJson("{$this->url}?view=today")->assertOk()->json('data');
    expect($rows)->toHaveCount(1)
        ->and($rows[0]['case_type'])->toBe('repeat')
        ->and($rows[0]['today_visit']['status'])->toBe('checked_in')
        ->and(Patient::query()->withoutGlobalScopes()->where('first_name', 'Twice')->count())->toBe(1);
});

test('treatment progress comes from the treatments, not the visit: ongoing, complete, or nothing', function () {
    $owner = $this->t->actAsOwner();
    $treatments = app(PatientTreatments::class);
    $dental = $this->f->patient('Dental', 'Ongoing');
    $treatments->start($dental, $this->t->rct(), $this->f->doctorA, $this->f->branch, $owner, ['targets' => ['tooth' => [['tooth_number' => 16]]]]);
    $physio = $this->f->patient('Physio', 'Ongoing');
    $treatments->start($physio, $this->t->backRehab(), $this->t->physio, $this->f->branch, $owner);
    $done = $this->f->patient('Treatment', 'Done');
    $treatments->start($done, $this->t->rct(), $this->f->doctorA, $this->f->branch, $owner, ['targets' => ['tooth' => [['tooth_number' => 26]]]])
        ->forceFill(['status' => PatientTreatment::COMPLETED])->save();
    // A finished visit is not a finished treatment.
    $visitOnly = $this->f->patient('Visit', 'Only');
    ($this->visit)($visitOnly, '2026-10-17', 'completed');
    $stopped = $this->f->patient('Stopped', 'Treatment');
    $treatments->start($stopped, $this->t->rct(), $this->f->doctorA, $this->f->branch, $owner, ['targets' => ['tooth' => [['tooth_number' => 36]]]])
        ->forceFill(['status' => PatientTreatment::CANCELLED])->save();

    $rows = ($this->rows)();
    expect($rows['Dental Ongoing']['treatment_progress'])->toBe('ongoing')
        ->and($rows['Physio Ongoing']['treatment_progress'])->toBe('ongoing')
        ->and($rows['Treatment Done']['treatment_progress'])->toBe('complete')
        ->and($rows['Visit Only']['treatment_progress'])->toBeNull()
        ->and($rows['Stopped Treatment']['treatment_progress'])->toBeNull();

    // One open treatment beside a completed one is still ongoing.
    $treatments->start($done, $this->t->rct(), $this->f->doctorA, $this->f->branch, $owner, ['targets' => ['tooth' => [['tooth_number' => 46]]]]);
    expect(($this->rows)()['Treatment Done']['treatment_progress'])->toBe('ongoing');
});

test('another clinic\'s patients who attended today never appear in this clinic\'s Today', function () {
    $other = TreatmentFixture::make('other-clinic');
    $theirs = $other->f->patient('Other', 'Clinic');
    PatientVisit::withoutGlobalScopes()->create([
        'clinic_id' => $other->f->clinic->id, 'branch_id' => $other->f->branch->id, 'patient_id' => $theirs->id,
        'registration_type' => 'new', 'visit_date' => '2026-10-17', 'visit_status' => 'checked_in', 'queue_status' => 'waiting',
    ]);
    ($this->visit)($this->f->patient('Our', 'Patient'), '2026-10-17');

    expect(($this->rows)('?view=today')->keys()->all())->toBe(['Our Patient']);
});

test('for a member limited to one branch, the Ongoing filter and the Treatment column agree: treatment elsewhere counts for neither', function () {
    $owner = $this->t->actAsOwner();
    $treatments = app(PatientTreatments::class);
    $recorder = app(PatientBranchActivityRecorder::class);
    $north = Branch::factory()->create(['clinic_id' => $this->f->clinic->id, 'name' => 'North', 'is_main' => false, 'status' => BranchStatus::Active]);
    // Both are seen at North; one is treated at the main branch, the other at North.
    $treatedElsewhere = $this->f->patient('Treated', 'Elsewhere');
    $treatedHere = $this->f->patient('Treated', 'Here');
    foreach ([$treatedElsewhere, $treatedHere] as $patient) {
        $recorder->record($this->f->clinic->id, $patient->id, $north->id, now(), 'registration');
    }
    $treatments->start($treatedElsewhere, $this->t->rct(), $this->f->doctorA, $this->f->branch, $owner, ['targets' => ['tooth' => [['tooth_number' => 16]]]]);
    $treatments->start($treatedHere, $this->t->rct(), $this->f->doctorA, $north, $owner, ['targets' => ['tooth' => [['tooth_number' => 26]]]]);

    $user = User::factory()->create(['user_type' => UserType::ClinicUser]);
    $membership = ClinicMembership::factory()->create(['clinic_id' => $this->f->clinic->id, 'user_id' => $user->id, 'has_all_branches_access' => false, 'status' => ClinicMembershipStatus::Active]);
    DB::table('branch_clinic_membership')->insert(['clinic_id' => $this->f->clinic->id, 'clinic_membership_id' => $membership->id, 'branch_id' => $north->id]);
    $this->f->memberWithRole(ClinicRole::Receptionist);
    app(ClinicRoleAssignmentService::class)->syncRoles($user, [ClinicRole::Receptionist->value], [$north]);
    $as = fn () => $this->actingAs($user)->withSession([ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID => $membership->id, ClinicContext::SESSION_BRANCH_ID => $north->id]);

    $all = collect($as()->getJson($this->url)->assertOk()->json('data'))->keyBy('name');
    expect($all['Treated Here']['treatment_progress'])->toBe('ongoing')
        ->and($all['Treated Elsewhere']['treatment_progress'])->toBeNull();
    expect($as()->getJson("{$this->url}?active_care=1")->assertOk()->json('data.*.name'))->toBe(['Treated Here']);
});
