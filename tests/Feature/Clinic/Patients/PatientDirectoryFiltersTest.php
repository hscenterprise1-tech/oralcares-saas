<?php

use App\Models\Branch;
use App\Models\PatientBranchActivity;
use App\Models\PatientTreatment;
use App\Models\PatientVisit;
use App\PatientStatus;
use App\Services\Treatment\TreatmentReadModel;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\TreatmentFixture;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-30 10:00', 'Asia/Kolkata'));
    $this->t = TreatmentFixture::make();
    $this->f = $this->t->f;
    $this->asOwner = fn () => $this->actingAs($this->f->owner)->withSession($this->f->session);

    $this->directory = function (array $query = []): array {
        $props = [];
        $queryString = $query !== [] ? '?'.http_build_query($query) : '';
        ($this->asOwner)()->get('/clinic/patients'.$queryString)->assertOk()->assertInertia(function (Assert $page) use (&$props) {
            $page->component('ClinicPortal/Patients/Index', true);
            $props = $page->toArray()['props'];
        });

        return $props;
    };

    $this->patientIds = fn (array $props): array => collect($props['patients']['data'])->pluck('id')->all();
});

test('Filter 1: Branch filter restricts patients to specified branch activity', function () {
    $branchB = Branch::factory()->create(['clinic_id' => $this->f->clinic->id, 'name' => 'Uptown Branch']);

    $p1 = $this->f->patient('Alice', 'BranchA');
    $p2 = $this->f->patient('Bob', 'BranchB');

    // p1 at branchA, p2 at branchB
    PatientBranchActivity::query()->create([
        'clinic_id' => $this->f->clinic->id, 'patient_id' => $p1->id, 'branch_id' => $this->f->branch->id,
        'first_seen_at' => now(), 'last_seen_at' => now(), 'first_source' => 'visit',
    ]);
    PatientBranchActivity::query()->create([
        'clinic_id' => $this->f->clinic->id, 'patient_id' => $p2->id, 'branch_id' => $branchB->id,
        'first_seen_at' => now(), 'last_seen_at' => now(), 'first_source' => 'visit',
    ]);

    $res = ($this->directory)(['branch_id' => $branchB->id, 'view' => 'all']);
    expect(($this->patientIds)($res))->toContain($p2->id)
        ->and(($this->patientIds)($res))->not->toContain($p1->id);
});

test('Filter 2: Doctor filter filters by assigned practitioner or unassigned', function () {
    $doctor = $this->f->doctor('Dr. Sharma');

    $assigned = $this->f->patient('Assigned', 'Patient', ['current_practitioner_id' => $doctor->id]);
    $unassigned = $this->f->patient('Unassigned', 'Patient', ['current_practitioner_id' => null]);

    // Test specific doctor
    $resDoc = ($this->directory)(['practitioner_id' => $doctor->id, 'view' => 'all']);
    expect(($this->patientIds)($resDoc))->toContain($assigned->id)
        ->and(($this->patientIds)($resDoc))->not->toContain($unassigned->id);

    // Test unassigned ('none')
    $resNone = ($this->directory)(['practitioner_id' => 'none', 'view' => 'all']);
    expect(($this->patientIds)($resNone))->toContain($unassigned->id)
        ->and(($this->patientIds)($resNone))->not->toContain($assigned->id);
});

test('Filter 3: Registration date range filters by created_at range', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-01 10:00', 'Asia/Kolkata'));
    $pSeptember = $this->f->patient('Sep', 'Patient');

    $this->travelTo(CarbonImmutable::parse('2026-10-15 10:00', 'Asia/Kolkata'));
    $pOctober = $this->f->patient('Oct', 'Patient');

    $this->travelTo(CarbonImmutable::parse('2026-10-30 10:00', 'Asia/Kolkata'));

    $res = ($this->directory)([
        'registered_from' => '2026-10-01',
        'registered_to' => '2026-10-20',
        'view' => 'all',
    ]);

    expect(($this->patientIds)($res))->toContain($pOctober->id)
        ->and(($this->patientIds)($res))->not->toContain($pSeptember->id);
});

test('Filter 4: Gender filter matches male, female, or other', function () {
    $pMale = $this->f->patient('John', 'Male', ['gender' => 'male']);
    $pFemale = $this->f->patient('Jane', 'Female', ['gender' => 'female']);

    $resMale = ($this->directory)(['gender' => 'male', 'view' => 'all']);
    expect(($this->patientIds)($resMale))->toContain($pMale->id)
        ->and(($this->patientIds)($resMale))->not->toContain($pFemale->id);

    $resFemale = ($this->directory)(['gender' => 'female', 'view' => 'all']);
    expect(($this->patientIds)($resFemale))->toContain($pFemale->id)
        ->and(($this->patientIds)($resFemale))->not->toContain($pMale->id);
});

test('Filter 5: Patient status filter matches active and inactive', function () {
    $pActive = $this->f->patient('Active', 'Pat', ['status' => PatientStatus::Active]);
    $pInactive = $this->f->patient('Inactive', 'Pat', ['status' => PatientStatus::Inactive]);

    $resActive = ($this->directory)(['status' => 'active', 'view' => 'all']);
    expect(($this->patientIds)($resActive))->toContain($pActive->id)
        ->and(($this->patientIds)($resActive))->not->toContain($pInactive->id);

    $resInactive = ($this->directory)(['status' => 'inactive', 'view' => 'all']);
    expect(($this->patientIds)($resInactive))->toContain($pInactive->id)
        ->and(($this->patientIds)($resInactive))->not->toContain($pActive->id);
});

test('Filter 6: Treatment status matches ongoing, completed, and not started', function () {
    $pOngoing = $this->f->patient('Ongoing', 'Pat');
    PatientTreatment::query()->create([
        'clinic_id' => $this->f->clinic->id, 'patient_id' => $pOngoing->id, 'branch_id' => $this->f->branch->id,
        'title' => 'Root Canal', 'status' => TreatmentReadModel::OPEN[0],
    ]);

    $pCompleted = $this->f->patient('Completed', 'Pat');
    PatientTreatment::query()->create([
        'clinic_id' => $this->f->clinic->id, 'patient_id' => $pCompleted->id, 'branch_id' => $this->f->branch->id,
        'title' => 'Scaling', 'status' => PatientTreatment::COMPLETED,
    ]);

    $pNone = $this->f->patient('NoTreatment', 'Pat');

    // Test ongoing
    $resOngoing = ($this->directory)(['treatment_status' => 'ongoing', 'view' => 'all']);
    expect(($this->patientIds)($resOngoing))->toContain($pOngoing->id)
        ->and(($this->patientIds)($resOngoing))->not->toContain($pCompleted->id)
        ->and(($this->patientIds)($resOngoing))->not->toContain($pNone->id);

    // Test completed
    $resCompleted = ($this->directory)(['treatment_status' => 'completed', 'view' => 'all']);
    expect(($this->patientIds)($resCompleted))->toContain($pCompleted->id)
        ->and(($this->patientIds)($resCompleted))->not->toContain($pOngoing->id)
        ->and(($this->patientIds)($resCompleted))->not->toContain($pNone->id);

    // Test not_started
    $resNone = ($this->directory)(['treatment_status' => 'not_started', 'view' => 'all']);
    expect(($this->patientIds)($resNone))->toContain($pNone->id)
        ->and(($this->patientIds)($resNone))->not->toContain($pOngoing->id)
        ->and(($this->patientIds)($resNone))->not->toContain($pCompleted->id);
});

test('Filter 7: Patient type matches new vs repeat', function () {
    $pNew = $this->f->patient('NewCase', 'Pat');
    PatientVisit::withoutGlobalScopes()->create([
        'clinic_id' => $this->f->clinic->id, 'branch_id' => $this->f->branch->id, 'patient_id' => $pNew->id,
        'registration_type' => 'new', 'visit_date' => '2026-10-30', 'visit_status' => 'checked_in',
    ]);

    $pRepeat = $this->f->patient('RepeatCase', 'Pat');
    PatientVisit::withoutGlobalScopes()->create([
        'clinic_id' => $this->f->clinic->id, 'branch_id' => $this->f->branch->id, 'patient_id' => $pRepeat->id,
        'registration_type' => 'repeat', 'visit_date' => '2026-10-30', 'visit_status' => 'checked_in',
    ]);

    $resNew = ($this->directory)(['patient_type' => 'new', 'view' => 'all']);
    expect(($this->patientIds)($resNew))->toContain($pNew->id)
        ->and(($this->patientIds)($resNew))->not->toContain($pRepeat->id);

    $resRepeat = ($this->directory)(['patient_type' => 'repeat', 'view' => 'all']);
    expect(($this->patientIds)($resRepeat))->toContain($pRepeat->id)
        ->and(($this->patientIds)($resRepeat))->not->toContain($pNew->id);
});

test('Filter 8: Age range matches date of birth and legacy age calculation', function () {
    // Clinic clock is 2026-10-30
    // 25 years old: born 2001-05-15
    $p25 = $this->f->patient('Young', 'Adult', ['date_of_birth' => '2001-05-15']);
    // 50 years old: born 1976-05-15
    $p50 = $this->f->patient('Middle', 'Aged', ['date_of_birth' => '1976-05-15']);

    $resYoung = ($this->directory)(['age_min' => 20, 'age_max' => 30, 'view' => 'all']);
    expect(($this->patientIds)($resYoung))->toContain($p25->id)
        ->and(($this->patientIds)($resYoung))->not->toContain($p50->id);

    $resOlder = ($this->directory)(['age_min' => 40, 'age_max' => 60, 'view' => 'all']);
    expect(($this->patientIds)($resOlder))->toContain($p50->id)
        ->and(($this->patientIds)($resOlder))->not->toContain($p25->id);
});

test('Filter 9: Last visit date range matches patient branch activity last_seen_at', function () {
    $pRecent = $this->f->patient('Recent', 'Visit');
    PatientBranchActivity::query()->create([
        'clinic_id' => $this->f->clinic->id, 'patient_id' => $pRecent->id, 'branch_id' => $this->f->branch->id,
        'first_seen_at' => '2026-10-25 10:00:00', 'last_seen_at' => '2026-10-25 10:00:00', 'first_source' => 'visit',
    ]);

    $pOld = $this->f->patient('Old', 'Visit');
    PatientBranchActivity::query()->create([
        'clinic_id' => $this->f->clinic->id, 'patient_id' => $pOld->id, 'branch_id' => $this->f->branch->id,
        'first_seen_at' => '2026-08-01 10:00:00', 'last_seen_at' => '2026-08-01 10:00:00', 'first_source' => 'visit',
    ]);

    $res = ($this->directory)([
        'last_visit_from' => '2026-10-01',
        'last_visit_to' => '2026-10-30',
        'view' => 'all',
    ]);

    expect(($this->patientIds)($res))->toContain($pRecent->id)
        ->and(($this->patientIds)($res))->not->toContain($pOld->id);
});

test('all filters work in combination together', function () {
    $doctor = $this->f->doctor('Dr. Combination');

    $match = $this->f->patient('Target', 'Match', [
        'gender' => 'female',
        'status' => PatientStatus::Active,
        'current_practitioner_id' => $doctor->id,
        'date_of_birth' => '1996-01-01', // 30 years old on 2026-10-30
    ]);
    PatientBranchActivity::query()->create([
        'clinic_id' => $this->f->clinic->id, 'patient_id' => $match->id, 'branch_id' => $this->f->branch->id,
        'first_seen_at' => '2026-10-20 10:00:00', 'last_seen_at' => '2026-10-20 10:00:00', 'first_source' => 'visit',
    ]);
    PatientTreatment::query()->create([
        'clinic_id' => $this->f->clinic->id, 'patient_id' => $match->id, 'branch_id' => $this->f->branch->id,
        'title' => 'Active Treatment', 'status' => TreatmentReadModel::OPEN[0],
    ]);
    PatientVisit::withoutGlobalScopes()->create([
        'clinic_id' => $this->f->clinic->id, 'branch_id' => $this->f->branch->id, 'patient_id' => $match->id,
        'registration_type' => 'new', 'visit_date' => '2026-10-20', 'visit_status' => 'checked_in',
    ]);
    PatientBranchActivity::query()->where('patient_id', $match->id)->update(['last_seen_at' => '2026-10-20 10:00:00']);

    $nonMatch = $this->f->patient('Other', 'Person', [
        'gender' => 'male',
        'status' => PatientStatus::Active,
        'date_of_birth' => '1996-01-01',
    ]);

    $res = ($this->directory)([
        'gender' => 'female',
        'status' => 'active',
        'practitioner_id' => $doctor->id,
        'branch_id' => $this->f->branch->id,
        'treatment_status' => 'ongoing',
        'patient_type' => 'new',
        'age_min' => 25,
        'age_max' => 35,
        'last_visit_from' => '2026-10-01',
        'last_visit_to' => '2026-10-25',
        'view' => 'all',
    ]);

    expect(($this->patientIds)($res))->toContain($match->id)
        ->and(($this->patientIds)($res))->not->toContain($nonMatch->id);
});

test('keyset pagination works seamlessly with filters applied', function () {
    // Create 30 female patients with an active treatment
    $created = [];
    for ($i = 1; $i <= 30; $i++) {
        $p = $this->f->patient('Female', "Patient{$i}", ['gender' => 'female']);
        PatientTreatment::query()->create([
            'clinic_id' => $this->f->clinic->id, 'patient_id' => $p->id, 'branch_id' => $this->f->branch->id,
            'title' => "Treatment {$i}", 'status' => TreatmentReadModel::OPEN[0],
        ]);
        $created[] = $p;
    }

    // Page 1 with filters
    $page1 = ($this->directory)([
        'gender' => 'female',
        'treatment_status' => 'ongoing',
        'view' => 'all',
    ]);

    expect(count($page1['patients']['data']))->toBe(25)
        ->and($page1['patients']['next_cursor'])->not->toBeNull();

    // Page 2 using cursor
    $page2 = ($this->directory)([
        'gender' => 'female',
        'treatment_status' => 'ongoing',
        'cursor' => $page1['patients']['next_cursor'],
        'view' => 'all',
    ]);

    expect(count($page2['patients']['data']))->toBe(5);
    $page1Ids = collect($page1['patients']['data'])->pluck('id');
    $page2Ids = collect($page2['patients']['data'])->pluck('id');
    expect($page1Ids->intersect($page2Ids))->toBeEmpty();
});

test('invalid date ranges or age ranges fail validation cleanly', function () {
    // age_max < age_min
    ($this->asOwner)()->get('/clinic/patients?age_min=50&age_max=20')
        ->assertSessionHasErrors('age_max');

    // registered_to < registered_from
    ($this->asOwner)()->get('/clinic/patients?registered_from=2026-10-30&registered_to=2026-10-01')
        ->assertSessionHasErrors('registered_to');

    // last_visit_to < last_visit_from
    ($this->asOwner)()->get('/clinic/patients?last_visit_from=2026-10-30&last_visit_to=2026-10-01')
        ->assertSessionHasErrors('last_visit_to');
});
