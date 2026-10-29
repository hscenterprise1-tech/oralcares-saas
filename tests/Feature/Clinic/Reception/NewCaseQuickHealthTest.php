<?php

use App\Models\ClinicalSpecialty;
use App\Models\Patient;
use App\Models\PatientClinicalEntry;
use App\Support\Specialty\PracticeAreas;
use Carbon\CarbonImmutable;
use Tests\Support\TreatmentFixture;

/**
 * New Case's quick health chips are saved exactly as the popup sends them:
 * Diabetes, High BP, Heart problem and Asthma as the catalogue conditions,
 * "Allergy" as an allergy entry whose allergen is not yet known, and "None
 * known" as the recorded "no known allergies" status — nothing the chips
 * show is lost, and nothing else is invented.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-10 10:00', 'Asia/Kolkata'));
    $this->t = TreatmentFixture::make();
    $this->f = $this->t->f;
    $this->f->clinic->update(['specialties' => ['dental']]);
    $keyed = ['organization_id' => $this->f->clinic->organization_id, 'practice_area_key' => 'general_dentistry'];
    $entry = ClinicalSpecialty::withoutGlobalScopes()->forceCreate([...$keyed, 'name' => app(PracticeAreas::class)->get('general_dentistry')['label'], 'is_active' => true]);
    $this->dentist = $this->f->doctor('Dr. Raj Patel');
    $this->dentist->practitionerProfile()->withoutGlobalScopes()->update(['primary_specialty_id' => $entry->id, 'specialty' => $entry->name]);

    $this->newCase = fn (array $fields) => $this->actingAs($this->f->owner)->withSession($this->f->session)->postJson('/clinic/patients', [
        'surface' => 'new_case', 'first_name' => 'Asha', 'last_name' => 'Rao', 'phone' => '9811100124', 'gender' => 'female',
        'check_in' => true, 'doctor_id' => $this->dentist->id, ...$fields,
    ]);
    $this->entries = fn (string $kind) => PatientClinicalEntry::query()->withoutGlobalScopes()
        ->where('patient_id', Patient::query()->withoutGlobalScopes()->where('phone', '9811100124')->value('id'))->where('kind', $kind)
        ->orderBy('id')->get(['catalogue_key', 'label'])->map(fn ($row) => [$row->catalogue_key, $row->label])->all();
});

test('the four condition chips and the allergy chip are saved as the payload the popup sends', function () {
    ($this->newCase)([
        'medical_conditions' => [
            ['key' => 'diabetes', 'label' => 'Diabetes'],
            ['key' => 'hypertension', 'label' => 'Hypertension'],
            ['key' => 'heart_disease', 'label' => 'Heart disease'],
            ['key' => 'asthma', 'label' => 'Asthma'],
        ],
        'allergies' => [['label' => 'Allergy (unspecified)']],
        'allergy_status' => null,
    ])->assertCreated();

    expect(($this->entries)('condition'))->toBe([
        ['diabetes', 'Diabetes'],
        ['hypertension', 'Hypertension'],
        ['heart_disease', 'Heart disease'],
        ['asthma', 'Asthma'],
    ])->and(collect(($this->entries)('allergy'))->pluck(1)->all())->toBe(['Allergy (unspecified)']);
});

test('"None known" is saved as no known allergies, with no conditions or allergies recorded', function () {
    ($this->newCase)(['medical_conditions' => [], 'allergies' => [], 'allergy_status' => 'none_known'])->assertCreated();

    $patient = Patient::query()->withoutGlobalScopes()->where('phone', '9811100124')->sole();
    expect($patient->profile->allergy_status)->toBe('none_known')
        ->and(($this->entries)('condition'))->toBe([])
        ->and(($this->entries)('allergy'))->toBe([]);
});
