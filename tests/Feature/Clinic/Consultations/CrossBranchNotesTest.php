<?php

use App\BranchStatus;
use App\ClinicMembershipStatus;
use App\ClinicRole;
use App\Models\Branch;
use App\Models\ClinicMembership;
use App\Models\PatientClinicalAccessAudit;
use App\Models\User;
use App\Services\Clinical\EncounterWorkspace;
use App\Services\ClinicContext;
use App\Services\ClinicRoleAssignmentService;
use App\Services\Reception\ReceptionCheckIn;
use App\UserType;
use Carbon\CarbonImmutable;
use Tests\Support\ReceptionFixture;

/**
 * Another branch's consultations (D5, TR-14): a doctor limited to East
 * reads the Main branch's notes only while the patient attends East today,
 * and every note opened is audited. Otherwise they stay blocked.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-02 10:00', 'Asia/Kolkata'));
    $this->f = ReceptionFixture::make();
    [$mainUser] = $this->f->memberWithRole(ClinicRole::Doctor);
    $mainDoctor = $this->f->doctor('Dr. Main', $mainUser);
    $this->patient = $this->f->patient('Rahul', 'Sharma');
    $this->actingAs($mainUser);
    $this->encounter = app(EncounterWorkspace::class)->startForPatient($this->patient, $this->f->branch, $mainDoctor);

    $this->east = Branch::factory()->create(['clinic_id' => $this->f->clinic->id, 'name' => 'East', 'status' => BranchStatus::Active]);
    $this->eastUser = User::factory()->create(['user_type' => UserType::ClinicUser]);
    $membership = ClinicMembership::factory()->create([
        'clinic_id' => $this->f->clinic->id, 'user_id' => $this->eastUser->id, 'has_all_branches_access' => false, 'status' => ClinicMembershipStatus::Active,
    ]);
    $membership->assignBranch($this->east);
    app(ClinicRoleAssignmentService::class)->syncRoles($this->eastUser, [ClinicRole::Doctor->value], [$this->east]);
    $this->eastDoctor = $this->f->doctor('Dr. East', $this->eastUser);
    $this->asEast = fn () => $this->actingAs($this->eastUser)->withSession([ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID => $membership->id, ClinicContext::SESSION_BRANCH_ID => $this->east->id]);
    $this->attendEast = fn () => app(ReceptionCheckIn::class)->checkIn($this->f->clinic, $this->east, $this->patient->fresh(), doctor: $this->eastDoctor);
});

test('another branch\'s consultation stays blocked while the patient is not attending here today', function () {
    ($this->asEast)()->get("/clinic/consultations/{$this->encounter->id}")->assertForbidden();

    expect(PatientClinicalAccessAudit::query()->withoutGlobalScopes()->count())->toBe(0);
});

test('while the patient attends here today, the doctor reads the other branch\'s notes and each opening is audited', function () {
    $visit = ($this->attendEast)();

    ($this->asEast)()->get("/clinic/consultations/{$this->encounter->id}")->assertOk();
    ($this->asEast)()->getJson("/clinic/patients/{$this->patient->id}/360/encounters")
        ->assertOk()->assertJsonPath('data.0.id', $this->encounter->id);

    $audits = PatientClinicalAccessAudit::query()->withoutGlobalScopes()->orderBy('id')->get();
    expect($audits->pluck('resource_type')->all())->toBe(['encounter', 'encounter_summary'])
        ->and($audits->first()->source_branch_id)->toBe($this->f->branch->id)
        ->and($audits->first()->accessing_branch_id)->toBe($this->east->id)
        ->and($audits->first()->actor_user_id)->toBe($this->eastUser->id)
        ->and($audits->first()->attendance_visit_id)->toBe($visit->patient_visit_id);
});

test('the next day the notes are blocked again', function () {
    ($this->attendEast)();
    $this->travel(1)->days();

    ($this->asEast)()->get("/clinic/consultations/{$this->encounter->id}")->assertForbidden();
});
