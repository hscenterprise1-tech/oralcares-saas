<?php

use App\ClinicRole;
use App\Models\CompanyFeature;
use App\Models\CompanyPlan;
use App\Models\CompanySubscription;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\TreatmentFixture;

/**
 * Administration → Services & treatments offers only the specialties the
 * clinic has, with what each may target, and the catalogue API it writes
 * through. Only those who manage the catalogue may open it.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-07 10:00', 'Asia/Kolkata'));
    $this->t = TreatmentFixture::make();
    $this->f = $this->t->f;
    $this->asOwner = fn () => $this->actingAs($this->f->owner)->withSession($this->f->session);
});

test('the catalogue page offers each specialty the clinic has, with its targets and the API', function () {
    ($this->asOwner)()->get('/clinic/admin/catalog')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('ClinicPortal/Admin/Catalog', false)
        ->where('specialties.0.key', 'dental')
        ->where('specialties.0.targets.0.key', 'tooth')
        ->where('specialties.1.key', 'physiotherapy')
        ->where('specialties.1.targets', [])
        ->where('api.procedures', '/clinic/admin/catalog/procedures')
        ->where('api.prices', '/clinic/admin/catalog/prices'));
});

test('a physio-only plan offers only physiotherapy', function () {
    $plan = CompanyPlan::factory()->create();
    $plan->features()->sync(collect(['patients', 'physiotherapy'])->map(fn (string $key) => (CompanyFeature::where('key', $key)->first() ?? CompanyFeature::factory()->create(['key' => $key]))->id));
    CompanySubscription::factory()->create(['clinic_id' => $this->f->clinic->id, 'company_plan_id' => $plan->id]);

    ($this->asOwner)()->get('/clinic/admin/catalog')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('specialties', 1)->where('specialties.0.key', 'physiotherapy'));
});

test('staff who do not manage the catalogue cannot open it', function () {
    [$doctor, $session] = $this->f->memberWithRole(ClinicRole::Doctor);
    $this->actingAs($doctor)->withSession($session)->get('/clinic/admin/catalog')->assertForbidden();
});
