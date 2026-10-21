<?php

use App\BranchStatus;
use App\ClinicRole;
use App\Models\Branch;
use App\Models\ClinicalSpecialty;
use App\Models\ClinicEmployee;
use App\Models\CompanyFeature;
use App\Models\CompanyPlan;
use App\Models\CompanySubscription;
use App\Models\Leave;
use App\Models\Patient;
use App\Models\PatientVisit;
use App\Models\PractitionerQualification;
use App\Services\Clinical\ClinicalContext;
use App\Services\Clinical\ClinicalContextResolver;
use App\Services\Patient\ClinicSpecialties;
use App\Services\Reception\ReceptionCheckIn;
use App\Services\Treatment\PatientTreatments;
use App\Services\Treatment\TreatmentReadModel;
use App\Support\Specialty\PracticeAreas;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\TreatmentFixture;

/**
 * Reception picks the practitioner; the clinical context (module → practice
 * area → specialty) follows from them, on the server, inside the clinic's
 * plan, set-up and branch. Qualifications are recorded but never decide it.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-10 10:00', 'Asia/Kolkata'));
    $this->t = TreatmentFixture::make();
    $this->f = $this->t->f;
    $this->resolver = app(ClinicalContextResolver::class);
    $this->asOwner = fn () => $this->actingAs($this->f->owner)->withSession($this->f->session);

    // A practitioner whose primary specialty is a keyed practice area, with qualifications.
    $this->practitioner = function (string $name, ?string $area, array $qualifications = [], array $profile = []): ClinicEmployee {
        $employee = $this->f->doctor($name, $profile['user'] ?? null);
        $keyed = ['organization_id' => $this->f->clinic->organization_id, 'practice_area_key' => $area];
        $entry = $area ? (ClinicalSpecialty::withoutGlobalScopes()->where($keyed)->first()
            ?? ClinicalSpecialty::withoutGlobalScopes()->forceCreate([...$keyed, 'name' => app(PracticeAreas::class)->get($area)['label'], 'is_active' => true])) : null;
        $practitioner = $employee->practitionerProfile()->withoutGlobalScopes()->first();
        $practitioner->update(['primary_specialty_id' => $entry?->id, 'specialty' => $profile['specialty'] ?? $entry?->name ?? 'Dentist', 'is_active' => $profile['is_active'] ?? true]);
        foreach ($qualifications as $position => [$qualification, $degree, $specialization]) {
            PractitionerQualification::create(['clinic_id' => $this->f->clinic->id, 'doctor_profile_id' => $practitioner->id, 'qualification' => $qualification, 'degree_type' => $degree, 'specialization' => $specialization, 'position' => $position]);
        }

        return $employee->fresh();
    };
    $this->raj = ($this->practitioner)('Dr. Raj Patel', 'general_dentistry', [['BDS', 'bachelor', null]]);
    $this->priya = ($this->practitioner)('Dr. Priya Patel', 'orthodontics', [['BDS', 'bachelor', null], ['MDS', 'master', 'Orthodontics']]);
    $this->amit = ($this->practitioner)('Dr. Amit Shah', 'endodontics', [['BDS', 'bachelor', null], ['MDS', 'master', 'Conservative Dentistry & Endodontics']]);
    $this->neha = ($this->practitioner)('Dr. Neha Shah', 'orthopedic_rehabilitation', [['BPT', 'bachelor', null]]);

    $this->resolve = fn (array $sources, ?Branch $branch = null) => $this->resolver->resolve($this->f->clinic->fresh(), $branch ?? $this->f->branch, $sources);
    $this->subscribe = function (array $specialtyModules): void {
        $plan = CompanyPlan::factory()->create();
        $plan->features()->sync(collect(['appointments', 'patients', 'billing', ...$specialtyModules])
            ->map(fn (string $key) => (CompanyFeature::where('key', $key)->first() ?? CompanyFeature::factory()->create(['key' => $key]))->id));
        CompanySubscription::factory()->create(['clinic_id' => $this->f->clinic->id, 'company_plan_id' => $plan->id]);
    };
    $this->checkIn = fn (array $body) => ($this->asOwner)()->postJson('/clinic/reception/check-in', ['patient_id' => $this->f->patient()->id, ...$body]);
});

// ---------------------------------------------------------------- Practitioner profile

test('a practitioner\'s areas come from their specialty assignment, and qualifications stay separate', function () {
    $profile = fn (ClinicEmployee $employee) => $employee->practitionerProfile()->withoutGlobalScopes()->first();

    expect($profile($this->raj)->practiceAreaKeys())->toBe(['general_dentistry'])
        ->and($profile($this->raj)->specialtyKeys())->toBe(['dental'])
        ->and($profile($this->raj)->credentials())->toBe('BDS')
        ->and($profile($this->priya)->practiceAreaKeys())->toBe(['orthodontics'])
        ->and($profile($this->priya)->specialtyKeys())->toBe(['orthodontics', 'dental'])
        ->and($profile($this->priya)->credentials())->toBe('BDS · MDS')
        ->and($profile($this->amit)->specialtyKeys())->toBe(['dental'])
        ->and($profile($this->neha)->specialtyKeys())->toBe(['physiotherapy']);

    // MDS (Orthodontics) on record, but practising as a general dentist: the assignment decides.
    $generalist = ($this->practitioner)('Dr. Kavita Rao', 'general_dentistry', [['BDS', 'bachelor', null], ['MDS', 'master', 'Orthodontics']]);
    expect($profile($generalist)->practiceAreaKeys())->toBe(['general_dentistry']);
});

test('profiles recorded before areas existed are read from their names, then qualifications, then title', function () {
    $legacy = fn (string $name, array $profile, array $qualifications = []) => ($this->practitioner)($name, null, $qualifications, $profile)->practitionerProfile()->withoutGlobalScopes()->first();

    expect($legacy('Dr. Endo', ['specialty' => 'Endodontist'])->practiceAreaKeys())->toBe(['endodontics'])
        ->and($legacy('Dr. Title', ['specialty' => 'Dentist'])->practiceAreaKeys())->toBe(['general_dentistry'])
        ->and($legacy('Dr. Physician', ['specialty' => 'General Physician'])->practiceAreaKeys())->toBe(['general_medicine'])
        ->and($legacy('Dr. Quals', ['specialty' => 'Consultant'], [['MDS', 'master', 'Periodontology']])->practiceAreaKeys())->toBe(['periodontics'])
        ->and($legacy('Dr. Gone', ['specialty' => 'Orthodontist', 'is_active' => false])->practiceAreaKeys())->toBe([]);
});

test('a practitioner without a login is as resolvable as one with a login', function () {
    [$user] = $this->f->memberWithRole(ClinicRole::Doctor);
    $withLogin = ($this->practitioner)('Dr. Login', 'periodontics', [], ['user' => $user]);

    expect($this->raj->user_id)->toBeNull()
        ->and(($this->resolve)(['practitioner' => $this->raj->id])->practiceArea)->toBe('general_dentistry')
        ->and(($this->resolve)(['practitioner' => $withLogin->id])->practiceArea)->toBe('periodontics');
});

// ---------------------------------------------------------------- Context resolution

test('choosing the practitioner resolves module, area and specialty', function () {
    $context = ($this->resolve)(['practitioner' => $this->priya->id]);
    expect([$context->source, $context->module, $context->practiceArea, $context->specialtyKey])->toBe([ClinicalContext::SELECTED, 'dental', 'orthodontics', 'dental'])
        ->and($context->clinicSpecialtyId)->not->toBeNull()
        ->and($context->capabilities())->toHaveKeys(['dental_consultation', 'braces_treatment', 'aligner_treatment'])
        ->and($context->capabilities())->not->toHaveKey('root_canal_treatment');

    // With Orthodontics practised as its own specialty, the visit is recorded under it.
    app(ClinicSpecialties::class)->sync($this->f->clinic, ['dental', 'orthodontics', 'physiotherapy']);
    expect(($this->resolve)(['practitioner' => $this->priya->id])->specialtyKey)->toBe('orthodontics')
        ->and(($this->resolve)(['practitioner' => $this->amit->id])->specialtyKey)->toBe('dental');
});

test('each practitioner resolves to their own context', function (string $who, array $expected) {
    $context = ($this->resolve)(['practitioner' => $this->{$who}->id]);

    expect([$context->module, $context->practiceArea, $context->specialtyKey])->toBe($expected)
        ->and(app(PracticeAreas::class)->contextLabel($context->practiceArea))->not->toBeNull();
})->with([
    'BDS general dentist' => ['raj', ['dental', 'general_dentistry', 'dental']],
    'MDS orthodontist' => ['priya', ['dental', 'orthodontics', 'dental']],
    'MDS endodontist' => ['amit', ['dental', 'endodontics', 'dental']],
    'physiotherapist' => ['neha', ['physiotherapy', 'orthopedic_rehabilitation', 'physiotherapy']],
]);

test('sources are taken in order: chosen, appointment, queue, assigned, signed-in practitioner, branch', function () {
    $patient = $this->f->patient();
    $appointment = $this->f->appointment($patient, $this->amit, CarbonImmutable::parse('2026-10-10 11:00'));

    expect(($this->resolve)(['practitioner' => $this->priya->id, 'appointment' => $appointment])->practitioner->id)->toBe($this->priya->id)
        ->and(($this->resolve)(['appointment' => $appointment])->source)->toBe(ClinicalContext::APPOINTMENT)
        ->and(($this->resolve)(['appointment' => $appointment])->practiceArea)->toBe('endodontics');

    $item = app(ReceptionCheckIn::class)->checkIn($this->f->clinic, $this->f->branch, $this->f->patient('Queued', 'One'), doctor: $this->neha);
    expect(($this->resolve)(['queue_item' => $item])->source)->toBe(ClinicalContext::QUEUE)
        ->and(($this->resolve)(['queue_item' => $item])->module)->toBe('physiotherapy');

    $assigned = $this->f->patient('Assigned', 'Patient', ['current_practitioner_id' => $this->raj->id]);
    expect(($this->resolve)(['patient' => $assigned])->source)->toBe(ClinicalContext::ASSIGNED)
        ->and(($this->resolve)(['patient' => $assigned])->practitioner->id)->toBe($this->raj->id);

    [$user] = $this->f->memberWithRole(ClinicRole::Doctor);
    ($this->practitioner)('Dr. Self', 'prosthodontics', [], ['user' => $user]);
    expect(($this->resolve)(['user' => $user])->source)->toBe(ClinicalContext::CURRENT_PRACTITIONER)
        ->and(($this->resolve)(['user' => $user])->practiceArea)->toBe('prosthodontics');
});

test('without a practitioner: one specialty is the branch default, several need a choice, never a guess', function () {
    expect(($this->resolve)(['specialty' => 'physiotherapy'])->source)->toBe(ClinicalContext::SPECIALTY_SELECTION)
        ->and(($this->resolve)([])->isResolved())->toBeFalse()
        ->and(fn () => ($this->resolve)(['require' => true]))->toThrow(ValidationException::class, 'Choose the doctor, or what this visit is for.');

    $physioOnly = Branch::factory()->create(['clinic_id' => $this->f->clinic->id, 'name' => 'Rehab Wing', 'status' => BranchStatus::Active, 'specialties' => ['physiotherapy']]);
    $default = ($this->resolve)(['require' => true], $physioOnly);
    expect([$default->source, $default->specialtyKey])->toBe([ClinicalContext::BRANCH_DEFAULT, 'physiotherapy']);
});

test('the request cannot steer a practitioner into a context they do not practise', function () {
    expect(fn () => ($this->resolve)(['practitioner' => $this->priya->id, 'specialty' => 'physiotherapy']))->toThrow(ValidationException::class, 'does not practise Physiotherapy')
        ->and(fn () => ($this->resolve)(['practitioner' => $this->priya->id, 'area' => 'endodontics']))->toThrow(ValidationException::class)
        ->and(fn () => ($this->resolve)(['practitioner' => $this->neha->id, 'area' => 'orthodontics']))->toThrow(ValidationException::class);
});

test('inactive, on-leave and other-branch practitioners are refused, never swapped', function () {
    $inactive = ($this->practitioner)('Dr. Inactive', 'periodontics', [], ['is_active' => false]);
    $east = Branch::factory()->create(['clinic_id' => $this->f->clinic->id, 'name' => 'East', 'status' => BranchStatus::Active]);
    $eastOnly = ($this->practitioner)('Dr. East', 'orthodontics');
    $eastOnly->branches()->attach($east->id);
    Leave::create(['clinic_id' => $this->f->clinic->id, 'employee_id' => $this->amit->id, 'leave_type' => 'casual', 'status' => 'approved', 'start_date' => '2026-10-10', 'end_date' => '2026-10-10']);

    expect(fn () => ($this->resolve)(['practitioner' => $inactive->id]))->toThrow(ValidationException::class, 'not set up as a practitioner')
        ->and(fn () => ($this->resolve)(['practitioner' => $eastOnly->id]))->toThrow(ValidationException::class, 'does not see patients at Main Branch')
        ->and(fn () => ($this->resolve)(['practitioner' => $this->amit->id]))->toThrow(ValidationException::class, 'on leave today')
        ->and(($this->resolve)(['practitioner' => $eastOnly->id], $east)->practiceArea)->toBe('orthodontics');
});

// ---------------------------------------------------------------- Entitlements

test('a practitioner of a module the plan does not sell cannot be chosen', function (array $modules, array $offered, array $refused) {
    ($this->subscribe)($modules);
    $options = collect($this->resolver->practitionerOptions($this->f->clinic->fresh(), $this->f->branch))->pluck('name');

    foreach ($offered as $who) {
        expect($options)->toContain($this->{$who}->name);
        expect(($this->resolve)(['practitioner' => $this->{$who}->id])->isResolved())->toBeTrue();
    }
    foreach ($refused as $who) {
        expect($options)->not->toContain($this->{$who}->name);
        expect(fn () => ($this->resolve)(['practitioner' => $this->{$who}->id]))->toThrow(ValidationException::class, 'does not offer');
    }
})->with([
    'Dental only' => [['dental'], ['raj', 'priya', 'amit'], ['neha']],
    'Physiotherapy only' => [['physiotherapy'], ['neha'], ['raj', 'priya', 'amit']],
    'Dental + Physiotherapy' => [['dental', 'physiotherapy'], ['raj', 'priya', 'amit', 'neha'], []],
]);

// ---------------------------------------------------------------- Reception over HTTP

test('Repeat Case check-in: the chosen doctor alone decides the visit\'s context', function () {
    ($this->checkIn)(['practitioner_id' => $this->priya->id])->assertCreated()
        ->assertJsonPath('check_in.context.module.label', 'Dental')
        ->assertJsonPath('check_in.context.area.label', 'Orthodontics')
        ->assertJsonPath('check_in.doctor', 'Dr. Priya Patel');

    $visit = PatientVisit::query()->withoutGlobalScopes()->latest('id')->first();
    expect($visit->practice_area_key)->toBe('orthodontics')
        ->and($visit->clinic_specialty_id)->toBe(app(ClinicSpecialties::class)->active($this->f->clinic, 'dental')->id);

    ($this->checkIn)(['practitioner_id' => $this->neha->id])->assertCreated()->assertJsonPath('check_in.context.module.label', 'Physiotherapy');
    expect(PatientVisit::query()->withoutGlobalScopes()->latest('id')->first()->clinic_specialty_id)
        ->toBe(app(ClinicSpecialties::class)->active($this->f->clinic, 'physiotherapy')->id);
});

test('check-in refuses a forged specialty, area, cross-branch or cross-clinic practitioner', function () {
    ($this->checkIn)(['practitioner_id' => $this->priya->id, 'visit_specialty' => 'physiotherapy'])->assertStatus(422)->assertJsonValidationErrors('practitioner_id');
    ($this->checkIn)(['practitioner_id' => $this->neha->id, 'visit_area' => 'orthodontics'])->assertStatus(422)->assertJsonValidationErrors('practitioner_id');

    $east = Branch::factory()->create(['clinic_id' => $this->f->clinic->id, 'name' => 'East', 'status' => BranchStatus::Active]);
    $eastOnly = ($this->practitioner)('Dr. East', 'orthodontics');
    $eastOnly->branches()->attach($east->id);
    ($this->checkIn)(['practitioner_id' => $eastOnly->id])->assertStatus(422)->assertJsonValidationErrors('practitioner_id');

    $other = TreatmentFixture::make('other-clinic');
    ($this->checkIn)(['practitioner_id' => $other->f->doctorA->id])->assertNotFound();
    ($this->checkIn)([])->assertStatus(422)->assertJsonValidationErrors('practitioner_id');

    expect(PatientVisit::query()->withoutGlobalScopes()->where('clinic_id', $this->f->clinic->id)->count())->toBe(0);
});

test('New Case: the doctor resolves the context and the patient joins that specialty', function () {
    ($this->asOwner)()->postJson('/clinic/patients', [
        'surface' => 'new_case', 'first_name' => 'Isha', 'last_name' => 'Kapoor', 'phone' => '9811100777', 'gender' => 'female',
        'check_in' => true, 'doctor_id' => $this->neha->id,
    ])->assertCreated()
        ->assertJsonPath('check_in.doctor', 'Dr. Neha Shah')
        ->assertJsonPath('check_in.context.area.label', 'Orthopedic Rehabilitation');

    $patient = Patient::query()->withoutGlobalScopes()->where('phone', '9811100777')->sole();
    $enrolled = DB::table('patient_specialties as ps')->join('clinic_specialties as cs', 'cs.id', '=', 'ps.clinic_specialty_id')->where('ps.patient_id', $patient->id)->pluck('cs.specialty_key')->all();
    expect($enrolled)->toBe(['physiotherapy'])
        ->and(PatientVisit::query()->withoutGlobalScopes()->where('patient_id', $patient->id)->value('practice_area_key'))->toBe('orthopedic_rehabilitation');

    // The form's specialty may only pick among the doctor's own.
    ($this->asOwner)()->postJson('/clinic/patients', [
        'surface' => 'new_case', 'first_name' => 'Forged', 'last_name' => 'Context', 'phone' => '9811100778', 'gender' => 'male',
        'check_in' => true, 'doctor_id' => $this->priya->id, 'specialties' => ['physiotherapy'],
    ])->assertStatus(422)->assertJsonValidationErrors('doctor_id');
});

test('the desk\'s practitioner picker shows name, qualifications and what choosing them means', function () {
    $response = ($this->asOwner)()->getJson('/clinic/reception/intake')->assertOk();
    $priya = collect($response->json('doctors'))->firstWhere('name', 'Dr. Priya Patel');

    expect($priya['credentials'])->toBe('BDS · MDS')
        ->and($priya['module'])->toBe(['key' => 'dental', 'label' => 'Dental'])
        ->and($priya['area'])->toBe(['key' => 'orthodontics', 'label' => 'Orthodontics'])
        ->and($priya)->not->toHaveKeys(['clinic_id', 'email', 'user_id'])
        ->and(collect($response->json('doctors'))->firstWhere('name', 'Dr. Neha Shah')['module']['label'])->toBe('Physiotherapy');

    ($this->asOwner)()->get('/clinic/reception')->assertInertia(fn ($page) => $page->where('desk.doctors', fn ($doctors) => collect($doctors)->firstWhere('name', 'Dr. Priya Patel')['practice'] === 'Dental · Orthodontics'));
});

// ---------------------------------------------------------------- Staff profile

test('the staff profile assigns a practice area, keyed once per organisation and only within the plan', function () {
    $employee = $this->f->doctor('Dr. New Hire');
    ($this->asOwner)()->put("/clinic/admin/employees/{$employee->id}/professional", ['primary_specialty' => ['id' => null, 'name' => 'Periodontics', 'area' => 'periodontics'], 'additional_specialties' => [['id' => null, 'name' => 'Implantology']]])
        ->assertSessionHasNoErrors();

    $profile = $employee->practitionerProfile()->withoutGlobalScopes()->first();
    expect($profile->practiceAreaKeys())->toBe(['periodontics'])
        ->and(ClinicalSpecialty::withoutGlobalScopes()->where('practice_area_key', 'periodontics')->count())->toBe(1)
        ->and(ClinicalSpecialty::withoutGlobalScopes()->where('name', 'Implantology')->value('practice_area_key'))->toBeNull();

    ($this->subscribe)(['dental']);
    ($this->asOwner)()->put("/clinic/admin/employees/{$employee->id}/professional", ['primary_specialty' => ['id' => null, 'name' => 'Sports Rehabilitation', 'area' => 'sports_rehabilitation']])
        ->assertSessionHasErrors('primary_specialty');
    expect($profile->fresh()->practiceAreaKeys())->toBe(['periodontics']);
});

// ---------------------------------------------------------------- Patient 360

test('Patient 360 shows each module and area as its own care stream', function () {
    $patient = $this->f->patient('Both', 'Streams');
    $this->t->actAsOwner();
    $treatments = app(PatientTreatments::class);
    $treatments->startAdHoc($patient, 'dental', 'Braces', $this->priya, $this->f->branch, $this->f->owner);
    $treatments->startAdHoc($patient, 'physiotherapy', 'Knee rehabilitation', $this->neha, $this->f->branch, $this->f->owner);

    $streams = app(TreatmentReadModel::class)->careStreams($this->f->clinic->id, $patient->id, null);

    expect(collect($streams)->pluck('label')->all())->toBe(['Dental · Orthodontics', 'Physiotherapy · Orthopedic Rehabilitation'])
        ->and(collect($streams)->pluck('section')->all())->toBe(['treatment', 'physio'])
        ->and($streams[0]['treatments'][0]['title'])->toBe('Braces');
});

// ---------------------------------------------------------------- Backfill

test('the backfill keys legacy profiles from what they record; a dry run writes nothing', function () {
    $legacy = ($this->practitioner)('Dr. Legacy Endo', null, [], ['specialty' => 'Endodontist']);
    $profile = fn () => $legacy->practitionerProfile()->withoutGlobalScopes()->first()->fresh();

    $this->artisan('practitioners:backfill-practice-areas', ['--clinic' => [$this->f->clinic->id], '--dry-run' => true])->assertSuccessful();
    expect($profile()->primary_specialty_id)->toBeNull();

    $this->artisan('practitioners:backfill-practice-areas', ['--clinic' => [$this->f->clinic->id]])->assertSuccessful();
    expect($profile()->primarySpecialty->practice_area_key)->toBe('endodontics')
        ->and($profile()->practiceAreaKeys())->toBe(['endodontics']);
});
