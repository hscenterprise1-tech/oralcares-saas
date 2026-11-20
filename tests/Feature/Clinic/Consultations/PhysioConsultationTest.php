<?php

use App\ClinicPermission;
use App\ClinicRole;
use App\Models\Appointment;
use App\Models\ClinicalDiagnosis;
use App\Models\ClinicalEncounter;
use App\Models\PatientProcedure;
use App\Models\PatientSessionPackage;
use App\Models\PatientTreatment;
use App\Models\PhysioCarePlan;
use App\Models\PhysioExercise;
use App\Models\PhysioSession;
use App\Models\PhysioSessionExercise;
use App\Services\Clinical\EncounterWorkspace;
use App\Services\Physio\PhysioCatalogue;
use App\Services\Physio\SessionPackages;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\TreatmentFixture;

/**
 * The physio consultation by problem: the doctor finds the problems (a side
 * each), the patient starts the ones to treat now, each visit is one click at
 * the course's session type — paid from the problem's package or at the
 * type's price per visit, add-ons on top — and the visits are booked by the
 * course's frequency.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-03 10:00', 'Asia/Kolkata'));
    $this->t = TreatmentFixture::make();
    $this->f = $this->t->f;
    [$this->user, $this->session] = $this->f->memberWithRole(ClinicRole::Doctor);
    $this->me = $this->f->doctor('Neha Rao', $this->user);
    $this->me->practitionerProfile()->withoutGlobalScopes()->update(['specialty' => 'Physiotherapist']);
    $this->asMe = fn () => $this->actingAs($this->user)->withSession($this->session);
    $this->asOwner = fn () => $this->actingAs($this->f->owner)->withSession($this->f->session);
    // The Doctor role sells packages by default; a clinic may take that away.
    $this->withoutSale = function () {
        Role::query()->where('name', ClinicRole::Doctor->value)->whereNull('branch_id')->sole()->revokePermissionTo(ClinicPermission::PackageSell->value);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    };

    $owner = $this->t->actAsOwner();
    app(PhysioCatalogue::class)->save($this->f->clinic, [
        'treatments' => [
            ['key' => 'ultrasound', 'label' => 'Ultrasound', 'minutes' => 8, 'price' => 100],
            ['key' => 'ift', 'label' => 'IFT', 'minutes' => 15, 'price' => 150],
            ['key' => 'exercise', 'label' => 'Exercise', 'minutes' => 20, 'price' => 200],
        ],
        'addons' => [['key' => 'cupping', 'label' => 'Cupping', 'minutes' => 15, 'price' => 300]],
        'problems' => [
            ['key' => 'knee_pain', 'label' => 'Knee pain', 'sided' => true, 'types' => [
                ['key' => 'full', 'label' => 'Full treatment', 'treatments' => ['ultrasound', 'ift', 'exercise'], 'price' => 250],
                ['key' => 'exercise', 'label' => 'Exercise only', 'treatments' => ['exercise'], 'price' => 200],
                ['key' => 'machine', 'label' => 'Machine only', 'treatments' => ['ultrasound', 'ift'], 'price' => 100],
            ], 'packages' => [['id' => 'kfull10', 'name' => 'Knee full 10', 'sessions' => 10, 'price' => 2000, 'valid_days' => 30, 'types' => ['full']]]],
            ['key' => 'shoulder_pain', 'label' => 'Shoulder pain', 'sided' => true, 'types' => [
                // No fixed price: a visit costs the treatments given.
                ['key' => 'full', 'label' => 'Full treatment', 'treatments' => ['ultrasound', 'exercise'], 'price' => null],
            ], 'packages' => []],
        ],
    ], $owner);

    $this->patient = $this->f->patient('Ravi', 'Kumar');
    $this->actingAs($this->user);
    $this->encounter = app(EncounterWorkspace::class)->startForPatient($this->patient, $this->f->branch, $this->me);
    $this->encounter->forceFill(['specialty' => 'physiotherapy'])->save();
    $this->base = "/clinic/consultations/{$this->encounter->id}/physio";
    $this->course = fn (string $problem) => PatientTreatment::query()->withoutGlobalScopes()->where('physio_problem', $problem)->sole();
    $this->charges = fn () => PatientProcedure::query()->withoutGlobalScopes()->where('encounter_id', $this->encounter->id)->pluck('charged_amount', 'name')->map(fn ($v) => (float) $v)->all();
    $this->startKnee = function (array $start = ['type' => 'full'], string $side = 'right') {
        ($this->asMe)()->post("{$this->base}/problems", ['problem' => 'knee_pain', 'side' => $side])->assertSessionHasNoErrors();
        $knee = ($this->course)('knee_pain');
        ($this->asMe)()->post("{$this->base}/courses/{$knee->id}/start", $start)->assertSessionHasNoErrors();

        return $knee;
    };
});

test('the doctor finds two problems with their sides; the patient starts one and the other stays suggested', function () {
    ($this->asMe)()->post("{$this->base}/problems", ['problem' => 'shoulder_pain', 'side' => 'left'])->assertSessionHasNoErrors();
    ($this->startKnee)(['type' => 'full', 'frequency' => 'daily', 'sessions' => 10]);
    // The side is asked for a sided problem, and the same problem on the same side is not added twice.
    ($this->asMe)()->post("{$this->base}/problems", ['problem' => 'knee_pain'])->assertSessionHasErrors('physio');
    ($this->asMe)()->post("{$this->base}/problems", ['problem' => 'knee_pain', 'side' => 'right'])->assertSessionHasErrors('physio');

    ($this->asMe)()->get("/clinic/consultations/{$this->encounter->id}")->assertInertia(fn (Assert $page) => $page
        ->component('ClinicPortal/Consultations/Physio', false)
        ->has('physio.courses', 2)
        ->where('physio.courses.0.title', 'Knee pain · Right')
        ->where('physio.courses.0.status', 'active')
        ->where('physio.courses.0.type', 'full')
        ->where('physio.courses.0.frequency', 'daily')
        ->where('physio.courses.1.title', 'Shoulder pain · Left')
        ->where('physio.courses.1.status', 'suggested'));
});

test('one click: the session gives the type\'s treatments and charges the type\'s price, add-ons on top', function () {
    $knee = ($this->startKnee)();

    ($this->asMe)()->post("{$this->base}/finish", ['courses' => [['id' => $knee->id, 'done' => true, 'addons' => ['cupping']]]])->assertSessionHasNoErrors();

    $session = PhysioSession::query()->withoutGlobalScopes()->sole();
    expect(collect($session->given)->pluck('key')->all())->toBe(['ultrasound', 'ift', 'exercise'])
        ->and($session->session_type)->toBe('full')
        ->and((float) $session->charge)->toBe(550.0)
        ->and(($this->charges)())->toBe(['Knee pain · Right — Full treatment' => 250.0, 'Cupping' => 300.0])
        ->and(ClinicalEncounter::query()->withoutGlobalScopes()->find($this->encounter->id)->isFinalized())->toBeTrue();
});

test('a different type for the day is charged at its own price; the course keeps its type until changed', function () {
    $knee = ($this->startKnee)(['type' => 'full'], 'left');
    ($this->asMe)()->post("{$this->base}/courses/{$knee->id}/type", ['type' => 'exercise'])->assertSessionHasNoErrors();
    expect($knee->fresh()->session_type)->toBe('exercise');

    ($this->asMe)()->post("{$this->base}/finish", ['courses' => [['id' => $knee->id, 'done' => true, 'type' => 'machine']]])->assertSessionHasNoErrors();
    expect(($this->charges)())->toBe(['Knee pain · Left — Machine only' => 100.0])
        ->and($knee->fresh()->session_type)->toBe('exercise');
});

test('a package covers the session types it lists; nothing is charged for a covered visit', function () {
    ($this->asMe)()->post("{$this->base}/problems", ['problem' => 'knee_pain', 'side' => 'right']);
    $knee = ($this->course)('knee_pain');
    Role::query()->where('name', ClinicRole::Doctor->value)->whereNull('branch_id')->sole()->givePermissionTo(
        collect([ClinicPermission::PackageSell, ClinicPermission::BillingCreate])->map(fn ($p) => Permission::firstOrCreate(['name' => $p->value, 'guard_name' => 'web']))->all());
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    ($this->asMe)()->post("{$this->base}/courses/{$knee->id}/start", ['type' => 'full', 'package' => 'kfull10'])->assertSessionHasNoErrors();
    $package = app(SessionPackages::class)->eligible($knee->fresh())->first()['package'];
    expect($package->includes)->toBe(['full']);

    ($this->asMe)()->post("{$this->base}/finish", ['courses' => [['id' => $knee->id, 'done' => true]]])->assertSessionHasNoErrors();
    expect(app(SessionPackages::class)->balance($package->fresh()))->toBe(9)->and(($this->charges)())->toBe([]);
});

test('a doctor without the package permission cannot sell one, and a suggested problem cannot have a session', function () {
    ($this->withoutSale)();
    ($this->asMe)()->post("{$this->base}/problems", ['problem' => 'knee_pain', 'side' => 'right']);
    $knee = ($this->course)('knee_pain');

    ($this->asMe)()->post("{$this->base}/finish", ['courses' => [['id' => $knee->id, 'done' => true]]])->assertSessionHasErrors('physio');
    ($this->asMe)()->post("{$this->base}/courses/{$knee->id}/start", ['type' => 'full', 'package' => 'kfull10'])->assertSessionHasErrors('physio');
    expect($knee->fresh()->status)->toBe(PatientTreatment::PLANNED);
});

test('a consultation only keeps the problem, findings, diagnosis and advice, with nothing charged', function () {
    ($this->asMe)()->post("{$this->base}/finish", ['courses' => []])->assertSessionHasErrors('physio');

    ($this->asMe)()->post("{$this->base}/finish", [
        'consultation' => ['complaint' => 'Right knee pain on stairs', 'findings' => 'Quadriceps weak', 'diagnosis' => 'Patellofemoral pain', 'advice' => 'Ice after walking'],
        'courses' => [],
    ])->assertSessionHasNoErrors();

    $encounter = ClinicalEncounter::query()->withoutGlobalScopes()->find($this->encounter->id);
    expect([$encounter->chief_complaint, $encounter->examination, $encounter->follow_up_note])->toBe(['Right knee pain on stairs', 'Quadriceps weak', 'Ice after walking'])
        ->and($encounter->diagnoses()->withoutGlobalScopes()->pluck('description')->all())->toBe(['Patellofemoral pain'])
        ->and($encounter->isFinalized())->toBeTrue()
        ->and(PhysioSession::query()->withoutGlobalScopes()->count())->toBe(0);
});

test('the coming visits are booked by the frequency at one time', function () {
    $knee = ($this->startKnee)(['type' => 'full', 'frequency' => 'alternate']);

    $slot = null;
    ($this->asMe)()->get("/clinic/consultations/{$this->encounter->id}")->assertInertia(function (Assert $page) use (&$slot) {
        $day = collect($page->toArray()['props']['physio']['booking']['days'])->first(fn ($day) => $day['times'] !== []);
        $slot = ['date' => $day['date'], 'time' => $day['times'][0]];
    });

    ($this->asMe)()->post("{$this->base}/finish", ['courses' => [['id' => $knee->id, 'done' => true]], 'booking' => [...$slot, 'frequency' => 'alternate', 'count' => 3]])
        ->assertSessionHasNoErrors();

    $dates = Appointment::query()->withoutGlobalScopes()->where('patient_id', $this->patient->id)->orderBy('scheduled_at')->get()
        ->map(fn (Appointment $a) => $a->scheduled_at->format('Y-m-d H:i'))->all();
    expect($dates)->toHaveCount(3)
        ->and($dates[0])->toBe($slot['date'].' '.$slot['time'])
        ->and(CarbonImmutable::parse($dates[0])->diffInDays(CarbonImmutable::parse($dates[1])))->toBeGreaterThanOrEqual(2.0);
});

test('the owner sets problems with session types, add-ons and packages; a dental-only clinic has no physio settings', function () {
    ($this->asOwner)()->put('/clinic/admin/settings/physio', [
        'treatments' => [['key' => 'exercise', 'label' => 'Exercise', 'minutes' => 20]],
        'addons' => [],
        'problems' => [['label' => 'Ankle sprain', 'sided' => true, 'types' => [['label' => 'Exercise only', 'treatments' => ['exercise'], 'price' => 180]], 'packages' => [],
            'diagnoses' => ['Lateral ankle sprain', ' Lateral ankle sprain ', 'Achilles tendinopathy']]],
    ])->assertSessionHasNoErrors();
    expect(app(PhysioCatalogue::class)->type($this->f->clinic->fresh(), 'ankle_sprain', 'exercise_only')['price'])->toEqual(180)
        ->and(app(PhysioCatalogue::class)->problem($this->f->clinic->fresh(), 'ankle_sprain')['diagnoses'])->toBe(['Lateral ankle sprain', 'Achilles tendinopathy']);

    $this->f->clinic->update(['specialties' => ['dental']]);
    ($this->asOwner)()->get('/clinic/admin/settings/physio')->assertNotFound();
});

test('paying per visit, the patient buys a package mid-course; when it is used up the doctor is told and renews it', function () {
    Role::query()->where('name', ClinicRole::Doctor->value)->whereNull('branch_id')->sole()->givePermissionTo(
        collect([ClinicPermission::PackageSell, ClinicPermission::BillingCreate])->map(fn ($p) => Permission::firstOrCreate(['name' => $p->value, 'guard_name' => 'web']))->all());
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    app(PhysioCatalogue::class)->save($this->f->clinic, [
        ...app(PhysioCatalogue::class)->resolve($this->f->clinic),
        'problems' => collect(app(PhysioCatalogue::class)->resolve($this->f->clinic)['problems'])
            ->map(fn (array $p) => $p['key'] === 'knee_pain' ? [...$p, 'packages' => [['id' => 'k2', 'name' => 'Knee 2', 'sessions' => 2, 'price' => 450, 'valid_days' => 30, 'types' => []]]] : $p)->all(),
    ], $this->f->owner);

    $knee = ($this->startKnee)(['type' => 'full', 'sessions' => 1]);
    ($this->asMe)()->post("{$this->base}/finish", ['courses' => [['id' => $knee->id, 'done' => true]]])->assertSessionHasNoErrors();
    expect(($this->charges)())->toBe(['Knee pain · Right — Full treatment' => 250.0]);

    // The next visit: the patient buys the package, the visit is paid from it.
    $visit = function () {
        $this->travel(1)->days();
        $this->actingAs($this->user);
        $this->encounter = app(EncounterWorkspace::class)->startForPatient($this->patient, $this->f->branch, $this->me);
        $this->encounter->forceFill(['specialty' => 'physiotherapy'])->save();
        $this->base = "/clinic/consultations/{$this->encounter->id}/physio";
    };
    $visit();
    ($this->asMe)()->post("{$this->base}/courses/{$knee->id}/package", ['package' => 'k2'])->assertSessionHasNoErrors();
    expect($knee->fresh()->sessions_planned)->toBe(3);
    ($this->asMe)()->post("{$this->base}/finish", ['courses' => [['id' => $knee->id, 'done' => true]]])->assertSessionHasNoErrors();
    expect(($this->charges)())->toBe([]);

    $visit();
    ($this->asMe)()->post("{$this->base}/finish", ['courses' => [['id' => $knee->id, 'done' => true]]])->assertSessionHasNoErrors();

    // Used up: the screen says so, the history counts the package's sessions, and the renewal follows the old package.
    $visit();
    ($this->asMe)()->get("/clinic/consultations/{$this->encounter->id}")->assertInertia(fn (Assert $page) => $page
        ->where('physio.courses.0.package', null)
        ->where('physio.courses.0.package_ended.name', 'Knee 2')
        ->where('physio.courses.0.package_ended.used', 2)
        ->where('physio.history.0.package_use', '2 of 2')
        ->where('physio.history.1.package_use', '1 of 2')
        ->where('physio.history.2.charged', 250));
    ($this->asMe)()->post("{$this->base}/courses/{$knee->id}/package", ['package' => 'k2'])->assertSessionHasNoErrors();
    $packages = PatientSessionPackage::query()->withoutGlobalScopes()->where('patient_treatment_id', $knee->id)->orderBy('id')->get();
    expect($packages)->toHaveCount(2)->and($packages[1]->renews_package_id)->toBe($packages[0]->id);
});

test('a package cannot be bought for a suggested problem, nor without the permission', function () {
    ($this->asMe)()->post("{$this->base}/problems", ['problem' => 'knee_pain', 'side' => 'right']);
    $knee = ($this->course)('knee_pain');
    ($this->asMe)()->post("{$this->base}/courses/{$knee->id}/package", ['package' => 'kfull10'])->assertSessionHasErrors('physio');

    ($this->asMe)()->post("{$this->base}/courses/{$knee->id}/start", ['type' => 'full']);
    ($this->withoutSale)();
    ($this->asMe)()->post("{$this->base}/courses/{$knee->id}/package", ['package' => 'kfull10'])->assertSessionHasErrors('physio');
    expect(PatientSessionPackage::query()->withoutGlobalScopes()->count())->toBe(0);
});

test('without a fixed price a visit costs the treatments actually given; not done is not charged', function () {
    ($this->asMe)()->post("{$this->base}/problems", ['problem' => 'shoulder_pain', 'side' => 'left']);
    $shoulder = ($this->course)('shoulder_pain');
    ($this->asMe)()->post("{$this->base}/courses/{$shoulder->id}/start", ['type' => 'full'])->assertSessionHasNoErrors();

    // Nothing given is no session.
    ($this->asMe)()->post("{$this->base}/finish", ['courses' => [['id' => $shoulder->id, 'done' => true, 'given' => [
        ['key' => 'ultrasound', 'minutes' => 8, 'status' => 'not_performed'], ['key' => 'exercise', 'minutes' => 20, 'status' => 'not_performed'],
    ]]]])->assertSessionHasErrors('physio');

    ($this->asMe)()->post("{$this->base}/finish", ['courses' => [['id' => $shoulder->id, 'done' => true, 'given' => [
        ['key' => 'ultrasound', 'minutes' => 8, 'status' => 'partial'], ['key' => 'exercise', 'minutes' => 20, 'status' => 'not_performed'],
    ]]]])->assertSessionHasNoErrors();

    $session = PhysioSession::query()->withoutGlobalScopes()->sole();
    expect(($this->charges)())->toBe(['Shoulder pain · Left — Ultrasound' => 100.0])
        ->and((float) $session->charge)->toBe(100.0)
        ->and(collect($session->given)->pluck('status', 'key')->all())->toBe(['ultrasound' => 'partial', 'exercise' => 'not_performed'])
        ->and($session->modalities)->toBe(['ultrasound']);
});

test('one visit, two problems: the knee from its package, the shoulder per treatment given', function () {
    $knee = ($this->startKnee)(['type' => 'full', 'package' => 'kfull10']);
    ($this->asMe)()->post("{$this->base}/problems", ['problem' => 'shoulder_pain', 'side' => 'left']);
    $shoulder = ($this->course)('shoulder_pain');
    ($this->asMe)()->post("{$this->base}/courses/{$shoulder->id}/start", ['type' => 'full']);

    ($this->asMe)()->post("{$this->base}/finish", ['courses' => [['id' => $knee->id, 'done' => true], ['id' => $shoulder->id, 'done' => true]]])
        ->assertSessionHasNoErrors();

    $package = PatientSessionPackage::query()->withoutGlobalScopes()->sole();
    expect(app(SessionPackages::class)->balance($package))->toBe(9)
        ->and(($this->charges)())->toBe(['Shoulder pain · Left — Ultrasound' => 100.0, 'Shoulder pain · Left — Exercise' => 200.0]);

    // The doctor takes the payment at the clinic's checkout.
    ($this->asMe)()->get("/clinic/consultations/{$this->encounter->id}")
        ->assertInertia(fn (Assert $page) => $page->where('links.checkout', route('clinic.billing.checkout', $this->encounter)));
    ($this->asMe)()->get("/clinic/billing/checkout/{$this->encounter->id}")->assertOk();
});

test('the complaint and diagnosis are tapped once; a change keeps the earlier version', function () {
    ($this->asMe)()->post("{$this->base}/problems", ['problem' => 'knee_pain', 'side' => 'right']);
    $knee = ($this->course)('knee_pain');
    $details = "{$this->base}/courses/{$knee->id}/details";

    ($this->asMe)()->post($details, ['complaint' => ['severity' => 'unbearable']])->assertSessionHasErrors('complaint.severity');
    ($this->asMe)()->post($details, [
        'complaint' => ['duration' => 'weeks', 'severity' => 'moderate', 'difficulties' => ['stairs', 'bending'], 'previous' => ['painkillers']],
        'diagnosis' => 'Patellofemoral pain',
    ])->assertSessionHasNoErrors();

    $knee->refresh();
    $diagnosis = ClinicalDiagnosis::query()->withoutGlobalScopes()->where('encounter_id', $this->encounter->id)->sole();
    expect($knee->physio_details['complaint'])->toMatchArray(['duration' => 'weeks', 'severity' => 'moderate', 'difficulties' => ['stairs', 'bending'], 'previous' => ['painkillers']])
        ->and($diagnosis->description)->toBe('Patellofemoral pain')
        ->and($diagnosis->details['patient_treatment_id'])->toBe($knee->id)
        ->and($knee->primary_diagnosis_id)->toBe($diagnosis->id);

    ($this->asMe)()->post($details, ['complaint' => ['duration' => 'weeks', 'severity' => 'severe', 'difficulties' => ['stairs'], 'previous' => []]])->assertSessionHasNoErrors();

    ($this->asMe)()->get("/clinic/consultations/{$this->encounter->id}")->assertInertia(fn (Assert $page) => $page
        ->where('physio.courses.0.details.complaint.severity', 'severe')
        ->where('physio.courses.0.details.revisions', 1)
        ->where('physio.courses.0.diagnosis', 'Patellofemoral pain')
        ->has('physio.choices.categories', 8));
});

test('the plan keeps its aim, goals and instructions; a finished visit changes nothing', function () {
    $knee = ($this->startKnee)();
    $details = "{$this->base}/courses/{$knee->id}/details";

    ($this->asMe)()->post($details, ['category' => 'teleport'])->assertSessionHasErrors('category');
    ($this->asMe)()->post($details, ['category' => 'rehabilitation', 'goals' => 'Climb stairs without pain', 'instructions' => 'Ice after walking'])->assertSessionHasNoErrors();
    expect($knee->fresh()->physio_details['plan'])->toBe(['category' => 'rehabilitation', 'instructions' => 'Ice after walking'])
        ->and($knee->fresh()->goal)->toBe('Climb stairs without pain');

    ($this->asMe)()->post("{$this->base}/finish", ['courses' => [['id' => $knee->id, 'done' => true]]])->assertSessionHasNoErrors();
    ($this->asMe)()->post($details, ['goals' => 'Something else'])->assertStatus(403);
    expect($knee->fresh()->goal)->toBe('Climb stairs without pain');
});

test('one step: the problem, its assessment and plan, and the treatment started — or nothing at all', function () {
    $plan = fn (array $extra = []) => ($this->asMe)()->post("{$this->base}/plan", [
        'problem' => 'knee_pain', 'side' => 'right',
        'complaint' => ['duration' => 'weeks', 'severity' => 'moderate', 'difficulties' => ['stairs']],
        'diagnosis' => 'Patellofemoral pain', 'category' => 'rehabilitation', 'goals' => 'Stairs without pain',
        'type' => 'full', 'frequency' => 'alternate', 'sessions' => 12, 'start' => true, ...$extra,
    ]);

    // A bad package undoes everything: no problem is left half made.
    $plan(['package' => 'nope'])->assertSessionHasErrors('physio');
    expect(PatientTreatment::query()->withoutGlobalScopes()->count())->toBe(0);

    $plan()->assertSessionHasNoErrors();
    $knee = ($this->course)('knee_pain');
    expect([$knee->status, $knee->session_type, $knee->visit_frequency, $knee->sessions_planned, $knee->goal])->toBe([PatientTreatment::IN_PROGRESS, 'full', 'alternate', 12, 'Stairs without pain'])
        ->and($knee->physio_details['complaint']['severity'])->toBe('moderate')
        ->and($knee->physio_details['plan']['category'])->toBe('rehabilitation')
        ->and($knee->primary_diagnosis_id)->not->toBeNull();

    // The running plan changes the same way; a problem found but started later keeps its plan.
    ($this->asMe)()->post("{$this->base}/plan", ['course_id' => $knee->id, 'type' => 'exercise', 'frequency' => 'daily', 'sessions' => 15])->assertSessionHasNoErrors();
    expect([$knee->fresh()->session_type, $knee->fresh()->sessions_planned])->toBe(['exercise', 15]);
    ($this->asMe)()->post("{$this->base}/plan", ['problem' => 'shoulder_pain', 'side' => 'left', 'type' => 'full', 'sessions' => 6, 'start' => false])->assertSessionHasNoErrors();
    expect(($this->course)('shoulder_pain')->only(['status', 'sessions_planned']))->toBe(['status' => PatientTreatment::PLANNED, 'sessions_planned' => 6]);

    // With the problems recorded, a consultation only needs nothing typed.
    ($this->asMe)()->post("{$this->base}/finish", ['courses' => []])->assertSessionHasNoErrors();
    expect(ClinicalEncounter::query()->withoutGlobalScopes()->find($this->encounter->id)->chief_complaint)->toBe('Physiotherapy: Knee pain · Right, Shoulder pain · Left');
});

test('exercises are prescribed from the library, copied, revised only when changed, and printed', function () {
    $owner = $this->t->actAsOwner();
    $catalogue = app(PhysioCatalogue::class);
    $catalogue->save($this->f->clinic, [...$catalogue->resolve($this->f->clinic), 'exercises' => [
        ['key' => 'slr', 'label' => 'Straight leg raise', 'category' => 'knee', 'sets' => 3, 'reps' => 10, 'hold' => 5, 'instructions' => 'Keep the knee straight'],
        ['key' => 'quad_sets', 'label' => 'Quad sets', 'category' => 'knee', 'sets' => 3, 'reps' => 15],
    ]], $owner);

    $plan = fn (array $exercises, array $extra = []) => ($this->asMe)()->post("{$this->base}/plan", ['problem' => 'knee_pain', 'side' => 'right', 'type' => 'full', 'start' => true, 'exercises' => $exercises, ...$extra]);
    $plan([['key' => 'nope']])->assertSessionHasErrors('physio');
    $plan([['key' => 'slr', 'per_day' => 2], ['key' => 'quad_sets', 'sets' => 2]])->assertSessionHasNoErrors();
    $knee = ($this->course)('knee_pain');

    $carePlan = PhysioCarePlan::query()->withoutGlobalScopes()->where('patient_treatment_id', $knee->id)->sole();
    expect($carePlan->exercises()->withoutGlobalScopes()->get()->map->only(['name', 'sets', 'reps', 'hold_seconds', 'frequency'])->all())->toBe([
        ['name' => 'Straight leg raise', 'sets' => 3, 'reps' => 10, 'hold_seconds' => 5, 'frequency' => '2× a day'],
        ['name' => 'Quad sets', 'sets' => 2, 'reps' => 15, 'hold_seconds' => null, 'frequency' => null],
    ]);

    // The same list again changes nothing; a changed one is a new revision, the old one kept.
    $again = fn (array $exercises) => ($this->asMe)()->post("{$this->base}/plan", ['course_id' => $knee->id, 'exercises' => $exercises]);
    $again([['key' => 'slr', 'per_day' => 2], ['key' => 'quad_sets', 'sets' => 2]])->assertSessionHasNoErrors();
    expect(PhysioCarePlan::query()->withoutGlobalScopes()->where('patient_treatment_id', $knee->id)->count())->toBe(1);
    $again([['key' => 'slr', 'per_day' => 3]])->assertSessionHasNoErrors();
    expect(PhysioCarePlan::query()->withoutGlobalScopes()->where('patient_treatment_id', $knee->id)->pluck('status')->all())->toBe([PhysioCarePlan::SUPERSEDED, PhysioCarePlan::ACTIVE]);

    ($this->asMe)()->get("/clinic/consultations/{$this->encounter->id}")->assertInertia(fn (Assert $page) => $page
        ->where('physio.courses.0.exercises.0.name', 'Straight leg raise')
        ->where('physio.courses.0.exercises.0.per_day', 3)
        ->has('physio.exercises', 2));
    ($this->asMe)()->get("{$this->base}/courses/{$knee->id}/exercise-sheet")->assertOk()->assertSee('Straight leg raise')->assertSee('3× a day')->assertDontSee('Quad sets');
});

test('the exercises are done as prescribed in one tap, or marked partly done or skipped', function () {
    ($this->asMe)()->post("{$this->base}/plan", ['problem' => 'knee_pain', 'side' => 'right', 'type' => 'full', 'start' => true,
        'exercises' => [['key' => 'straight_leg_raise'], ['key' => 'quad_sets'], ['key' => 'heel_slides']]])->assertSessionHasNoErrors();
    $knee = ($this->course)('knee_pain');
    $ids = PhysioExercise::query()->withoutGlobalScopes()->where('patient_treatment_id', $knee->id)->orderBy('sort_order')->pluck('id')->all();

    ($this->asMe)()->post("{$this->base}/finish", ['courses' => [['id' => $knee->id, 'done' => true, 'exercises' => [
        ['id' => $ids[1], 'status' => 'partial', 'sets' => 2, 'reps' => 15],
        ['id' => $ids[2], 'status' => 'skipped'],
    ]]]])->assertSessionHasNoErrors();

    expect(PhysioSessionExercise::query()->withoutGlobalScopes()->orderBy('position')->get()->map->only(['name', 'status', 'sets', 'reps'])->all())->toBe([
        ['name' => 'Straight leg raise', 'status' => 'performed', 'sets' => 3, 'reps' => 10],
        ['name' => 'Quad sets', 'status' => 'partial', 'sets' => 2, 'reps' => 15],
        ['name' => 'Heel slides', 'status' => 'skipped', 'sets' => null, 'reps' => null],
    ]);
    ($this->asMe)()->get("/clinic/consultations/{$this->encounter->id}")->assertInertia(fn (Assert $page) => $page->where('physio.history.0.exercises.1.status', 'partial'));
});

test('the owner keeps the exercise library and each problem\'s usual exercises; a form without the library leaves it as it was', function () {
    $put = fn (array $body) => ($this->asOwner)()->put('/clinic/admin/settings/physio', [
        'treatments' => [['key' => 'exercise', 'label' => 'Exercise', 'minutes' => 20]], 'addons' => [],
        'problems' => [['key' => 'knee_pain', 'label' => 'Knee pain', 'sided' => true, 'types' => [['label' => 'Exercise only', 'treatments' => ['exercise']]], 'packages' => [], 'exercises' => ['step_ups', 'gone']]],
        ...$body,
    ]);

    $put(['exercises' => [['label' => 'Step-ups', 'category' => 'knee', 'sets' => 3, 'reps' => 12, 'level' => 'medium', 'equipment' => 'Step', 'precautions' => 'Hold the rail']]])->assertSessionHasNoErrors();
    $put(['exercises' => [['label' => 'Bad', 'category' => 'elbow']]])->assertSessionHasErrors('exercises.0.category');

    $catalogue = app(PhysioCatalogue::class);
    expect($catalogue->exercises($this->f->clinic->fresh())['step_ups'])->toMatchArray(['label' => 'Step-ups', 'category' => 'knee', 'sets' => 3, 'reps' => 12, 'level' => 'medium', 'equipment' => 'Step', 'precautions' => 'Hold the rail'])
        ->and($catalogue->problem($this->f->clinic->fresh(), 'knee_pain')['exercises'])->toBe(['step_ups']);

    $put([])->assertSessionHasNoErrors();
    expect(array_keys($catalogue->exercises($this->f->clinic->fresh())))->toBe(['step_ups']);
});

test('progress is what the doctor records: goals reached, notes kept, a review date, how the patient felt', function () {
    ($this->asMe)()->post("{$this->base}/plan", ['problem' => 'knee_pain', 'side' => 'right', 'type' => 'full', 'start' => true,
        'complaint' => ['severity' => 'severe', 'difficulties' => ['stairs']], 'goals' => 'Stairs without pain, Sit on the floor'])->assertSessionHasNoErrors();
    $knee = ($this->course)('knee_pain');
    $progress = "{$this->base}/courses/{$knee->id}/progress";

    ($this->asMe)()->post($progress, ['goal' => 'Fly', 'done' => true])->assertSessionHasErrors('physio');
    ($this->asMe)()->post($progress, ['goal' => 'Stairs without pain', 'done' => true])->assertSessionHasNoErrors();
    ($this->asMe)()->post($progress, ['note' => 'Stairs easier', 'review' => true, 'review_on' => '2026-11-17'])->assertSessionHasNoErrors();
    ($this->asMe)()->post($progress, ['review_on' => '2026-01-01'])->assertSessionHasErrors('review_on');
    // The complaint as it is now; the first one stays the "then".
    ($this->asMe)()->post("{$this->base}/plan", ['course_id' => $knee->id, 'complaint' => ['severity' => 'mild', 'difficulties' => []]])->assertSessionHasNoErrors();
    ($this->asMe)()->post("{$this->base}/finish", ['courses' => [['id' => $knee->id, 'done' => true, 'feel' => 'better']]])->assertSessionHasNoErrors();

    ($this->asMe)()->get("/clinic/consultations/{$this->encounter->id}")->assertInertia(fn (Assert $page) => $page
        ->where('physio.courses.0.progress.initial.complaint.severity', 'severe')
        ->where('physio.courses.0.progress.now.severity', 'mild')
        ->where('physio.courses.0.progress.goals', [['text' => 'Stairs without pain', 'done_on' => '2026-11-03'], ['text' => 'Sit on the floor', 'done_on' => null]])
        ->where('physio.courses.0.progress.notes.0.text', 'Stairs easier')
        ->where('physio.courses.0.progress.notes.0.review', true)
        ->where('physio.courses.0.progress.review_on', '2026-11-17')
        ->where('physio.courses.0.progress.feels.0.feel', 'better'));
});

test('a package shows when it was bought, its bill and how much is left; a follow-up is added from the visit', function () {
    $knee = ($this->startKnee)(['type' => 'full', 'package' => 'kfull10']);

    ($this->asMe)()->get("/clinic/consultations/{$this->encounter->id}")->assertInertia(fn (Assert $page) => $page
        ->where('physio.courses.0.packages.0.name', 'Knee full 10')
        ->where('physio.courses.0.packages.0.state', 'active')
        ->where('physio.courses.0.packages.0.left', 10)
        ->where('physio.courses.0.packages.0.sold_on', '2026-11-03')
        ->where('physio.courses.0.packages.0.bill.amount', 2000)
        ->where('physio.courses.0.packages.0.bill.due', 2000)
        ->where('links.follow_up', route('clinic.patients.follow-ups.store', $this->patient->id)));

    ($this->asMe)()->post(route('clinic.patients.follow-ups.store', $this->patient->id), ['due_on' => '2026-11-17', 'reason' => 'Knee pain · Right review'])->assertSessionHasNoErrors();
    ($this->asMe)()->get("/clinic/consultations/{$this->encounter->id}")->assertInertia(fn (Assert $page) => $page
        ->where('physio.follow_ups.0.due_on', '2026-11-17')
        ->where('physio.follow_ups.0.reason', 'Knee pain · Right review'));
});

test('every report of a problem is made from its record, prints, and downloads as a PDF', function () {
    ($this->asMe)()->post("{$this->base}/plan", ['problem' => 'knee_pain', 'side' => 'right', 'type' => 'full', 'start' => true, 'package' => null,
        'complaint' => ['duration' => 'weeks', 'severity' => 'moderate', 'difficulties' => ['stairs']], 'diagnosis' => 'Patellofemoral pain',
        'category' => 'rehabilitation', 'goals' => 'Stairs without pain', 'exercises' => [['key' => 'straight_leg_raise', 'per_day' => 2]]])->assertSessionHasNoErrors();
    $knee = ($this->course)('knee_pain');
    $report = fn (string $kind, array $query = []) => ($this->asMe)()->get("{$this->base}/courses/{$knee->id}/reports/{$kind}".($query ? '?'.http_build_query($query) : ''));

    // No session yet: there is no session report.
    $report('session')->assertNotFound();
    ($this->asMe)()->post("{$this->base}/finish", ['courses' => [['id' => $knee->id, 'done' => true, 'feel' => 'better']]])->assertSessionHasNoErrors();

    $report('assessment')->assertOk()->assertSee('Assessment &amp; diagnosis', false)->assertSee('1–3 weeks')->assertSee('Stair climbing')->assertSee('Patellofemoral pain');
    $report('plan')->assertOk()->assertSee('Full treatment')->assertSee('Rehabilitation')->assertSee('Straight leg raise')->assertSee('2× a day');
    $report('session')->assertOk()->assertSee('Session 1')->assertSee('Ultrasound')->assertSee('Better');
    $report('progress')->assertOk()->assertSee('Stairs without pain')->assertSee('Pending');
    $report('package')->assertOk()->assertSee('No package bought');
    $report('discharge')->assertNotFound();
    expect($report('plan', ['download' => 1])->headers->get('content-type'))->toContain('pdf');

    // Another patient's problem is not this visit's to print.
    $other = PatientTreatment::query()->withoutGlobalScopes()->whereKey($knee->id)->first()->replicate(['physio_details']);
    $other->forceFill(['patient_id' => $this->f->patient('Someone', 'Else')->id])->saveQuietly();
    ($this->asMe)()->get("{$this->base}/courses/{$other->id}/reports/plan")->assertNotFound();
});
