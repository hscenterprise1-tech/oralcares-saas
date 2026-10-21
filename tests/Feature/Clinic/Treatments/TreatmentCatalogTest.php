<?php

use App\ClinicRole;
use App\Models\CatalogPriceVersion;
use App\Models\CatalogStage;
use App\Models\CatalogTreatment;
use App\Models\CatalogTreatmentVersion;
use App\Services\Patient\ClinicSpecialties;
use App\Services\Treatment\CatalogPricing;
use App\Services\Treatment\TreatmentCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Tests\Support\TreatmentFixture;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-03 10:00', 'Asia/Kolkata'));
    $this->t = TreatmentFixture::make();
    $this->catalog = app(TreatmentCatalog::class);
    $this->pricing = app(CatalogPricing::class);
});

test('a treatment is built as versions of stages and procedures, and publishing makes it current', function () {
    $version = $this->t->rct();
    $treatment = CatalogTreatment::sole();

    expect($treatment->current_version_id)->toBe($version->id)
        ->and($version->status)->toBe(CatalogTreatmentVersion::PUBLISHED)
        ->and($version->stages()->pluck('name')->all())->toBe(['Access opening', 'Cleaning & shaping', 'Obturation'])
        ->and($version->stages->first()->procedures()->sole()->clinical_procedure_id)->toBe($this->t->procedures['ACC']->id);
});

test('a published version never changes; a new configuration is a new version', function () {
    $v1 = $this->t->rct();
    $owner = $this->t->actAsOwner();

    expect(fn () => $v1->update(['charging_model' => 'per_stage']))->toThrow(LogicException::class)
        ->and(fn () => CatalogStage::query()->create(['clinic_id' => $v1->clinic_id, 'catalog_treatment_version_id' => $v1->id, 'position' => 9, 'name' => 'Crown']))->toThrow(LogicException::class)
        ->and(fn () => $v1->stages()->first()->update(['name' => 'Renamed']))->toThrow(LogicException::class)
        ->and(fn () => $v1->delete())->toThrow(LogicException::class);

    $v2 = $this->catalog->newVersionFrom($v1, $owner);
    $v2->stages()->first()->update(['name' => 'Access cavity']);
    $this->catalog->publish($v2, $owner);

    expect($v2->version)->toBe(2)
        ->and($v1->fresh()->status)->toBe(CatalogTreatmentVersion::RETIRED)
        ->and($v1->fresh()->stages()->first()->name)->toBe('Access opening')
        ->and(CatalogTreatment::sole()->current_version_id)->toBe($v2->id);
});

test('a treatment takes only active procedures of its own specialty, in an active clinic specialty', function () {
    $owner = $this->t->actAsOwner();
    $treatment = $this->catalog->createTreatment($this->t->f->clinic, ['specialty_key' => 'dental', 'code' => 'X', 'name' => 'Filling'], $owner);
    $config = fn (string $code) => ['treatment_type' => 'single', 'charging_model' => 'per_procedure', 'stages' => [['name' => 'Do it', 'procedures' => [['clinical_procedure_id' => $this->t->procedures[$code]->id]]]]];

    expect(fn () => $this->catalog->draftVersion($treatment, $config('PHY'), $owner))->toThrow(DomainException::class, 'another specialty');

    $this->t->procedures['FIL']->update(['is_active' => false]);
    expect(fn () => $this->catalog->draftVersion($treatment, $config('FIL'), $owner))->toThrow(DomainException::class, 'inactive')
        ->and(fn () => $this->catalog->createTreatment($this->t->f->clinic, ['specialty_key' => 'ophthalmology', 'code' => 'EYE', 'name' => 'Eye'], $owner))->toThrow(DomainException::class);
});

test('a price is never edited: a new price closes the old one, and history answers what applied when', function () {
    $owner = $this->t->actAsOwner();
    $clinic = $this->t->f->clinic;
    $procedure = $this->t->procedures['FIL'];

    $first = $this->pricing->set($clinic, 'clinical_procedure_id', $procedure->id, 1500, null, $owner);
    $this->travel(1)->day();
    $second = $this->pricing->set($clinic, 'clinical_procedure_id', $procedure->id, 1800, null, $owner);
    $branchPrice = $this->pricing->set($clinic, 'clinical_procedure_id', $procedure->id, 1700, $this->t->f->branch, $owner);

    expect(CatalogPriceVersion::count())->toBe(3)
        ->and($first->fresh()->effective_to)->not->toBeNull()
        ->and($this->pricing->current($clinic->id, 'clinical_procedure_id', $procedure->id)->id)->toBe($second->id)
        ->and($this->pricing->current($clinic->id, 'clinical_procedure_id', $procedure->id, $this->t->f->branch->id)->id)->toBe($branchPrice->id)
        ->and($this->pricing->current($clinic->id, 'clinical_procedure_id', $procedure->id, null, now()->subHours(12))->id)->toBe($first->id)
        ->and(fn () => $second->update(['amount' => 1]))->toThrow(LogicException::class)
        ->and(fn () => $first->delete())->toThrow(LogicException::class);
});

test('only catalog managers change the catalog, and only the owner configures specialties', function () {
    $this->t->actAsRole(ClinicRole::Receptionist);
    expect(fn () => $this->catalog->createTreatment($this->t->f->clinic, ['specialty_key' => 'dental', 'code' => 'Y', 'name' => 'Y'], auth()->user()))->toThrow(AuthorizationException::class);

    $admin = $this->t->actAsRole(ClinicRole::ClinicAdmin);
    expect($this->catalog->createTreatment($this->t->f->clinic, ['specialty_key' => 'dental', 'code' => 'Y', 'name' => 'Y'], $admin))->toBeInstanceOf(CatalogTreatment::class)
        ->and(fn () => app(ClinicSpecialties::class)->configure($this->t->f->clinic, ['dental'], $admin))->toThrow(AuthorizationException::class);

    $owner = $this->t->actAsOwner();
    expect(app(ClinicSpecialties::class)->configure($this->t->f->clinic, ['dental'], $owner))->toBe(['dental']);
});
