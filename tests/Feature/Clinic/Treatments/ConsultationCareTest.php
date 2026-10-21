<?php

use App\ClinicRole;
use App\Models\ClinicalDiagnosis;
use App\Models\ClinicalEncounter;
use App\Models\PatientSessionPackage;
use App\Models\PatientTreatment;
use App\Models\PhysioCarePlan;
use App\Models\PhysioSession;
use App\Models\SessionPackageUsage;
use App\Services\Clinical\EncounterWorkspace;
use App\Services\Physio\PhysioRecord;
use App\Services\Physio\SessionPackages;
use App\Services\Reception\ReceptionCheckIn;
use App\Services\Treatment\ConsultationCare;
use App\Services\Treatment\PatientTreatments;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\TreatmentFixture;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-06 10:00', 'Asia/Kolkata'));
    $this->t = TreatmentFixture::make();
    $this->f = $this->t->f;
    [$this->doctorUser, $this->doctorSession] = $this->f->memberWithRole(ClinicRole::Doctor);
    $this->me = $this->f->doctor('Ms. Rehab', $this->doctorUser);
    $this->me->practitionerProfile()->withoutGlobalScopes()->update(['specialty' => 'Physiotherapist']);
    $this->asDoctor = fn () => $this->actingAs($this->doctorUser)->withSession($this->doctorSession);
    $this->patient = $this->f->patient('Priya', 'Shah');

    // Today's visit: checked in, started from Today.
    $this->visit = function () {
        $item = app(ReceptionCheckIn::class)->checkIn($this->f->clinic, $this->f->branch, $this->patient, doctor: $this->me);
        ($this->asDoctor)()->post("/clinic/consultations/queue/{$item->id}")->assertRedirect();

        return ClinicalEncounter::withoutGlobalScopes()->where('patient_id', $this->patient->id)->latest('id')->firstOrFail();
    };
    $this->care = fn (ClinicalEncounter $encounter) => collect(($this->asDoctor)()->get("/clinic/consultations/{$encounter->id}")->assertOk()->viewData('page')['props']['care']['treatments'])->keyBy('title');
});

test('Knee and Back in one consultation: each card keeps its own package and numbering, and completes independently', function () {
    $this->t->actAs($this->doctorUser, $this->doctorSession);
    $treatments = app(PatientTreatments::class);
    $knee = $treatments->startAdHoc($this->patient, 'physiotherapy', 'Knee Rehabilitation', $this->me, $this->f->branch, $this->doctorUser, ['sessions_planned' => 12]);
    $back = $treatments->startAdHoc($this->patient, 'physiotherapy', 'Back Rehabilitation', $this->me, $this->f->branch, $this->doctorUser, ['sessions_planned' => 10]);
    $kneePack = app(SessionPackages::class)->sell($knee, $this->f->branch, ['name' => 'Knee 12', 'sessions_total' => 12], $this->me);
    $backPack = app(SessionPackages::class)->sell($back, $this->f->branch, ['name' => 'Back 10', 'sessions_total' => 10], $this->me);
    // An earlier Knee visit: today's Knee session is #2, Back's is #1.
    $earlier = app(EncounterWorkspace::class)->startForPatient($this->patient, $this->f->branch, $this->me, null, 'physiotherapy');
    $physio = app(PhysioRecord::class);
    $physio->completeSession($physio->openSession($knee, $earlier, $this->me, $this->doctorUser), $kneePack->id, $this->me, $this->doctorUser);
    app(EncounterWorkspace::class)->autosave($earlier, ['chief_complaint' => 'Knee review'], 0);
    app(EncounterWorkspace::class)->finalize($earlier->fresh(), $this->me);

    $encounter = ($this->visit)();
    $cards = ($this->care)($encounter);
    expect($cards)->toHaveCount(2)
        ->and($cards['Knee Rehabilitation']['packages'])->toHaveCount(1)
        ->and($cards['Knee Rehabilitation']['packages'][0])->toMatchArray(['id' => $kneePack->id, 'patient_treatment_id' => $knee->id, 'remaining' => 11, 'remaining_after' => 10, 'eligible' => true])
        ->and($cards['Back Rehabilitation']['packages'][0])->toMatchArray(['id' => $backPack->id, 'patient_treatment_id' => $back->id, 'remaining' => 10])
        ->and($cards['Knee Rehabilitation']['next_session_number'])->toBe(2)
        ->and($cards['Back Rehabilitation']['next_session_number'])->toBe(1);

    // Open both sessions in the same consultation.
    foreach ($cards as $card) {
        ($this->asDoctor)()->postJson($card['links']['open_session'], ['encounter_id' => $encounter->id])->assertCreated();
    }
    $cards = ($this->care)($encounter);
    $kneeCard = $cards['Knee Rehabilitation'];
    $backCard = $cards['Back Rehabilitation'];

    // A draft uses nothing.
    ($this->asDoctor)()->putJson($kneeCard['links']['session'], ['subjective' => 'Stairs easier', 'pain_before' => 5])->assertOk();
    expect(($this->care)($encounter)['Knee Rehabilitation']['packages'][0]['remaining'])->toBe(11);

    // The Back session cannot be paid from the Knee package.
    ($this->asDoctor)()->postJson($backCard['links']['complete_session'], ['package_id' => $kneePack->id])
        ->assertStatus(422)->assertJson(['message' => 'That package belongs to a different treatment.']);

    // Completing, and completing again, uses one Knee session.
    ($this->asDoctor)()->postJson($kneeCard['links']['complete_session'], ['package_id' => $kneePack->id])->assertOk()->assertJsonPath('data.session_number', 2);
    ($this->asDoctor)()->postJson($kneeCard['links']['complete_session'], ['package_id' => $kneePack->id])->assertOk();
    ($this->asDoctor)()->postJson($backCard['links']['complete_session'], ['package_id' => $backPack->id])->assertOk()->assertJsonPath('data.session_number', 1);

    $cards = ($this->care)($encounter);
    expect($cards['Knee Rehabilitation']['packages'][0]['remaining'])->toBe(10)
        ->and($cards['Back Rehabilitation']['packages'][0]['remaining'])->toBe(9)
        ->and($cards['Knee Rehabilitation']['session'])->toMatchArray(['status' => 'completed', 'session_number' => 2, 'package_id' => $kneePack->id])
        ->and($cards['Back Rehabilitation']['session'])->toMatchArray(['status' => 'completed', 'session_number' => 1, 'package_id' => $backPack->id])
        ->and(SessionPackageUsage::withoutGlobalScopes()->where('package_id', $kneePack->id)->where('entry_type', 'consume')->count())->toBe(2)
        ->and(PhysioSession::withoutGlobalScopes()->where('encounter_id', $encounter->id)->pluck('patient_treatment_id')->sort()->values()->all())->toBe([$knee->id, $back->id]);

    // Reversing gives the session back to its own package only.
    ($this->asDoctor)()->postJson($kneeCard['links']['reverse_session'], ['reason' => 'Wrong visit'])->assertOk();
    $cards = ($this->care)($encounter);
    expect($cards['Knee Rehabilitation']['packages'][0]['remaining'])->toBe(11)
        ->and($cards['Back Rehabilitation']['packages'][0]['remaining'])->toBe(9);
});

test('an expired package is shown but cannot pay; prescribed, skipped and free exercises are kept apart', function () {
    $this->t->actAs($this->doctorUser, $this->doctorSession);
    $knee = app(PatientTreatments::class)->startAdHoc($this->patient, 'physiotherapy', 'Knee Rehabilitation', $this->me, $this->f->branch, $this->doctorUser);
    $old = app(SessionPackages::class)->sell($knee, $this->f->branch, ['name' => 'Old pack', 'sessions_total' => 5, 'valid_until' => '2026-10-06'], $this->me);
    $this->travelTo(CarbonImmutable::parse('2026-10-08 10:00', 'Asia/Kolkata'));
    $encounter = ($this->visit)();
    $this->t->actAs($this->doctorUser, $this->doctorSession);
    $plan = app(PhysioRecord::class)->startPlan($knee, ['title' => 'Knee plan'], [['name' => 'Quad sets', 'sets' => 3, 'reps' => 10], ['name' => 'Hamstring stretch']], $this->me, $this->doctorUser, $encounter);

    $card = ($this->care)($encounter)['Knee Rehabilitation'];
    expect($card['packages'][0])->toMatchArray(['id' => $old->id, 'expired' => true, 'eligible' => false, 'remaining_after' => null])
        ->and($card['plan'])->toMatchArray(['id' => $plan->id, 'revision' => 1])
        ->and(collect($card['plan']['exercises'])->pluck('name')->all())->toBe(['Quad sets', 'Hamstring stretch']);

    ($this->asDoctor)()->postJson($card['links']['open_session'], ['encounter_id' => $encounter->id])->assertCreated();
    $card = ($this->care)($encounter)['Knee Rehabilitation'];
    [$quad, $stretch] = $card['plan']['exercises'];
    ($this->asDoctor)()->putJson($card['links']['session'], ['exercises' => [
        ['physio_exercise_id' => $quad['id'], 'status' => 'performed', 'sets' => 3, 'reps' => 12],
        ['physio_exercise_id' => $stretch['id'], 'status' => 'skipped'],
        ['name' => 'Step-ups', 'status' => 'performed', 'sets' => 2, 'reps' => 8],
    ]])->assertOk();
    ($this->asDoctor)()->postJson($card['links']['complete_session'], ['package_id' => $old->id])->assertStatus(422)->assertJson(['message' => 'That package has expired.']);
    ($this->asDoctor)()->postJson($card['links']['complete_session'], ['package_id' => null])->assertOk();

    $session = ($this->care)($encounter)['Knee Rehabilitation']['session'];
    expect(collect($session['exercises'])->map(fn ($e) => [$e['name'], $e['status'], $e['physio_exercise_id'] !== null])->all())->toBe([
        ['Quad sets', 'performed', true], ['Hamstring stretch', 'skipped', true], ['Step-ups', 'performed', false],
    ])
        ->and($plan->fresh()->exercises()->pluck('name')->all())->toBe(['Quad sets', 'Hamstring stretch'])
        ->and(SessionPackageUsage::withoutGlobalScopes()->count())->toBe(0);
});

test('Start treatment from the visit sets up the plan, package and today\'s session in one step, or nothing at all', function () {
    $encounter = ($this->visit)();
    $this->actingAs($this->doctorUser);
    $diagnosis = app(EncounterWorkspace::class)->addDiagnosis($encounter, ['description' => 'Knee osteoarthritis', 'status' => 'confirmed', 'is_primary' => true]);
    $start = fn (array $extra = []) => [
        'mode' => 'adhoc', 'specialty_key' => 'physiotherapy', 'title' => 'Knee Rehabilitation', 'primary_diagnosis_id' => $diagnosis->id, 'goal' => 'Climb stairs without pain',
        'sessions_planned' => 12, 'plan' => ['frequency' => '3x/week', 'exercises' => [['name' => 'Quad sets', 'sets' => 3, 'reps' => 10]]], 'open_session' => true, ...$extra,
    ];

    // A practitioner without billing rights cannot sell the package: refused before anything is written.
    ($this->asDoctor)()->postJson("/clinic/consultations/{$encounter->id}/treatments", $start(['package' => ['name' => 'Knee 12', 'sessions_total' => 12]]))->assertForbidden();
    // Another patient's diagnosis: refused, and the treatment is not left behind.
    $theirs = ClinicalDiagnosis::query()->withoutGlobalScopes()->create(['clinic_id' => $this->f->clinic->id, 'organization_id' => $this->f->clinic->organization_id, 'encounter_id' => $encounter->id, 'patient_id' => $this->f->patient('Other', 'One')->id, 'description' => 'Not hers', 'status' => 'active']);
    ($this->asDoctor)()->postJson("/clinic/consultations/{$encounter->id}/treatments", $start(['primary_diagnosis_id' => $theirs->id]))->assertStatus(422);
    expect(PatientTreatment::withoutGlobalScopes()->count())->toBe(0);

    $id = ($this->asDoctor)()->postJson("/clinic/consultations/{$encounter->id}/treatments", $start())->assertCreated()->json('data.id');
    $treatment = PatientTreatment::withoutGlobalScopes()->findOrFail($id);
    expect($treatment)->goal->toBe('Climb stairs without pain')->primary_diagnosis_id->toBe($diagnosis->id)->encounter_id->toBe($encounter->id)
        ->and(PhysioCarePlan::withoutGlobalScopes()->where('patient_treatment_id', $id)->sole())->revision->toBe(1)->frequency->toBe('3x/week')
        ->and(PhysioSession::withoutGlobalScopes()->where('encounter_id', $encounter->id)->sole())->patient_treatment_id->toBe($id)->status->toBe('draft');

    $card = ($this->care)($encounter)['Knee Rehabilitation'];
    expect($card)->toMatchArray(['runs_sessions' => true, 'goal' => 'Climb stairs without pain'])
        ->and($card['diagnosis']['description'])->toBe('Knee osteoarthritis')
        ->and($card['session']['status'])->toBe('draft');

    // With billing rights (the owner, practising physiotherapy, in their own consultation) the package is sold for this treatment in the same step.
    $ownerAsPhysio = $this->f->doctor('Dr. Owner', $this->f->owner);
    $ownerAsPhysio->practitionerProfile()->withoutGlobalScopes()->update(['specialty' => 'Physiotherapist']);
    $this->t->actAsOwner();
    $ownersVisit = app(EncounterWorkspace::class)->startForPatient($this->patient, $this->f->branch, $ownerAsPhysio, null, 'physiotherapy');
    $id = $this->actingAs($this->f->owner)->withSession($this->f->session)->postJson("/clinic/consultations/{$ownersVisit->id}/treatments", $start([
        'title' => 'Back Rehabilitation', 'primary_diagnosis_id' => null, 'open_session' => false, 'package' => ['name' => 'Back 10', 'sessions_total' => 10],
    ]))->assertCreated()->json('data.id');
    expect(PatientSessionPackage::withoutGlobalScopes()->sole())->patient_treatment_id->toBe($id)->sessions_total->toBe(10);
});

test('Reception cannot start treatment from a consultation, and another clinic\'s consultation is not found', function () {
    $encounter = ($this->visit)();
    [$receptionUser, $receptionSession] = $this->f->memberWithRole(ClinicRole::Receptionist);
    $this->actingAs($receptionUser)->withSession($receptionSession)
        ->postJson("/clinic/consultations/{$encounter->id}/treatments", ['mode' => 'adhoc', 'specialty_key' => 'physiotherapy', 'title' => 'Knee'])->assertForbidden();
    $this->actingAs($receptionUser)->withSession($receptionSession)->get("/clinic/consultations/{$encounter->id}")->assertForbidden();

    $elsewhere = TreatmentFixture::make('elsewhere');
    $elsewhere->actAsOwner();
    $theirs = app(EncounterWorkspace::class)->startForPatient($elsewhere->f->patient(), $elsewhere->f->branch, $elsewhere->physio);
    ($this->asDoctor)()->postJson("/clinic/consultations/{$theirs->id}/treatments", ['mode' => 'adhoc', 'specialty_key' => 'physiotherapy', 'title' => 'Knee'])->assertNotFound();
    expect(PatientTreatment::withoutGlobalScopes()->count())->toBe(0);
});

test('Dental runs on the same workflow: a catalogue treatment names its tooth, works by stages, and has no sessions or packages', function () {
    $version = $this->t->rct();
    $dentist = $this->f->doctor('Dr. Owner', $this->f->owner);
    $asOwner = fn () => $this->actingAs($this->f->owner)->withSession($this->f->session);
    $this->t->actAsOwner();
    $encounter = app(EncounterWorkspace::class)->startForPatient($this->patient, $this->f->branch, $dentist, null, 'dental');

    $asOwner()->postJson("/clinic/consultations/{$encounter->id}/treatments", ['mode' => 'catalog', 'version_id' => $version->id])
        ->assertStatus(422)->assertJson(['message' => 'Which tooth is this treatment for?']);
    $id = $asOwner()->postJson("/clinic/consultations/{$encounter->id}/treatments", ['mode' => 'catalog', 'version_id' => $version->id, 'targets' => ['tooth' => [['tooth_number' => 36]]]])
        ->assertCreated()->json('data.id');
    // A dental treatment with no tooth target needs none.
    $asOwner()->postJson("/clinic/consultations/{$encounter->id}/treatments", ['mode' => 'adhoc', 'specialty_key' => 'dental', 'title' => 'Scaling'])->assertCreated();

    $card = collect($asOwner()->get("/clinic/consultations/{$encounter->id}")->viewData('page')['props']['care']['treatments'])->firstWhere('id', $id);
    expect($card)->toMatchArray(['runs_sessions' => false, 'packages' => [], 'session' => null])
        ->and($card['targets']['tooth'][0]['tooth_number'])->toBe(36)
        ->and(collect($card['stages'])->pluck('name')->all())->toBe(['Access opening', 'Cleaning & shaping', 'Obturation']);

    $first = $card['stages'][0];
    $asOwner()->postJson($first['links']['start'], ['encounter_id' => $encounter->id])->assertOk();
    $asOwner()->postJson($first['links']['procedures'], ['encounter_id' => $encounter->id, 'clinical_procedure_id' => $this->t->procedures['ACC']->id])->assertCreated();
    $asOwner()->postJson($first['links']['complete'], ['encounter_id' => $encounter->id])->assertOk();

    $card = collect($asOwner()->get("/clinic/consultations/{$encounter->id}")->viewData('page')['props']['care']['treatments'])->firstWhere('id', $id);
    expect($card['progress'])->toMatchArray(['done' => 1, 'total' => 3, 'unit' => 'stages'])
        ->and($card['next_stage']['name'])->toBe('Cleaning & shaping')
        // Physiotherapy sessions are refused for a dental treatment.
        ->and($asOwner()->postJson($card['links']['open_session'], ['encounter_id' => $encounter->id])->status())->toBeIn([403, 422]);
});

test('the treatments panel reads a fixed number of queries however many treatments the patient has', function () {
    $encounter = ($this->visit)();
    $this->t->actAs($this->doctorUser, $this->doctorSession);
    $add = function (int $n) use ($encounter) {
        foreach (range(1, $n) as $i) {
            $treatment = app(PatientTreatments::class)->startAdHoc($this->patient, 'physiotherapy', 'Rehab '.uniqid(), $this->me, $this->f->branch, $this->doctorUser);
            app(SessionPackages::class)->sell($treatment, $this->f->branch, ['name' => "Pack {$i}", 'sessions_total' => 5], $this->me);
            app(PhysioRecord::class)->startPlan($treatment, ['title' => 'Plan'], [['name' => 'Bridge']], $this->me, $this->doctorUser, $encounter);
            app(PhysioRecord::class)->openSession($treatment, $encounter, $this->me, $this->doctorUser);
        }
    };
    $count = function () use ($encounter) {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $care = app(ConsultationCare::class)->build($encounter, $this->patient, $this->doctorUser, true);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return [$queries, count($care['treatments'])];
    };

    $add(2);
    $count(); // the request's one-off lookups (who is signed in) are memoised after the first read
    [$few, $cardsFew] = $count();
    $add(4);
    [$many, $cardsMany] = $count();

    expect($cardsFew)->toBe(2)->and($cardsMany)->toBe(6)->and($many)->toBe($few);
});
