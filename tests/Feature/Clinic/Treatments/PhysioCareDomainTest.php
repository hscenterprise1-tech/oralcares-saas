<?php

use App\BranchStatus;
use App\ClinicMembershipStatus;
use App\ClinicRole;
use App\Models\Branch;
use App\Models\ClinicalDiagnosis;
use App\Models\ClinicMembership;
use App\Models\Notification;
use App\Models\PatientBilling;
use App\Models\PatientBillingPayment;
use App\Models\PatientSessionPackage;
use App\Models\PatientTreatment;
use App\Models\PhysioCarePlan;
use App\Models\PhysioSession;
use App\Models\SecurityAuditLog;
use App\Models\SessionPackageUsage;
use App\Models\User;
use App\Services\Billing\EncounterCheckout;
use App\Services\Clinical\EncounterWorkspace;
use App\Services\ClinicContext;
use App\Services\ClinicRoleAssignmentService;
use App\Services\Physio\PhysioRecord;
use App\Services\Physio\SessionPackages;
use App\Services\Treatment\LegacyTreatmentMirror;
use App\Services\Treatment\PatientTreatments;
use App\UserType;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Tests\Support\TreatmentFixture;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-06 10:00', 'Asia/Kolkata'));
    $this->t = TreatmentFixture::make();
    $this->f = $this->t->f;
    [$this->therapistUser, $this->therapistSession] = $this->f->memberWithRole(ClinicRole::Doctor);
    $this->therapist = $this->f->doctor('Ms. Rehab', $this->therapistUser);
    $this->therapist->practitionerProfile()->withoutGlobalScopes()->update(['specialty' => 'Physiotherapist']);
    $this->asTherapist = fn () => $this->t->actAs($this->therapistUser, $this->therapistSession);
    ($this->asTherapist)();

    $this->physio = app(PhysioRecord::class);
    $this->packages = app(SessionPackages::class);
    $this->treatments = app(PatientTreatments::class);
    $this->patient = $this->f->patient('Rahul', 'Shah');
    $this->visit = fn () => app(EncounterWorkspace::class)->startForPatient($this->patient, $this->f->branch, $this->therapist, null, 'physiotherapy');
    $this->start = fn (string $title, array $options = []) => $this->treatments->startAdHoc($this->patient, 'physiotherapy', $title, $this->therapist, $this->f->branch, $this->therapistUser, $options);
    $this->sell = fn (PatientTreatment $treatment, int $sessions, array $extra = []) => $this->packages->sell($treatment, $this->f->branch, ['name' => "{$treatment->title} {$sessions}-pack", 'sessions_total' => $sessions, ...$extra], $this->therapist);
    $this->session = function (PatientTreatment $treatment, $encounter, ?PatientSessionPackage $package = null, array $data = []) {
        $session = $this->physio->openSession($treatment, $encounter, $this->therapist, $this->therapistUser);
        if ($data !== []) {
            $session = $this->physio->saveSession($session, $data, $this->therapist, $this->therapistUser);
        }

        return $this->physio->completeSession($session, $package?->id, $this->therapist, $this->therapistUser);
    };
});

// ---------------------------------------------------------------- Concurrent treatments

test('a patient holds Knee, Back and Shoulder treatments at once, each with its own plan', function () {
    $knee = ($this->start)('Knee Rehabilitation', ['sessions_planned' => 12]);
    $back = ($this->start)('Lower Back Rehabilitation', ['sessions_planned' => 10]);
    $shoulder = ($this->start)('Shoulder Rehabilitation');

    foreach ([$knee, $back, $shoulder] as $treatment) {
        $this->physio->startPlan($treatment, ['title' => "{$treatment->title} plan"], [['name' => 'Exercise for '.$treatment->title]], $this->therapist, $this->therapistUser);
    }

    expect(PatientTreatment::query()->where('patient_id', $this->patient->id)->where('status', 'in_progress')->count())->toBe(3)
        ->and(PhysioCarePlan::query()->where('status', 'active')->pluck('patient_treatment_id')->sort()->values()->all())->toBe([$knee->id, $back->id, $shoulder->id])
        ->and($knee->source)->toBe(PatientTreatment::SOURCE_ADHOC);

    expect(fn () => $this->physio->startPlan($knee, ['title' => 'Again'], [], $this->therapist, $this->therapistUser))
        ->toThrow(DomainException::class, 'already has an active plan');
});

// ---------------------------------------------------------------- Plan revisions

test('a revision supersedes the plan without touching it; one active revision per treatment', function () {
    $knee = ($this->start)('Knee Rehabilitation');
    $v1 = $this->physio->startPlan($knee, ['title' => 'Knee plan', 'frequency' => 'Daily'], [['name' => 'Pelvic tilt', 'sets' => 3, 'reps' => 10], ['name' => 'Cat-cow'], ['name' => 'Hamstring stretch']], $this->therapist, $this->therapistUser);
    $before = $v1->fresh()->only(['title', 'goals', 'frequency', 'planned_sessions']);

    $v2 = $this->physio->revisePlan($v1, [], [['name' => 'Pelvic tilt', 'sets' => 3, 'reps' => 15], ['name' => 'Cat-cow'], ['name' => 'Wall squat']], $this->therapist, $this->therapistUser);

    expect($v1->fresh())->status->toBe(PhysioCarePlan::SUPERSEDED)
        ->and($v1->fresh()->only(['title', 'goals', 'frequency', 'planned_sessions']))->toBe($before)
        ->and($v1->exercises()->pluck('name')->all())->toBe(['Pelvic tilt', 'Cat-cow', 'Hamstring stretch'])
        ->and($v1->exercises()->first()->reps)->toBe(10)
        ->and($v2)->revision->toBe(2)->supersedes_id->toBe($v1->id)->status->toBe(PhysioCarePlan::ACTIVE)->frequency->toBe('Daily')
        ->and($v2->exercises()->pluck('name')->all())->toBe(['Pelvic tilt', 'Cat-cow', 'Wall squat']);

    // History cannot be rewritten, and the database refuses a second active revision.
    expect(fn () => $v1->exercises()->first()->update(['reps' => 99]))->toThrow(LogicException::class)
        ->and(fn () => PhysioCarePlan::query()->withoutGlobalScopes()->create(['clinic_id' => $knee->clinic_id, 'patient_id' => $knee->patient_id, 'patient_treatment_id' => $knee->id, 'title' => 'Sneaky', 'status' => 'active', 'starts_on' => now()->toDateString()]))
        ->toThrow(QueryException::class);
});

// ---------------------------------------------------------------- Sessions

test('one visit holds a Knee session and a Back session, numbered within each treatment', function () {
    $knee = ($this->start)('Knee Rehabilitation');
    $back = ($this->start)('Lower Back Rehabilitation');
    $first = ($this->visit)();
    ($this->session)($knee, $first);
    $second = ($this->visit)();
    ($this->session)($knee, $second);
    ($this->session)($back, $second);

    $inVisit = PhysioSession::query()->where('encounter_id', $second->id)->orderBy('id')->get();
    expect($inVisit->pluck('patient_treatment_id')->all())->toBe([$knee->id, $back->id])
        ->and($inVisit->pluck('session_number')->all())->toBe([2, 1])
        ->and($knee->fresh()->sessions_completed)->toBe(2)
        ->and($back->fresh()->sessions_completed)->toBe(1);

    // The same treatment twice in one visit is the same session, and the database refuses a duplicate.
    expect($this->physio->openSession($knee, $second, $this->therapist, $this->therapistUser)->id)->toBe($inVisit[0]->id)
        ->and(fn () => PhysioSession::query()->withoutGlobalScopes()->create(['clinic_id' => $knee->clinic_id, 'patient_id' => $knee->patient_id, 'encounter_id' => $second->id, 'patient_treatment_id' => $knee->id, 'status' => 'draft']))
        ->toThrow(QueryException::class);
});

test('a draft consumes nothing; completion consumes exactly once, retried or not; a failed completion leaves no trace', function () {
    $knee = ($this->start)('Knee Rehabilitation');
    $package = ($this->sell)($knee, 2);
    $visit = ($this->visit)();

    $draft = $this->physio->openSession($knee, $visit, $this->therapist, $this->therapistUser);
    $this->physio->saveSession($draft, ['subjective' => 'Stiff in the morning', 'pain_before' => 6], $this->therapist, $this->therapistUser);
    expect($this->packages->balance($package))->toBe(2)->and(SessionPackageUsage::count())->toBe(0);

    $this->physio->completeSession($draft, $package->id, $this->therapist, $this->therapistUser);
    $this->physio->completeSession($draft, $package->id, $this->therapist, $this->therapistUser);
    expect($this->packages->balance($package))->toBe(1)->and(SessionPackageUsage::count())->toBe(1)
        ->and($package->fresh()->sessions_used)->toBe(1)
        ->and($draft->fresh())->status->toBe(PhysioSession::COMPLETED)->session_number->toBe(1)->package_id->toBe($package->id);

    // A completion that fails (package refused) leaves the session a draft and the ledger untouched.
    $back = ($this->start)('Lower Back Rehabilitation');
    $backDraft = $this->physio->openSession($back, $visit, $this->therapist, $this->therapistUser);
    expect(fn () => $this->physio->completeSession($backDraft, $package->id, $this->therapist, $this->therapistUser))->toThrow(InvalidArgumentException::class);
    expect($backDraft->fresh())->status->toBe(PhysioSession::DRAFT)->session_number->toBeNull()
        ->and(SessionPackageUsage::count())->toBe(1)
        ->and($back->fresh()->sessions_completed)->toBe(0);
});

// ---------------------------------------------------------------- Package isolation

test('a Knee session cannot consume the Back package, nor a Back session the Knee package', function () {
    $knee = ($this->start)('Knee Rehabilitation');
    $back = ($this->start)('Lower Back Rehabilitation');
    $kneePack = ($this->sell)($knee, 12);
    $backPack = ($this->sell)($back, 10);
    $visit = ($this->visit)();

    expect(fn () => ($this->session)($knee, $visit, $backPack))->toThrow(InvalidArgumentException::class, 'different treatment')
        ->and(fn () => ($this->session)($back, $visit, $kneePack))->toThrow(InvalidArgumentException::class, 'different treatment');

    ($this->session)($knee, $visit, $kneePack);
    ($this->session)($back, $visit, $backPack);
    expect($this->packages->balance($kneePack))->toBe(11)->and($this->packages->balance($backPack))->toBe(9)
        ->and($this->packages->eligible($knee)->pluck('package.id')->all())->toBe([$kneePack->id])
        ->and($this->packages->eligible($back)->pluck('package.id')->all())->toBe([$backPack->id]);
});

test('another clinic\'s package, an expired one and an exhausted one are refused', function () {
    $knee = ($this->start)('Knee Rehabilitation');
    $expired = ($this->sell)($knee, 5, ['valid_until' => '2026-10-01']);
    $exhausted = ($this->sell)($knee, 1);
    $other = TreatmentFixture::make('other-clinic');
    $other->actAsOwner();
    $theirTreatment = app(PatientTreatments::class)->startAdHoc($other->f->patient(), 'physiotherapy', 'Their knee', $other->physio, $other->f->branch, $other->f->owner);
    $theirs = app(SessionPackages::class)->sell($theirTreatment, $other->f->branch, ['name' => 'Theirs', 'sessions_total' => 5], $other->physio);
    ($this->asTherapist)();

    ($this->session)($knee, ($this->visit)(), $exhausted);
    expect(fn () => ($this->session)($knee, ($this->visit)(), $theirs))->toThrow(InvalidArgumentException::class, 'not this patient')
        ->and(fn () => ($this->session)($knee, ($this->visit)(), $expired))->toThrow(DomainException::class, 'expired')
        ->and(fn () => ($this->session)($knee, ($this->visit)(), $exhausted))->toThrow(DomainException::class, 'no sessions left')
        ->and($this->packages->eligible($knee))->toHaveCount(0);
});

test('with several valid packages nothing is chosen for the clinician, and renewal keeps the old package and its history', function () {
    $knee = ($this->start)('Knee Rehabilitation');
    $first = ($this->sell)($knee, 2, ['valid_until' => '2026-11-30']);
    $second = ($this->sell)($knee, 10, ['valid_until' => '2026-12-31']);

    // Completing without naming a package consumes from none.
    ($this->session)($knee, ($this->visit)());
    expect($this->packages->balance($first))->toBe(2)->and($this->packages->balance($second))->toBe(10)
        ->and($this->packages->eligible($knee)->pluck('package.id')->all())->toBe([$first->id, $second->id]);

    ($this->session)($knee, ($this->visit)(), $first);
    ($this->session)($knee, ($this->visit)(), $first);
    $renewal = ($this->sell)($knee, 10, ['renews_package_id' => $first->id]);

    expect($first->fresh())->status->toBe(PatientSessionPackage::EXHAUSTED)->sessions_used->toBe(2)
        ->and(SessionPackageUsage::query()->where('package_id', $first->id)->count())->toBe(2)
        ->and($renewal)->renews_package_id->toBe($first->id)->patient_treatment_id->toBe($knee->id)
        ->and(fn () => ($this->sell)(($this->start)('Back'), 5, ['renews_package_id' => $first->id]))->toThrow(InvalidArgumentException::class, 'same treatment');
});

// ---------------------------------------------------------------- Ledger

test('the balance is the ledger: consume, reversal and audited adjustment', function () {
    $knee = ($this->start)('Knee Rehabilitation');
    $package = ($this->sell)($knee, 12);
    $one = ($this->session)($knee, ($this->visit)(), $package);
    ($this->session)($knee, ($this->visit)(), $package);

    $this->physio->reverseSession($one, 'Entered against the wrong patient', $this->therapist, $this->therapistUser);
    $owner = $this->t->actAsOwner();
    $this->packages->adjust($package, 2, 'Goodwill: two sessions lost to a power cut', $owner);

    $entries = SessionPackageUsage::query()->where('package_id', $package->id)->orderBy('id')->get(['entry_type', 'quantity']);
    expect($entries->map(fn ($e) => [$e->entry_type, $e->quantity])->all())->toBe([['consume', -1], ['consume', -1], ['reversal', 1], ['adjustment', 2]])
        ->and($this->packages->balance($package))->toBe(12 - 2 + 1 + 2)
        ->and($package->fresh())->sessions_used->toBe(1)->sessions_adjusted->toBe(2)
        ->and($package->fresh()->remaining())->toBe(13)
        ->and(SecurityAuditLog::where('event', 'package.adjusted')->sole()->context)->toMatchArray(['quantity' => 2, 'remaining' => 13])
        ->and(SecurityAuditLog::where('event', 'physio.session.reversed')->exists())->toBeTrue()
        ->and($one->fresh()->status)->toBe(PhysioSession::REVERSED);

    // The cache is not writable through the model, and adjusting needs billing rights.
    $package->fill(['sessions_used' => 0])->save();
    expect($package->fresh()->sessions_used)->toBe(1);
    ($this->asTherapist)();
    expect(fn () => $this->packages->adjust($package, 1, 'I want more', $this->therapistUser))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->packages->adjust($package->fresh(), -20, 'Too much', $this->t->actAsOwner()))->toThrow(DomainException::class, 'below zero');
});

// ---------------------------------------------------------------- Prescribed vs performed

test('a session records performed, skipped and ad-hoc exercises against the plan, with names kept as they were', function () {
    $knee = ($this->start)('Knee Rehabilitation');
    $plan = $this->physio->startPlan($knee, ['title' => 'Knee plan'], [['name' => 'Pelvic tilt'], ['name' => 'Cat-cow'], ['name' => 'Hamstring stretch']], $this->therapist, $this->therapistUser);
    [$tilt, $catCow, $hamstring] = $plan->exercises()->get()->all();

    $session = ($this->session)($knee, ($this->visit)(), null, ['exercises' => [
        ['physio_exercise_id' => $tilt->id, 'status' => 'performed', 'sets' => 3, 'reps' => 10],
        ['physio_exercise_id' => $catCow->id, 'status' => 'performed', 'sets' => 2, 'reps' => 10, 'intensity' => 'gentle'],
        ['physio_exercise_id' => $hamstring->id, 'status' => 'skipped', 'notes' => 'Pain on stretch'],
        ['name' => 'Ice pack', 'status' => 'performed', 'duration_seconds' => 600],
    ]]);

    $rows = $session->performedExercises()->get();
    expect($rows->map(fn ($r) => [$r->name, $r->status, $r->physio_exercise_id])->all())->toBe([
        ['Pelvic tilt', 'performed', $tilt->id], ['Cat-cow', 'performed', $catCow->id], ['Hamstring stretch', 'skipped', $hamstring->id], ['Ice pack', 'performed', null],
    ])->and($rows[2]->sets)->toBeNull();

    // Revising the plan, and renaming in the new revision, leave the session exactly as recorded.
    $v2 = $this->physio->revisePlan($plan, [], [['name' => 'Posterior pelvic tilt'], ['name' => 'Cat-cow']], $this->therapist, $this->therapistUser);
    $v2->exercises()->first()->update(['name' => 'Supine pelvic tilt']);
    expect($session->performedExercises()->pluck('name')->all())->toBe(['Pelvic tilt', 'Cat-cow', 'Hamstring stretch', 'Ice pack'])
        ->and($plan->exercises()->pluck('name')->all())->toBe(['Pelvic tilt', 'Cat-cow', 'Hamstring stretch']);

    // Another plan's exercise, and edits after completion, are refused.
    $draft = $this->physio->openSession($knee, ($this->visit)(), $this->therapist, $this->therapistUser);
    expect(fn () => $this->physio->saveSession($draft, ['exercises' => [['physio_exercise_id' => $tilt->id]]], $this->therapist, $this->therapistUser))->toThrow(InvalidArgumentException::class, 'not on this session')
        ->and(fn () => $this->physio->saveSession($session, ['subjective' => 'Changed later'], $this->therapist, $this->therapistUser))->toThrow(DomainException::class, 'fixed');
});

// ---------------------------------------------------------------- Diagnosis and goal

test('a treatment references a consultation diagnosis and carries a goal; the diagnosis stays on its consultation', function () {
    $visit = ($this->visit)();
    $diagnosis = ClinicalDiagnosis::query()->withoutGlobalScopes()->create(['clinic_id' => $this->f->clinic->id, 'organization_id' => $this->f->clinic->organization_id, 'encounter_id' => $visit->id, 'patient_id' => $this->patient->id, 'description' => 'Knee osteoarthritis', 'status' => 'active', 'is_primary' => true]);
    $knee = ($this->start)('Knee Rehabilitation', ['primary_diagnosis_id' => $diagnosis->id, 'goal' => 'Climb stairs without pain']);

    expect($knee)->primary_diagnosis_id->toBe($diagnosis->id)->goal->toBe('Climb stairs without pain')
        ->and($diagnosis->fresh())->encounter_id->toBe($visit->id)->description->toBe('Knee osteoarthritis');

    $this->treatments->setClinicalContext($knee, null, 'Walk 2 km', $this->therapistUser, $this->therapist);
    expect($knee->fresh())->primary_diagnosis_id->toBeNull()->goal->toBe('Walk 2 km')
        ->and(ClinicalDiagnosis::query()->whereKey($diagnosis->id)->exists())->toBeTrue();

    $someoneElse = $this->f->patient('Other', 'Person');
    $theirs = ClinicalDiagnosis::query()->withoutGlobalScopes()->create(['clinic_id' => $this->f->clinic->id, 'organization_id' => $this->f->clinic->organization_id, 'encounter_id' => $visit->id, 'patient_id' => $someoneElse->id, 'description' => 'Not yours', 'status' => 'active', 'is_primary' => true]);
    expect(fn () => $this->treatments->setClinicalContext($knee, $theirs->id, null, $this->therapistUser, $this->therapist))->toThrow(InvalidArgumentException::class, 'not one recorded for this patient');

    $other = TreatmentFixture::make('other-clinic');
    $other->actAsOwner();
    $otherPatient = $other->f->patient();
    $otherVisit = app(EncounterWorkspace::class)->startForPatient($otherPatient, $other->f->branch, $other->physio);
    $otherClinics = ClinicalDiagnosis::query()->withoutGlobalScopes()->create(['clinic_id' => $other->f->clinic->id, 'organization_id' => $other->f->clinic->organization_id, 'encounter_id' => $otherVisit->id, 'patient_id' => $otherPatient->id, 'description' => 'Other clinic', 'status' => 'active', 'is_primary' => true]);
    ($this->asTherapist)();
    expect(fn () => $this->treatments->setClinicalContext($knee, $otherClinics->id, null, $this->therapistUser, $this->therapist))->toThrow(InvalidArgumentException::class, 'not one recorded for this patient')
        ->and(fn () => ($this->start)('Back Rehabilitation', ['primary_diagnosis_id' => $otherClinics->id]))->toThrow(InvalidArgumentException::class, 'not one recorded for this patient')
        ->and(PatientTreatment::withoutGlobalScopes()->where('clinic_id', $this->f->clinic->id)->count())->toBe(1);
});

// ---------------------------------------------------------------- Legacy compatibility

test('the legacy paths reuse the named treatment and never mirror a canonical plan into a second treatment', function () {
    $knee = ($this->start)('Knee Rehabilitation');
    $visit = ($this->visit)();
    $plan = $this->physio->startPlan($knee, ['title' => 'Knee plan'], [['name' => 'Quad sets']], $this->therapist, $this->therapistUser, $visit);
    $mirror = app(LegacyTreatmentMirror::class);

    expect($mirror->mirrorPhysioCarePlan($plan)->id)->toBe($knee->id);

    $session = $this->physio->recordSession($visit, ['patient_treatment_id' => $knee->id, 'subjective' => 'Better', 'complete' => true], $this->therapist, $this->therapistUser);
    $revision = $this->physio->replaceExercises($plan, [['name' => 'Step-ups']], $this->therapist, $this->therapistUser);

    expect($session->patient_treatment_id)->toBe($knee->id)
        ->and($revision)->patient_treatment_id->toBe($knee->id)->revision->toBe(2)
        ->and($mirror->mirrorPhysioCarePlan($revision)->id)->toBe($knee->id)
        ->and(PatientTreatment::withoutGlobalScopes()->pluck('id')->all())->toBe([$knee->id])
        ->and($knee->fresh())->source->toBe(PatientTreatment::SOURCE_ADHOC)->sessions_completed->toBe(1);
});

// ---------------------------------------------------------------- Security over HTTP

test('Reception cannot do clinical treatment work; nested ids from another treatment or clinic are 404', function () {
    $knee = ($this->start)('Knee Rehabilitation');
    $back = ($this->start)('Lower Back Rehabilitation');
    $visit = ($this->visit)();
    $backSession = $this->physio->openSession($back, $visit, $this->therapist, $this->therapistUser);
    $backPack = ($this->sell)($back, 5);
    [$reception, $receptionSession] = $this->f->memberWithRole(ClinicRole::Receptionist);
    $asTherapist = fn () => $this->actingAs($this->therapistUser)->withSession($this->therapistSession);

    $this->actingAs($reception)->withSession($receptionSession)->postJson("/clinic/treatments/{$knee->id}/sessions", ['encounter_id' => $visit->id])->assertForbidden();
    $this->actingAs($reception)->withSession($receptionSession)->postJson("/clinic/treatments/{$knee->id}/plans", ['title' => 'Nope'])->assertForbidden();

    // A session or package addressed under the wrong treatment does not exist there.
    $asTherapist()->postJson("/clinic/treatments/{$knee->id}/sessions/{$backSession->id}/complete")->assertNotFound();
    $asTherapist()->postJson("/clinic/treatments/{$knee->id}/packages/{$backPack->id}/adjust", ['quantity' => 1, 'reason' => 'Test'])->assertNotFound();

    $other = TreatmentFixture::make('other-clinic');
    $other->actAsOwner();
    $theirs = app(PatientTreatments::class)->startAdHoc($other->f->patient(), 'physiotherapy', 'Theirs', $other->physio, $other->f->branch, $other->f->owner);
    $asTherapist()->postJson("/clinic/treatments/{$theirs->id}/sessions", ['encounter_id' => $visit->id])->assertNotFound();

    // Over HTTP, the right treatment works end to end.
    $kneePack = ($this->sell)($knee, 5);
    $id = $asTherapist()->postJson("/clinic/treatments/{$knee->id}/sessions", ['encounter_id' => $visit->id])->assertCreated()->json('data.id');
    $asTherapist()->postJson("/clinic/treatments/{$knee->id}/sessions/{$id}/complete", ['package_id' => $backPack->id])->assertStatus(422);
    $asTherapist()->postJson("/clinic/treatments/{$knee->id}/sessions/{$id}/complete", ['package_id' => $kneePack->id])->assertOk()->assertJsonPath('data.status', 'completed');
});

test('a clinician limited to another branch cannot work on a treatment held here, and archived patients are read-only', function () {
    $knee = ($this->start)('Knee Rehabilitation');
    $east = Branch::factory()->create(['clinic_id' => $this->f->clinic->id, 'name' => 'East', 'status' => BranchStatus::Active]);
    $user = User::factory()->create(['user_type' => UserType::ClinicUser]);
    $membership = ClinicMembership::factory()->create(['clinic_id' => $this->f->clinic->id, 'user_id' => $user->id, 'is_primary_owner' => false, 'has_all_branches_access' => false, 'status' => ClinicMembershipStatus::Active]);
    $membership->assignBranch($east);
    app(ClinicRoleAssignmentService::class)->syncRoles($user, [ClinicRole::Doctor->value], [$east]);
    $eastTherapist = $this->f->doctor('Mr. East', $user);
    $eastTherapist->practitionerProfile()->withoutGlobalScopes()->update(['specialty' => 'Physiotherapist']);
    $this->t->actAs($user, [ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID => $membership->id, ClinicContext::SESSION_BRANCH_ID => $east->id]);

    expect(fn () => $this->physio->startPlan($knee, ['title' => 'From East'], [], $eastTherapist, $user))->toThrow(AuthorizationException::class);

    ($this->asTherapist)();
    $this->patient->update(['status' => 'archived']);
    expect(fn () => $this->physio->openSession($knee, ($this->visit)(), $this->therapist, $this->therapistUser))->toThrow(AuthorizationException::class, 'read-only')
        ->and(fn () => ($this->start)('Hip'))->toThrow(AuthorizationException::class, 'read-only');
});

test('a dental treatment cannot carry physiotherapy records', function () {
    $owner = $this->t->actAsOwner();
    $rct = $this->treatments->start($this->patient, $this->t->rct(), $this->f->doctorA, $this->f->branch, $owner, ['targets' => ['tooth' => [['tooth_number' => 16]]]]);

    expect(fn () => $this->physio->startPlan($rct, ['title' => 'Exercises'], [], $this->f->doctorA, $owner))->toThrow(DomainException::class, 'does not use physiotherapy');
});

// ---------------------------------------------------------------- Billing a package-paid visit

test('a visit a session package pays for is not charged a consultation at checkout; an expired package pays for nothing', function () {
    $this->therapist->practitionerProfile()->withoutGlobalScopes()->update(['consultation_fee' => 500]);
    $knee = ($this->start)('Knee Rehabilitation');
    $package = ($this->sell)($knee, 10);
    $encounter = ($this->visit)();
    $lines = fn () => collect(app(EncounterCheckout::class)->lines($encounter->fresh()))->pluck('key')->all();

    expect($lines())->not->toContain('consultation');

    // The package lapses: the consultation is billed as usual.
    $package->update(['valid_until' => now()->subDay()->toDateString()]);
    expect($lines())->toContain('consultation');
});

test('a physiotherapy visit is billed at its own stage, once: checkout bill, payment with receipt and print, then the payment messages', function () {
    $this->therapist->practitionerProfile()->withoutGlobalScopes()->update(['consultation_fee' => 500]);
    $encounter = ($this->visit)();
    $encounter->forceFill(['chief_complaint' => 'Knee pain'])->save();
    app(EncounterWorkspace::class)->finalize($encounter, $this->therapist);
    $bill = app(EncounterCheckout::class)->bill($encounter->fresh(), ['consultation' => 500], $this->therapist);

    expect(collect(app(EncounterCheckout::class)->lines($encounter->fresh()))->pluck('key')->all())->not->toContain('consultation');

    $owner = $this->t->actAsOwner();
    $paid = $this->actingAs($owner)->withSession($this->f->session)
        ->postJson(route('clinic.billing.payments.store', $bill), ['method' => 'cash', 'amount' => 500, 'idempotency_key' => 'physio-pay-0001'])->assertCreated()
        ->assertJsonPath('billing.outstanding', 0);
    $payment = PatientBillingPayment::query()->withoutGlobalScopes()->sole();

    $this->actingAs($owner)->withSession($this->f->session)->get($paid->json('billing.receipt.print_url'))->assertOk()
        ->assertSee('PAYMENT RECEIPT')->assertSee($payment->receipt_number)->assertSee('₹500.00');
    expect(Notification::query()->whereIn('event_key', ["payment:{$payment->id}", "receipt:{$payment->id}"])->count())->toBe(2)
        ->and(PatientBilling::query()->withoutGlobalScopes()->where('patient_id', $this->patient->id)->count())->toBe(1);
});
