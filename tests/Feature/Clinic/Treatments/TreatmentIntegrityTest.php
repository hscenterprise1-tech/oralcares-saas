<?php

use App\BranchStatus;
use App\ClinicMembershipStatus;
use App\ClinicRole;
use App\Models\Branch;
use App\Models\CatalogTreatment;
use App\Models\CatalogTreatmentVersion;
use App\Models\ClinicMembership;
use App\Models\PatientTreatment;
use App\Models\User;
use App\Services\Billing\EncounterCheckout;
use App\Services\Clinical\EncounterWorkspace;
use App\Services\ClinicContext;
use App\Services\ClinicRoleAssignmentService;
use App\Services\Treatment\PatientTreatments;
use App\Services\Treatment\TreatmentCatalog;
use App\Services\Treatment\TreatmentCheckout;
use App\UserType;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Tests\Support\TreatmentFixture;

/**
 * Integrity rules not covered elsewhere: every pinned term of a started
 * treatment is frozen, work is refused for the wrong patient, and only the
 * current published version of this clinic can be started.
 * (Published versions, `charged_amount`, wrong branch/specialty/clinic and
 * inactive treatments/specialties: TreatmentCatalogTest, PatientTreatmentTest.)
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-03 10:00', 'Asia/Kolkata'));
    $this->t = TreatmentFixture::make();
    $this->f = $this->t->f;
    $this->patient = $this->f->patient('Rajesh', 'Patel');
    $this->treatments = app(PatientTreatments::class);
    $this->tooth = ['targets' => ['tooth' => [['tooth_number' => 16]]]];
});

test('once started, no pinned price or term of the treatment or its stages can change', function () {
    $owner = $this->t->actAsOwner();
    $perStage = $this->treatments->start($this->patient, $this->t->rct('per_stage'), $this->f->doctorA, $this->f->branch, $owner, $this->tooth);
    $perProcedure = $this->treatments->start($this->patient, $this->t->rct('per_procedure'), $this->f->doctorA, $this->f->branch, $owner, $this->tooth);
    $stage = $perStage->stages->first();

    expect(fn () => $stage->update(['agreed_amount' => 1]))->toThrow(LogicException::class)
        ->and(fn () => $stage->update(['price_version_id' => null]))->toThrow(LogicException::class)
        ->and(fn () => $perProcedure->update(['price_snapshot' => []]))->toThrow(LogicException::class)
        ->and(fn () => $perProcedure->update(['charging_model' => 'upfront']))->toThrow(LogicException::class)
        ->and(fn () => $perProcedure->update(['catalog_treatment_version_id' => $perStage->catalog_treatment_version_id]))->toThrow(LogicException::class)
        ->and(fn () => $perProcedure->update(['patient_id' => $this->f->patient('Other', 'One')->id]))->toThrow(LogicException::class)
        ->and($stage->fresh()->agreed_amount)->toBe('3000.00');
});

test('work for one patient\'s treatment cannot be recorded in another patient\'s consultation', function () {
    $owner = $this->t->actAsOwner();
    $treatment = $this->treatments->start($this->patient, $this->t->rct('per_procedure'), $this->f->doctorA, $this->f->branch, $owner, $this->tooth);
    $someoneElse = $this->f->patient('Meena', 'Shah');
    $theirVisit = app(EncounterWorkspace::class)->startForPatient($someoneElse, $this->f->branch, $this->f->doctorA);

    expect(fn () => $this->treatments->recordProcedure($treatment->stages->first(), $this->t->procedures['ACC'], $theirVisit, $this->f->doctorA, $owner))
        ->toThrow(InvalidArgumentException::class, 'different patient')
        ->and(fn () => $this->treatments->start($this->patient, $this->t->rct(), $this->f->doctorA, $this->f->branch, $owner, ['encounter' => $theirVisit, ...$this->tooth]))
        ->toThrow(InvalidArgumentException::class, 'different patient')
        ->and(fn () => $this->treatments->startStage($treatment->stages->first(), $owner, $this->f->doctorA, $theirVisit))->toThrow(InvalidArgumentException::class, 'different patient')
        ->and(fn () => $this->treatments->completeStage($treatment->stages->first(), $owner, $this->f->doctorA, $theirVisit))->toThrow(InvalidArgumentException::class, 'different patient')
        ->and($treatment->stages->first()->fresh())->status->toBe('pending')->encounter_id->toBeNull();

    // Over the API: a stage reached through another patient's treatment is not found.
    [$user, $session] = $this->f->memberWithRole(ClinicRole::Doctor);
    $this->f->doctorA->update(['user_id' => $user->id]);
    $theirs = $this->treatments->start($someoneElse, $this->t->rct(), $this->f->doctorA, $this->f->branch, $this->t->actAsOwner(), $this->tooth);
    $this->actingAs($user)->withSession($session)
        ->postJson("/clinic/treatments/{$treatment->id}/stages/{$theirs->stages->first()->id}/start")->assertNotFound();
});

test('only the current published version of an active treatment can be started — never a draft', function () {
    $owner = $this->t->actAsOwner();
    $published = $this->t->rct();
    $draft = app(TreatmentCatalog::class)->newVersionFrom($published, $owner);

    expect($draft->status)->toBe(CatalogTreatmentVersion::DRAFT)
        ->and(fn () => $this->treatments->start($this->patient, $draft, $this->f->doctorA, $this->f->branch, $owner, $this->tooth))->toThrow(DomainException::class, 'current published version');

    app(TreatmentCatalog::class)->setActive(CatalogTreatment::sole(), false, $owner);
    [$user, $session] = $this->f->memberWithRole(ClinicRole::Doctor);
    $this->f->doctorA->update(['user_id' => $user->id]);
    $this->actingAs($user)->withSession($session)
        ->postJson("/clinic/patients/{$this->patient->id}/treatments", ['version_id' => $published->id, ...$this->tooth])
        ->assertUnprocessable()->assertJsonPath('message', 'This treatment is no longer offered.');
    expect(PatientTreatment::count())->toBe(0);
});

test('a catalog version of another clinic cannot be started here', function () {
    $other = TreatmentFixture::make('other-clinic');
    $theirVersion = $other->rct();
    [$user, $session] = $this->f->memberWithRole(ClinicRole::Doctor);
    $this->f->doctorA->update(['user_id' => $user->id]);

    $this->actingAs($user)->withSession($session)
        ->postJson("/clinic/patients/{$this->patient->id}/treatments", ['version_id' => $theirVersion->id, ...$this->tooth])->assertNotFound();
    expect(fn () => $this->treatments->start($this->patient, $theirVersion, $this->f->doctorA, $this->f->branch, $this->t->actAsOwner(), $this->tooth))
        ->toThrow(AuthorizationException::class)
        ->and(PatientTreatment::count())->toBe(0);
});

test('a stage moves under the patient\'s own consultation, and its charge stays with that patient and treatment', function () {
    $owner = $this->t->actAsOwner();
    $treatment = $this->treatments->start($this->patient, $this->t->rct('per_stage'), $this->f->doctorA, $this->f->branch, $owner, $this->tooth);
    $workspace = app(EncounterWorkspace::class);
    $ours = $workspace->startForPatient($this->patient, $this->f->branch, $this->f->doctorA);
    $someoneElse = $this->f->patient('Meena', 'Shah');
    $theirs = $workspace->startForPatient($someoneElse, $this->f->branch, $this->f->doctorA);
    $stage = $treatment->stages->first();

    $this->treatments->startStage($stage, $owner, $this->f->doctorA, $ours);
    expect($stage->fresh())->status->toBe('in_progress')->encounter_id->toBe($ours->id);
    $this->treatments->completeStage($stage->fresh(), $owner, $this->f->doctorA, $ours);
    expect($stage->fresh())->status->toBe('completed')->encounter_id->toBe($ours->id);

    $checkout = app(TreatmentCheckout::class);
    expect(collect($checkout->dueCharges($this->patient, $ours))->sole())
        ->toMatchArray(['key' => "stage:{$stage->id}", 'patient_treatment_id' => $treatment->id, 'patient_treatment_stage_id' => $stage->id, 'unit_price' => 3000.0])
        ->and($checkout->dueCharges($someoneElse, $theirs))->toBe([])
        ->and($checkout->dueCharges($someoneElse))->toBe([])
        ->and(collect(app(EncounterCheckout::class)->lines($theirs))->pluck('patient_treatment_id')->filter()->all())->toBe([]);
});

test('a consultation of another clinic, or at a branch the member does not work at, is refused for stage work', function () {
    $owner = $this->t->actAsOwner();
    $east = Branch::factory()->create(['clinic_id' => $this->f->clinic->id, 'name' => 'East', 'status' => BranchStatus::Active]);
    $treatment = $this->treatments->start($this->patient, $this->t->rct('per_stage'), $this->f->doctorA, $east, $owner, $this->tooth);
    $atMain = app(EncounterWorkspace::class)->startForPatient($this->patient, $this->f->branch, $this->f->doctorA);
    $atEast = app(EncounterWorkspace::class)->startForPatient($this->patient, $east, $this->f->doctorA);

    // Another clinic's consultation: refused in the service, not found over the API.
    $other = TreatmentFixture::make('other-clinic');
    $other->actAsOwner();
    $elsewhere = app(EncounterWorkspace::class)->startForPatient($other->f->patient('Asha', 'Rao'), $other->f->branch, $other->f->doctorA);
    $owner = $this->t->actAsOwner();
    expect(fn () => $this->treatments->startStage($treatment->stages->first(), $owner, $this->f->doctorA, $elsewhere))->toThrow(InvalidArgumentException::class, 'different patient');
    $this->actingAs($this->f->owner)->withSession($this->f->session)
        ->postJson("/clinic/treatments/{$treatment->id}/stages/{$treatment->stages->first()->id}/start", ['encounter_id' => $elsewhere->id])->assertNotFound();

    // A member who works only at East.
    $this->f->memberWithRole(ClinicRole::Doctor);
    $user = User::factory()->create(['user_type' => UserType::ClinicUser]);
    $membership = ClinicMembership::factory()->create(['clinic_id' => $this->f->clinic->id, 'user_id' => $user->id, 'is_primary_owner' => false, 'has_all_branches_access' => false, 'status' => ClinicMembershipStatus::Active]);
    $membership->assignBranch($east);
    app(ClinicRoleAssignmentService::class)->syncRoles($user, [ClinicRole::Doctor->value], [$east]);
    $eastDoctor = $this->t->actAs($user, [ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID => $membership->id, ClinicContext::SESSION_BRANCH_ID => $east->id]);
    $stage = $treatment->stages->first();

    expect(fn () => $this->treatments->startStage($stage, $eastDoctor, $this->f->doctorA, $atMain))->toThrow(AuthorizationException::class, 'branch of this consultation')
        ->and($stage->fresh())->status->toBe('pending')->encounter_id->toBeNull();
    $this->treatments->startStage($stage, $eastDoctor, $this->f->doctorA, $atEast);
    expect(fn () => $this->treatments->completeStage($stage->fresh(), $eastDoctor, $this->f->doctorA, $atMain))->toThrow(AuthorizationException::class, 'branch of this consultation');
    $this->treatments->completeStage($stage->fresh(), $eastDoctor, $this->f->doctorA, $atEast);
    expect($stage->fresh())->status->toBe('completed')->encounter_id->toBe($atEast->id);

    // A treatment at a branch the member does not work at cannot be touched at all.
    $mainTreatment = $this->treatments->start($this->patient, $this->t->rct('per_stage'), $this->f->doctorA, $this->f->branch, $this->t->actAsOwner(), $this->tooth);
    $this->t->actAs($user, [ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID => $membership->id, ClinicContext::SESSION_BRANCH_ID => $east->id]);
    expect(fn () => $this->treatments->startStage($mainTreatment->stages->first(), $eastDoctor, $this->f->doctorA, $atEast))->toThrow(AuthorizationException::class);
});
