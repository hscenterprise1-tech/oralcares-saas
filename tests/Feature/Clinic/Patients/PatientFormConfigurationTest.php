<?php

use App\ClinicRole;
use App\Models\ClinicalSpecialty;
use App\Models\ClinicEmployee;
use App\Models\ClinicSettingAudit;
use App\Models\HealthcareSpecialtyMaster;
use App\Models\Patient;
use App\Models\PatientClinicalEntry;
use App\Services\PatientFieldConfigService;
use App\Support\Specialty\PracticeAreas;
use Carbon\CarbonImmutable;
use Tests\Support\ReceptionFixture;

/**
 * The owner's patient form settings: each address part, the clinic's own
 * labels and help text, past surgery and family history, starting
 * templates, and settings scoped to one module in a clinic practising two.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-04 10:00', 'Asia/Kolkata'));
    foreach ([['general_dentistry', 'dental'], ['physiotherapy', 'rehabilitation']] as [$key, $category]) {
        HealthcareSpecialtyMaster::query()->firstOrCreate(['key' => $key], ['name' => str($key)->headline(), 'category' => $category, 'category_label' => str($category)->headline(), 'is_active' => true]);
    }
    $this->f = ReceptionFixture::make();
    $this->f->clinic->update(['specialties' => ['dental']]);
    $this->fields = app(PatientFieldConfigService::class);
    $this->asOwner = fn () => $this->actingAs($this->f->owner)->withSession($this->f->session);
    $this->person = fn (array $extra = []) => ['first_name' => 'Meera', 'last_name' => 'Iyer', 'phone' => '9811100777', 'gender' => 'female', ...$extra];
    $this->practitioner = function (string $name, string $area): ClinicEmployee {
        $employee = $this->f->doctor($name);
        $keyed = ['organization_id' => $this->f->clinic->organization_id, 'practice_area_key' => $area];
        $entry = ClinicalSpecialty::withoutGlobalScopes()->where($keyed)->first()
            ?? ClinicalSpecialty::withoutGlobalScopes()->forceCreate([...$keyed, 'name' => app(PracticeAreas::class)->get($area)['label'], 'is_active' => true]);
        $employee->practitionerProfile()->withoutGlobalScopes()->update(['primary_specialty_id' => $entry->id, 'specialty' => $entry->name]);

        return $employee->fresh();
    };
});

// ---------------------------------------------------------------- The settings screen

test('the owner opens Patient form settings with every setting it saves, and no vitals', function () {
    ($this->asOwner)()->get('/clinic/admin/settings/patient-form')->assertOk()
        ->assertInertia(fn ($page) => $page->component('ClinicPortal/Settings/PatientForm', true)
            ->has('fields.address_locality.state')->has('fields.surgical_history.state')->has('fields.email.label')
            ->where('quick_care', ['allergy_status' => 'optional', 'alerts' => true, 'alert_roles' => null, 'conditions_from_catalogue' => false])
            ->has('lists.visit_reason')->has('lists.surgery')->has('lists.family_history')->has('lists.medication')
            ->where('system_fields.0.key', 'first_name')->where('system_fields.1.key', 'phone')
            ->has('templates', 3)
            ->where('links.template', route('admin.settings.patient-fields.template')));

    [$receptionist, $session] = $this->f->memberWithRole(ClinicRole::Receptionist);
    $this->actingAs($receptionist)->withSession($session)->get('/clinic/admin/settings/patient-form')->assertForbidden();
});

test('Settings in the navigation opens the patient form, as a built page', function () {
    $navigation = collect(($this->asOwner)()->get('/clinic/admin/settings/patient-form')->viewData('page')['props']['clinic']['navigation'] ?? []);
    $entry = $navigation->firstWhere('key', 'settings');

    expect($entry)->not->toBeNull()
        ->and($entry['available'])->toBeTrue()
        ->and($entry['href'])->toBe(route('admin.settings.patient-form', [], false));
});

// ---------------------------------------------------------------- Address

test('every address part is saved on registration and on edit', function () {
    $address = ['address' => '12 Lake Road', 'locality' => 'Koregaon Park', 'city' => 'Pune', 'state' => 'Maharashtra', 'pincode' => '411001', 'country' => 'India'];

    ($this->asOwner)()->post('/clinic/patients', ($this->person)($address))->assertSessionHasNoErrors();
    $patient = Patient::sole();
    expect($patient->profile->only(array_keys($address)))->toBe($address);

    // Editing one part changes only that part.
    ($this->asOwner)()->put("/clinic/patients/{$patient->id}", ($this->person)(['locality' => 'Baner']))->assertSessionHasNoErrors();
    expect($patient->fresh()->profile->only(array_keys($address)))->toBe([...$address, 'locality' => 'Baner']);
});

test('New Case offers the address and contact details by default, and saves every address part; Repeat Case never changes them; Edit returns them', function () {
    $intake = ($this->asOwner)()->getJson('/clinic/reception/intake')->assertOk()->json('fields');
    expect(collect(['address', 'address_locality', 'address_city', 'address_state', 'address_pincode', 'address_country', 'email', 'alternate_phone', 'emergency_contact'])
        ->mapWithKeys(fn ($field) => [$field => $intake[$field]['state']])->unique()->all())->toBe(['address' => 'optional']);

    $address = ['address' => '7 Hill Street', 'locality' => 'Bandra West', 'city' => 'Mumbai', 'state' => 'Maharashtra', 'pincode' => '400050', 'country' => 'India'];
    $id = ($this->asOwner)()->postJson('/clinic/patients', ['surface' => 'new_case', 'specialties' => ['dental'], 'check_in' => true, 'doctor_id' => $this->f->doctorA->id, ...($this->person)($address)])
        ->assertCreated()->json('patient.id');
    expect(Patient::findOrFail($id)->profile->only(array_keys($address)))->toBe($address);

    // Repeat Case the next day: a visit, the address untouched.
    $this->travel(1)->days();
    ($this->asOwner)()->postJson('/clinic/reception/check-in', ['patient_id' => $id, 'practitioner_id' => $this->f->doctorA->id, 'address' => 'Somewhere else', 'country' => 'Nowhere'])->assertCreated();
    expect(Patient::findOrFail($id)->profile->only(array_keys($address)))->toBe($address);

    // Edit (its page is still to come: P1.4) is sent every part to show.
    ($this->asOwner)()->get("/clinic/patients/{$id}/edit")->assertInertia(fn ($page) => $page->where('patient.locality', 'Bandra West')->where('patient.country', 'India')->where('patient.address', '7 Hill Street')->etc());
});

test('an address part follows the address line until it has a setting of its own, then stands alone', function () {
    // A clinic that hid the whole address before the parts existed: every part is hidden too.
    $this->fields->update($this->f->clinic, ['address' => 'hidden'], $this->f->owner);
    $definitions = $this->fields->definitions($this->f->clinic->fresh());
    expect(collect(PatientFieldConfigService::ADDRESS_PARTS)->map(fn ($part) => $definitions[$part]['state'])->unique()->all())->toBe(['hidden']);

    // The line comes back optional: the parts follow (never stored on their own).
    $this->fields->update($this->f->clinic->fresh(), ['address' => 'optional'], $this->f->owner);
    expect($this->fields->definitions($this->f->clinic->fresh())['address_city']['state'])->toBe('optional')
        ->and($this->f->clinic->fresh()->patient_field_config)->not->toHaveKey('address_city');

    // The city made required on its own: enforced on both forms, while the line stays optional.
    $this->fields->update($this->f->clinic->fresh(), ['address_city' => 'required'], $this->f->owner);
    ($this->asOwner)()->post('/clinic/patients', ($this->person)())->assertSessionHasErrors('city');
    ($this->asOwner)()->postJson('/clinic/patients', ['surface' => 'new_case', ...($this->person)()])->assertStatus(422)->assertJsonValidationErrors('city');
    ($this->asOwner)()->post('/clinic/patients', ($this->person)(['city' => 'Pune']))->assertSessionHasNoErrors();

    // A hidden part is never stored, whatever is sent.
    $this->fields->update($this->f->clinic->fresh(), ['address_country' => 'hidden'], $this->f->owner);
    ($this->asOwner)()->post('/clinic/patients', ($this->person)(['phone' => '9811100778', 'city' => 'Pune', 'country' => 'Nowhere']))->assertSessionHasNoErrors();
    expect(Patient::where('phone', '9811100778')->sole()->profile->country)->toBeNull();
});

// ---------------------------------------------------------------- Labels and help

test('the clinic\'s own labels and help text are kept, trimmed and bounded, and reach both forms', function () {
    ($this->asOwner)()->put('/clinic/admin/settings/patient-fields', [
        'address_locality' => ['label' => '  Area / ward  ', 'help' => 'As written on their ID'],
        'email' => ['label' => str_repeat('x', 61)],
    ])->assertSessionHasErrors('email.label');

    ($this->asOwner)()->put('/clinic/admin/settings/patient-fields', ['address_locality' => ['label' => '  Area / ward  ', 'help' => 'As written on their ID']])->assertSessionHasNoErrors();

    ($this->asOwner)()->get('/clinic/patients/create')->assertInertia(fn ($page) => $page
        ->where('form.fields.address_locality.label', 'Area / ward')->where('form.fields.address_locality.help', 'As written on their ID')
        ->where('form.fields.email.label', null));
    ($this->asOwner)()->getJson('/clinic/reception/intake')->assertJsonPath('fields.address_locality.label', 'Area / ward');
});

// ---------------------------------------------------------------- Past surgery and family history

test('past surgery and family history are offered, enforced when required, and recorded as clinical entries', function () {
    $this->fields->update($this->f->clinic, ['surgical_history' => 'required', 'family_history' => ['state' => 'optional', 'new_case' => true]], $this->f->owner);

    ($this->asOwner)()->getJson('/clinic/reception/intake')->assertJsonPath('fields.surgical_history.state', 'required')
        ->assertJson(fn ($json) => $json->has('catalogue.surgical_history.0')->has('catalogue.family_history.0')->etc());

    ($this->asOwner)()->postJson('/clinic/patients', ['surface' => 'new_case', ...($this->person)()])->assertStatus(422)->assertJsonValidationErrors('surgical_history');
    ($this->asOwner)()->postJson('/clinic/patients', ['surface' => 'new_case', ...($this->person)([
        'surgical_history' => [['key' => 'joint_replacement', 'label' => 'Joint replacement'], ['label' => 'Tonsillectomy']],
        'family_history' => [['key' => 'family_diabetes', 'label' => 'Diabetes']],
    ])])->assertCreated();

    $entries = PatientClinicalEntry::query()->withoutGlobalScopes()->get();
    expect($entries->where('kind', 'surgery')->pluck('label')->sort()->values()->all())->toBe(['Joint replacement', 'Tonsillectomy'])
        ->and($entries->where('kind', 'family_history')->pluck('catalogue_key')->all())->toBe(['family_diabetes']);
});

// ---------------------------------------------------------------- Templates

test('a template sets a fresh clinic up, records the change, and is the owner\'s to apply', function (string $template, array $expect) {
    ($this->asOwner)()->post('/clinic/admin/settings/patient-fields/template', ['template' => $template])->assertSessionHasNoErrors();

    $definitions = $this->fields->definitions($this->f->clinic->fresh());
    foreach ($expect as $field => $setting) {
        expect(collect($definitions[$field])->only(array_keys($setting))->all())->toBe($setting);
    }
    expect(ClinicSettingAudit::query()->where('section', 'patient_fields')->get()->contains(fn ($audit) => ($audit->new_values['template'] ?? null) === $template))->toBeTrue()
        // Nothing medical is made required.
        ->and(collect(['allergies', 'medical_conditions', 'medications', 'surgical_history', 'family_history'])->filter(fn ($field) => $definitions[$field]['state'] === 'required')->all())->toBe([]);

    [$receptionist, $session] = $this->f->memberWithRole(ClinicRole::Receptionist);
    $this->actingAs($receptionist)->withSession($session)->post('/clinic/admin/settings/patient-fields/template', ['template' => $template])->assertForbidden();
})->with([
    'dental' => ['dental', ['blood_group' => ['state' => 'optional', 'new_case' => false], 'family_history' => ['state' => 'hidden']]],
    'physio' => ['physio', ['surgical_history' => ['state' => 'optional', 'new_case' => true], 'blood_group' => ['state' => 'hidden']]],
    'combined' => ['combined', ['surgical_history' => ['state' => 'optional', 'new_case' => true, 'specialties' => ['physiotherapy']]]],
]);

test('a template never overwrites what the clinic has already customised, unless asked to', function () {
    $this->fields->update($this->f->clinic, ['blood_group' => 'required', 'family_history' => ['state' => 'optional', 'label' => 'Runs in the family']], $this->f->owner);

    ($this->asOwner)()->post('/clinic/admin/settings/patient-fields/template', ['template' => 'dental'])->assertSessionHasNoErrors();
    $definitions = $this->fields->definitions($this->f->clinic->fresh());
    expect($definitions['blood_group']['state'])->toBe('required')
        ->and($definitions['family_history']['label'])->toBe('Runs in the family')
        ->and($definitions['family_history']['state'])->toBe('optional')
        // What the clinic had not touched took the template's setting.
        ->and($definitions['medications']['new_case'])->toBeFalse();

    ($this->asOwner)()->post('/clinic/admin/settings/patient-fields/template', ['template' => 'dental', 'replace_customised' => true])->assertSessionHasNoErrors();
    expect($this->fields->definitions($this->f->clinic->fresh())['blood_group']['state'])->toBe('optional')
        ->and($this->fields->definitions($this->f->clinic->fresh())['family_history']['state'])->toBe('hidden');
});

// ---------------------------------------------------------------- One module's settings in a clinic practising two

test('in a Dental and Physiotherapy clinic, a field kept for Physiotherapy applies to Physiotherapy visits only, and the visit must match the form', function () {
    $this->f->clinic->update(['specialties' => ['dental', 'physiotherapy']]);
    $raj = ($this->practitioner)('Dr. Raj Patel', 'general_dentistry');
    $neha = ($this->practitioner)('Dr. Neha Shah', 'sports_rehabilitation');
    $this->fields->update($this->f->clinic, ['surgical_history' => ['state' => 'required', 'specialties' => ['physiotherapy']]], $this->f->owner);

    // A Dental visit does not ask for it.
    ($this->asOwner)()->postJson('/clinic/patients', ['surface' => 'new_case', 'specialties' => ['dental'], 'check_in' => true, 'doctor_id' => $raj->id, ...($this->person)()])->assertCreated();
    // A Physiotherapy visit requires it.
    ($this->asOwner)()->postJson('/clinic/patients', ['surface' => 'new_case', 'specialties' => ['physiotherapy'], 'check_in' => true, 'doctor_id' => $neha->id, ...($this->person)(['phone' => '9811100779'])])
        ->assertStatus(422)->assertJsonValidationErrors('surgical_history');
    // A form filled in for Dental cannot check the patient in with a physiotherapist, nor for a Physiotherapy area.
    ($this->asOwner)()->postJson('/clinic/patients', ['surface' => 'new_case', 'specialties' => ['dental'], 'check_in' => true, 'doctor_id' => $neha->id, ...($this->person)(['phone' => '9811100780'])])
        ->assertStatus(422)->assertJsonValidationErrors('doctor_id');
    ($this->asOwner)()->postJson('/clinic/patients', ['surface' => 'new_case', 'specialties' => ['dental'], 'check_in' => true, 'doctor_id' => $neha->id, 'visit_area' => 'sports_rehabilitation', ...($this->person)(['phone' => '9811100781'])])
        ->assertStatus(422)->assertJsonValidationErrors('doctor_id');

    // A field cannot be scoped to a module the clinic does not practise.
    ($this->asOwner)()->put('/clinic/admin/settings/patient-fields', ['email' => ['specialties' => ['dermatology']]])->assertSessionHasErrors('email.specialties.0');
    expect(Patient::count())->toBe(1);
});
