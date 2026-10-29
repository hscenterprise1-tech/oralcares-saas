<?php

use App\Models\ClinicEmployee;
use App\Models\HealthcareSpecialtyMaster;
use App\Models\Patient;
use App\Models\PatientBranchActivity;
use App\Models\PatientMerge;
use App\Models\SecurityAuditLog;
use App\Services\Patient\ClinicSpecialties;
use App\Services\Patient\PatientSpecialties;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\ReceptionFixture;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-03 10:00', 'Asia/Kolkata'));
    foreach ([['general_dentistry', 'dental'], ['physiotherapy', 'rehabilitation']] as [$key, $category]) {
        HealthcareSpecialtyMaster::create(['key' => $key, 'name' => str($key)->headline(), 'category' => $category, 'category_label' => str($category)->headline(), 'is_active' => true]);
    }
    $this->f = ReceptionFixture::make();
    $this->f->clinic->update(['specialties' => ['dental']]);
    $this->asOwner = fn () => $this->actingAs($this->f->owner)->withSession($this->f->session);
    $this->register = fn (array $fields = []) => ($this->asOwner)()->post('/clinic/patients', [
        'first_name' => 'Kavya', 'last_name' => 'Menon', 'phone' => '9811100001', 'gender' => 'female', ...$fields,
    ]);
});

test('the directory sends one cursor page with contact, specialty and last-seen branch, and no counts', function () {
    app(ClinicSpecialties::class)->sync($this->f->clinic, ['dental', 'physiotherapy']);
    $patient = $this->f->patient('Asha', 'Rao', ['email' => 'asha@example.com']);
    app(PatientSpecialties::class)->enroll($patient, 'physiotherapy', 'manual');
    PatientBranchActivity::create(['clinic_id' => $this->f->clinic->id, 'patient_id' => $patient->id, 'branch_id' => $this->f->branch->id, 'first_seen_at' => now(), 'last_seen_at' => now(), 'first_source' => 'visit']);
    $this->f->patient('Other', 'Person');

    ($this->asOwner)()->get('/clinic/patients?specialty=physiotherapy')->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('ClinicPortal/Patients/Index', true)
            ->has('patients.data', 1)
            ->where('patients.data.0.email', 'asha@example.com')
            ->where('patients.data.0.specialties', ['physiotherapy'])
            ->where('patients.data.0.last_branch', $this->f->branch->name)
            ->where('patients.next_cursor', null)
            ->where('filters.specialty', 'physiotherapy')
            ->where('can.create', true)
            ->missing('metrics')
            ->missing('patients.total')
        );
});

test('there is no patient-status filter: a status in an old link changes nothing', function () {
    $this->f->patient('Asha', 'Rao');

    ($this->asOwner)()->get('/clinic/patients?view=all&status=deleted')->assertOk()->assertSessionHasNoErrors()
        ->assertInertia(fn (Assert $page) => $page->where('filters.status', '')->where('patients.data.0.name', 'Asha Rao'));
});

test('merged records cannot be edited: the page redirects to the patient kept and the save is forbidden', function () {
    $survivor = $this->f->patient('Kept', 'Record');
    $merged = $this->f->patient('Gone', 'Record', ['status' => 'merged']);
    PatientMerge::create(['clinic_id' => $this->f->clinic->id, 'source_patient_id' => $merged->id, 'survivor_patient_id' => $survivor->id, 'moved' => [], 'conflicts' => [], 'merged_by' => $this->f->owner->id, 'merged_at' => now()]);
    $update = ['first_name' => 'Changed', 'last_name' => 'Name', 'phone' => '9000011111', 'gender' => 'male'];

    ($this->asOwner)()->get("/clinic/patients/{$merged->id}/edit")->assertRedirect(route('clinic.patients.show', $survivor->id));
    ($this->asOwner)()->put("/clinic/patients/{$merged->id}", $update)->assertForbidden();

    expect($merged->fresh()->first_name)->toBe('Gone');
    ($this->asOwner)()->get("/clinic/patients/{$survivor->id}/edit")->assertOk()
        ->assertInertia(fn ($page) => $page->component('ClinicPortal/Patients/Edit', false)->where('patient.status', 'active'));
});

test('a merged record is never offered as a possible duplicate', function () {
    $this->f->patient('Kavya', 'Menon', ['phone' => '9811100001', 'status' => 'merged']);

    ($this->asOwner)()->postJson('/clinic/patients/check-duplicate', ['phone' => '9811100001'])->assertOk()->assertJsonPath('has_duplicates', false);
    ($this->register)()->assertSessionHasNoErrors();
});

test('registering over an acknowledged duplicate still needs a reason, which the audit keeps', function () {
    $existing = $this->f->patient('Kavya', 'Menon', ['phone' => '9811100001']);

    ($this->register)(['duplicate_acknowledged_ids' => [$existing->id]])
        ->assertSessionHasErrors('duplicate_reason')->assertSessionHas('possible_duplicates');
    ($this->register)(['duplicate_acknowledged_ids' => [$existing->id], 'duplicate_reason' => '  '])->assertSessionHasErrors('duplicate_reason');
    expect(Patient::count())->toBe(1);

    ($this->register)(['duplicate_acknowledged_ids' => [$existing->id], 'duplicate_reason' => 'Mother and daughter, same phone'])->assertSessionHasNoErrors();
    expect(Patient::count())->toBe(2)
        ->and(SecurityAuditLog::where('event', 'patient.duplicate_override')->sole()->context['reason'])->toBe('Mother and daughter, same phone');
});

test('duplicate matches carry specialty and last-seen branch so the desk can recognise the person', function () {
    $existing = $this->f->patient('Kavya', 'Menon', ['phone' => '9811100001']);
    app(PatientSpecialties::class)->enroll($existing, 'dental', 'manual');
    PatientBranchActivity::create(['clinic_id' => $this->f->clinic->id, 'patient_id' => $existing->id, 'branch_id' => $this->f->branch->id, 'first_seen_at' => now(), 'last_seen_at' => now(), 'first_source' => 'visit']);

    ($this->asOwner)()->postJson('/clinic/patients/check-duplicate', ['phone' => '9811100001'])->assertOk()
        ->assertJsonPath('matches.0.specialties', ['dental'])
        ->assertJsonPath('matches.0.last_seen.branch', $this->f->branch->name);
});

test('only practising staff are offered as the doctor at registration', function () {
    $receptionist = ClinicEmployee::create(['clinic_id' => $this->f->clinic->id, 'name' => 'Desk Person', 'status' => 'active', 'email' => 'desk@example.com']);
    $doctors = collect([$this->f->doctorA->id, $this->f->doctorB->id])->sort()->values()->all();

    ($this->asOwner)()->get('/clinic/patients/create')->assertOk()
        ->assertInertia(fn ($page) => $page->component('ClinicPortal/Patients/Create', true)
            ->where('practitioners', fn ($practitioners) => collect($practitioners)->pluck('id')->sort()->values()->all() === $doctors)
        );
    expect(collect(ClinicEmployee::activePractitioners($this->f->clinic->id))->pluck('id'))->not->toContain($receptionist->id);
});
