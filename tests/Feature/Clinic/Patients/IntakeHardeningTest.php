<?php

use App\BranchStatus;
use App\ClinicMembershipStatus;
use App\ClinicRole;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Branch;
use App\Models\ClinicMembership;
use App\Models\ClinicQueue;
use App\Models\ClinicQueueItem;
use App\Models\ClinicSettingAudit;
use App\Models\ConsentRecord;
use App\Models\HealthcareSpecialtyMaster;
use App\Models\Patient;
use App\Models\PatientAlert;
use App\Models\PatientBranchActivity;
use App\Models\PatientClinicalEntry;
use App\Models\PatientSpecialty;
use App\Models\PatientVisit;
use App\Models\User;
use App\Services\Clinical\ClinicalCatalogue;
use App\Services\Clinical\EncounterWorkspace;
use App\Services\ClinicContext;
use App\Services\ClinicRoleAssignmentService;
use App\Services\Patient\ClinicSpecialties;
use App\Services\Patient\PatientClinicalEntries;
use App\Services\PatientFieldConfigService;
use App\UserType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\ReceptionFixture;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-04 10:00', 'Asia/Kolkata'));
    foreach ([['general_dentistry', 'dental'], ['physiotherapy', 'rehabilitation']] as [$key, $category]) {
        HealthcareSpecialtyMaster::create(['key' => $key, 'name' => str($key)->headline(), 'category' => $category, 'category_label' => str($category)->headline(), 'is_active' => true]);
    }
    $this->f = ReceptionFixture::make();
    $this->f->clinic->update(['specialties' => ['dental']]);
    app(ClinicSpecialties::class)->sync($this->f->clinic, ['dental', 'physiotherapy']);
    $this->catalogue = app(ClinicalCatalogue::class);
    $this->entries = app(PatientClinicalEntries::class);
    [$this->receptionist, $this->receptionSession] = $this->f->memberWithRole(ClinicRole::Receptionist);
    $this->asOwner = fn () => $this->actingAs($this->f->owner)->withSession($this->f->session);
    $this->asReception = fn () => $this->actingAs($this->receptionist)->withSession($this->receptionSession);
    $this->newCase = fn (array $fields = [], $as = null) => ($as ?? ($this->asOwner)())->postJson('/clinic/patients', [
        'surface' => 'new_case', 'first_name' => 'Kavya', 'last_name' => 'Menon', 'phone' => '9811100001', 'gender' => 'female', 'specialties' => ['dental'], ...$fields,
    ]);
});

// ---------------------------------------------------------------- Permission matrix

test('the owner can manage every part of intake configuration, and each change is audited', function () {
    ($this->asOwner)()->putJson('/clinic/admin/settings/catalogue/condition/asthma', ['name' => 'Asthma (any)', 'position' => 0, 'required' => true, 'specialty_keys' => ['dental']])->assertOk();
    ($this->asOwner)()->postJson('/clinic/admin/settings/catalogue/allergy', ['name' => 'Chlorhexidine', 'severity' => 'critical'])->assertCreated()->assertJsonPath('key', 'c_chlorhexidine');
    ($this->asOwner)()->putJson('/clinic/admin/settings/catalogue/visit_type/review', ['enabled' => false])->assertOk();
    ($this->asOwner)()->put('/clinic/admin/settings/patient-fields', ['email' => ['state' => 'optional', 'order' => 5, 'new_case' => true, 'visible_roles' => ['Receptionist', 'Doctor'], 'editable_roles' => ['Receptionist']]])->assertSessionHasNoErrors();
    ($this->asOwner)()->put('/clinic/admin/settings/check-in', ['visit_reason' => 'required', 'priority' => false])->assertSessionHasNoErrors();
    ($this->asOwner)()->getJson('/clinic/admin/settings/catalogue/condition')->assertOk()->assertJsonPath('items.0.name', 'Asthma (any)');

    expect(ClinicSettingAudit::where('clinic_id', $this->f->clinic->id)->pluck('section')->countBy()->all())
        ->toEqualCanonicalizing(['clinical_catalogue' => 3, 'patient_fields' => 1, 'check_in' => 1]);
    $email = app(PatientFieldConfigService::class)->definitions($this->f->clinic->fresh())['email'];
    expect($email)->toMatchArray(['order' => 5, 'new_case' => true, 'visible_roles' => ['Doctor', 'Receptionist'], 'editable_roles' => ['Receptionist']]);
});

test('Reception consumes configuration but can change none of it', function () {
    ($this->asReception)()->getJson('/clinic/reception/intake')->assertOk();

    ($this->asReception)()->getJson('/clinic/admin/settings/catalogue/condition')->assertForbidden();
    ($this->asReception)()->putJson('/clinic/admin/settings/catalogue/condition/asthma', ['enabled' => false])->assertForbidden();
    ($this->asReception)()->postJson('/clinic/admin/settings/catalogue/condition', ['name' => 'X'])->assertForbidden();
    ($this->asReception)()->put('/clinic/admin/settings/patient-fields', ['email' => 'required'])->assertForbidden();
    ($this->asReception)()->put('/clinic/admin/settings/check-in', ['priority' => false])->assertForbidden();

    expect(ClinicSettingAudit::count())->toBe(0);
});

test('Reception records clinical entries only while registering; changing them afterwards is refused', function () {
    ($this->newCase)(['medical_conditions' => ['diabetes'], 'allergies' => ['Latex']], ($this->asReception)())->assertCreated();
    $patient = Patient::sole();

    ($this->asReception)()->putJson("/clinic/patients/{$patient->id}/clinical-entries/allergy", ['entries' => []])->assertForbidden();
    ($this->asReception)()->putJson("/clinic/patients/{$patient->id}/clinical-entries/condition", ['entries' => [['key' => 'asthma']]])->assertForbidden();
    ($this->asReception)()->put("/clinic/patients/{$patient->id}", ['first_name' => 'Kavya', 'last_name' => 'Menon', 'phone' => '9811100001', 'gender' => 'female', 'allergies' => []])->assertForbidden();

    expect($this->entries->labelsFor($patient, 'allergy'))->toBe(['Latex'])
        ->and($this->entries->labelsFor($patient, 'condition'))->toBe(['Diabetes']);
});

test('a clinician manages clinical entries, resolving rather than deleting, but gets no settings rights', function () {
    $patient = $this->f->patient();
    $this->entries->add($patient, 'allergy', $this->entries->prepare($this->f->clinic->id, 'allergy', [['label' => 'Latex']], 'a'), 'registration', null);
    [$doctor, $session] = $this->f->memberWithRole(ClinicRole::Doctor);
    $asDoctor = fn () => $this->actingAs($doctor)->withSession($session);

    $asDoctor()->putJson("/clinic/patients/{$patient->id}/clinical-entries/allergy", ['entries' => [['label' => 'Penicillin', 'severity' => 'critical']]])
        ->assertOk()->assertJsonPath('entries', ['Penicillin']);
    $asDoctor()->putJson("/clinic/patients/{$patient->id}/clinical-entries/medication", ['entries' => [['label' => 'Metformin', 'details' => ['dose' => '500mg', 'frequency' => 'BD']]]])->assertOk();

    $latex = PatientClinicalEntry::query()->withoutGlobalScopes()->where('patient_id', $patient->id)->where('label', 'Latex')->sole();
    expect($latex->status)->toBe('resolved')->and($latex->resolved_by)->toBe($doctor->id)
        ->and(PatientClinicalEntry::query()->withoutGlobalScopes()->where('label', 'Metformin')->sole()->details)->toBe(['dose' => '500mg', 'frequency' => 'BD'])
        ->and($patient->alerts()->where('type', 'allergy')->sole()->only(['description', 'severity']))->toBe(['description' => 'Allergy: Penicillin', 'severity' => 'critical']);

    $asDoctor()->putJson('/clinic/admin/settings/catalogue/condition/asthma', ['enabled' => false])->assertForbidden();
    $asDoctor()->put('/clinic/admin/settings/patient-fields', ['email' => 'required'])->assertForbidden();
});

test('clinical entries of an archived patient, or another clinic\'s, cannot be changed', function () {
    $archived = $this->f->patient('Old', 'Record', ['status' => 'archived']);
    ($this->asOwner)()->putJson("/clinic/patients/{$archived->id}/clinical-entries/allergy", ['entries' => []])->assertForbidden();

    $other = ReceptionFixture::make('other-clinic');
    $theirs = $other->patient();
    ($this->asOwner)()->putJson("/clinic/patients/{$theirs->id}/clinical-entries/allergy", ['entries' => []])->assertNotFound();
});

// ---------------------------------------------------------------- Payload privacy

test('nothing Reception is sent carries the clinical record, only identity and safety flags', function () {
    $patient = $this->f->patient('Rajesh', 'Patel', ['phone' => '9876501284']);
    PatientBranchActivity::create(['clinic_id' => $this->f->clinic->id, 'patient_id' => $patient->id, 'branch_id' => $this->f->branch->id, 'first_seen_at' => now()->subDay(), 'last_seen_at' => now()->subDay(), 'first_source' => 'visit']);
    $clinicId = $this->f->clinic->id;
    $this->entries->add($patient, 'allergy', $this->entries->prepare($clinicId, 'allergy', [['label' => 'Penicillin']], 'a'), 'registration', null);
    $this->entries->add($patient, 'condition', $this->entries->prepare($clinicId, 'condition', [['key' => 'heart_disease'], ['key' => 'hypertension']], 'c'), 'registration', null);
    $this->entries->add($patient, 'medication', $this->entries->prepare($clinicId, 'medication', [['label' => 'Atorvastatin']], 'm'), 'clinical', null);
    $this->actingAs($this->f->owner);
    $encounter = app(EncounterWorkspace::class)->startForPatient($patient, $this->f->branch, $this->f->doctorA, null, 'dental');
    $encounter->update(['chief_complaint' => 'SECRET-COMPLAINT', 'history' => 'SECRET-HISTORY', 'examination' => 'SECRET-EXAM']);
    $inertia = ['X-Inertia' => 'true', 'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(request())];

    $payloads = [
        'search' => ($this->asReception)()->getJson('/clinic/reception/check-in/search?q=Rajesh')->assertOk()->getContent(),
        'recent' => ($this->asReception)()->getJson('/clinic/reception/check-in/recent')->assertOk()->getContent(),
        'intake' => ($this->asReception)()->getJson('/clinic/reception/intake')->assertOk()->getContent(),
        'duplicate' => ($this->asReception)()->postJson('/clinic/patients/check-duplicate', ['phone' => '9876501284'])->assertOk()->getContent(),
        'edit' => ($this->asReception)()->get("/clinic/patients/{$patient->id}/edit", $inertia)->assertOk()->getContent(),
        'patient360' => ($this->asReception)()->get("/clinic/patients/{$patient->id}", $inertia)->assertOk()->getContent(),
    ];

    // The Patient 360 sections Reception may open.
    foreach (['timeline', 'appointments', 'investigations', 'billing', 'documents', 'dental'] as $section) {
        $payloads["360.{$section}"] = ($this->asReception)()->getJson("/clinic/patients/{$patient->id}/360/{$section}")->getContent();
    }

    foreach ($payloads as $name => $json) {
        expect($json)->not->toContain('SECRET-', "{$name} leaked encounter content")
            ->not->toContain('Atorvastatin', "{$name} leaked a medication")
            ->not->toContain('Hypertension', "{$name} leaked a non-critical condition");
    }
    expect($payloads['search'])->toContain('Heart disease', 'Penicillin')
        ->and($payloads['duplicate'])->not->toContain('Penicillin')
        ->and(json_decode($payloads['patient360'], true)['props']['context']['conditions']['reported'] ?? null)->toBe([])
        ->and(json_decode($payloads['patient360'], true)['props']['context']['medications']['reported'] ?? null)->toBe([]);

    // Clinical sections are refused outright.
    foreach (['encounters', 'prescriptions', 'treatment', 'audit'] as $section) {
        ($this->asReception)()->getJson("/clinic/patients/{$patient->id}/360/{$section}")->assertForbidden();
    }
});

// ---------------------------------------------------------------- Effective configuration, end to end

test('a renamed, reordered, required condition flows from override to the intake payload to storage', function () {
    $this->catalogue->configure($this->f->clinic, $this->f->owner, 'condition', 'thyroid_disorder', ['name' => 'Thyroid', 'position' => 0, 'required' => true]);

    $conditions = ($this->asReception)()->getJson('/clinic/reception/intake')->json('catalogue.conditions');
    expect($conditions[0])->toMatchArray(['key' => 'thyroid_disorder', 'name' => 'Thyroid', 'required' => true]);

    ($this->newCase)(['medical_conditions' => [['key' => 'thyroid_disorder', 'label' => 'anything the client says']]], ($this->asReception)())->assertCreated();
    expect(PatientClinicalEntry::query()->withoutGlobalScopes()->sole()->only(['catalogue_key', 'label']))->toBe(['catalogue_key' => 'thyroid_disorder', 'label' => 'Thyroid']);
});

test('an allergy switched off, renamed or limited to a specialty is enforced on save', function () {
    $this->catalogue->addCustom($this->f->clinic, $this->f->owner, 'allergy', 'Chlorhexidine', ['severity' => 'critical', 'specialty_keys' => ['dental']]);
    $this->catalogue->configure($this->f->clinic, $this->f->owner, 'allergy', 'c_chlorhexidine', ['name' => 'Chlorhexidine mouthwash']);
    $this->catalogue->addCustom($this->f->clinic, $this->f->owner, 'allergy', 'Kinesio tape', ['specialty_keys' => ['physiotherapy']]);
    $this->catalogue->addCustom($this->f->clinic, $this->f->owner, 'allergy', 'Retired one');
    $this->catalogue->configure($this->f->clinic, $this->f->owner, 'allergy', 'c_retired_one', ['enabled' => false]);

    ($this->newCase)(['allergies' => ['c_retired_one']])->assertStatus(422)->assertJsonValidationErrors('allergies.0');
    ($this->newCase)(['allergies' => ['c_kinesio_tape']])->assertStatus(422)->assertJsonValidationErrors('allergies.0');
    ($this->newCase)(['allergies' => ['c_chlorhexidine']])->assertCreated();

    expect(PatientClinicalEntry::query()->withoutGlobalScopes()->sole()->only(['label', 'severity']))->toBe(['label' => 'Chlorhexidine mouthwash', 'severity' => 'critical']);
});

test('visit types: specialty-specific, disabled or archived ones are refused by the server', function () {
    $this->catalogue->addCustom($this->f->clinic, $this->f->owner, 'visit_type', 'Exercise session', ['specialty_keys' => ['physiotherapy']]);
    $this->catalogue->configure($this->f->clinic, $this->f->owner, 'visit_type', 'procedure', ['enabled' => false]);
    $this->catalogue->configure($this->f->clinic, $this->f->owner, 'visit_type', 'review', ['archived' => true]);
    $patient = $this->f->patient();
    $checkIn = fn (array $visit) => ($this->asReception)()->postJson('/clinic/reception/check-in', ['patient_id' => $patient->id, ...$visit]);

    $checkIn(['visit_specialty' => 'dental', 'visit_type' => 'c_exercise_session'])->assertStatus(422)->assertJsonValidationErrors('visit_type');
    $checkIn(['visit_specialty' => 'dental', 'visit_type' => 'procedure'])->assertStatus(422)->assertJsonValidationErrors('visit_type');
    $checkIn(['visit_specialty' => 'dental', 'visit_type' => 'review'])->assertStatus(422)->assertJsonValidationErrors('visit_type');
    $checkIn(['visit_specialty' => 'dental', 'visit_type' => 'made_up'])->assertStatus(422)->assertJsonValidationErrors('visit_type');
    expect(PatientVisit::count())->toBe(0);

    $checkIn(['visit_specialty' => 'physiotherapy', 'visit_type' => 'c_exercise_session'])->assertCreated();
    expect(PatientVisit::sole()->visit_type_key)->toBe('c_exercise_session');
});

test('registration fields follow surface, specialty and order settings', function () {
    $fields = app(PatientFieldConfigService::class);
    $fields->update($this->f->clinic, [
        'alternate_phone' => ['state' => 'optional', 'new_case' => true, 'full_page' => false],
        'blood_group' => ['state' => 'optional', 'new_case' => false],
        'email' => ['state' => 'required', 'specialties' => ['physiotherapy']],
        'address' => ['order' => 1],
    ], $this->f->owner);

    // New Case keeps a New-Case-only field and drops a full-page-only one.
    ($this->newCase)(['alternate_phone' => '9811199999', 'blood_group' => 'B+'])->assertCreated();
    expect(Patient::sole()->only(['alternate_phone', 'blood_group']))->toBe(['alternate_phone' => '9811199999', 'blood_group' => null]);

    // The full page does the reverse.
    ($this->asOwner)()->post('/clinic/patients', ['first_name' => 'Ravi', 'last_name' => 'K', 'phone' => '9811100002', 'gender' => 'male', 'alternate_phone' => '9811188888', 'blood_group' => 'O+', 'specialties' => ['dental']])->assertSessionHasNoErrors();
    expect(Patient::where('phone', '9811100002')->sole()->only(['alternate_phone', 'blood_group']))->toBe(['alternate_phone' => null, 'blood_group' => 'O+']);

    // Email is required only for physiotherapy.
    ($this->newCase)(['phone' => '9811100003', 'first_name' => 'Asha', 'specialties' => ['physiotherapy']])->assertStatus(422)->assertJsonValidationErrors('email');
    ($this->newCase)(['phone' => '9811100003', 'first_name' => 'Asha', 'specialties' => ['physiotherapy'], 'email' => 'asha@example.com'])->assertCreated();

    // The intake payload carries the clinic's order.
    $order = collect(($this->asReception)()->getJson('/clinic/reception/intake')->json('fields'))->sortBy('order')->keys()->first();
    expect($order)->toBe('address');
});

// ---------------------------------------------------------------- Multiple specialties

test('with two specialties, items and visit types follow the chosen one and the server checks membership', function () {
    $this->catalogue->addCustom($this->f->clinic, $this->f->owner, 'condition', 'Joint replacement', ['specialty_keys' => ['physiotherapy']]);

    $intake = ($this->asReception)()->getJson('/clinic/reception/intake')->json();
    expect(collect($intake['specialties'])->pluck('key')->sort()->values()->all())->toBe(['dental', 'physiotherapy'])
        ->and(collect($intake['catalogue']['conditions'])->firstWhere('key', 'c_joint_replacement')['specialty_keys'])->toBe(['physiotherapy'])
        ->and(collect($intake['catalogue']['conditions'])->firstWhere('key', 'diabetes')['specialty_keys'])->toBeNull();

    ($this->newCase)(['medical_conditions' => ['c_joint_replacement']])->assertStatus(422);
    ($this->newCase)(['specialties' => ['ophthalmology']])->assertStatus(422)->assertJsonValidationErrors('specialties.0');
    ($this->newCase)(['specialties' => ['physiotherapy'], 'medical_conditions' => ['c_joint_replacement', 'diabetes'], 'check_in' => true])->assertCreated();

    $patient = Patient::sole();
    expect(PatientSpecialty::query()->withoutGlobalScopes()->where('patient_id', $patient->id)->with('clinicSpecialty')->get()->pluck('clinicSpecialty.specialty_key')->all())->toBe(['physiotherapy'])
        ->and(PatientVisit::sole()->clinic_specialty_id)->toBe(app(ClinicSpecialties::class)->active($this->f->clinic, 'physiotherapy')->id);
});

// ---------------------------------------------------------------- Duplicates and check-in safety

test('New Case meets the server duplicate gate on name and date of birth too', function () {
    $existing = $this->f->patient('Rohan', 'Das', ['phone' => '9000000001', 'date_of_birth' => '1985-02-01']);

    ($this->newCase)(['first_name' => 'rohan', 'last_name' => 'DAS', 'phone' => '9000000002', 'date_of_birth' => '1985-02-01'])
        ->assertStatus(409)->assertJsonPath('possible_duplicates.0.id', $existing->id)->assertJsonPath('possible_duplicates.0.matched_on', ['name_dob']);
});

test('a failed check-in leaves no patient, visit, queue entry, enrolment, activity, entries, alerts or consent behind', function () {
    $counts = fn () => [Patient::count(), PatientVisit::count(), ClinicQueueItem::count(), PatientSpecialty::query()->withoutGlobalScopes()->count(), PatientBranchActivity::query()->withoutGlobalScopes()->count(), PatientClinicalEntry::query()->withoutGlobalScopes()->count(), PatientAlert::query()->withoutGlobalScopes()->count(), ConsentRecord::query()->withoutGlobalScopes()->count()];
    $before = $counts();
    ClinicQueue::query()->withoutGlobalScopes()->create(['clinic_id' => $this->f->clinic->id, 'branch_id' => $this->f->branch->id, 'queue_date' => '2026-10-04', 'name' => 'Closed', 'closed_at' => now()]);

    ($this->newCase)(['check_in' => true, 'medical_conditions' => ['heart_disease'], 'allergies' => ['Latex'], 'communication_consent' => true])->assertStatus(422);

    expect($counts())->toBe($before);
});

test('check-in refuses a patient already queued, an archived record, an unknown specialty, and members without the queue permission', function () {
    $patient = $this->f->patient();
    $checkIn = fn (array $body, $as = null) => ($as ?? ($this->asReception)())->postJson('/clinic/reception/check-in', $body);

    $checkIn(['patient_id' => $patient->id, 'visit_specialty' => 'dental'])->assertCreated();
    $checkIn(['patient_id' => $patient->id, 'visit_specialty' => 'dental'])->assertStatus(422)->assertJsonValidationErrors('patient_id');
    expect(ClinicQueueItem::count())->toBe(1)->and(PatientVisit::count())->toBe(1);

    $archived = $this->f->patient('Old', 'Record', ['status' => 'archived']);
    $checkIn(['patient_id' => $archived->id])->assertStatus(422);

    $other = $this->f->patient('Third', 'One');
    $checkIn(['patient_id' => $other->id, 'visit_specialty' => 'ophthalmology'])->assertStatus(422)->assertJsonValidationErrors('visit_specialty');

    [$billing, $session] = $this->f->memberWithRole(ClinicRole::BillingStaff);
    $checkIn(['patient_id' => $other->id], $this->actingAs($billing)->withSession($session))->assertForbidden();
    ($this->newCase)(['check_in' => true], $this->actingAs($billing)->withSession($session))->assertForbidden();
    expect(ClinicQueueItem::count())->toBe(1);
});

test('a forged session branch never checks a patient in at a branch the member cannot open', function () {
    $east = Branch::factory()->create(['clinic_id' => $this->f->clinic->id, 'name' => 'East', 'status' => BranchStatus::Active]);
    $user = User::factory()->create(['user_type' => UserType::ClinicUser]);
    $membership = ClinicMembership::factory()->create(['clinic_id' => $this->f->clinic->id, 'user_id' => $user->id, 'is_primary_owner' => false, 'has_all_branches_access' => false, 'status' => ClinicMembershipStatus::Active]);
    $membership->assignBranch($east);
    app(ClinicRoleAssignmentService::class)->syncRoles($user, [ClinicRole::Receptionist->value], [$east]);
    $patient = $this->f->patient();

    $this->actingAs($user)->withSession([ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID => $membership->id, ClinicContext::SESSION_BRANCH_ID => $this->f->branch->id])
        ->postJson('/clinic/reception/check-in', ['patient_id' => $patient->id]);

    expect(PatientVisit::query()->where('branch_id', $this->f->branch->id)->exists())->toBeFalse();
});

test('Repeat Case search costs the same queries for one result as for eight', function () {
    $queries = function () {
        DB::flushQueryLog();
        DB::enableQueryLog();
        ($this->asReception)()->getJson('/clinic/reception/check-in/search?q=Bulk')->assertOk();

        return count(DB::getQueryLog());
    };
    $queries(); // warm the configuration and roster caches first
    $first = $this->f->patient('Bulk', 'One');
    $this->entries->add($first, 'allergy', $this->entries->prepare($this->f->clinic->id, 'allergy', [['label' => 'Latex']], 'a'), 'registration', null);
    $one = $queries();

    foreach (range(2, 8) as $n) {
        $patient = $this->f->patient('Bulk', "Patient{$n}");
        $this->entries->add($patient, 'allergy', $this->entries->prepare($this->f->clinic->id, 'allergy', [['label' => 'Latex']], 'a'), 'registration', null);
        PatientBranchActivity::create(['clinic_id' => $this->f->clinic->id, 'patient_id' => $patient->id, 'branch_id' => $this->f->branch->id, 'first_seen_at' => now(), 'last_seen_at' => now(), 'first_source' => 'visit']);
    }

    expect($queries())->toBe($one);
});
