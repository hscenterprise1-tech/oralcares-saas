<?php

use App\ClinicRole;
use App\Models\PatientTimelineEvent;
use App\Services\Clinical\EncounterWorkspace;
use App\Services\ClinicalOrderService;
use App\Services\ClinicalPrescriptionService;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\ReceptionFixture;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-02 10:00', 'Asia/Kolkata'));
    $this->f = ReceptionFixture::make();
    [$this->doctorUser, $this->doctorSession] = $this->f->memberWithRole(ClinicRole::Doctor);
    $this->me = $this->f->doctor('Dr. Me', $this->doctorUser);
    $this->patient = $this->f->patient('Rahul', 'Sharma');
    $this->actingAs($this->doctorUser);

    $workspace = app(EncounterWorkspace::class);
    $this->encounter = $workspace->startForPatient($this->patient, $this->f->branch, $this->me);
    $workspace->autosave($this->encounter, ['chief_complaint' => 'Toothache'], 0);
    $workspace->addDiagnosis($this->encounter, ['description' => 'Irreversible pulpitis', 'code' => 'K04.0']);
    app(ClinicalPrescriptionService::class)->issuePrescription($this->encounter, [], [
        ['drug_name' => 'Amoxicillin', 'strength' => '500 mg', 'dosage' => '1 capsule', 'frequency' => 'TDS', 'duration' => '5 days'],
    ], $this->me);
    $order = app(ClinicalOrderService::class)->placeOrder($this->encounter, ['priority' => 'routine'], [['item_name' => 'CBC', 'category' => 'lab']], $this->me);
    app(ClinicalOrderService::class)->recordResult($order->items()->sole(), null, ['values' => [['name' => 'Hb', 'value' => '9.0', 'flag' => 'low']]]);
    $workspace->finalize($this->encounter, $this->me);

    $this->asDoctor = fn () => $this->actingAs($this->doctorUser)->withSession($this->doctorSession);
    $this->section = fn (string $section) => "/clinic/patients/{$this->patient->id}/360/{$section}";
});

test('quick context shows diagnoses and medicines exactly as recorded, never invented', function () {
    ($this->asDoctor)()->get("/clinic/patients/{$this->patient->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('context.conditions.diagnosed.0.description', 'Irreversible pulpitis')
            ->where('context.conditions.diagnosed.0.code', 'K04.0')
            ->where('context.medications.prescribed.0.name', 'Amoxicillin 500 mg')
            ->where('context.medications.prescribed.0.dosage', '1 capsule')
            ->where('context.medications.prescribed.0.frequency', 'Three times a day')
            ->where('context.medications.prescribed.0.duration', '5 days')
            ->where('context.allergies', [])
            ->where('context.medications.reported', []));
});

test('the overview tells now, next and recent from the record', function () {
    ($this->asDoctor)()->get("/clinic/patients/{$this->patient->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('overview.current.open_consultation', null)
            ->where('overview.recent.consultation.number', 'ENC-2627-000001')
            ->where('overview.recent.consultation.diagnosis', 'Irreversible pulpitis')
            ->where('overview.recent.consultation.signed', true)
            ->where('overview.recent.investigation.name', 'CBC')
            ->where('overview.recent.investigation.is_abnormal', true)
            ->where('overview.next.investigations.0.name', 'CBC'));
});

test('encounters, prescriptions and investigations come back from their sections as written', function () {
    ($this->asDoctor)()->getJson(($this->section)('encounters'))
        ->assertOk()
        ->assertJsonPath('data.0.number', 'ENC-2627-000001')
        ->assertJsonPath('data.0.status', 'signed')
        ->assertJsonPath('data.0.complaint', 'Toothache')
        ->assertJsonPath('data.0.diagnoses.0.code', 'K04.0')
        ->assertJsonPath('data.0.url', route('clinic.consultations.show', $this->encounter));

    ($this->asDoctor)()->getJson(($this->section)('prescriptions'))
        ->assertJsonPath('data.0.items.0.name', 'Amoxicillin')
        ->assertJsonPath('data.0.items.0.strength', '500 mg')
        ->assertJsonPath('data.0.items.0.frequency', 'Three times a day')
        ->assertJsonPath('data.0.practitioner', 'Dr. Me')
        ->assertJsonPath('data.0.items.0.timing', null);

    ($this->asDoctor)()->getJson(($this->section)('investigations'))
        ->assertJsonPath('data.0.name', 'CBC')
        ->assertJsonPath('data.0.result.is_abnormal', true)
        ->assertJsonPath('data.0.result.values.0.value', '9.0')
        ->assertJsonPath('counts.abnormal', 1);
    ($this->asDoctor)()->getJson(($this->section)('investigations').'?status=reviewed')->assertJsonPath('meta.total', 0);
    ($this->asDoctor)()->getJson(($this->section)('investigations').'?status=bogus')->assertUnprocessable();
});

test('a practitioner can start a consultation from Patient 360; reception cannot and sees no clinical context', function () {
    ($this->asDoctor)()->get("/clinic/patients/{$this->patient->id}")
        ->assertInertia(fn (Assert $page) => $page->where('actions', fn ($actions) => collect($actions)->contains('key', 'start_consultation')));

    [$receptionUser, $receptionSession] = $this->f->memberWithRole(ClinicRole::Receptionist);
    $this->actingAs($receptionUser)->withSession($receptionSession)->get("/clinic/patients/{$this->patient->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('actions', fn ($actions) => ! collect($actions)->contains('key', 'start_consultation'))
            ->where('context.conditions.diagnosed', [])
            ->where('context.medications.prescribed', []));
    $this->actingAs($receptionUser)->withSession($receptionSession)->post("/clinic/consultations/patients/{$this->patient->id}")->assertForbidden();
});

test('clinical events read as clinical history on the timeline, and are hidden from reception', function () {
    expect(PatientTimelineEvent::where('patient_id', $this->patient->id)->pluck('event_type')->all())
        ->toContain('encounter_started', 'prescription_issued', 'clinical_order_placed', 'investigation_resulted', 'encounter_finalized');

    ($this->asDoctor)()->getJson(($this->section)('timeline'))
        ->assertOk()
        ->assertJson(fn ($json) => $json->where('data', fn ($events) => collect($events)
            ->contains(fn ($event) => $event['title'] === 'Consultation signed' && $event['category'] === 'consultation' && $event['description'] === 'Consultation ENC-2627-000001 signed.'))->etc());

    [$receptionUser, $receptionSession] = $this->f->memberWithRole(ClinicRole::Receptionist);
    $this->actingAs($receptionUser)->withSession($receptionSession)->getJson(($this->section)('timeline'))
        ->assertOk()
        ->assertJson(fn ($json) => $json->where('data', fn ($events) => collect($events)->whereIn('category', ['consultation', 'prescription', 'investigation', 'treatment'])->isEmpty())->etc());
});
