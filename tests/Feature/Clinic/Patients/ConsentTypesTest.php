<?php

use App\ClinicRole;
use App\Models\ClinicConsentType;
use App\Models\ClinicConsentTypeVersion;
use App\Models\ConsentRecord;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\ReceptionFixture;

/**
 * Configurable consents: the clinic's own list and wording, a specialty's
 * consents only where it has that specialty, and every given consent tied
 * to the exact wording version it was given against.
 */
beforeEach(function () {
    $this->f = ReceptionFixture::make('consent-types');
    $this->f->clinic->update(['specialties' => ['physiotherapy']]);
    $this->asOwner = fn () => $this->actingAs($this->f->owner)->withSession($this->f->session);
    $this->patient = $this->f->patient('Asha', 'Rao');
    $this->url = "/clinic/patients/{$this->patient->id}/consents";
});

test('a physiotherapy clinic offers the shared and physiotherapy consents, never dental ones', function () {
    $options = collect(($this->asOwner)()->getJson($this->url)->assertOk()->json('options'))->pluck('key');

    expect($options)->toContain('treatment', 'communication', 'whatsapp', 'physio_treatment', 'package_terms')
        ->not->toContain('dental_treatment', 'extraction', 'oral_surgery');
    ($this->asOwner)()->postJson($this->url, ['type' => 'extraction', 'method' => 'verbal'])->assertUnprocessable();
});

test('a consent is given against the clinic\'s current wording; a new wording is a new version and the old one stays', function () {
    $first = ($this->asOwner)()->postJson($this->url, ['type' => 'physio_treatment', 'method' => 'written'])->assertCreated()->json('data.id');
    $type = ClinicConsentType::query()->withoutGlobalScopes()->where('clinic_id', $this->f->clinic->id)->where('key', 'physio_treatment')->sole();

    ($this->asOwner)()->put("/clinic/admin/consents/{$type->id}", ['label' => 'Physiotherapy treatment', 'text' => 'New wording, agreed with our lawyer.'])->assertSessionHasNoErrors();
    $second = ($this->asOwner)()->postJson($this->url, ['type' => 'physio_treatment', 'method' => 'digital'])->assertCreated()->json('data.id');

    $versions = ClinicConsentTypeVersion::query()->where('clinic_consent_type_id', $type->id)->orderBy('version')->get();
    expect($versions->pluck('version')->all())->toBe([1, 2])
        ->and(ConsentRecord::find($first))->consent_version->toBe('1')->text_hash->toBe($versions[0]->text_hash)
        ->and(ConsentRecord::find($second))->consent_version->toBe('2')->text_hash->toBe(hash('sha256', 'New wording, agreed with our lawyer.'));

    // A wording is evidence: it cannot be rewritten.
    expect(fn () => $versions[0]->update(['text' => 'Changed']))->toThrow(LogicException::class);
});

test('a type switched off is no longer offered; its given consents remain', function () {
    ($this->asOwner)()->postJson($this->url, ['type' => 'package_terms', 'method' => 'verbal'])->assertCreated();
    $type = ClinicConsentType::query()->withoutGlobalScopes()->where('clinic_id', $this->f->clinic->id)->where('key', 'package_terms')->sole();

    ($this->asOwner)()->post("/clinic/admin/consents/{$type->id}/active", ['active' => false])->assertSessionHasNoErrors();

    expect(collect(($this->asOwner)()->getJson($this->url)->json('options'))->pluck('key'))->not->toContain('package_terms')
        ->and(ConsentRecord::query()->where('patient_id', $this->patient->id)->where('consent_type', 'package_terms')->count())->toBe(1);
    ($this->asOwner)()->get('/clinic/admin/consents')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('ClinicPortal/Admin/Consents', false)
        ->where('types', fn ($types) => collect($types)->pluck('key')->contains('package_terms') && collect($types)->pluck('key')->doesntContain('extraction')));
});

test('only settings managers configure consents; Patient 360 shows the Consents section', function () {
    [$user, $session] = $this->f->memberWithRole(ClinicRole::Receptionist);

    $this->actingAs($user)->withSession($session)->get('/clinic/admin/consents')->assertForbidden();
    $this->actingAs($user)->withSession($session)->get("/clinic/patients/{$this->patient->id}")->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('sections', fn ($sections) => collect($sections)->pluck('key')->contains('consents'))
            ->where('endpoints.consents', $this->url));
});
