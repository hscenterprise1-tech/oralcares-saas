<?php

use App\ClinicRole;
use App\Models\CompanyFeature;
use App\Models\CompanyPlan;
use App\Models\CompanySubscription;
use App\Models\PatientProcedure;
use App\Models\PatientToothStatusHistory;
use App\Services\Clinical\EncounterWorkspace;
use App\Services\Reception\ReceptionCheckIn;
use App\Services\Reception\VisitContext;
use App\Support\Specialty\SpecialtyEntitlements;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\TreatmentFixture;

/**
 * A clinic uses exactly the specialty products its plan sells. The clinic
 * configures what it practises; the plan decides what it may use. Every
 * surface reads the same resolvers, so this checks the shared props, the
 * Front Desk intake, registration, the consultation's specialty routes, the
 * service catalogue and billing, settings, the Command Center and a plan
 * change.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-06 10:00', 'Asia/Kolkata'));
    $this->t = TreatmentFixture::make();
    $this->f = $this->t->f;
    $this->subscribe = function (array $specialtyModules): void {
        $plan = CompanyPlan::factory()->create();
        $plan->features()->sync(collect(['appointments', 'patients', 'billing', ...$specialtyModules])
            ->map(fn (string $key) => (CompanyFeature::where('key', $key)->first() ?? CompanyFeature::factory()->create(['key' => $key]))->id));
        // A plan change is a newer subscription; the resolver reads the latest.
        CompanySubscription::factory()->create(['clinic_id' => $this->f->clinic->id, 'company_plan_id' => $plan->id]);
    };
    $this->asOwner = fn () => $this->actingAs($this->f->owner)->withSession($this->f->session);
    $this->shared = function (): array {
        $clinic = [];
        ($this->asOwner)()->get('/clinic/patients')->assertOk()->assertInertia(function (Assert $page) use (&$clinic) {
            $clinic = $page->toArray()['props']['clinic'];
        });

        return $clinic;
    };
    // An open consultation with a practitioner of the given specialty.
    $this->consultation = function (string $specialty): array {
        [$user, $session] = $this->f->memberWithRole(ClinicRole::Doctor);
        $doctor = $this->f->doctor('Dr. '.ucfirst($specialty), $user);
        $doctor->practitionerProfile()->withoutGlobalScopes()->update(['specialty' => $specialty === 'dental' ? 'Dentist' : 'Physiotherapist']);
        $item = app(ReceptionCheckIn::class)->checkIn($this->f->clinic, $this->f->branch, $this->f->patient('Entitled', ucfirst($specialty)), doctor: $doctor,
            context: VisitContext::fromInput($this->f->clinic, ['visit_specialty' => $specialty]));
        $this->actingAs($user);
        $encounter = app(EncounterWorkspace::class)->startFromQueue($item, $doctor);

        return [$encounter, fn () => $this->actingAs($user)->withSession($session)];
    };
});

test('each plan shows exactly the specialties it sells, everywhere the clinic chooses one', function (array $modules, array $specialties, array $present, array $absent) {
    ($this->subscribe)($modules);

    $clinic = ($this->shared)();
    expect($clinic['specialties'])->toBe($specialties)
        ->and($clinic['extensions'])->toContain(...$present);
    foreach ($absent as $extension) {
        expect($clinic['extensions'])->not->toContain($extension);
    }

    // The Front Desk's New Case / Repeat Case offer the same specialties; one is chosen for them.
    $intake = ($this->asOwner)()->getJson('/clinic/reception/intake')->assertOk()->json('specialties');
    expect(collect($intake)->pluck('key')->all())->toBe($specialties);
})->with([
    'dental plan' => [['dental'], ['dental'], ['odontogram'], ['physio_assessment', 'session_packages']],
    'physiotherapy plan' => [['physiotherapy'], ['physiotherapy'], ['physio_assessment', 'session_packages'], ['odontogram']],
    'dental and physiotherapy plan' => [['dental', 'physiotherapy'], ['dental', 'physiotherapy'], ['odontogram', 'session_packages'], []],
    'a plan that sells no specialty module keeps the clinic\'s own set' => [[], ['dental', 'physiotherapy'], ['odontogram', 'session_packages'], []],
]);

test('a physiotherapy clinic cannot register a dental patient or chart a tooth, even by direct request', function () {
    ($this->subscribe)(['physiotherapy']);

    ($this->asOwner)()->post('/clinic/patients', ['first_name' => 'Ravi', 'last_name' => 'K', 'phone' => '9811100002', 'gender' => 'male', 'specialties' => ['dental']])
        ->assertSessionHasErrors('specialties.0');

    [$encounter, $asDoctor] = ($this->consultation)('physiotherapy');
    $asDoctor()->post("/clinic/consultations/{$encounter->id}/dental/chart", ['tooth' => 16, 'condition' => array_key_first(config('clinic_dental.conditions'))])
        ->assertForbidden();
    expect(PatientToothStatusHistory::withoutGlobalScopes()->where('encounter_id', $encounter->id)->exists())->toBeFalse();

    // The consultation offers no dental services, and a dental one sent anyway is refused.
    $asDoctor()->get("/clinic/consultations/{$encounter->id}")->assertInertia(fn (Assert $page) => $page
        ->where('dental', null)
        ->where('services', fn ($services) => collect($services)->pluck('code')->intersect(['ACC', 'CLN', 'OBT', 'FIL'])->isEmpty()));
    $asDoctor()->post("/clinic/consultations/{$encounter->id}/procedures", ['clinical_procedure_id' => $this->t->procedures['FIL']->id]);
    expect(PatientProcedure::withoutGlobalScopes()->where('encounter_id', $encounter->id)->exists())->toBeFalse();
});

test('a dental clinic cannot record physiotherapy or sell a session package, even by direct request', function () {
    ($this->subscribe)(['dental']);
    [$encounter, $asDoctor] = ($this->consultation)('dental');

    $asDoctor()->post("/clinic/consultations/{$encounter->id}/physio/assessment", ['chief_complaint' => 'Knee pain'])->assertForbidden();
    $asDoctor()->post("/clinic/consultations/{$encounter->id}/procedures", ['clinical_procedure_id' => $this->t->procedures['PHY']->id]);
    $asDoctor()->post("/clinic/consultations/{$encounter->id}/procedures", ['clinical_procedure_id' => $this->t->procedures['FIL']->id]);

    expect(PatientProcedure::withoutGlobalScopes()->where('encounter_id', $encounter->id)->pluck('code')->all())->toBe(['FIL']);
});

test('a clinic set up as dental that buys physiotherapy can check patients in to physiotherapy at once', function () {
    $this->f->clinic->update(['specialties' => ['dental']]);
    ($this->subscribe)(['physiotherapy']);

    $intake = ($this->asOwner)()->getJson('/clinic/reception/intake')->assertOk()->json('specialties');
    expect(collect($intake)->pluck('key')->all())->toBe(['physiotherapy']);
    [$encounter] = ($this->consultation)('physiotherapy');
    expect($encounter->specialty)->toBe('physiotherapy');
});

test('the owner configures only specialties the plan sells, and settings offer nothing else', function () {
    ($this->subscribe)(['physiotherapy']);

    $offered = ($this->asOwner)()->getJson('/clinic/admin/specialties')->assertOk()->json('data');
    expect(collect($offered)->pluck('key')->all())->toBe(['physiotherapy']);
});

test('the Command Center raises physiotherapy packages only for a clinic that bought physiotherapy', function (array $modules, bool $physioFigures) {
    ($this->subscribe)($modules);
    DB::table('patient_session_packages')->insert(['clinic_id' => $this->f->clinic->id, 'branch_id' => $this->f->branch->id, 'patient_id' => $this->f->patient('Package', 'Ending')->id,
        'name' => 'Knee 10', 'sessions_total' => 10, 'status' => 'active', 'valid_until' => '2026-10-08', 'created_at' => now(), 'updated_at' => now()]);

    ($this->asOwner)()->get('/clinic/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('widgetData.needs_attention.items', fn ($items) => collect($items)->pluck('id')->contains('packages_expiring') === $physioFigures));
})->with([
    'dental plan' => [['dental'], false],
    'physiotherapy plan' => [['physiotherapy'], true],
]);

test('a plan change hides a specialty without losing its records, and an upgrade brings it back', function () {
    ($this->subscribe)(['dental', 'physiotherapy']);
    [$encounter, $asDoctor] = ($this->consultation)('dental');
    $asDoctor()->post("/clinic/consultations/{$encounter->id}/dental/chart", ['tooth' => 16, 'condition' => array_key_first(config('clinic_dental.conditions'))])
        ->assertSessionHasNoErrors();

    ($this->subscribe)(['physiotherapy']);
    expect(($this->shared)()['specialties'])->toBe(['physiotherapy']);
    $asDoctor()->post("/clinic/consultations/{$encounter->id}/dental/chart", ['tooth' => 17, 'condition' => array_key_first(config('clinic_dental.conditions'))])
        ->assertForbidden();
    expect(PatientToothStatusHistory::withoutGlobalScopes()->where('encounter_id', $encounter->id)->count())->toBe(1);

    ($this->subscribe)(['dental', 'physiotherapy']);
    expect(($this->shared)()['specialties'])->toBe(['dental', 'physiotherapy']);
});

test('the entitlement rule: a specialty module grants all its specialties; no module, no restriction', function () {
    $entitlements = app(SpecialtyEntitlements::class);

    expect($entitlements->fromEntitlement(['has_subscription' => true, 'modules' => ['dental']]))->toBe(['dental', 'orthodontics'])
        ->and($entitlements->fromEntitlement(['has_subscription' => true, 'modules' => ['patients', 'billing']]))->toBeNull()
        ->and($entitlements->fromEntitlement(['has_subscription' => false, 'modules' => []]))->toBeNull()
        ->and($entitlements->productsOf(['dental', 'orthodontics']))->toBe(['dental'])
        ->and(SpecialtyEntitlements::restrict(['dental'], ['physiotherapy'], ['physiotherapy']))->toBe(['physiotherapy'])
        ->and(SpecialtyEntitlements::restrict(['dental'], ['physiotherapy']))->toBe([]);
});
