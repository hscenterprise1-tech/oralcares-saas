<?php

use App\ClinicRole;
use App\Models\HealthcareSpecialtyMaster;
use App\Models\Patient;
use App\Models\PatientClinicalEntry;
use App\Services\Patient\PatientClinicalEntries;
use App\Services\PatientFieldConfigService;
use Carbon\CarbonImmutable;
use Tests\Support\ReceptionFixture;

/**
 * Editing a patient changes only what the form sent and the member may
 * change: a field left out, hidden by the clinic, or closed to the member's
 * role keeps what is recorded. Hiding a field never erases data.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-04 10:00', 'Asia/Kolkata'));
    HealthcareSpecialtyMaster::create(['key' => 'general_dentistry', 'name' => 'General Dentistry', 'category' => 'dental', 'category_label' => 'Dental', 'is_active' => true]);
    $this->f = ReceptionFixture::make();
    $this->f->clinic->update(['specialties' => ['dental']]);
    $this->asOwner = fn () => $this->actingAs($this->f->owner)->withSession($this->f->session);

    $this->patient = $this->f->patient('Meera', 'Iyer', [
        'phone' => '9876533333', 'gender' => 'female', 'email' => 'meera@example.com', 'alternate_phone' => '9876500000', 'blood_group' => 'B+', 'date_of_birth' => '1990-02-03',
    ]);
    $this->patient->profile()->create([
        'clinic_id' => $this->f->clinic->id, 'organization_id' => $this->f->clinic->organization_id,
        'address' => '12 Lake Road', 'city' => 'Pune', 'state' => 'Maharashtra', 'pincode' => '411001',
        'emergency_contact_name' => 'Ravi Iyer', 'emergency_contact_phone' => '9876511111',
    ]);
    $entries = app(PatientClinicalEntries::class);
    $entries->add($this->patient, 'allergy', $entries->prepare($this->f->clinic->id, 'allergy', [['label' => 'Penicillin']], 'allergies'), 'registration', null);

    $this->edit = fn (array $fields, $as = null) => ($as ?? ($this->asOwner)())->put("/clinic/patients/{$this->patient->id}", $fields);
    $this->recorded = fn () => [
        ...$this->patient->fresh()->only(['email', 'alternate_phone', 'blood_group']),
        'date_of_birth' => $this->patient->fresh()->date_of_birth?->toDateString(),
        ...$this->patient->fresh()->profile->only(['address', 'city', 'state', 'pincode', 'emergency_contact_name', 'emergency_contact_phone']),
        'allergies' => PatientClinicalEntry::query()->withoutGlobalScopes()->where('patient_id', $this->patient->id)->where('kind', 'allergy')->where('status', 'active')->pluck('label')->all(),
    ];
});

test('fields the edit form leaves out keep what is recorded', function () {
    $before = ($this->recorded)();

    ($this->edit)(['first_name' => 'Meera', 'last_name' => 'Iyer', 'phone' => '9876533333', 'gender' => 'female'])->assertSessionHasNoErrors();

    expect(($this->recorded)())->toBe($before);
});

test('a field the clinic has hidden keeps its data, even when an empty value is sent for it', function () {
    app(PatientFieldConfigService::class)->update($this->f->clinic, ['email' => 'hidden', 'address' => 'hidden', 'allergies' => 'hidden', 'blood_group' => 'hidden'], $this->f->owner);
    $before = ($this->recorded)();

    ($this->edit)([
        'first_name' => 'Meera', 'last_name' => 'Iyer', 'phone' => '9876533333', 'gender' => 'female',
        'email' => '', 'address' => '', 'city' => '', 'state' => '', 'pincode' => '', 'allergies' => [], 'blood_group' => '',
    ])->assertSessionHasNoErrors();

    expect(($this->recorded)())->toBe($before);
});

test('a field closed to the member\'s role is not changed by them', function () {
    app(PatientFieldConfigService::class)->update($this->f->clinic, ['email' => ['editable_roles' => [ClinicRole::Doctor->value]]], $this->f->owner);
    [$receptionist, $session] = $this->f->memberWithRole(ClinicRole::Receptionist);

    ($this->edit)(['first_name' => 'Meera', 'last_name' => 'Iyer', 'phone' => '9876533333', 'gender' => 'female', 'email' => 'changed@example.com'], $this->actingAs($receptionist)->withSession($session))
        ->assertSessionHasNoErrors();

    expect($this->patient->fresh()->email)->toBe('meera@example.com');
});

test('an explicit change is saved, and clearing an optional field is an explicit change', function () {
    ($this->edit)(['first_name' => 'Meera', 'last_name' => 'Iyer', 'phone' => '9876533333', 'gender' => 'female', 'email' => '', 'city' => 'Mumbai'])->assertSessionHasNoErrors();

    expect($this->patient->fresh()->email)->toBeNull()
        ->and($this->patient->fresh()->profile->city)->toBe('Mumbai')
        ->and($this->patient->fresh()->profile->address)->toBe('12 Lake Road');
});

test('editing uses the same phone rule as registration', function () {
    ($this->edit)(['first_name' => 'Meera', 'last_name' => 'Iyer', 'phone' => '98765abcde', 'gender' => 'female'])->assertSessionHasErrors('phone');
    ($this->edit)(['first_name' => 'Meera', 'last_name' => 'Iyer', 'phone' => '+44 20 7946 0958', 'gender' => 'female'])->assertSessionHasNoErrors();

    expect(Patient::find($this->patient->id)->phone)->toBe('+44 20 7946 0958');
});
