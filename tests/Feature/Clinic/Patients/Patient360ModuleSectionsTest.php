<?php

use App\ClinicRole;
use App\Models\CompanyFeature;
use App\Models\CompanyPlan;
use App\Models\CompanySubscription;
use App\Services\Clinical\EncounterWorkspace;
use App\Services\Patient\PatientBranchActivityRecorder;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\TreatmentFixture;

/**
 * Dental and Physio are different products: a clinic sees the section of
 * each module its plan sells, and the server refuses the other — not just
 * the screen hiding it.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-26 10:00', 'Asia/Kolkata'));
    $this->t = TreatmentFixture::make();
    $this->f = $this->t->f;
    $this->patient = $this->f->patient('Meera', 'Iyer');
    app(PatientBranchActivityRecorder::class)->record($this->f->clinic->id, $this->patient->id, $this->f->branch->id, now(), 'registration');
    $this->subscribe = function (array $modules): void {
        $plan = CompanyPlan::factory()->create();
        $plan->features()->sync(collect(['appointments', 'patients', 'billing', ...$modules])
            ->map(fn (string $key) => (CompanyFeature::where('key', $key)->first() ?? CompanyFeature::factory()->create(['key' => $key]))->id));
        CompanySubscription::factory()->create(['clinic_id' => $this->f->clinic->id, 'company_plan_id' => $plan->id]);
    };
    $this->asOwner = fn () => $this->actingAs($this->f->owner)->withSession($this->f->session);
    $this->sections = function (): array {
        $keys = [];
        ($this->asOwner)()->get("/clinic/patients/{$this->patient->id}")->assertOk()->assertInertia(function (Assert $page) use (&$keys) {
            $keys = collect($page->toArray()['props']['sections'])->where('built', true)->pluck('key')->all();
        });

        return $keys;
    };
    $this->section = fn (string $key) => ($this->asOwner)()->getJson(route('clinic.patients.360.section', [$this->patient->id, $key]));
});

test('a dental-only clinic gets the Dental section and the server refuses Physio', function () {
    ($this->subscribe)(['dental']);

    expect(($this->sections)())->toContain('dental')->not->toContain('physio');
    ($this->section)('dental')->assertOk()->assertJsonPath('view', 'clinical');
    ($this->section)('physio')->assertForbidden();
});

test('a physio-only clinic gets the Physio section and the server refuses Dental', function () {
    ($this->subscribe)(['physiotherapy']);

    expect(($this->sections)())->toContain('physio')->not->toContain('dental');
    ($this->section)('physio')->assertOk()->assertJsonStructure(['plan', 'sessions', 'packages', 'progress']);
    ($this->section)('dental')->assertForbidden();
});

test('a clinic with both modules gets both sections', function () {
    ($this->subscribe)(['dental', 'physiotherapy']);

    expect(($this->sections)())->toContain('dental', 'physio');
});

test('a clinic that practises neither Dental nor Physio gets neither section, the server refuses both, and its consultation has no dental or physio panel', function () {
    // A plan selling no specialty module does not restrict (SpecialtyEntitlements); the clinic's own specialties decide.
    ($this->subscribe)([]);
    $this->f->clinic->update(['specialties' => ['general_medicine']]);

    expect(($this->sections)())->not->toContain('dental')->not->toContain('physio');
    ($this->section)('dental')->assertForbidden();
    ($this->section)('physio')->assertForbidden();

    [$doctorUser, $doctorSession] = $this->f->memberWithRole(ClinicRole::Doctor);
    $doctor = $this->f->doctor('Dr. General', $doctorUser);
    $this->actingAs($doctorUser);
    $encounter = app(EncounterWorkspace::class)->startForPatient($this->patient, $this->f->branch, $doctor);
    $this->actingAs($doctorUser)->withSession($doctorSession)->get("/clinic/consultations/{$encounter->id}")->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('dental', null)->where('physio', null));
    $this->actingAs($doctorUser)->withSession($doctorSession)->post("/clinic/consultations/{$encounter->id}/dental/chart", ['tooth' => 36, 'condition' => 'caries'])->assertForbidden();
});
