<?php

use App\ClinicRole;
use App\Models\ClinicCatalogueSetting;
use App\Models\ClinicSettingAudit;
use App\Models\HealthcareSpecialtyMaster;
use App\Models\PatientClinicalEntry;
use App\Models\PatientMedicalHistory;
use App\Services\Clinical\ClinicalCatalogue;
use App\Services\MedicalHistoryService;
use App\Services\Patient\PatientClinicalEntries;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Tests\Support\ReceptionFixture;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-04 10:00', 'Asia/Kolkata'));
    HealthcareSpecialtyMaster::create(['key' => 'general_dentistry', 'name' => 'General Dentistry', 'category' => 'dental', 'category_label' => 'Dental', 'is_active' => true]);
    $this->f = ReceptionFixture::make();
    $this->f->clinic->update(['specialties' => ['dental']]);
    $this->other = ReceptionFixture::make('other-clinic');
    $this->catalogue = app(ClinicalCatalogue::class);
    $this->entries = app(PatientClinicalEntries::class);
});

test('every clinic starts on the shared catalogue with no rows of its own', function () {
    expect(collect($this->catalogue->effective($this->f->clinic->id, 'condition'))->pluck('name')->all())->toBe([
        'Diabetes', 'Hypertension', 'Heart disease', 'Asthma', 'Thyroid disorder', 'Kidney disease', 'Liver disease', 'Epilepsy', 'Cancer', 'Blood disorder', 'Pregnancy',
    ])
        ->and(collect($this->catalogue->effective($this->f->clinic->id, 'visit_type'))->pluck('name')->all())->toBe(['Consultation', 'Follow-up', 'Procedure', 'Review'])
        ->and(ClinicCatalogueSetting::query()->withoutGlobalScopes()->count())->toBe(0);
});

test('a clinic\'s changes stay its own, and each is audited with before and after', function () {
    $this->catalogue->configure($this->f->clinic, $this->f->owner, 'condition', 'thyroid_disorder', ['name' => 'Thyroid', 'position' => 0]);
    $this->catalogue->configure($this->f->clinic, $this->f->owner, 'condition', 'cancer', ['enabled' => false]);
    $this->catalogue->configure($this->f->clinic, $this->f->owner, 'visit_type', 'review', ['name' => 'Review visit']);

    $mine = collect($this->catalogue->effective($this->f->clinic->id, 'condition'));
    $theirs = collect($this->catalogue->effective($this->other->clinic->id, 'condition'));
    expect($mine->first()['name'])->toBe('Thyroid')
        ->and($mine->pluck('key'))->not->toContain('cancer')
        ->and($theirs->pluck('name'))->toContain('Thyroid disorder', 'Cancer')
        ->and(collect($this->catalogue->effective($this->other->clinic->id, 'visit_type'))->pluck('name'))->toContain('Review')
        ->and(ClinicCatalogueSetting::query()->withoutGlobalScopes()->where('clinic_id', $this->other->clinic->id)->count())->toBe(0);

    $audit = ClinicSettingAudit::where('section', 'clinical_catalogue')->where('clinic_id', $this->f->clinic->id)->orderBy('id')->first();
    expect($audit->user_id)->toBe($this->f->owner->id)
        ->and($audit->old_values)->toMatchArray(['kind' => 'condition', 'key' => 'thyroid_disorder', 'name' => 'Thyroid disorder'])
        ->and($audit->new_values)->toMatchArray(['name' => 'Thyroid', 'position' => 0]);
});

test('an archived item is no longer offered, while patients keep what was recorded', function () {
    $patient = $this->f->patient();
    $this->entries->add($patient, 'condition', $this->entries->prepare($this->f->clinic->id, 'condition', [['key' => 'epilepsy']], 'c'), 'registration', null);

    $this->catalogue->configure($this->f->clinic, $this->f->owner, 'condition', 'epilepsy', ['archived' => true]);

    expect(collect($this->catalogue->effective($this->f->clinic->id, 'condition'))->pluck('key'))->not->toContain('epilepsy')
        ->and($this->entries->labelsFor($patient, 'condition'))->toBe(['Epilepsy'])
        ->and(fn () => $this->entries->prepare($this->f->clinic->id, 'condition', [['key' => 'epilepsy', 'label' => 'epilepsy']], 'c'))->toThrow(ValidationException::class);
});

test('a clinic can add an item of its own; no other clinic sees it', function () {
    $key = $this->catalogue->addCustom($this->f->clinic, $this->f->owner, 'condition', 'Sickle cell trait', ['severity' => 'high']);

    expect($key)->toBe('c_sickle_cell_trait')
        ->and(collect($this->catalogue->effective($this->f->clinic->id, 'condition'))->firstWhere('key', $key)['severity'])->toBe('high')
        ->and(collect($this->catalogue->effective($this->other->clinic->id, 'condition'))->pluck('key'))->not->toContain($key);
});

test('only members who manage settings can change the catalogue, through the clinic in their session', function () {
    [$receptionist, $session] = $this->f->memberWithRole(ClinicRole::Receptionist);

    $this->actingAs($receptionist)->withSession($session)->putJson('/clinic/admin/settings/catalogue/condition/asthma', ['enabled' => false])->assertForbidden();
    $this->actingAs($receptionist)->withSession($session)->postJson('/clinic/admin/settings/catalogue/condition', ['name' => 'Nope'])->assertForbidden();
    $this->actingAs($receptionist)->withSession($session)->putJson('/clinic/admin/settings/check-in', ['visit_reason' => 'required'])->assertForbidden();
    expect(ClinicCatalogueSetting::query()->withoutGlobalScopes()->count())->toBe(0);

    $this->actingAs($this->f->owner)->withSession($this->f->session)->putJson('/clinic/admin/settings/catalogue/condition/asthma', ['enabled' => false, 'severity' => 'high'])->assertOk();
    $this->actingAs($this->f->owner)->withSession($this->f->session)->putJson('/clinic/admin/settings/catalogue/condition/not_a_thing', ['enabled' => false])->assertNotFound();
    $this->actingAs($this->f->owner)->withSession($this->f->session)->putJson('/clinic/admin/settings/catalogue/rubbish/asthma', ['enabled' => false])->assertNotFound();

    expect(ClinicCatalogueSetting::query()->withoutGlobalScopes()->sole()->clinic_id)->toBe($this->f->clinic->id);
});

test('Reception sees safety flags on the edit page but not the clinical record, and cannot change it', function () {
    $patient = $this->f->patient('Meera', 'Iyer', ['phone' => '9876533333', 'gender' => 'female']);
    $this->entries->add($patient, 'allergy', $this->entries->prepare($this->f->clinic->id, 'allergy', [['label' => 'Penicillin']], 'a'), 'registration', null);
    $patient->alerts()->create(['clinic_id' => $patient->clinic_id, 'type' => 'manual', 'description' => 'Pacemaker', 'severity' => 'critical']);
    [$receptionist, $session] = $this->f->memberWithRole(ClinicRole::Receptionist);

    $this->actingAs($receptionist)->withSession($session)->get("/clinic/patients/{$patient->id}/edit")->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('patient.allergies', [])
            ->where('patient.medical_alerts', [])
            ->where('patient.safety_flags', [
                ['kind' => 'alert', 'label' => 'Pacemaker', 'severity' => 'critical'],
                ['kind' => 'allergy', 'label' => 'Penicillin', 'severity' => 'high'],
            ])
            ->where('clinical', ['can_view' => false, 'can_edit' => false]));

    $demographics = ['first_name' => 'Meera', 'last_name' => 'Iyer', 'phone' => '9876533334', 'gender' => 'female'];
    $this->actingAs($receptionist)->withSession($session)->put("/clinic/patients/{$patient->id}", [...$demographics, 'allergies' => []])->assertForbidden();
    expect($this->entries->allergyLabels($patient))->toBe(['Penicillin']);

    // Demographics alone are the desk's to change.
    $this->actingAs($receptionist)->withSession($session)->put("/clinic/patients/{$patient->id}", $demographics)->assertRedirect();
    expect($patient->fresh()->phone)->toBe('9876533334');
});

test('safety alerts follow the clinic\'s severity, not hard-coded condition names', function () {
    $patient = $this->f->patient();
    app(MedicalHistoryService::class)->updateMedicalHistory($patient, ['cardiac_condition' => true, 'diabetes' => true]);

    expect($patient->alerts()->orderBy('description')->get(['description', 'severity'])->toArray())->toBe([
        ['description' => 'Diabetes', 'severity' => 'high'],
        ['description' => 'Heart disease', 'severity' => 'critical'],
    ]);

    // A clinic that rates heart disease lower gets no alert for it on the next write.
    $this->catalogue->configure($this->f->clinic, $this->f->owner, 'condition', 'heart_disease', ['severity' => 'moderate']);
    $other = $this->f->patient('Second', 'Patient');
    app(MedicalHistoryService::class)->updateMedicalHistory($other, ['cardiac_condition' => true]);
    expect($other->alerts()->count())->toBe(0);
});

test('the backfill moves legacy conditions, allergies and medicines into entries, idempotently, never reviving a resolved one', function () {
    $patient = $this->f->patient();
    PatientMedicalHistory::query()->withoutGlobalScopes()->create([
        'clinic_id' => $this->f->clinic->id, 'patient_id' => $patient->id,
        'allergies' => ['Penicillin', 'Peanuts'], 'medical_conditions' => ['Type 2 diabetes', 'Asthma'],
        'hypertension' => true, 'current_medications' => ['Metformin 500mg'],
    ]);

    $this->artisan('patients:backfill-clinical-entries', ['--clinic' => [$this->f->clinic->id], '--dry-run' => true])->assertSuccessful();
    expect(PatientClinicalEntry::query()->withoutGlobalScopes()->count())->toBe(0);

    $this->artisan('patients:backfill-clinical-entries', ['--clinic' => [$this->f->clinic->id]])->assertSuccessful();
    $entries = PatientClinicalEntry::query()->withoutGlobalScopes()->where('patient_id', $patient->id)->get();
    expect($entries)->toHaveCount(6)
        ->and($entries->every(fn ($entry) => $entry->source === 'backfill'))->toBeTrue()
        ->and($entries->where('kind', 'condition')->pluck('catalogue_key')->filter()->sort()->values()->all())->toBe(['asthma', 'hypertension'])
        ->and($entries->firstWhere('label', 'Type 2 diabetes')->catalogue_key)->toBeNull()
        ->and($entries->firstWhere('kind', 'medication')->label)->toBe('Metformin 500mg')
        ->and($patient->alerts()->pluck('description')->sort()->values()->all())->toBe(['Allergy: Peanuts', 'Allergy: Penicillin'])
        // The legacy columns are read, never changed.
        ->and($patient->medicalHistory()->withoutGlobalScopes()->first()->allergies)->toBe(['Penicillin', 'Peanuts']);

    // A clinician resolves Peanuts; running again adds nothing and does not revive it.
    $this->entries->replace($patient, 'allergy', $this->entries->prepare($this->f->clinic->id, 'allergy', [['label' => 'Penicillin']], 'a'), 'clinical', $this->f->owner);
    $this->artisan('patients:backfill-clinical-entries', ['--clinic' => [$this->f->clinic->id]])->assertSuccessful();

    expect(PatientClinicalEntry::query()->withoutGlobalScopes()->where('patient_id', $patient->id)->count())->toBe(6)
        ->and($this->entries->allergyLabels($patient))->toBe(['Penicillin']);
});

test('the backfill never reaches another clinic', function () {
    $theirs = $this->other->patient();
    PatientMedicalHistory::query()->withoutGlobalScopes()->create(['clinic_id' => $this->other->clinic->id, 'patient_id' => $theirs->id, 'allergies' => ['Latex']]);

    $this->artisan('patients:backfill-clinical-entries', ['--clinic' => [$this->f->clinic->id]])->assertSuccessful();

    expect(PatientClinicalEntry::query()->withoutGlobalScopes()->count())->toBe(0);
});
