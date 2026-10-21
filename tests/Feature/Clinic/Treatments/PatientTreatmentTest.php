<?php

use App\BranchStatus;
use App\ClinicMembershipStatus;
use App\ClinicRole;
use App\Models\Branch;
use App\Models\CatalogTreatment;
use App\Models\ClinicMembership;
use App\Models\PatientBillingItem;
use App\Models\PatientProcedure;
use App\Models\PatientSpecialty;
use App\Models\PatientTreatment;
use App\Models\PatientTreatmentStage;
use App\Models\PhysioSession;
use App\Models\User;
use App\Services\Billing\EncounterCheckout;
use App\Services\BillingService;
use App\Services\Clinical\EncounterWorkspace;
use App\Services\ClinicContext;
use App\Services\ClinicRoleAssignmentService;
use App\Services\Patient\ClinicSpecialties;
use App\Services\Treatment\CatalogPricing;
use App\Services\Treatment\PatientTreatments;
use App\Services\Treatment\TreatmentCatalog;
use App\UserType;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Tests\Support\ReceptionFixture;
use Tests\Support\TreatmentFixture;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-03 10:00', 'Asia/Kolkata'));
    $this->t = TreatmentFixture::make();
    $this->f = $this->t->f;
    $this->patient = $this->f->patient('Rajesh', 'Patel');
    $this->treatments = app(PatientTreatments::class);
    $this->tooth16 = ['targets' => ['tooth' => [['tooth_number' => 16]]]];
    $this->encounter = fn () => app(EncounterWorkspace::class)->startForPatient($this->patient, $this->f->branch, $this->f->doctorA);
});

test('starting a treatment pins its version and prices; later catalog changes never reach it', function () {
    $v1 = $this->t->rct('upfront', price: 8000);
    $owner = $this->t->actAsOwner();
    $first = $this->treatments->start($this->patient, $v1, $this->f->doctorA, $this->f->branch, $owner, $this->tooth16);

    // The clinic raises the price and changes the configuration.
    $catalog = app(TreatmentCatalog::class);
    $v2 = $catalog->newVersionFrom($v1, $owner);
    app(CatalogPricing::class)->set($this->f->clinic, 'catalog_treatment_version_id', $v2->id, 9000, null, $owner);
    $catalog->publish($v2, $owner);
    $second = $this->treatments->start($this->patient, $v2->fresh(), $this->f->doctorA, $this->f->branch, $owner, $this->tooth16);

    expect($first->fresh())->catalog_treatment_version_id->toBe($v1->id)->agreed_amount->toBe('8000.00')->stages_total->toBe(3)
        ->and($second)->catalog_treatment_version_id->toBe($v2->id)->agreed_amount->toBe('9000.00')
        ->and(fn () => $first->update(['agreed_amount' => 9000]))->toThrow(LogicException::class)
        // An old version can no longer be started.
        ->and(fn () => $this->treatments->start($this->patient, $v1->fresh(), $this->f->doctorA, $this->f->branch, $owner, $this->tooth16))->toThrow(DomainException::class)
        ->and(PatientSpecialty::sole()->source)->toBe('treatment');

    // Deactivating stops new starts, existing ones carry on.
    $catalog->setActive(CatalogTreatment::sole(), false, $owner);
    expect(fn () => $this->treatments->start($this->patient, $v2->fresh(), $this->f->doctorA, $this->f->branch, $owner, $this->tooth16))->toThrow(DomainException::class, 'no longer offered')
        ->and($first->fresh()->status)->toBe(PatientTreatment::IN_PROGRESS);
});

test('stages run in order, optional ones can be skipped, and the treatment completes with the last', function () {
    $owner = $this->t->actAsOwner();
    $treatment = $this->treatments->start($this->patient, $this->t->rct(), $this->f->doctorA, $this->f->branch, $owner, $this->tooth16);
    [$access, $cleaning, $obturation] = $treatment->stages->all();

    expect(fn () => $this->treatments->completeStage($cleaning, $owner, $this->f->doctorA))->toThrow(DomainException::class, 'earlier stages')
        ->and(fn () => $this->treatments->skipStage($access, $owner, $this->f->doctorA))->toThrow(DomainException::class, 'optional');

    $this->treatments->startStage($access, $owner, $this->f->doctorA);
    $this->treatments->completeStage($access, $owner, $this->f->doctorA);
    $this->treatments->completeStage($cleaning, $owner, $this->f->doctorA);
    expect($treatment->fresh())->stages_completed->toBe(2)->status->toBe(PatientTreatment::IN_PROGRESS);

    $this->treatments->completeStage($obturation, $owner, $this->f->doctorA);
    expect($treatment->fresh())->status->toBe(PatientTreatment::COMPLETED)->stages_completed->toBe(3)
        ->and(fn () => $this->treatments->completeStage($obturation->fresh(), $owner, $this->f->doctorA))->toThrow(DomainException::class);
});

test('a treatment that needs a tooth takes valid FDI teeth', function () {
    $owner = $this->t->actAsOwner();
    $version = $this->t->rct();

    expect(fn () => $this->treatments->start($this->patient, $version, $this->f->doctorA, $this->f->branch, $owner))->toThrow(InvalidArgumentException::class, 'Which tooth')
        ->and(fn () => $this->treatments->start($this->patient, $version, $this->f->doctorA, $this->f->branch, $owner, ['targets' => ['tooth' => [['tooth_number' => 19]]]]))->toThrow(InvalidArgumentException::class, 'FDI');

    $treatment = $this->treatments->start($this->patient, $version, $this->f->doctorA, $this->f->branch, $owner, ['targets' => ['tooth' => [['tooth_number' => 16, 'surfaces' => ['O']], ['tooth_number' => 17]]]]);
    expect($treatment->teeth->pluck('tooth_number')->all())->toBe([16, 17])->and($treatment->teeth->first()->surfaces)->toBe(['O']);
});

test('starting needs the permission, an active clinic specialty and a practitioner of it', function () {
    $version = $this->t->rct();

    $reception = $this->t->actAsRole(ClinicRole::Receptionist);
    expect(fn () => $this->treatments->start($this->patient, $version, $this->f->doctorA, $this->f->branch, $reception, $this->tooth16))->toThrow(AuthorizationException::class, 'cannot start');

    $doctor = $this->t->actAsRole(ClinicRole::Doctor);
    expect($this->treatments->start($this->patient, $version, $this->f->doctorA, $this->f->branch, $doctor, $this->tooth16))->toBeInstanceOf(PatientTreatment::class)
        // A physiotherapist is not a practitioner of dentistry.
        ->and(fn () => $this->treatments->start($this->patient, $version, $this->t->physio, $this->f->branch, $doctor, $this->tooth16))->toThrow(AuthorizationException::class, 'not a practitioner');

    // The clinic stops practising dentistry.
    app(ClinicSpecialties::class)->sync($this->f->clinic, ['physiotherapy']);
    expect(fn () => $this->treatments->start($this->patient, $version, $this->f->doctorA, $this->f->branch, $this->t->actAsOwner(), $this->tooth16))->toThrow(AuthorizationException::class, 'does not practise');
});

test('a branch-limited member cannot start or do treatment at another branch, nor for a patient they cannot open', function () {
    $version = $this->t->rct();
    $east = Branch::factory()->create(['clinic_id' => $this->f->clinic->id, 'name' => 'East', 'status' => BranchStatus::Active]);
    $this->f->memberWithRole(ClinicRole::Doctor);
    $user = User::factory()->create(['user_type' => UserType::ClinicUser]);
    $membership = ClinicMembership::factory()->create(['clinic_id' => $this->f->clinic->id, 'user_id' => $user->id, 'is_primary_owner' => false, 'has_all_branches_access' => false, 'status' => ClinicMembershipStatus::Active]);
    $membership->assignBranch($east);
    app(ClinicRoleAssignmentService::class)->syncRoles($user, [ClinicRole::Doctor->value], [$east]);
    $eastDoctor = $this->t->actAs($user, [ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID => $membership->id, ClinicContext::SESSION_BRANCH_ID => $east->id]);

    // The patient was only ever seen at the main branch.
    expect(fn () => $this->treatments->start($this->patient, $version, $this->f->doctorA, $east, $eastDoctor, $this->tooth16))->toThrow(AuthorizationException::class, 'cannot open')
        ->and(fn () => $this->treatments->start($this->patient, $version, $this->f->doctorA, $this->f->branch, $eastDoctor, $this->tooth16))->toThrow(AuthorizationException::class);

    $treatment = $this->treatments->start($this->patient, $version, $this->f->doctorA, $this->f->branch, $this->t->actAsOwner(), $this->tooth16);
    $this->t->actAs($user, [ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID => $membership->id, ClinicContext::SESSION_BRANCH_ID => $east->id]);
    expect(fn () => $this->treatments->startStage($treatment->stages->first(), $user, $this->f->doctorA))->toThrow(AuthorizationException::class);
});

test('another clinic cannot start treatment for, or touch, this clinic\'s patient', function () {
    $version = $this->t->rct();
    $other = ReceptionFixture::make('other-clinic');
    $other->clinic->update(['specialties' => ['dental']]);
    $treatment = $this->treatments->start($this->patient, $version, $this->f->doctorA, $this->f->branch, $this->t->actAsOwner(), $this->tooth16);

    $outsider = $this->t->actAs($other->owner, $other->session);
    expect(fn () => $this->treatments->start($this->patient, $version, $other->doctorA, $other->branch, $outsider, $this->tooth16))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->treatments->completeStage($treatment->stages->first(), $outsider, $other->doctorA))->toThrow(AuthorizationException::class)
        ->and(app(ClinicContext::class)->runForClinic($other->clinic, null, fn () => PatientTreatment::query()->count()))->toBe(0);
});

test('a procedure done for a per-procedure treatment is charged its pinned price, fixed for good', function () {
    $owner = $this->t->actAsOwner();
    $treatment = $this->treatments->start($this->patient, $this->t->rct('per_procedure'), $this->f->doctorA, $this->f->branch, $owner, $this->tooth16);
    app(CatalogPricing::class)->set($this->f->clinic, 'clinical_procedure_id', $this->t->procedures['ACC']->id, 9999, null, $owner);
    $encounter = ($this->encounter)();
    $access = $treatment->stages->first();

    expect(fn () => $this->treatments->recordProcedure($access, $this->t->procedures['OBT'], $encounter, $this->f->doctorA, $owner))->toThrow(DomainException::class, 'not part of this stage');

    $done = $this->treatments->recordProcedure($access, $this->t->procedures['ACC'], $encounter, $this->f->doctorA, $owner);
    expect($done)->charged_amount->toBe('2000.00')->patient_treatment_id->toBe($treatment->id)->patient_treatment_stage_id->toBe($access->id)
        ->and($access->fresh()->status)->toBe(PatientTreatmentStage::IN_PROGRESS)
        ->and(fn () => $done->fresh()->update(['charged_amount' => 1]))->toThrow(LogicException::class)
        ->and(fn () => app(EncounterWorkspace::class)->removeProcedure($encounter, $done))->toThrow(DomainException::class);
});

test('checkout bills each charging model at its pinned amount and links every line to the treatment', function () {
    $owner = $this->t->actAsOwner();
    $encounter = ($this->encounter)();
    $upfront = $this->treatments->start($this->patient, $this->t->rct('upfront', price: 8000), $this->f->doctorA, $this->f->branch, $owner, ['encounter' => $encounter, ...$this->tooth16]);
    // Work inside an upfront treatment is not billed again on its own.
    $this->treatments->recordProcedure($upfront->stages->first(), $this->t->procedures['ACC'], $encounter, $this->f->doctorA, $owner);

    $perStage = $this->treatments->start($this->patient, $this->t->rct('per_stage'), $this->f->doctorA, $this->f->branch, $owner, ['encounter' => $encounter, 'targets' => ['tooth' => [['tooth_number' => 26]]]]);
    $this->treatments->completeStage($perStage->stages->first(), $owner, $this->f->doctorA, $encounter);
    app(CatalogPricing::class)->set($this->f->clinic, 'catalog_stage_id', $perStage->stages->first()->catalog_stage_id, 7777, null, $owner);

    $checkout = app(EncounterCheckout::class);
    $lines = collect($checkout->lines($encounter))->keyBy('key');
    expect($lines->keys()->all())->toEqualCanonicalizing(["treatment:{$upfront->id}", 'stage:'.$perStage->stages->first()->id])
        ->and($lines["treatment:{$upfront->id}"]['unit_price'])->toBe(8000.0)
        ->and($lines['stage:'.$perStage->stages->first()->id]['unit_price'])->toBe(3000.0);

    app(EncounterWorkspace::class)->autosave($encounter, ['chief_complaint' => 'Pain in 16 and 26'], $encounter->fresh()->lock_version);
    app(EncounterWorkspace::class)->finalize($encounter, $this->f->doctorA);
    $bill = $checkout->bill($encounter->fresh(), $lines->map(fn () => null)->all(), $this->f->doctorA);

    $items = PatientBillingItem::query()->where('patient_billing_id', $bill->id)->get()->keyBy('patient_treatment_id');
    expect($items[$upfront->id])->unit_price->toBe('8000.00')->price_version_id->toBe($upfront->price_version_id)
        ->and($items[$perStage->id])->unit_price->toBe('3000.00')->patient_treatment_stage_id->toBe($perStage->stages->first()->id)
        ->and($checkout->lines($encounter->fresh()))->toBe([]);
});

test('a bill line cannot charge for another patient\'s treatment', function () {
    $owner = $this->t->actAsOwner();
    $treatment = $this->treatments->start($this->patient, $this->t->rct(), $this->f->doctorA, $this->f->branch, $owner, $this->tooth16);
    $someoneElse = $this->f->patient('Other', 'Person');

    expect(fn () => app(BillingService::class)->createBill($this->f->clinic, $someoneElse, $this->f->branch, [
        ['description' => 'RCT', 'unit_price' => 8000, 'patient_treatment_id' => $treatment->id],
    ]))->toThrow(InvalidArgumentException::class, 'own treatment');
});

test('a session-based treatment counts sessions, closes its stage when they are done, and its SOAP notes link to it', function () {
    $owner = $this->t->actAsOwner();
    $version = $this->t->backRehab();
    $therapist = $this->t->physio;
    $treatment = $this->treatments->start($this->patient, $version, $therapist, $this->f->branch, $owner);
    $stage = $treatment->stages->first();

    expect($treatment)->sessions_planned->toBe(10)->valid_until->toDateString()->toBe(now()->addDays(90)->toDateString());

    $encounter = app(EncounterWorkspace::class)->startForPatient($this->patient, $this->f->branch, $therapist);
    $session = $this->treatments->recordProcedure($stage, $this->t->procedures['PHY'], $encounter, $therapist, $owner);
    $soap = PhysioSession::query()->withoutGlobalScopes()->create(['clinic_id' => $this->f->clinic->id, 'patient_id' => $this->patient->id, 'encounter_id' => $encounter->id, 'therapist_id' => $therapist->id, 'session_number' => 1]);
    $this->treatments->attachPhysioSession($soap, $session, $owner, $therapist);

    expect($treatment->fresh()->sessions_completed)->toBe(1)
        ->and($session->charged_amount)->toBe('800.00')
        ->and($soap->fresh())->patient_treatment_id->toBe($treatment->id)->patient_procedure_id->toBe($session->id);

    // Nine more sessions finish the package.
    foreach (range(2, 10) as $n) {
        $this->travel(2)->days();
        $visit = app(EncounterWorkspace::class)->startForPatient($this->patient, $this->f->branch, $therapist);
        $this->treatments->recordProcedure($stage->fresh(), $this->t->procedures['PHY'], $visit, $therapist, $owner);
    }
    expect($stage->fresh()->status)->toBe(PatientTreatmentStage::COMPLETED)
        ->and($treatment->fresh())->status->toBe(PatientTreatment::COMPLETED)->sessions_completed->toBe(10)
        ->and(PatientProcedure::query()->where('patient_treatment_id', $treatment->id)->sum('charged_amount'))->toEqual(8000);
});

test('a paused treatment takes no work until resumed, and a cancelled one none at all', function () {
    $owner = $this->t->actAsOwner();
    $treatment = $this->treatments->start($this->patient, $this->t->rct(), $this->f->doctorA, $this->f->branch, $owner, $this->tooth16);

    $this->treatments->pause($treatment, $owner, $this->f->doctorA, 'Patient travelling');
    expect(fn () => $this->treatments->startStage($treatment->stages->first(), $owner, $this->f->doctorA))->toThrow(DomainException::class, 'paused');

    $this->treatments->resume($treatment, $owner, $this->f->doctorA);
    $this->treatments->cancel($treatment, $owner, $this->f->doctorA, 'Extraction chosen instead');
    expect($treatment->fresh())->status->toBe(PatientTreatment::CANCELLED)->status_reason->toBe('Extraction chosen instead')
        ->and(fn () => $this->treatments->startStage($treatment->stages->first(), $owner, $this->f->doctorA))->toThrow(DomainException::class, 'closed');
});
