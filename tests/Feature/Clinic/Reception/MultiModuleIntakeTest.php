<?php

use App\Models\ClinicalSpecialty;
use App\Models\ClinicEmployee;
use App\Models\HealthcareSpecialtyMaster;
use App\Models\Patient;
use App\Models\PatientBilling;
use App\Models\PatientVisit;
use App\Services\Patient\ClinicSpecialties;
use App\Services\PatientFieldConfigService;
use App\Services\Reception\PatientFlowConfigService;
use App\Support\Specialty\PracticeAreas;
use Carbon\CarbonImmutable;
use Tests\Support\ReceptionFixture;

/**
 * New Case and Repeat Case at a Physiotherapy-only clinic and at a clinic
 * practising Dental and Physiotherapy: what the desk is offered, what the
 * server accepts, and that registering never raises a charge by itself.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-04 10:00', 'Asia/Kolkata'));
    foreach ([['general_dentistry', 'dental'], ['physiotherapy', 'rehabilitation']] as [$key, $category]) {
        HealthcareSpecialtyMaster::query()->firstOrCreate(['key' => $key], ['name' => str($key)->headline(), 'category' => $category, 'category_label' => str($category)->headline(), 'is_active' => true]);
    }
    $this->f = ReceptionFixture::make();
    $this->asOwner = fn () => $this->actingAs($this->f->owner)->withSession($this->f->session);

    // A practitioner in a keyed practice area (sports_rehabilitation is Physiotherapy; general_dentistry is Dental).
    $this->practitioner = function (string $name, string $area, ?float $fee = null): ClinicEmployee {
        $employee = $this->f->doctor($name);
        $keyed = ['organization_id' => $this->f->clinic->organization_id, 'practice_area_key' => $area];
        $entry = ClinicalSpecialty::withoutGlobalScopes()->where($keyed)->first()
            ?? ClinicalSpecialty::withoutGlobalScopes()->forceCreate([...$keyed, 'name' => app(PracticeAreas::class)->get($area)['label'], 'is_active' => true]);
        $employee->practitionerProfile()->withoutGlobalScopes()->update(['primary_specialty_id' => $entry->id, 'specialty' => $entry->name, 'consultation_fee' => $fee]);

        return $employee->fresh();
    };
    $this->newCase = fn (array $fields = []) => ($this->asOwner)()->postJson('/clinic/patients', [
        'surface' => 'new_case', 'first_name' => 'Meera', 'last_name' => 'Iyer', 'phone' => '9811100777', 'gender' => 'female', ...$fields,
    ]);
    $this->bills = fn () => PatientBilling::query()->withoutGlobalScopes()->where('clinic_id', $this->f->clinic->id)->count();
});

test('a Physiotherapy-only clinic offers only Physiotherapy, and registering or checking in raises no charge', function () {
    $this->f->clinic->update(['specialties' => ['physiotherapy']]);
    $neha = ($this->practitioner)('Dr. Neha Shah', 'sports_rehabilitation');

    $config = ($this->asOwner)()->getJson('/clinic/reception/intake')->assertOk()->json();
    expect(collect($config['specialties'])->pluck('key')->all())->toBe(['physiotherapy'])
        ->and(collect($config['doctors'])->flatMap(fn (array $doctor) => collect($doctor['contexts'])->pluck('specialty'))->unique()->values()->all())->toBe(['physiotherapy']);

    // Register only: a patient, no visit, no bill.
    ($this->newCase)()->assertCreated()->assertJsonPath('check_in', null)->assertJsonPath('billing', null);
    // Register & check in with the physiotherapist: a visit, no bill (no case or registration fee exists).
    ($this->newCase)(['phone' => '9811100778', 'first_name' => 'Ravi', 'check_in' => true, 'doctor_id' => $neha->id, 'visit_reason' => 'Knee pain'])
        ->assertCreated()->assertJsonPath('billing', null);

    $visit = PatientVisit::sole();
    expect($visit->clinic_specialty_id)->toBe(app(ClinicSpecialties::class)->active($this->f->clinic, 'physiotherapy')->id)
        ->and(($this->bills)())->toBe(0);
});

test('at a Physiotherapy clinic that takes fees at check-in, a physiotherapist without a consultation fee is checked in free', function () {
    $this->f->clinic->update(['specialties' => ['physiotherapy']]);
    $neha = ($this->practitioner)('Dr. Neha Shah', 'sports_rehabilitation');
    app(PatientFlowConfigService::class)->updateCheckIn($this->f->clinic, ['payment' => 'at_check_in'], $this->f->owner);

    ($this->newCase)(['check_in' => true, 'doctor_id' => $neha->id])->assertCreated()->assertJsonPath('billing', null);
    ($this->newCase)(['phone' => '9811100779', 'first_name' => 'Ravi'])->assertCreated()->assertJsonPath('billing', null);

    expect(($this->bills)())->toBe(0);
});

test('Dental: a fee charged at check-in is the one the desk was shown; register-only and a doctor without a fee charge nothing', function () {
    $this->f->clinic->update(['specialties' => ['dental']]);
    $raj = ($this->practitioner)('Dr. Raj Patel', 'general_dentistry', 500);
    $amit = ($this->practitioner)('Dr. Amit Shah', 'general_dentistry');
    app(PatientFlowConfigService::class)->updateCheckIn($this->f->clinic, ['payment' => 'at_check_in', 'show_fee' => false], $this->f->owner);

    // The desk sees the fee before saving, even with fee display off: it will be charged.
    expect(collect(($this->asOwner)()->getJson('/clinic/reception/intake')->json('doctors'))->firstWhere('id', $raj->id)['fee'])->toEqual(500);

    // Register only: nothing, whatever the doctor's fee.
    ($this->newCase)(['doctor_id' => $raj->id])->assertCreated()->assertJsonPath('billing', null);
    // A dentist with no fee: a zero-payable visit, no bill (and so no payment step).
    ($this->newCase)(['phone' => '9811100781', 'check_in' => true, 'doctor_id' => $amit->id, 'expected_charge' => null])->assertCreated()->assertJsonPath('billing', null);
    expect(($this->bills)())->toBe(0);

    // Shown "no charge", the server would charge ₹500: refused, and nothing is created.
    $visits = PatientVisit::count();
    ($this->newCase)(['phone' => '9811100780', 'check_in' => true, 'doctor_id' => $raj->id, 'expected_charge' => null])
        ->assertStatus(422)->assertJsonValidationErrors('expected_charge');
    expect(Patient::count())->toBe(2)->and(PatientVisit::count())->toBe($visits)->and(($this->bills)())->toBe(0);

    // Shown ₹500: one bill for exactly that, owing — the payment is a separate step.
    ($this->newCase)(['phone' => '9811100780', 'check_in' => true, 'doctor_id' => $raj->id, 'expected_charge' => 500])
        ->assertCreated()->assertJsonPath('billing.total', 500)->assertJsonPath('billing.outstanding', 500);
    expect(($this->bills)())->toBe(1);
});

test('Physiotherapy never charges at check-in, even with a fee and a clinic that collects at check-in', function () {
    $this->f->clinic->update(['specialties' => ['physiotherapy']]);
    $neha = ($this->practitioner)('Dr. Neha Shah', 'sports_rehabilitation', 500);
    app(PatientFlowConfigService::class)->updateCheckIn($this->f->clinic, ['payment' => 'at_check_in'], $this->f->owner);

    // The desk is told Physiotherapy does not charge at check-in.
    ($this->asOwner)()->getJson('/clinic/reception/intake')->assertJsonPath('check_in.no_charge_modules', ['physiotherapy']);

    // A client that expected the fee is told the truth: nothing is charged.
    ($this->newCase)(['check_in' => true, 'doctor_id' => $neha->id, 'expected_charge' => 500])->assertStatus(422)->assertJsonValidationErrors('expected_charge');
    $patientId = ($this->newCase)(['check_in' => true, 'doctor_id' => $neha->id, 'expected_charge' => null])->assertCreated()->assertJsonPath('billing', null)->json('patient.id');

    // Repeat Case the next day: still nothing.
    $this->travel(1)->days();
    ($this->asOwner)()->postJson('/clinic/reception/check-in', ['patient_id' => $patientId, 'practitioner_id' => $neha->id])->assertCreated()->assertJsonPath('billing', null);

    expect(($this->bills)())->toBe(0)->and(PatientVisit::count())->toBe(2);
});

test('a Dental and Physiotherapy clinic asks what the visit is for, and the server holds the choice to what is practised', function () {
    $this->f->clinic->update(['specialties' => ['dental', 'physiotherapy']]);
    $raj = ($this->practitioner)('Dr. Raj Patel', 'general_dentistry');
    $neha = ($this->practitioner)('Dr. Neha Shah', 'sports_rehabilitation');

    $config = ($this->asOwner)()->getJson('/clinic/reception/intake')->assertOk()->json();
    expect(collect($config['specialties'])->pluck('key')->sort()->values()->all())->toBe(['dental', 'physiotherapy']);

    // No doctor and no module: refused, never guessed.
    ($this->newCase)(['check_in' => true])->assertStatus(422)->assertJsonValidationErrors('doctor_id');
    // A module the clinic does not practise is refused.
    ($this->newCase)(['check_in' => true, 'specialties' => ['dermatology']])->assertStatus(422);
    // A dentist cannot take a Physiotherapy visit.
    ($this->newCase)(['check_in' => true, 'doctor_id' => $raj->id, 'specialties' => ['physiotherapy'], 'visit_area' => 'sports_rehabilitation'])->assertStatus(422)->assertJsonValidationErrors('doctor_id');
    expect(Patient::count())->toBe(0);

    // The physiotherapist decides the context: a Physiotherapy visit.
    $patientId = ($this->newCase)(['check_in' => true, 'doctor_id' => $neha->id])->assertCreated()->json('patient.id');
    expect(PatientVisit::sole()->clinic_specialty_id)->toBe(app(ClinicSpecialties::class)->active($this->f->clinic, 'physiotherapy')->id);

    // The same person comes back for Dental: one patient, a second visit under Dental.
    $this->travel(1)->days();
    ($this->asOwner)()->postJson('/clinic/reception/check-in', ['patient_id' => $patientId, 'practitioner_id' => $raj->id])->assertCreated();
    expect(Patient::count())->toBe(1)
        ->and(PatientVisit::query()->latest('id')->first()->clinic_specialty_id)->toBe(app(ClinicSpecialties::class)->active($this->f->clinic, 'dental')->id)
        ->and(($this->bills)())->toBe(0);
});

test('the owner\'s field settings are enforced on both registration surfaces', function () {
    app(PatientFieldConfigService::class)->update($this->f->clinic, [
        'email' => ['state' => 'required', 'new_case' => true, 'full_page' => true],
        'blood_group' => 'hidden',
    ], $this->f->owner);

    // New Case (JSON) and the full page (form) both require the email and drop the hidden blood group.
    ($this->newCase)()->assertStatus(422)->assertJsonValidationErrors('email');
    ($this->asOwner)()->post('/clinic/patients', ['first_name' => 'Meera', 'last_name' => 'Iyer', 'phone' => '9811100777', 'gender' => 'female'])->assertSessionHasErrors('email');

    ($this->asOwner)()->post('/clinic/patients', ['first_name' => 'Meera', 'last_name' => 'Iyer', 'phone' => '9811100777', 'gender' => 'female', 'email' => 'meera@example.com', 'blood_group' => 'O+'])->assertSessionHasNoErrors();
    expect(Patient::sole()->blood_group)->toBeNull();

    // Both surfaces are sent the same settings to render.
    ($this->asOwner)()->get('/clinic/patients/create')->assertInertia(fn ($page) => $page->where('form.fields.email.state', 'required')->where('form.fields.blood_group.state', 'hidden'));
    $intake = ($this->asOwner)()->getJson('/clinic/reception/intake')->json('fields');
    expect($intake['email']['state'])->toBe('required')->and($intake['blood_group']['state'])->toBe('hidden');
});
