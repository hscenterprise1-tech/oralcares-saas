<?php

use App\ClinicRole;
use App\Models\CatalogPriceVersion;
use App\Models\ClinicalProcedure;
use App\Models\CompanyFeature;
use App\Models\CompanyPlan;
use App\Models\CompanySubscription;
use App\Services\Dental\DentalConsultation;
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

test('the page lists the clinic\'s services with their price and offers the ready-made list it does not have yet', function () {
    ($this->asOwner)()->get('/clinic/admin/catalog')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('services')
        ->where('library', fn ($library) => collect($library)->pluck('code')->contains('DEN-RCT') && collect($library)->pluck('code')->contains('DEN-IMP'))
        ->where('api.library', '/clinic/admin/catalog/procedures/library'));
});

test('only the ready-made services the owner picks are added, each priced, and not twice', function () {
    $first = ($this->asOwner)()->postJson('/clinic/admin/catalog/procedures/library', ['codes' => ['DEN-RCT', 'DEN-XRAY']]);
    $first->assertCreated()->assertJsonPath('data.added', 2);

    $clinicId = $this->f->clinic->id;
    expect(ClinicalProcedure::withoutGlobalScopes()->where('clinic_id', $clinicId)->whereIn('code', ['DEN-RCT', 'DEN-XRAY'])->count())->toBe(2)
        ->and(ClinicalProcedure::withoutGlobalScopes()->where('clinic_id', $clinicId)->where('code', 'DEN-IMP')->exists())->toBeFalse();

    $rct = ClinicalProcedure::withoutGlobalScopes()->where('clinic_id', $clinicId)->where('code', 'DEN-RCT')->first();
    expect((float) CatalogPriceVersion::withoutGlobalScopes()->where('clinical_procedure_id', $rct->id)->whereNull('effective_to')->value('amount'))->toBe(6000.0);

    ($this->asOwner)()->postJson('/clinic/admin/catalog/procedures/library', ['codes' => ['DEN-RCT']])->assertCreated()->assertJsonPath('data.added', 0);
});

test('a service carries the clinic\'s own number of visits, which the dental visit page uses instead of the standard hint', function () {
    $id = ($this->asOwner)()->postJson('/clinic/admin/catalog/procedures', [
        'code' => 'MY-RCT', 'name' => 'Root canal treatment', 'category' => 'Endodontics', 'sittings' => ['visits' => 2, 'step' => 3],
    ])->assertCreated()->json('data.id');

    $service = ClinicalProcedure::withoutGlobalScopes()->findOrFail($id);
    expect(DentalConsultation::sittingsFor($service))->toBe(['visits' => 2, 'step' => 3]);

    ($this->asOwner)()->putJson("/clinic/admin/catalog/procedures/{$id}", ['code' => 'MY-RCT', 'name' => 'Root canal treatment', 'sittings' => ['visits' => 4, 'step' => 10]])->assertOk();
    expect(DentalConsultation::sittingsFor($service->fresh()))->toBe(['visits' => 4, 'step' => 10]);

    $plain = ClinicalProcedure::withoutGlobalScopes()->create(['clinic_id' => $this->f->clinic->id, 'code' => 'MY-RCT2', 'name' => 'Root canal treatment', 'category' => 'Endodontics', 'is_active' => true]);
    expect(DentalConsultation::sittingsFor($plain))->toBe(['visits' => 3, 'step' => 5]);
});

test('staff who do not manage the catalogue cannot add from the ready-made list', function () {
    [$doctor, $session] = $this->f->memberWithRole(ClinicRole::Doctor);
    $this->actingAs($doctor)->withSession($session)->postJson('/clinic/admin/catalog/procedures/library', ['codes' => ['DEN-RCT']])->assertForbidden();
});

test('staff who do not manage the catalogue cannot open it', function () {
    [$doctor, $session] = $this->f->memberWithRole(ClinicRole::Doctor);
    $this->actingAs($doctor)->withSession($session)->get('/clinic/admin/catalog')->assertForbidden();
});
