<?php

use App\ClinicRole;
use App\Models\PatientAlert;
use App\Models\PatientClinicalEntry;
use App\Services\Patient\PatientBranchActivityRecorder;
use App\Services\Patient\PatientClinicalEntries;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\ReceptionFixture;

/**
 * Patient 360's header strip keeps four kinds of information apart: medical
 * conditions (clinical roles, with who recorded them and how serious),
 * safety alerts (everyone; only the precautions staff recorded, not the
 * copies kept of allergies and conditions), notes (clinical roles), and no
 * allergies at all on this page — their records untouched. Clinical staff
 * manage conditions through the existing clinical-entries endpoint; a
 * "View bills" link appears only for members the Billing register admits.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-26 10:00', 'Asia/Kolkata'));
    $this->f = ReceptionFixture::make();
    $this->patient = $this->f->patient('Meera', 'Iyer', ['uhid' => 'UHID-2026-0400']);
    app(PatientBranchActivityRecorder::class)->record($this->f->clinic->id, $this->patient->id, $this->f->branch->id, now(), 'registration');
    $entries = app(PatientClinicalEntries::class);
    $entries->add($this->patient, 'allergy', $entries->prepare($this->f->clinic->id, 'allergy', [['label' => 'Penicillin']], 'a'), 'registration', null);
    $entries->add($this->patient, 'condition', $entries->prepare($this->f->clinic->id, 'condition', [['key' => 'hypertension'], ['key' => 'diabetes']], 'c'), 'registration', null);
    PatientAlert::create(['clinic_id' => $this->f->clinic->id, 'patient_id' => $this->patient->id, 'type' => 'manual', 'description' => 'Prefers morning appointments', 'severity' => 'low']);
    PatientAlert::create(['clinic_id' => $this->f->clinic->id, 'patient_id' => $this->patient->id, 'type' => 'flag', 'description' => 'Bleeding risk: check before extractions', 'severity' => 'critical']);
    $this->patient->medicalHistory()->create(['clinic_id' => $this->f->clinic->id, 'organization_id' => $this->f->clinic->organization_id, 'notes' => 'Anxious about injections']);
    $this->asOwner = fn () => $this->actingAs($this->f->owner)->withSession($this->f->session);
    $this->asRole = function (ClinicRole $role) {
        [$user, $session] = $this->f->memberWithRole($role);

        return fn () => $this->actingAs($user)->withSession($session);
    };
    $this->props = function ($as): array {
        $props = [];
        $as()->get("/clinic/patients/{$this->patient->id}")->assertOk()->assertInertia(function (Assert $page) use (&$props) {
            $props = $page->toArray()['props'];
        });

        return $props;
    };
});

test('clinical roles see the medical conditions, most serious first, with how they came to be on record', function () {
    $context = ($this->props)(($this->asRole)(ClinicRole::Doctor))['context'];

    expect(array_column($context['medical_conditions'], 'label'))->toBe(['Diabetes', 'Hypertension'])
        ->and($context['medical_conditions'][0])->toMatchArray(['key' => 'diabetes', 'severity' => 'high', 'source' => 'registration', 'recorded_on' => '2026-10-26'])
        ->and($context['medical_notes'])->toBe('Anxious about injections')
        ->and($context['medical_notes_updated_on'])->toBe('2026-10-26');

    foreach ([ClinicRole::Receptionist, ClinicRole::BillingStaff] as $role) {
        $context = ($this->props)(($this->asRole)($role))['context'];
        expect($context['medical_conditions'])->toBe([])->and($context['medical_notes'])->toBeNull();
    }
});

test('safety alerts are the recorded precautions only, most serious first; allergy and condition copies are not repeated, and nothing is deleted', function () {
    // Diabetes is high severity, so the entries service also keeps a `medical_condition` alert for it.
    expect(PatientAlert::where('patient_id', $this->patient->id)->pluck('type')->sort()->values()->all())->toBe(['allergy', 'flag', 'manual', 'medical_condition']);

    foreach ([$this->asOwner, ($this->asRole)(ClinicRole::Receptionist), ($this->asRole)(ClinicRole::BillingStaff)] as $as) {
        expect(collect(($this->props)($as)['context']['alerts'])->map(fn (array $alert) => ['text' => $alert['text'], 'severity' => $alert['severity']])->all())->toBe([
            ['text' => 'Bleeding risk: check before extractions', 'severity' => 'critical'],
            ['text' => 'Prefers morning appointments', 'severity' => 'low'],
        ]);
    }

    // Allergy records are untouched (the page simply does not show them).
    expect(PatientClinicalEntry::query()->withoutGlobalScopes()->where('patient_id', $this->patient->id)->where('kind', 'allergy')->value('label'))->toBe('Penicillin')
        ->and(PatientAlert::where('patient_id', $this->patient->id)->where('type', 'allergy')->exists())->toBeTrue();
});

test('the condition editor is offered to clinical staff only, with the clinic\'s options, and saving through it is recorded as clinical', function () {
    $editor = ($this->props)(($this->asRole)(ClinicRole::Doctor))['condition_editor'];
    expect($editor['url'])->toBe(route('clinic.patients.clinical-entries.update', [$this->patient, 'condition']))
        ->and(array_column($editor['options'], 'key'))->toContain('diabetes', 'hypertension', 'asthma');

    expect(($this->props)(($this->asRole)(ClinicRole::Receptionist))['condition_editor'])->toBeNull()
        ->and(($this->props)(($this->asRole)(ClinicRole::BillingStaff))['condition_editor'])->toBeNull();

    $asDoctor = ($this->asRole)(ClinicRole::Doctor);
    $asDoctor()->putJson($editor['url'], ['entries' => [['key' => 'diabetes'], ['key' => 'asthma', 'severity' => 'moderate']]])->assertOk();

    $conditions = collect(($this->props)($asDoctor)['context']['medical_conditions'])->keyBy('label');
    expect($conditions->keys()->all())->toBe(['Diabetes', 'Asthma'])
        ->and($conditions['Asthma'])->toMatchArray(['source' => 'clinical', 'severity' => 'moderate'])
        // Hypertension was left out: resolved and kept, not deleted.
        ->and(PatientClinicalEntry::query()->withoutGlobalScopes()->where('patient_id', $this->patient->id)->where('catalogue_key', 'hypertension')->value('status'))->toBe(PatientClinicalEntry::RESOLVED);

    ($this->asRole)(ClinicRole::Receptionist)()->putJson($editor['url'], ['entries' => []])->assertForbidden();
});

test('View bills links to the Billing register for this patient only for members it admits', function () {
    $bills = route('clinic.billing.index', ['q' => 'UHID-2026-0400']);

    expect(($this->props)($this->asOwner)['links']['bills'])->toBe($bills)
        ->and(($this->props)(($this->asRole)(ClinicRole::BillingStaff))['links']['bills'])->toBe($bills)
        // The front desk collects payments: it sees money and opens the register.
        ->and(($this->props)(($this->asRole)(ClinicRole::Receptionist))['links']['bills'])->toBe($bills)
        ->and(($this->props)(($this->asRole)(ClinicRole::Doctor))['links']['bills'])->toBeNull();

    ($this->asRole)(ClinicRole::BillingStaff)()->get($bills)->assertOk();
    ($this->asRole)(ClinicRole::Receptionist)()->get($bills)->assertOk();
    ($this->asRole)(ClinicRole::Doctor)()->get($bills)->assertForbidden();
});
