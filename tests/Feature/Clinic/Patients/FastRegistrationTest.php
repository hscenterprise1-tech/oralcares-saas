<?php

use App\ClinicRole;
use App\Models\ClinicQueue;
use App\Models\ClinicQueueItem;
use App\Models\ClinicSettingAudit;
use App\Models\HealthcareSpecialtyMaster;
use App\Models\Patient;
use App\Models\PatientClinicalEntry;
use App\Models\PatientVisit;
use App\Models\SecurityAuditLog;
use App\Services\Clinical\ClinicalCatalogue;
use App\Services\Patient\ClinicSpecialties;
use App\Services\PatientFieldConfigService;
use App\Services\Reception\PatientFlowConfigService;
use Carbon\CarbonImmutable;
use Tests\Support\ReceptionFixture;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-04 10:00', 'Asia/Kolkata'));
    foreach ([['general_dentistry', 'dental'], ['physiotherapy', 'rehabilitation']] as [$key, $category]) {
        HealthcareSpecialtyMaster::create(['key' => $key, 'name' => str($key)->headline(), 'category' => $category, 'category_label' => str($category)->headline(), 'is_active' => true]);
    }
    $this->f = ReceptionFixture::make();
    $this->f->clinic->update(['specialties' => ['dental']]);
    $this->asOwner = fn () => $this->actingAs($this->f->owner)->withSession($this->f->session);
    // Reception's New Case dialog: JSON, the new_case surface.
    $this->newCase = fn (array $fields = [], $as = null) => ($as ?? ($this->asOwner)())->postJson('/clinic/patients', [
        'surface' => 'new_case', 'first_name' => 'Kavya', 'last_name' => 'Menon', 'phone' => '9811100001', 'gender' => 'female',
        'specialties' => ['dental'], ...$fields,
    ]);
});

test('New Case registers the patient, records conditions and allergies, and checks them in, in one go', function () {
    ($this->newCase)([
        'medical_conditions' => ['diabetes', 'hypertension'],
        'allergies' => ['Latex'],
        'check_in' => true,
        'doctor_id' => $this->f->doctorA->id,
        'visit_type' => 'consultation',
        'visit_reason' => 'Tooth pain',
    ])->assertCreated()
        ->assertJsonPath('patient.name', 'Kavya Menon')
        ->assertJsonPath('check_in.doctor', 'Dr. Asha Mehta')
        ->assertJsonPath('check_in.case_number', 'OPD-2026-000001');

    $patient = Patient::sole();
    $visit = PatientVisit::sole();
    expect($visit->patient_id)->toBe($patient->id)
        ->and($visit->visit_type_key)->toBe('consultation')
        ->and($visit->visit_reason)->toBe('Tooth pain')
        ->and($visit->clinic_specialty_id)->toBe(app(ClinicSpecialties::class)->active($this->f->clinic, 'dental')->id)
        ->and(ClinicQueueItem::sole()->doctor_id)->toBe($this->f->doctorA->id);

    $entries = PatientClinicalEntry::query()->withoutGlobalScopes()->where('patient_id', $patient->id)->get();
    expect($entries->where('kind', 'condition')->pluck('catalogue_key')->sort()->values()->all())->toBe(['diabetes', 'hypertension'])
        ->and($entries->firstWhere('catalogue_key', 'diabetes')->severity)->toBe('high')
        ->and($entries->firstWhere('kind', 'allergy')->label)->toBe('Latex')
        ->and($entries->every(fn ($entry) => $entry->source === 'registration' && $entry->recorded_by === $this->f->owner->id))->toBeTrue();

    // Alerts come from severity, not code: diabetes (high) alerts, hypertension (moderate) does not.
    expect($patient->alerts()->orderBy('description')->pluck('description')->all())->toBe(['Allergy: Latex', 'Diabetes']);
});

test('if the check-in fails, nothing is registered', function () {
    ClinicQueue::query()->withoutGlobalScopes()->create([
        'clinic_id' => $this->f->clinic->id, 'branch_id' => $this->f->branch->id, 'queue_date' => '2026-10-04', 'name' => 'Closed', 'closed_at' => now(),
    ]);

    ($this->newCase)(['check_in' => true, 'medical_conditions' => ['diabetes']])->assertStatus(422)->assertJsonValidationErrors('patient_id');

    expect(Patient::count())->toBe(0)->and(PatientClinicalEntry::query()->withoutGlobalScopes()->count())->toBe(0);
});

test('a condition the clinic has switched off, or limited to another specialty, is refused', function () {
    $catalogue = app(ClinicalCatalogue::class);
    $catalogue->configure($this->f->clinic, $this->f->owner, 'condition', 'asthma', ['enabled' => false]);
    $catalogue->configure($this->f->clinic, $this->f->owner, 'condition', 'pregnancy', ['specialty_keys' => ['physiotherapy']]);

    ($this->newCase)(['medical_conditions' => ['asthma']])->assertStatus(422)->assertJsonValidationErrors('medical_conditions.0');
    ($this->newCase)(['medical_conditions' => ['pregnancy']])->assertStatus(422)->assertJsonValidationErrors('medical_conditions.0');
    expect(Patient::count())->toBe(0);

    app(ClinicSpecialties::class)->sync($this->f->clinic, ['dental', 'physiotherapy']);
    ($this->newCase)(['specialties' => ['physiotherapy'], 'medical_conditions' => ['pregnancy']])->assertCreated();
});

test('the visit type must be one the clinic offers for that specialty, and the reason follows the check-in setting', function () {
    $catalogue = app(ClinicalCatalogue::class);
    $catalogue->configure($this->f->clinic, $this->f->owner, 'visit_type', 'procedure', ['enabled' => false]);
    app(PatientFlowConfigService::class)->updateCheckIn($this->f->clinic, ['visit_reason' => 'required'], $this->f->owner);

    ($this->newCase)(['check_in' => true, 'visit_type' => 'procedure', 'visit_reason' => 'Filling'])->assertStatus(422)->assertJsonValidationErrors('visit_type');
    ($this->newCase)(['check_in' => true, 'visit_type' => 'review'])->assertStatus(422)->assertJsonValidationErrors('visit_reason');
    expect(Patient::count())->toBe(0);

    ($this->newCase)(['check_in' => true, 'visit_type' => 'review', 'visit_reason' => 'Post-op check'])->assertCreated();
});

test('New Case meets the server duplicate gate inside the same request flow', function () {
    $existing = $this->f->patient('Kavya', 'Menon', ['phone' => '9811100001']);

    ($this->newCase)()->assertStatus(409)->assertJsonPath('possible_duplicates.0.id', $existing->id);
    ($this->newCase)(['duplicate_acknowledged_ids' => [$existing->id]])->assertStatus(422)
        ->assertJsonValidationErrors('duplicate_reason')->assertJsonPath('possible_duplicates.0.id', $existing->id);
    expect(Patient::count())->toBe(1);

    ($this->newCase)(['duplicate_acknowledged_ids' => [$existing->id], 'duplicate_reason' => 'Sister on the family phone', 'check_in' => true])->assertCreated();
    expect(Patient::count())->toBe(2)
        ->and(SecurityAuditLog::where('event', 'patient.duplicate_override')->sole()->context['reason'])->toBe('Sister on the family phone');
});

test('the field settings decide what New Case requires and keeps, per role', function () {
    $fields = app(PatientFieldConfigService::class);
    $fields->update($this->f->clinic, [
        'blood_group' => 'hidden',
        'email' => ['state' => 'required', 'visible_roles' => ['Doctor']],
    ], $this->f->owner);

    // The owner sees email (and it is required); blood group is dropped even if sent.
    ($this->newCase)(['blood_group' => 'O+'])->assertStatus(422)->assertJsonValidationErrors('email');
    ($this->newCase)(['blood_group' => 'O+', 'email' => 'kavya@example.com'])->assertCreated();
    expect(Patient::sole()->blood_group)->toBeNull();

    // A receptionist does not see email: not required of them, and not kept.
    [$receptionist, $session] = $this->f->memberWithRole(ClinicRole::Receptionist);
    ($this->newCase)(['phone' => '9811100002', 'first_name' => 'Ravi', 'email' => 'ravi@example.com'], $this->actingAs($receptionist)->withSession($session))->assertCreated();
    expect(Patient::where('phone', '9811100002')->sole()->email)->toBeNull();
});

test('first name and mobile cannot be configured away, and field changes are audited', function () {
    ($this->asOwner)()->put('/clinic/admin/settings/patient-fields', ['first_name' => 'hidden', 'gender' => 'optional'])->assertSessionHasNoErrors();

    expect(app(PatientFieldConfigService::class)->resolve($this->f->clinic->fresh()))->not->toHaveKey('first_name')
        ->and(app(PatientFieldConfigService::class)->resolve($this->f->clinic->fresh())['gender'])->toBe('optional');
    ($this->newCase)(['first_name' => ''])->assertStatus(422)->assertJsonValidationErrors('first_name');

    $audit = ClinicSettingAudit::where('section', 'patient_fields')->sole();
    expect($audit->user_id)->toBe($this->f->owner->id)
        ->and($audit->old_values['gender']['state'])->toBe('required')
        ->and($audit->new_values['gender']['state'])->toBe('optional');
});

test('a required field is always shown in New Case, whatever the surface setting says', function () {
    app(PatientFieldConfigService::class)->update($this->f->clinic, ['address' => ['state' => 'required', 'new_case' => false]], $this->f->owner);

    expect(app(PatientFieldConfigService::class)->definitions($this->f->clinic->fresh())['address']['new_case'])->toBeTrue();
    ($this->newCase)()->assertStatus(422)->assertJsonValidationErrors('address');
});
